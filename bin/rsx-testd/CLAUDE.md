# rsx-testd - agent notes

The docker side of `php artisan rsx:test --framework`. It sweeps leftover containers,
verifies the image it builds on, builds the test image, serves the unix-socket work queue
the containers pull test classes from, runs N of them, and prunes. **Zero npm dependencies**
(node core only), like `rsx-lockd`; `system/bin/` self-distributes downstream, so do not add
one.

Read `README.md` first (what it is, how it is invoked, its exit-code contract). This file is
the mechanics: what the images are, what a container is, what crosses the socket, and every
non-obvious ordering rule a change here must not break.

## The division of labor - nothing is implemented twice

| Side | Owns |
|---|---|
| PHP (`app/RSpade/Commands/Rsx/Rsx_Test_Command.php`) | discovery (`select_test_classes`), the singleton flock, queue ordering (`order_classes_longest_first`), RUNNING the tests (`$class::run()` inside a container), and the printed output (`print_class_results` / `print_summary` / `merge_and_report`) |
| Node (this directory) | the docker lifecycle - sweep, dev-image check, build-context filter, build, the queue RPC, N containers, `results.jsonl`, prune |

PHP fills the run directory (`classes.json`, an `ipc/` directory) and spawns
`node orchestrator.js --run-dir= --workers= --image= --dev-image= --project-root=` through
`RsxLocks::command_without_inherited_locks()` and a Symfony `Process` with `setTimeout(null)`,
streaming its output live and keeping the last 20 lines to repeat under a failure message.
The worker inside a container sends back the SAME per-class record the sequential loop would
have printed, so the two paths print through one printer and cannot drift.

## The gate - when any of this happens at all

`Rsx_Test_Command::docker_mode_gate_passes()` is TRUE only when ALL of:

- `--framework` was asked for, AND
- no narrowing selector - no class argument, no `--filter`, no `--group` (a subset never
  earns an image build and N container boots), AND
- `--sequential` was NOT passed, AND
- this is an RSpade DEVELOPMENT container (`/.rspade_container_dev`, the only place the
  nested docker daemon and the shipped dev image exist), AND
- `docker info` exits 0 (the whole probe: absent binary, dead daemon, no socket access).

Every other invocation - the application suite, any subset, any non-dev-container box - runs
the unchanged single-process sequential path IN THIS process. The gate is checked BEFORE the
test database is touched: in docker mode every test runs against a container's own database,
so provisioning this box's would be pure cost.

**Class order is deterministic only in sequential mode** (name order). Docker mode seeds the
queue longest-first and hands each class to whichever container is free, so a class can be
preceded by a class it has never followed before. That is not a defect of the runner - it
exposed one real stale-cache bug the sequential order was hiding by luck
(`tests/test_runner/issues_encountered.md`, item 2).

## The two images

**`rspade/rspade-server-dev:latest`** is the SHIPPED development image
(`system/app/RSpade/resource/docker/Dockerfile`, built by
`bash system/app/RSpade/resource/docker/build.sh dev`). This daemon NEVER builds it - it is
somebody's deliberate, minutes-long build - it only checks it.

**`rspade-test:latest`** is `FROM` that image, built here from `Dockerfile.test`. It is FROM
it because that image already IS the runtime the framework is written for: supervisor,
mysql-server, redis, rsx-lockd, php-fpm, nginx, mail-catcher, the entrypoint. What the test
image adds is the thing that makes a container cheap to start - a MIGRATED database baked
into the datadir template.

**Consequence to be aware of: tests run on the shipped image's PHP (8.5 today), not on
whatever PHP this dev container happens to run.** That is arguably the right target, but an
assumption about the host's PHP version will surface as test failures.

**`ensure_dev_image()` refuses a STALE dev image, not just a missing one.** A build on top of
a stale base SUCCEEDS and every container then runs a PHP the framework is not written for,
which reads as a scatter of unrelated test failures nobody attributes to the image. So the
image's own `PHP_MAJOR_VERSION.PHP_MINOR_VERSION` is probed (with the entrypoint REPLACED, so
the answer is the only output) and compared against the `PHP_VERSION=` the shipped dev
Dockerfile declares TODAY - read out of that file rather than pinned a second time here.
Either failure is fatal and names `bash system/app/RSpade/resource/docker/build.sh dev`.

## The datadir-template hook

The dev image ships `/opt/rspade/mysql-datadir-template.tgz`, a tarball of a pristine MySQL
data directory, and its entrypoint unpacks it into `/var/lib/mysql` **only when
`/var/lib/mysql/mysql` (the system schema) is absent**. That absence test is the hook:

