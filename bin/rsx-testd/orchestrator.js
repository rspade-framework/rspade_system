#!/usr/bin/env node

/**
 * rsx-testd - the docker side of the RSpade framework test runner.
 *
 * PHP decides WHICH classes run and prints the results; this process owns docker and the
 * work queue, and nothing is implemented twice. It is spawned by
 * Rsx_Test_Command::run_docker() with a run directory PHP has already filled:
 *
 *     node system/bin/rsx-testd/orchestrator.js \
 *          --run-dir=<abs> --workers=N --image=rspade-test:latest \
 *          --dev-image=rspade/rspade-server-dev:latest --project-root=<abs>
 *
 * The sequence: sweep leftover containers -> verify the dev image the test image is FROM ->
 * generate the build-context filter -> build the test image -> serve the work queue on a
 * unix socket -> start N containers that pull whole classes from it -> wait for every one of
 * them -> prune. Mechanics, in full: CLAUDE.md in this directory.
 *
 * EXIT CODE = "DID I PRODUCE results.jsonl". Zero whenever the run reached the point of
 * having a results file, INCLUDING a run in which a container died: a dead worker is a TEST
 * outcome, and PHP turns every class with no record into a FAIL naming it. Non-zero means
 * there is nothing to report at all - docker unusable, the sweep gave up, the dev image
 * missing or stale, the build failed, a container that could not be spawned.
 *
 * @FILENAME-CONVENTION-EXCEPTION - Node.js daemon entry point
 */

const fs = require('fs');
const path = require('path');
const { execFile } = require('child_process');

const docker = require('./lib/docker.js');
const { Queue_Server } = require('./lib/queue_server.js');

// The label every container this daemon starts carries, and the ONLY handle the zombie
// sweep and the prune act on. Nothing else on the box is ever touched.
const RUN_LABEL = 'rsx-test-run';

// How long the zombie sweep may wait for the docker daemon to finish killing and removing
// leftover test containers.
//
// THIS IS THE ONE SANCTIONED TIMEOUT IN THIS DAEMON (owner-requested), and it is
// legitimate for the reason the mandate names: it bounds a wait on an EXTERNAL PARTY - the
// docker daemon reaping containers - and NOT on any work of our own. Nothing here is
// bounded: not the build, not a container, not the suite. Expiry degrades to a loud,
// coherent, evidence-carrying fatal that tells the operator what is wrong with the box and
// what to do about it (below), never to a half-run or a silent skip. A daemon that has not
// reaped a handful of containers in three minutes is not slow, it is wedged - and starting
// a run against it would collide with containers still holding the names we are about to
// use.
const SWEEP_BOUND_MS = 180000;

// How often the sweep re-asks the daemon which containers are still there.
const SWEEP_POLL_MS = 500;

// Run directories kept under storage/rsx-tmp. Enough for a post-mortem of the last few
// runs (worker logs, classes.json, results.jsonl); old ones are removed so a box that runs
// the suite all day does not accumulate them.
const RUN_DIRS_KEPT = 3;

// The datadir every container mounts on tmpfs. A whole migrated test database lives in RAM
// here, which is what makes an in-container reset a sub-second restore.
const DATADIR_TMPFS_SIZE = '2g';

// Playwright's downloaded browsers. They live in a HOME cache, not in the project, so they
// are outside the build context and cannot be COPYed into the image - and a container
// without them has a genuinely under-provisioned environment: rsx:health FAILs its
// "Chromium Browser" row, which is a real failure of a real check, not a test artifact.
// Mounting the host's own cache read-only gives a worker exactly the browsers the
// sequential run uses. Absent on this box, the mount is skipped and a container is then
// short a browser in precisely the way the host is.
const PLAYWRIGHT_BROWSERS_DIR = '/root/.cache/ms-playwright';

function log(message) {
    process.stdout.write('[rsx-testd] ' + message + '\n');
}

function log_error(message) {
    process.stderr.write('[rsx-testd] ' + message + '\n');
}

/** A fatal this process cannot continue past. Carries its own diagnosis. */
class Fatal extends Error {}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Parse the argv PHP hands us. Every option is --key=value; a missing one is a
 * programming error in the caller, so it is fatal rather than defaulted.
 */
