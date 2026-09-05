/**
 * Thin wrappers over the `docker` CLI.
 *
 * EVERY invocation is an ARGV ARRAY handed to child_process directly. No shell, ever - not
 * bash, and above all not the implicit /bin/sh that execFile-with-a-string or exec() would
 * use (dash here). Nothing in this file interpolates a value into a command line, so a
 * container name, an image tag or a label can never become shell syntax.
 *
 * NO TIMEOUTS. Not one of these calls carries a deadline: a build takes as long as the
 * build takes, a container runs as long as the tests take, and a `docker kill` answers when
 * the daemon answers. The ONE bounded wait in this daemon is the zombie sweep in
 * orchestrator.js, which is owner-sanctioned and justified beside its constant.
 */

const { execFile, spawn } = require('child_process');

// docker's own output is the only thing that flows through these buffers - a `ps -aq` list
// or a `image ls` table. Generous enough that a box with a lot of images cannot truncate a
// listing into a wrong answer.
const OUTPUT_BUFFER_BYTES = 64 * 1024 * 1024;

/**
 * Run one docker command to completion and capture it. NEVER rejects on a non-zero exit -
 * "did the daemon know this image" is an answer, not an exception - so every caller reads
 * the code itself.
 *
 * @param {Array<string>} args argv tokens after `docker`
 * @return {Promise<{code:number, stdout:string, stderr:string}>}
 */
function docker_capture(args) {
    return new Promise((resolve) => {
        execFile('docker', args, { maxBuffer: OUTPUT_BUFFER_BYTES }, (err, stdout, stderr) => {
            resolve({
                code: err ? (typeof err.code === 'number' ? err.code : 1) : 0,
                stdout: String(stdout || ''),
                stderr: String(stderr || ''),
            });
        });
    });
}

/**
 * Run one docker command with its output streamed to this process's stdout/stderr - used
 * for the image build, which the developer watches live.
 *
 * @param {Array<string>} args
 * @return {Promise<number>} exit code
 */
function docker_stream(args) {
    return new Promise((resolve, reject) => {
        const child = spawn('docker', args, { stdio: ['ignore', 'inherit', 'inherit'] });
        child.on('error', reject);
        child.on('close', (code) => resolve(code === null ? 1 : code));
    });
}

/** Does the daemon answer at all? The whole probe: absent binary, dead daemon, no access. */
async function info() {
    const result = await docker_capture(['info']);

    return result.code === 0;
}

/**
 * Container ids carrying our label.
 *
 * @param {string} label label key (a bare key matches any value)
 * @param {boolean} include_stopped -a
 * @return {Promise<Array<string>>}
 */
async function ps_ids(label, include_stopped) {
    const args = ['ps'];
    if (include_stopped) {
        args.push('-a');
    }
    args.push('-q', '--filter', 'label=' + label);

    const result = await docker_capture(args);
    if (result.code !== 0) {
        return [];
    }

    return result.stdout.split('\n').map((s) => s.trim()).filter((s) => s.length > 0);
}

/** SIGKILL a set of containers. Best effort: one already dead is not an error here. */
function kill_containers(ids) {
    return docker_capture(['kill'].concat(ids));
}

/** Remove a set of containers. Best effort, same reasoning as kill_containers(). */
function rm_containers(ids) {
    return docker_capture(['rm', '-f'].concat(ids));
}

/** Does the daemon hold this image? */
async function image_exists(image) {
    const result = await docker_capture(['image', 'inspect', image]);

    return result.code === 0;
}

/**
 * Run a throwaway container and capture what it printed.
 *
 * The entrypoint is REPLACED, not passed through. The dev image's entrypoint is a full
 * environment bring-up - supervisor, mysqld, redis, nginx - which prints pages of narration
 * to stdout before it ever reaches the command. That is right for a container that serves
 * something and pure noise for a one-line probe, whose whole value is that its output is
 * exactly the answer.
 *
 * @param {string} image
 * @param {string} entrypoint binary to run instead of the image's entrypoint
 * @param {Array<string>} command_args
 */
function run_capture(image, entrypoint, command_args) {
    return docker_capture(['run', '--rm', '--entrypoint', entrypoint, image].concat(command_args));
}

/**
 * Build an image, streaming the build log.
 *
 * @param {string} dockerfile absolute path
 * @param {string} tag
 * @param {string} context absolute path
 * @return {Promise<number>} exit code
 */
function build(dockerfile, tag, context) {
    return docker_stream(['build', '-f', dockerfile, '-t', tag, context]);
}

/** Add a second tag to an existing image. */
function tag_image(source, target) {
    return docker_capture(['tag', source, target]);
}

/**
 * Every tag of a repository, as full `repo:tag` references.
 *
 * @param {string} repository e.g. rspade-test
 * @return {Promise<Array<string>>}
 */
async function image_tags(repository) {
    const result = await docker_capture([
        'image', 'ls', repository, '--format', '{{.Repository}}:{{.Tag}}',
    ]);
    if (result.code !== 0) {
        return [];
    }

    return result.stdout
        .split('\n')
        .map((s) => s.trim())
        .filter((s) => s.length > 0 && !s.endsWith(':<none>'));
}

/** Remove image tags. Best effort - one still referenced simply stays. */
function rmi(refs) {
    if (refs.length === 0) {
        return Promise.resolve({ code: 0, stdout: '', stderr: '' });
    }

    return docker_capture(['rmi'].concat(refs));
}

/** Drop dangling images. */
function image_prune() {
    return docker_capture(['image', 'prune', '-f']);
}

/** Drop stopped containers carrying our label, and only those. */
function container_prune(label) {
    return docker_capture(['container', 'prune', '-f', '--filter', 'label=' + label]);
}

/**
 * Start one worker container in the FOREGROUND as a child process, its combined output
 * going to the given file descriptor. Returns the child so the caller can await its exit -
 * with no deadline: the container lives exactly as long as its share of the suite.
 *
 * @param {Array<string>} args argv tokens after `docker`
 * @param {number} log_fd
 * @return {ChildProcess}
 */
function spawn_container(args, log_fd) {
    return spawn('docker', args, { stdio: ['ignore', log_fd, log_fd] });
}

module.exports = {
    docker_capture,
    docker_stream,
    info,
    ps_ids,
    kill_containers,
    rm_containers,
    image_exists,
    run_capture,
    build,
    tag_image,
    image_tags,
    rmi,
    image_prune,
    container_prune,
    spawn_container,
};