- `Dockerfile.test` runs the whole provisioning sequence once and REPLACES that tarball with
  the resulting fully-migrated, baseline-seeded datadir, then `rm -rf /var/lib/mysql/*`;
- every container is run with `--tmpfs /var/lib/mysql:size=2g`, so `/var/lib/mysql/mysql` is
  absent at boot, the entrypoint fills a RAM datadir from the baked template in about a
  second, and an in-container reset between classes is a restore from RAM.

Nothing was added to the entrypoint to make this work. The template path and its one
condition were already the contract.

## The build sequence, and its two ordering traps

`Dockerfile.test` is `COPY . /var/www/html` plus ONE `RUN` layer, on purpose (see the
clean-shutdown rule below). In order:

1. `rm -f /.rspade_container_dev`. That flag gates the development-mode migrate DATADIR
   SNAPSHOT, which stops mysqld through supervisorctl and copies `/var/lib/mysql` -
   meaningless during an image build and actively harmful inside a throwaway container.
2. `rm -f /etc/supervisor/conf.d/tasks.conf`. No background `rsx:task:process` loop racing
   the tests, which drive the task system explicitly. Everything else stays: mysql, redis,
   rsx-lockd, php-fpm, nginx, mail-catcher, fpc-proxy.
3. `rm -f .env`, then `php system/bootstrap/rsx_env_heal.php` builds one from `.env.dist`
   and mints an `APP_KEY` (it runs pre-boot: no autoloader, no config, no database).
   **NOTHING FROM THE HOST `.env` EVER REACHES THE IMAGE** - the build-context filter
   excludes it AND the file is removed, so even a stale filter cannot point a container at
   the developer's database, mail transport or secrets.
4. The container's own keys are then set with a bash replace-or-append (`sed` would need a
   delimiter no value can contain, and an `APP_URL` has slashes): `APP_URL=http://$HOSTNAME`
   (the LITERAL unquoted token - `Rsx_App_Url` resolves it with `gethostname()` at boot, so
   one image serves every worker under its own name), `RSX_MODE=development`,
   `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=rspade`, `DB_USERNAME=rspade`,
   `DB_PASSWORD=rspadepass`, `DB_TEST_DATABASE=rspade_test`, `REDIS_HOST=127.0.0.1`,
   `IS_FRAMEWORK_DEVELOPER=true`, `REALTIME_ENABLED=false`, blank `RSPADE_DEFAULT_EMAIL` /
   `RSPADE_DEFAULT_PASSWORD` (the test baseline needs `users` id 1 for itself), and
   `BCRYPT_ROUNDS=4` (nothing here defends a password against an attacker).
   **`MAIL_DELIVERY` is deliberately NOT set**: `.env.dist` says `aiosmtpd`, which is the
   catcher this image already runs and what the developer box uses; forcing `disabled` froze
   the queue inside a container while the mail tests - written against the catcher, asserting
   its aiosmtpd greeting - expected delivery. A test container mirrors the developer box, not
   a third configuration that exists nowhere else.
5. The provisioning itself runs THROUGH the entrypoint, which is already the in-container
   helper: `bash /usr/local/bin/rspade-entrypoint bash -c "..."` unpacks the stock template,
   starts supervisor, waits for redis and mysql, runs `mysql/provision.sql` when `USE rspade`
   fails, migrates the development database, runs the command, then stops supervisor.
   The command is, IN THIS ORDER:
   1. `php system/artisan rsx:test --framework --_provision-only` -
      `prepare_test_database()` + `ensure_baseline_cache()`: `rspade_test` migrated, the
      baseline user seeded, and the migration-hash dump under `storage/db_backups` that an
      in-container reset restores from. It runs no tests.
   2. `php system/artisan rsx:manifest:build --clean`.
   3. `php system/artisan rsx:bundle:compile`.

**ORDER TRAP 1 - the manifest build comes AFTER provisioning, and it is `--clean`.** The
model index (and with it every auto-detected datetime/date/boolean CAST) is built by
introspecting the DEFAULT connection's tables. Provisioning drops and re-migrates
`rspade_test` in a subprocess whose default database IS `rspade_test`, so a manifest built
during or before that step indexes an empty database and bakes a model index with NO columns
- which silently removes every cast in every container. Measured cost of getting it backwards:
**133 failures across the framework suite**, all of them an ISO datetime string reaching
MySQL raw. Building last puts the default connection back on the fully migrated `rspade`, and
`--clean` is what makes it actually rebuild - the manifest is "fresh" by then, so an
incremental build would keep the empty index.