function parse_argv(argv) {
    const options = {};
    for (const arg of argv.slice(2)) {
        const eq = arg.indexOf('=');
        if (!arg.startsWith('--') || eq === -1) {
            throw new Fatal('unrecognized argument: ' + arg);
        }
        options[arg.slice(2, eq)] = arg.slice(eq + 1);
    }

    for (const required of ['run-dir', 'workers', 'image', 'dev-image', 'project-root']) {
        if (!options[required]) {
            throw new Fatal('missing required argument --' + required + '=');
        }
    }

    return {
        run_dir: options['run-dir'],
        workers: parseInt(options.workers, 10),
        image: options.image,
        dev_image: options['dev-image'],
        project_root: options['project-root'],
    };
}

// =============================================================================
// 1. ZOMBIE SWEEP
// =============================================================================

/**
 * Remove every container left behind by an earlier run - a killed orchestrator, a crashed
 * box, an interrupted build. They are found by our label alone, so nothing else on the
 * daemon is even looked at.
 *
 * Kill, wait for none running, remove, wait for none listed. The waits poll rather than
 * assume: `docker kill` returns when the daemon has ACCEPTED the signal, not when the
 * container is gone, and starting a container whose name is still taken fails.
 */
async function sweep_zombies() {
    const existing = await docker.ps_ids(RUN_LABEL, true);
    if (existing.length === 0) {
        return;
    }

    log('sweeping ' + existing.length + ' leftover test container(s)');

    const deadline = Date.now() + SWEEP_BOUND_MS;

    await docker.kill_containers(existing);
    await sweep_wait_until_empty(false, deadline);

    const remaining = await docker.ps_ids(RUN_LABEL, true);
    if (remaining.length > 0) {
        await docker.rm_containers(remaining);
    }
    await sweep_wait_until_empty(true, deadline);

    log('sweep complete');
}

async function sweep_wait_until_empty(include_stopped, deadline) {
    for (;;) {
        const ids = await docker.ps_ids(RUN_LABEL, include_stopped);
        if (ids.length === 0) {
            return;
        }

        if (Date.now() >= deadline) {
            throw new Fatal(
                'could not kill existing zombie test containers - this may be a system '
                + 'stability issue; wait a little while and try the test suite again later'
            );
        }

        await sleep(SWEEP_POLL_MS);
    }
}

// =============================================================================
// 2. THE DEV IMAGE THE TEST IMAGE IS FROM
// =============================================================================

/**
 * The test image is FROM the shipped development image, and this daemon never builds that
 * one - `bash system/app/RSpade/resource/docker/build.sh dev` does.
 *
 * A STALE DEV IMAGE IS WORSE THAN A MISSING ONE: a build on top of it succeeds and every
 * container then runs a PHP the framework is not written for, which surfaces as a scatter
 * of unrelated test failures nobody attributes to the image. So presence is only half the
 * check - the image's own PHP major.minor must match what the dev Dockerfile installs
 * TODAY, read out of that Dockerfile rather than pinned in a second place here.
 */
async function ensure_dev_image(dev_image, project_root) {
    const build_hint = 'bash system/app/RSpade/resource/docker/build.sh dev';

    if (!(await docker.image_exists(dev_image))) {
        throw new Fatal(
            'the development image ' + dev_image + ' is not present. Build it with: ' + build_hint
        );
    }

    const expected = read_dockerfile_php_version(project_root);

    const probe = await docker.run_capture(dev_image, 'php', [
        '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;',
    ]);

    const actual = probe.stdout.trim();

    if (probe.code !== 0 || actual !== expected) {
        throw new Fatal(
            'the development image ' + dev_image + ' is stale: it runs PHP "'
            + (actual === '' ? '(no answer)' : actual) + '" but the shipped Dockerfile installs PHP '
            + expected + '. Rebuild it with: ' + build_hint
        );
    }

    log('dev image ' + dev_image + ' ok (PHP ' + actual + ')');
}

/**
 * The PHP major.minor the shipped dev Dockerfile installs. It declares it once as
 * PHP_VERSION and spells every package as php${PHP_VERSION}; a literal php<X.Y> is
 * accepted too, so this keeps reading the right answer if that ever changes.
 */
function read_dockerfile_php_version(project_root) {
    const dockerfile = path.join(project_root, 'system/app/RSpade/resource/docker/Dockerfile');

    if (!fs.existsSync(dockerfile)) {
        throw new Fatal('the shipped development Dockerfile is missing: ' + dockerfile);
    }

    const contents = fs.readFileSync(dockerfile, 'utf8');

    const declared = contents.match(/PHP_VERSION\s*=\s*"?(\d+\.\d+)/);
    if (declared) {
        return declared[1];
    }

    const literal = contents.match(/\bphp(\d+\.\d+)\b/);
    if (literal) {
        return literal[1];
    }

    throw new Fatal('could not read the PHP version out of ' + dockerfile);
}

// =============================================================================
// 3. THE GENERATED .dockerignore
// =============================================================================

/**
 * The build context is the project root, so it needs a filter - and the filter is
 * GENERATED for the build and deleted after it, never tracked.
 *
 * WHY GENERATED. Two of its three parts are obvious (.git is enormous and meaningless in
 * an image; storage/ must be per-container, not the developer box's). The third is the
 * reason it cannot be a committed file: the checkout's own local-only exclusions must not
 * reach the image, and NAMING them in a tracked file would publish exactly what they exist
 * to keep local. They are read from the checkout at build time and never printed.
 */
function write_dockerignore(project_root) {
    const target = path.join(project_root, '.dockerignore');

    const lines = ['.git', 'storage/', '/.env'];

    const exclude_file = path.join(project_root, '.git/info/exclude');
    if (fs.existsSync(exclude_file)) {
        for (const raw of fs.readFileSync(exclude_file, 'utf8').split('\n')) {
            const line = raw.trim();
            if (line === '' || line.startsWith('#')) {
                continue;
            }
            lines.push(line);
        }
    }

    fs.writeFileSync(target, lines.join('\n') + '\n');

    return target;
}

function remove_dockerignore(project_root) {
    const target = path.join(project_root, '.dockerignore');
    if (fs.existsSync(target)) {
        fs.unlinkSync(target);
    }
}

/**
 * The generated file is untracked, and a run killed between writing it and removing it
 * would otherwise leave it as noise in `git status`. The checkout excludes it locally; if
 * it does not yet, add the line - the same reasoning that put it there in the first place.
 */
function ensure_dockerignore_excluded(project_root) {
    const exclude_file = path.join(project_root, '.git/info/exclude');
    if (!fs.existsSync(exclude_file)) {
        return;
    }

    const contents = fs.readFileSync(exclude_file, 'utf8');
    for (const raw of contents.split('\n')) {
        if (raw.trim() === '/.dockerignore') {
            return;
        }
    }

    fs.appendFileSync(
        exclude_file,
        '\n# The docker test image build writes this at build time and removes it after.\n'
        + '/.dockerignore\n'
    );
}

// =============================================================================
// 4. THE BUILD
// =============================================================================

async function build_test_image(image, project_root) {
    const dockerfile = path.join(project_root, 'system/app/RSpade/resource/docker/Dockerfile.test');
    if (!fs.existsSync(dockerfile)) {
        throw new Fatal('the test Dockerfile is missing: ' + dockerfile);
    }

    log('building ' + image);

    const started = Date.now();
    const code = await docker.build(dockerfile, image, project_root);

    if (code !== 0) {
        throw new Fatal('the test image build failed (see the build output above)');
    }

    log('build finished in ' + Math.round((Date.now() - started) / 1000) + 's');

    await tag_revision(image, project_root);
}

/**
 * Best-effort revision tag, exactly as build.sh does it: downstream, system/ is a git
 * submodule whose HEAD IS the installed release. In the framework monorepo system/ is
 * authored source with no .git of its own, and that is not an error - the image simply
 * gets its :latest tag and nothing else.
 */
function tag_revision(image, project_root) {
    const system_dir = path.join(project_root, 'system');
    if (!fs.existsSync(path.join(system_dir, '.git'))) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        execFile('git', ['-C', system_dir, 'rev-parse', 'HEAD'], async (err, stdout) => {
            const revision = String(stdout || '').trim().slice(0, 12);
            if (err || revision.length !== 12) {
                resolve();
                return;
            }

            const repository = image.slice(0, image.lastIndexOf(':'));
            await docker.tag_image(image, repository + ':' + revision);
            log('tagged ' + repository + ':' + revision);
            resolve();
        });
    });
}