**ORDER TRAP 2 - wait for mysqld to be GONE before archiving.** The layer that tars
`/var/lib/mysql` must also be the layer that shut mysqld down, hence one `RUN`: a datadir
captured while the server is running (or across two layers) forces InnoDB crash recovery in
EVERY container started from it. The entrypoint has already asked supervisor to stop; the
`while pgrep -x mysqld; do sleep 1; done` is the direct proof. No deadline - mysqld exits
when it has finished flushing.

`rsx:bundle:compile` is in the list because a container never serves a web request. Bundles
compile just-in-time on the first page view, so a container that only runs artisan has none
of the compiled output and four asset tests honestly SKIP themselves. Compiling at build time
is the same JIT compile, taken once instead of never.

Last, and after the provisioning layer on purpose (editing it is then a one-second rebuild of
one tiny layer), the CMD wrapper is copied to `/usr/local/bin/rsx-test-worker-run`.
The orchestrator tags the built image `:latest` plus the 12-char `system/` HEAD when `system/`
has a `.git` (downstream it is a submodule whose HEAD IS the release; here it is authored
source with no `.git`, and getting only `:latest` is correct, not an error).

## The generated `.dockerignore`

The build context is the project root, so it needs a filter, and the filter is GENERATED for
the build and removed in a `finally` - **never tracked**. It contains `.git`, `storage/`,
`/.env`, plus every non-comment line of `.git/info/exclude`.

Two of those are obvious (`.git` is enormous and meaningless in an image; `storage/` must be
per-container, not the developer box's). The third is why it cannot be a committed file:
the checkout's own local-only exclusions must not reach the image, and NAMING them in a
tracked file would publish exactly what they exist to keep local. They are read at build time
and never printed. `ensure_dockerignore_excluded()` appends `/.dockerignore` to
`.git/info/exclude` so a run killed between writing and removing it leaves no untracked noise.
Everything else ships regardless of size - `vendor/` and `node_modules/` included.

## Running a container

```
docker run --rm --name rsx-test-w<N> --hostname rsx-test-w<N>.dev.local \
  --add-host rsx-test-w<N>.dev.local:127.0.0.1 \
  --label rsx-test-run=<run_id> \
  --tmpfs /var/lib/mysql:size=2g \
  -v <run_dir>/ipc:/rsx-test-ipc \
  [-v /root/.cache/ms-playwright:/root/.cache/ms-playwright:ro] \
  rspade-test:latest \
  bash /usr/local/bin/rsx-test-worker-run <N> /rsx-test-ipc/orchestrator.sock
```

Started in the FOREGROUND as a child process (never `-d`), combined output to
`<run_dir>/worker-<N>.log`, awaited with no deadline.

- **The hostname carries `.dev.`** because `Rsx::is_dev_site()` is literally "does the
  hostname contain `.dev.`" and the framework gates outbound mail and SMS on it. The
  developer box this suite is written against is a `.dev.` host; a worker called plain
  `rsx-test-w1` is not, and four tests asserting dev-site behaviour saw production behaviour
  instead. The container NAME stays short - only the resolvable hostname carries the suffix.
- **`--add-host`** because a container does not resolve its own hostname here by default, and
  the resolved `APP_URL` is that hostname.
- **The playwright mount** is conditional on the host cache existing. Those browsers live in
  a HOME cache, outside the build context, so they cannot be COPYed in - and a container
  without them genuinely fails `rsx:health`'s "Chromium Browser" row. Mounting the host's own
  cache read-only gives a worker exactly the browsers the sequential run uses; absent, the
  container is short a browser in precisely the way the host is.
- **No codebase mount.** The tree is baked, so `storage/`, the flock directory and the build
  output are per-container and writable.

**The CMD is a readiness wrapper, not `php artisan` directly.**
`resource/docker/rsx-test-worker-run.sh` polls `supervisorctl status` until every program is
RUNNING and then pings rsx-lockd over `/dev/tcp` (the lockd wire protocol answers `ping`
pre-hello), and only then `exec`s the worker - same pid, so the container's exit status IS the
worker's. The entrypoint waits for redis and mysql only, because those are the two IT needs;
rsx-lockd and mail-catcher both declare `startsecs=5` and are still coming up when the CMD
starts. That race cost **17 failures across three mail concerns plus a health-command
failure**, every one a service that was RUNNING seconds later. SPAWNING IS NOT READINESS -
the same rule `maintenance-mode.sh` enforces before it lets php-fpm serve.
**No timeout anywhere in the wrapper**, but it refuses to WAIT ON THE DEAD: a supervisord
that answers nothing, any program in FATAL, or a roster missing one of
`mysql redis rsx-lockd mail-catcher` is a loud non-zero exit with the status output attached.

**The filename carries the `rsx-` prefix because `system/.gitignore` ignores `test-*`** - a
file the framework ships must not be one git quietly leaves behind.

## The queue protocol

One unix socket at `<run_dir>/ipc/orchestrator.sock` (chmod 777 - it is reached through a
bind mount, so do not depend on uid alignment), newline-delimited JSON, ONE request per
connection, the response on the connection its request arrived on, nothing ever unsolicited.
That is the house RPC shape; the PHP client (`Rsx_Test_Command::queue_request()`) connects,
writes one line, reads ONE line with `fgets`, closes, with no read timeout.

```
{"id":N,"method":"ping"}
    -> {"id":N,"result":"pong"}
{"id":N,"method":"queue.next","worker_id":W}
    -> {"id":N,"class":"<fqcn>","short":"<short>","requires_db_reset":<bool>}
       or {"id":N,"class":null}                        once drained
{"id":N,"method":"queue.result","worker_id":W,"class","short","results","duration"[,"error"]}
    -> {"id":N,"ok":true}
```

`lib/protocol.js` is PURE (require it from a test without binding anything) and deliberately
mirrors `rsx-lockd/lib/protocol.js`: same encode/decode contract, same newline splitter, same
`MAX_FRAME_BYTES` (1 MB) cap on a peer that never sends a newline. `decode_frame()` never
throws and rejects non-object frames, because this process is the only thing that can write
`results.jsonl` and one uncaught throw would cost the whole run's outcome. An unknown method,
a malformed frame or an oversized frame is refused without taking the server down.

`classes.json` is seeded by PHP longest-first and `shift()` IS the whole dispatch policy: a
measured duration from `storage/rsx-tmp/test-timings.json` when there is one, else the
`$requires_db_reset` proxy (those carry the re-provision cost), else source size / 100k. It is
an ordering CACHE and never a deadline; `merge_and_report()` writes the measured durations
back, so packing self-improves. `requires_db_reset` rides in the row so the queue never has to
load a PHP class to answer.

**A `results.jsonl` record is exactly what `merge_and_report()` reads**:
`{class, short, results, duration[, error]}` - `worker_id` is queue bookkeeping and is
dropped. Records are appended AS THEY ARRIVE, not at the end, so a run that dies half way
still leaves every finished class on disk.

## Dead workers

`queue.next` records `holder = worker_id`; `queue.result` clears it. When a container exits,
`held_by()` names every class it was still holding, and those classes get **no record** -
which is precisely how PHP reports them: FAILED, "class produced no result (worker terminated
before it finished)". **A dead worker is a TEST outcome, never a silent drop and never an
infrastructure abort.** Which is why the exit-code contract is "did I produce
`results.jsonl`": 0 whenever the run reached a results file, INCLUDING a run with a dead
container; non-zero only when there is nothing to report at all (docker unusable, the sweep
gave up, the dev image missing or stale, the build failed, a container that could not be
spawned). PHP treats a non-zero exit as an infrastructure failure and never reaches the merge.

On the worker side every queue RPC failure is fatal by design: a worker that cannot reach the
queue has no way to report what it did and no way to know what to do next, so it throws, the
container exits non-zero, and the classes it held are reported. Silently retrying would hide a
broken orchestrator behind a run that merely looks slow.

## The zombie sweep and its ONE sanctioned bound

Before anything else: `docker ps -aq --filter label=rsx-test-run` -> `docker kill` -> poll
every 500 ms until none is running -> `docker rm` -> poll until none is listed. Found by OUR
LABEL alone, so nothing else on the daemon is even looked at. The polls exist because
`docker kill` returns when the daemon ACCEPTED the signal, not when the container is gone, and
starting a container whose name is still taken fails.

`SWEEP_BOUND_MS = 180000` is **the only timeout in this daemon**, owner-requested, and it is
legitimate for the reason the mandate names: it bounds a wait on an EXTERNAL PARTY (the docker
daemon reaping containers) and on no work of our own, and expiry degrades to a loud,
evidence-carrying fatal - *"could not kill existing zombie test containers - this may be a
system stability issue; wait a little while and try the test suite again later"* - never to a
half-run or a silent skip. A daemon that has not reaped a handful of containers in three
minutes is not slow, it is wedged. **Nothing else here is bounded**: not the build, not a
container, not the suite, not one `docker` call (`lib/docker.js` carries no deadline at all).

`SIGTERM`/`SIGINT` (registered before anything is created): kill and remove every labelled
container, close the queue, remove the generated `.dockerignore`, exit 1.