// =============================================================================
// 5-7. THE QUEUE AND THE CONTAINERS
// =============================================================================

/**
 * Start one container per worker and wait for every one of them. A container runs THIS
 * SAME artisan command in worker mode: it pulls whole classes from the queue socket it
 * bind-mounts and sends each class's outcome back, until the queue is drained.
 *
 * No deadline on any of it. The suite takes as long as the suite takes.
 */
async function run_workers(options, queue, run_id) {
    const children = [];

    for (let n = 1; n <= options.workers; n++) {
        const name = 'rsx-test-w' + n;
        // The container's own hostname carries `.dev.`, because Rsx::is_dev_site() is
        // literally "does the hostname contain .dev." and the framework gates outbound
        // mail and SMS on it. The developer box this suite is written against is a
        // `.dev.` host; a worker called plain `rsx-test-w1` is not, so four tests that
        // assert dev-site behaviour saw production behaviour instead. The container
        // name stays short - only the resolvable hostname carries the suffix.
        const host_name = name + '.dev.local';
        const log_path = path.join(options.run_dir, 'worker-' + n + '.log');
        const log_fd = fs.openSync(log_path, 'w');

        const args = [
            'run', '--rm',
            '--name', name,
            '--hostname', host_name,
            // The container's APP_URL resolves to its own hostname; without this the name
            // does not resolve inside the container and nothing can reach its own nginx.
            '--add-host', host_name + ':127.0.0.1',
            '--label', RUN_LABEL + '=' + run_id,
            '--tmpfs', '/var/lib/mysql:size=' + DATADIR_TMPFS_SIZE,
            '-v', path.join(options.run_dir, 'ipc') + ':/rsx-test-ipc',
        ];

        if (fs.existsSync(PLAYWRIGHT_BROWSERS_DIR)) {
            args.push('-v', PLAYWRIGHT_BROWSERS_DIR + ':' + PLAYWRIGHT_BROWSERS_DIR + ':ro');
        }

        args.push(
            options.image,
            // Not `php artisan` directly: the wrapper waits for the container's whole
            // supervisor roster (and for rsx-lockd to answer a ping) before it execs the
            // worker, because the entrypoint only ever waited for redis and mysql.
            'bash', '/usr/local/bin/rsx-test-worker-run',
            String(n),
            '/rsx-test-ipc/orchestrator.sock'
        );

        let child;
        try {
            child = docker.spawn_container(args, log_fd);
        } catch (err) {
            fs.closeSync(log_fd);
            throw new Fatal('could not spawn worker ' + n + ': ' + err.message);
        }

        log('worker ' + n + ' started');

        children.push(new Promise((resolve, reject) => {
            child.on('error', (err) => {
                fs.closeSync(log_fd);
                reject(new Fatal('could not spawn worker ' + n + ': ' + err.message));
            });

            child.on('close', (code) => {
                fs.closeSync(log_fd);
                log('worker ' + n + ' finished (exit ' + code + ')');

                // A class still held by this worker will never be answered for. It gets no
                // record, which is precisely how PHP reports it: FAILED, "worker terminated
                // before it finished". Named here so worker-N.log is where to look.
                for (const held of queue.held_by(n)) {
                    log_error('worker ' + n + ' terminated while running ' + held.short);
                }

                resolve();
            });
        }));
    }

    await Promise.all(children);
}

// =============================================================================
// 9. PRUNE
// =============================================================================

/**
 * After a completed run: drop every superseded test and dev image tag, the dangling layers
 * they leave behind, and any stopped container carrying OUR label. We are the docker
 * daemon's only user here, and an image build per run accumulates gigabytes otherwise.
 *
 * Deliberately narrow: only these two repositories, only :latest is kept, and only our own
 * label is pruned from the container list. Best effort throughout - a prune failure never
 * costs a completed run its results.
 */