## The singleton flock

`Rsx_Test_Command::acquire_runner_singleton()` takes a RAW `flock(LOCK_EX)` on
`storage/flock/rsx_test_runner.lock` at the top of `handle()`, **on BOTH paths** - sequential
and docker - and holds it for the process, releasing in a `register_shutdown_function`. It is
deliberately NOT an `RsxLocks` cluster lock: it must hold across a maintenance window (where
cluster locks are granted as no-ops) and be taken before any service it depends on is
consulted. It mirrors `Rsx_Preboot_Service::__acquire_file_lock()`. **No timeout** - a second
`rsx:test` prints "Waiting for another test run to finish..." and waits, because two runs
share one test database, one dump cache and (in docker mode) the container names.

## Pruning

After a COMPLETED run, in a try/catch whose failure never costs a reportable run its results:
every tag of `rspade-test` and `rspade/rspade-server-dev` except the two `:latest`, then
`docker image prune -f` (dangling), then `docker container prune -f` filtered to our label.
Deliberately narrow - only those two repositories, only our own label. `prune_run_dirs()` keeps
the newest 3 `storage/rsx-tmp/test-run-*` directories (their names carry a sortable timestamp)
so a box that runs the suite all day still has the last few post-mortems and nothing older.

## The test-run allowance

`rsx:test` declares the internal `--_test-run` flag on ITSELF and `Rsx_Artisan` forwards it to
every child it spawns, so a migrate or a command a test runs in `debug`/`production` mode is
still recognisably under the suite. `Rsx_App_Url::enforce_scheme_from_env()` grants http to
any process carrying it (`Rsx_Test_Abstract::suite_is_running()`). That matters here because a
test container is an http box by construction (`APP_URL=http://$HOSTNAME`, no TLS in front of
it) and no test can arrange otherwise. Nothing security-relevant reads the flag, and a served
web request carries no argv, so the https-outside-development guardrail is intact for what it
protects.

## Worker counts and measured numbers

`min(WORKER_MAX=8, cores, floor(MemTotal_MB / WORKER_MEMORY_MB=1000))`, floor 1, and never
more containers than classes. `--workers=N` overrides the formula (an experiment knob; the
floors still apply). Cores from `/proc/cpuinfo`, memory from `/proc/meminfo` - no shell. A
container is a whole environment (mysqld on tmpfs + redis + rsx-lockd + php), which is why
memory floors it as well as cores. **8 workers on this box.**

Measured on this box, whole framework suite:

| Run | Wall time |
|---|---|
| docker, including a full test-image rebuild | ~246 s |
| docker, image layers cached | ~90 s |
| `--sequential` | ~752 s |

## Debugging a worker

- **`<run_dir>/worker-<N>.log`** is that container's combined stdout/stderr - the entrypoint
  narration, the readiness wrapper's `[test-worker N]` lines, and everything the worker
  printed. The orchestrator prints the run directory before it starts, and PHP names it again
  under an infrastructure failure. The last three run directories survive.
- **A shell in the real image**:
  `docker run -it --tmpfs /var/lib/mysql:size=2g rspade-test:latest bash` - the entrypoint
  fills the tmpfs datadir from the baked template, brings the services up, and drops you at a
  prompt with a migrated `rspade_test`; the container stops when the shell exits. From there
  `php artisan rsx:test --framework --group=<concern> --sequential` reproduces one concern
  inside exactly the environment a worker had.
- **The orchestrator does not currently log which class each worker took in which order.**
  Adding that would turn a two-run bisect into a one-run reproduction
  (`tests/test_runner/issues_encountered.md` closes with the same note).

## Invariants a change must not break

1. **One implementation of each thing.** Discovery, the output format and the running of a
   test are PHP's; docker and the queue are node's. A second class list, a second printer or a
   second notion of "which classes run" is the defect this design exists to prevent.
2. **The exit code is "did I produce results.jsonl"** - a dead container is a test outcome,
   not an infrastructure failure.
3. **No timeout but the sweep**, and its justification stays written beside the constant.
4. **Never throw out of a frame path.** `decode_frame` returns results; `__answer` catches.
5. **Only our label.** The sweep, the signal handler and the container prune act on
   `rsx-test-run` and nothing else; the image prune touches those two repositories and nothing
   else.
6. **Nothing from the host `.env`, ever** - the filter excludes it AND the build removes it.
7. **The generated `.dockerignore` is never tracked, and its local-only entries are never
   printed.**
8. **The manifest build stays last and stays `--clean`** (order trap 1).
9. **ASCII only** - `[OK]` / `[ERROR]`, no emoji, no box drawing.