async function prune(image, dev_image) {
    const superseded = [];

    for (const ref of [image, dev_image]) {
        const repository = ref.slice(0, ref.lastIndexOf(':'));
        for (const tag of await docker.image_tags(repository)) {
            if (tag !== ref) {
                superseded.push(tag);
            }
        }
    }

    if (superseded.length > 0) {
        log('pruning ' + superseded.length + ' superseded image tag(s)');
        await docker.rmi(superseded);
    }

    await docker.image_prune();
    await docker.container_prune(RUN_LABEL);
}

/**
 * Keep the newest RUN_DIRS_KEPT run directories and delete the rest. The names carry a
 * sortable timestamp, so newest-first is a plain descending sort.
 */
function prune_run_dirs(project_root) {
    const parent = path.join(project_root, 'storage/rsx-tmp');
    if (!fs.existsSync(parent)) {
        return;
    }

    const dirs = fs.readdirSync(parent)
        .filter((name) => name.startsWith('test-run-'))
        .sort()
        .reverse();

    for (const name of dirs.slice(RUN_DIRS_KEPT)) {
        fs.rmSync(path.join(parent, name), { recursive: true, force: true });
    }
}

// =============================================================================
// MAIN
// =============================================================================

async function main() {
    const options = parse_argv(process.argv);
    const run_id = path.basename(options.run_dir);

    // Registered before anything is created, so an interrupt at any point takes the
    // containers, the socket and the generated build filter with it.
    let queue = null;
    let interrupted = false;

    const handle_signal = async (signal) => {
        if (interrupted) {
            return;
        }
        interrupted = true;

        log_error('received ' + signal + ' - killing test containers');

        const ids = await docker.ps_ids(RUN_LABEL, true);
        if (ids.length > 0) {
            await docker.kill_containers(ids);
            await docker.rm_containers(ids);
        }

        if (queue) {
            await queue.close();
        }

        remove_dockerignore(options.project_root);

        process.exit(1);
    };

    process.on('SIGTERM', () => { handle_signal('SIGTERM'); });
    process.on('SIGINT', () => { handle_signal('SIGINT'); });

    if (!(await docker.info())) {
        throw new Fatal('the docker daemon is not reachable (docker info failed)');
    }

    await sweep_zombies();
    await ensure_dev_image(options.dev_image, options.project_root);

    ensure_dockerignore_excluded(options.project_root);
    write_dockerignore(options.project_root);
    try {
        await build_test_image(options.image, options.project_root);
    } finally {
        remove_dockerignore(options.project_root);
    }

    const classes_path = path.join(options.run_dir, 'classes.json');
    if (!fs.existsSync(classes_path)) {
        throw new Fatal('classes.json is missing from the run directory: ' + classes_path);
    }
    const classes = JSON.parse(fs.readFileSync(classes_path, 'utf8'));

    queue = new Queue_Server({
        socket_path: path.join(options.run_dir, 'ipc/orchestrator.sock'),
        classes: classes,
        results_path: path.join(options.run_dir, 'results.jsonl'),
        log: log,
    });
    await queue.start();

    log('queue serving ' + classes.length + ' class(es) to ' + options.workers + ' container(s)');

    const started = Date.now();

    try {
        await run_workers(options, queue, run_id);
    } finally {
        await queue.close();
    }

    log(
        'run complete: ' + queue.result_count + '/' + classes.length + ' class results in '
        + Math.round((Date.now() - started) / 1000) + 's'
    );

    // FROM HERE THE RUN HAS PRODUCED ITS RESULTS. Everything below is housekeeping, and a
    // failure in it must not turn a reportable run into an infrastructure failure.
    try {
        await prune(options.image, options.dev_image);
        prune_run_dirs(options.project_root);
    } catch (err) {
        log_error('prune failed (results are unaffected): ' + err.message);
    }
}

main().then(
    () => process.exit(0),
    (err) => {
        log_error(err instanceof Fatal ? err.message : (err.stack || String(err)));
        process.exit(1);
    }
);
