# docker - test catalog

## t1_sizing.sh (cli) and Container_Sizing_Test (php) - container resource sizing

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-FPM-01 | the RAM and RAM + swap ceilings bound every PHP process, php-fpm is held to 3 a core, and the task workers are about a quarter of php-fpm (at least 2); php-fpm never below 2 | cli | `plan` for 2 GB/1 core with and without 2 GB swap, 4 GB/2 cores with and without 4 GB swap, 8 GB/4, 16 GB/8 | php-fpm 2, 4, 7, 8, 16, 32; tasks 2, 2, 2, 2, 4, 8 | implemented | 2026-10-31 |
| DOCKER-FPM-02 | the Redis cap is 32 MB under 1 GB, otherwise max(64, 5%), at most 2 GB; the scratch reservation is at most 2 GB too | cli | 512 MB, 1 GB, 1.2 GB, 32 GB, 64 GB; 128 GB | 32, 64, 64, 1638, 2048; scratch 2048 | implemented | 2026-10-31 |
| DOCKER-FPM-13 | past 64 php-fpm workers the task workers grow by one per 25; the calculation plans at most 140 PHP processes and says so, a count set by hand is never capped and brings no notice; the task workers split one scheduled to two on demand | cli | 16 cores/32 GB, 32/64 GB, 128/512 GB; `PHP_FPM_WORKER_COUNT=300`; `RSX_TASK_SCHEDULED_WORKERS=3` | 64 + 16 (5/11); 122 + 18 (6/12) and the notice; 300 + 25, no notice; 3 scheduled | implemented | 2026-10-31 |
| DOCKER-FPM-03 | the reservation (fixed, scratch, Redis, MySQL on dev, extra) and the three ceilings are reported | cli | `plan 2 4096 4096`, `plan 2 4096 0 dev` | the printed reservation and ceilings | implemented | 2026-10-31 |
| DOCKER-FPM-04 | one pool takes every php-fpm worker; min_spare is max(3, one per core) capped at half the pool, max_spare min(2 per core, three quarters of the pool), neither above the pool; apply writes them (start_servers = min_spare) | cli | every plan above; apply prod checked against the plan for its own cores | the printed total / min_spare / max_spare values | implemented | 2026-11-05 |
| DOCKER-FPM-05 | every switch replaces its part of the calculation | cli | each of the eight switches | the changed figure | implemented | 2026-10-31 |
| DOCKER-FPM-06 | a switch that is not a whole number in range is refused, naming it; PHP_FPM_WORKER_COUNT needs 2 | cli | `abc`, `0`, `-3`, `2.5`; `PHP_FPM_WORKER_COUNT=1` | non-zero exit naming the switch | implemented | 2026-10-31 |
| DOCKER-FPM-07 | apply prod writes the pool (each key once, nothing else moved), both memory ini files, the Redis cap and the record, all 644, and no MySQL file | cli | a scratch `--root` with a copy of the production pool | the files and their values; sizing.json fields | implemented | 2026-10-31 |
| DOCKER-FPM-08 | both task pools come from the project .env when the environment is silent, and the environment wins | cli | `.env` RSX_TASK_MAX_WORKERS 5, then the env 7; `.env` RSX_TASK_SCHEDULED_WORKERS 4 | on_demand 5, then 7; scheduled 4 | implemented | 2026-10-31 |
| DOCKER-FPM-09 | apply dev leaves the pool alone and writes 320 MB and the MySQL buffer pool | cli | a scratch root with the dev pool | pool unchanged; the ini, the cnf; pool_sized false | implemented | 2026-10-31 |
| DOCKER-FPM-10 | missing production pool files stop the start | cli | apply prod against an empty root | non-zero exit, "php-fpm pools were not sized" | implemented | 2026-10-31 |
| DOCKER-FPM-11 | in a built image the step sizes everything at start, php-fpm and redis accept the result, and php -i in the CLI and php-fpm reports the limit | manual | each image started; `php-fpm -t`; `redis-cli CONFIG_rspade_internal GET maxmemory`; `php -r 'echo ini_get("memory_limit");'` | the start line; accepted; the cap; 320M dev, 352M prod (384M with RSPADE_PHP_MEMORY_LIMIT_MB=384) | verified by hand 2026-10-31 at the earlier 256M/320M; the dev image's baked value re-checked 2026-11-05 | 2026-11-05 |
| DOCKER-FPM-12 | a limit docker puts on the container (`--memory`, `--cpus`) is what is measured, not the host | manual | the image started with `--memory 4g --cpus 2` | the start line names 2 cores and 4096 MB | NOT verified: the docker available inside the development container cannot apply resource limits | 2026-10-08 |
| DOCKER-SIZE-01 | the on-demand task pool is automatic when unset: the container's count, 2 with no record, and an explicit value wins | php | config null / empty / 5 against a scratch record | 3, 2, 3, 5 | implemented | 2026-10-31 |
| DOCKER-SIZE-03 | the scheduled task pool is automatic: the container's count, 1 with no record, and an explicit value wins | php | config null / 2 against a scratch record | 1, 6, 2 | implemented | 2026-10-31 |
| DOCKER-SIZE-04 | a record the 140-process cap held back writes the Laravel log notice once per record (the cron tick), again for a new record, and the health row says so; an uncapped record writes nothing | php | scratch records and a scratch marker | false; true, false; true; the row names the cap and rsx:man server_sizing | implemented | 2026-10-31 |
| DOCKER-SIZE-02 | the rsx:health "Resource Sizing" row reports the budget as INFO, the winning ceiling, and marks switched figures | php | a scratch record, calculated then overridden | the detail strings | implemented | 2026-10-31 |

## Docker_Playwright_Pin_Test (php) - the browser the images install

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-PW-01 | the Chromium build the images install is the one the shipped `playwright` package launches | php | `ARG PLAYWRIGHT_VERSION` in the Dockerfile vs `node_modules/playwright/package.json` | equal | implemented | 2026-10-11 |
| DOCKER-PW-02 | the browser is installed in the stage both the dev and prod targets are built from | php | position of the install line in the Dockerfile | before `FROM base AS dev` | implemented | 2026-10-11 |
| DOCKER-PW-03 | in both built images Chromium launches through the `playwright` package and prints a PDF whose `<a href>` is a link, as root and as `www-data` with an empty environment | manual | `chromium.launch()` + `page.pdf()` in a container from each image; `pdfinfo -url` | "printed"; one link annotation; a PDF from the `www-data` run | verified by hand 2026-10-11 (dev and prod) | 2026-10-11 |

## t2_security_update.sh (cli) and Security_Update_Health_Test (php) - automatic security updates

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-SEC-01 | the scheduled check runs at the next 4-hour UTC slot within 600 s either side, and a draw at or before now takes the slot after | cli | `plan` for several clocks and draws | the target epochs | implemented | 2026-10-29 |
| DOCKER-SEC-02 | switched off, the updater calls nothing, says so, and writes a `disabled` status | cli | `RSX_AUTO_SECURITY_UPDATES` = 0, false, NO, Off | "are off"; status result disabled | implemented | 2026-10-29 |
| DOCKER-SEC-03 | an unknown mode does nothing and never fails a caller | cli | `sometimes` | exit 0, no status | implemented | 2026-10-29 |
| DOCKER-SEC-04 | switched off, the runner idles rather than exiting (supervisor would restart it) | cli | the runner source | `exec sleep infinity` | implemented | 2026-10-29 |
| DOCKER-SEC-05 | the health row follows the status: OK current or installed-and-reloaded; WARN absent, off, failed, restart pending | php | a scratch status file | the statuses | implemented | 2026-10-29 |
| DOCKER-SEC-06 | in the built dev image: the allow-list is the security pocket + the PHP PPA with MySQL held back; the start-up check runs before "Starting services" and reports; the program is RUNNING; USR2 reloads php-fpm on the same master and pages keep serving | manual | `docker run` of the image, `apt-config dump`, `supervisorctl status`, a probe page before and after USR2 | as stated; status `current` with PHP 8.5.12 | verified by hand 2026-10-29 | 2026-10-29 |
| DOCKER-SEC-07 | an available PHP patch release is installed | manual | `unattended-upgrade --dry-run` on a container holding PHP 8.5.11 | php8.5-* -> 8.5.12 selected, nothing else | verified by hand 2026-10-29 (dry run) | 2026-10-29 |

## Trusted_Proxies_Test (php) - the proxies in front of the container

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| TP-01 | the application list prefers .env, then the container value | php | rsx_trusted_proxy_list() over each combination | .env's; else the container's; else empty | implemented | 2026-11-03 |
| TP-02 | the container value reaches nginx and the pool | php | `apply` with RSPADE_TRUSTED_PROXIES, blank .env | realip lists loopback + each token; the pool carries the literal | implemented | 2026-11-03 |
| TP-03 | .env wins for nginx; the pools still carry the container value | php | both set | realip has .env's only; pools the container's | implemented | 2026-11-03 |
| TP-04 | nothing declared leaves loopback and clears an earlier start | php | a start with a value, then one without | two set_real_ip_from lines; no pool env line | implemented | 2026-11-03 |
| TP-05 | anything but addresses stops the start | php | `1.2.3.4;`, `0.0.0.0/0`, `::/0`, `foo`, an out-of-range octet, a newline, `/33` | non-zero exit, ERROR, nothing written | implemented | 2026-11-03 |
| TP-06 | nginx accepts the generated file | php | `nginx -t` over a config including it | exit 0 | implemented | 2026-11-03 |

## Nginx_Php_Url_Policy_Test (php) - the `.php` URL policy and the image's Proxority

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-PROX-01 | every listener's `location ~ \.php$` opens with the policy include, so the policy runs before its handler | php | `nginx/site.conf` | four locations (:80, :8000, :3301, :3302), each starting `include /etc/nginx/rspade/php-urls.conf;` | implemented | 2026-11-03 |
| DOCKER-PROX-02 | the checkout variant refuses, the no-checkout variant serves a file that exists | php | the two variant files, comments dropped | `return 404;` / `try_files $uri =404;` and nothing else | implemented | 2026-11-03 |
| DOCKER-PROX-03 | nginx accepts site.conf with either variant in place | php | `nginx -t` on a scratch tree (skips without nginx or its brotli module) | exit 0 for both | implemented | 2026-11-03 |
| DOCKER-PROX-06 | the base stage installs both variants (the checkout one active) and Proxority at /opt/rspade/proxority with the `proxority` wrapper; supervisor runs that copy; the entrypoint picks the variant by HAS_FRAMEWORK | php | Dockerfile, `supervisor/conf.d/proxority.conf`, `entrypoint.sh` | the COPY lines before `FROM base AS dev`; `command=bash /usr/local/bin/rspade-retry-run proxority bash /opt/rspade/proxority/proxority-run.sh`; the HAS_FRAMEWORK branch | implemented | 2026-11-03 |
| DOCKER-PROX-04 | a container with NO checkout (a docroot holding only `system/public/index.php` and `test.php`) runs Proxority under supervisor from /opt, `proxority status` answers, the whitelist refresh fails gracefully (empty list kept), and `/`, `/test.php`, `/index.php` answer the bare files through the proxy (event log `dispatched` lines) with X-Forwarded-Proto pinned per listener (http on :80 even when the client claims https; https + HTTPS=on on :8000); a missing `.php` is nginx's 404 | manual | dev image, `docker create` + `docker cp` of the docroot + `docker start` | as stated | verified by hand 2026-11-03 (dev) | 2026-11-03 |
| DOCKER-PROX-05 | in the built image `nginx -t` passes with each variant active, and with the checkout variant a `.php` URL answers 404 on all four listeners while `/` still reaches index.php | manual | the DOCKER-PROX-04 container, variant swapped + `nginx -s reload` | as stated | verified by hand 2026-11-03 (dev) | 2026-11-03 |

With a checkout mounted, the live request path through the image's Proxority (the `.php` refusal included) is `tests/proxority`'s `Proxority_Live_Stack_Test`, which runs in the docker test workers built from the development image.

## Retry_Run_Stale_Pidfile_Test (php) - a stale pid or lock file never keeps a service down

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-PID-01 | a pidfile naming a live process of ANOTHER program is removed, saying what the pid is; that process is untouched | php | `--clear-stale-pidfile` on a file holding a `sleep`'s pid, mysqld pattern | file gone, message names `sleep`, the sleep alive | implemented | 2026-11-10 |
| DOCKER-PID-02 | a pidfile naming a live copy of the service is kept, silently | php | the sleep's pid, sleep pattern | file kept, no output | implemented | 2026-11-10 |
| DOCKER-PID-03 | a dead pid, a non-numeric file and an empty file are removed | php | `999999999`, `not a pid`, empty | all removed | implemented | 2026-11-10 |
| DOCKER-PID-04 | a lock file holding a bare pid (mysqld's socket lock) is read like a pidfile | php | pid with no newline | removed when it names another program | implemented | 2026-11-10 |
| DOCKER-PID-05 | the runner's own pid is never the service, though its command line names it | php | pidfile holding the checking process's pid, pattern matching it | removed, "is this wrapper" | implemented | 2026-11-10 |
| DOCKER-PID-06 | `--exec` becomes the command | php | `--exec <label> bash -c 'echo ran; exit 7'` | output `ran`, exit 7 | implemented | 2026-11-10 |
| DOCKER-PID-07 | every service that keeps such a file has a table entry; nginx's matches nginx.conf; mysql and dockerd start through `--exec` | php | the shipped runner, nginx.conf, mysql.conf, dockerd-run.sh | entries and command lines present | implemented | 2026-11-10 |
| DOCKER-PID-08 | the rsx-lockd entry is the DAEMON's identity: `lockd.js run` and `lockd.js --config=<f> run` keep the file, a `lockd.js exec ...` client and `lockd.js dump` do not | php | the pattern read from the shipped table; live node processes with each command line | kept / removed as stated; every process alive | implemented | 2026-11-10 |

## Retry_Run_Service_State_Test (php) - a failing service is visible; the container health check

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-SVC-01 | five consecutive exits mark a service `failing` in its state file; one `[ERROR] [<label>] failing: N consecutive exits (last status S) since T` line at 5 and at each doubling, never per attempt; a stop signal writes `stopped` | php | a runner for `exit 3` (no wait) on a scratch `--state-dir` | status failing, last status 3, UTC streak_since; ERROR lines at 5, 10, 20, ...; stopped, no pid | implemented | 2026-11-10 |
| DOCKER-SVC-02 | a run that lasts MAX_DELAY ends the streak while it is still running | php | six failures then a long run, MAX_DELAY 2 | failing during the young run, then running with 0 failures and no streak_since | implemented | 2026-11-10 |
| DOCKER-SVC-03 | the health check reports a failing service while its runner lives and ignores a stopped one and a file whose runner is gone | php | the runner of DOCKER-SVC-01; a `failing` file naming a dead runner pid | exit 1 naming the label; absent after the stop; ghost absent | implemented | 2026-11-10 |
| DOCKER-SVC-04 | the health check: a supervisor program FATAL is unhealthy, STOPPED is not, supervisorctl not answering is unhealthy, a lock daemon not answering a ping is unhealthy, everything RUNNING is healthy and silent | php | a stand-in `supervisorctl` first on PATH; `LOCK_SERVER_PORT` a dead port | the `[ERROR]` lines; exit 0 and no output | implemented | 2026-11-10 |
| DOCKER-SVC-05 | maintenance mode (the flag under `RSX_STORAGE_PATH`) and a missing checkout are healthy with one note, whatever else is wrong | php | flag in a scratch storage root; `--project-root` with no system/ | exit 0, `[INFO]` line | implemented | 2026-11-10 |
| DOCKER-SVC-06 | the image installs the health check in the base stage and declares it as the HEALTHCHECK | php | the Dockerfile | the COPY and HEALTHCHECK lines before `FROM base AS dev` | implemented | 2026-11-10 |
| DOCKER-SVC-07 | the built image reports `healthy` under `docker inspect` with a checkout, and `unhealthy` with a service failing | manual | the image started; a service broken by hand | `.State.Health.Status` | NOT verified: needs an image rebuild | 2026-11-10 |
| DOCKER-SVC-08 | `last_exit_status_repeats` counts the consecutive exits in the streak with the last exit's status, restarting at a change of status (the cannot-start evidence `maintenance-mode.sh` reads) | php | two exits with 3, then a missing script (127) every time | last status 127; repeats = consecutive failures - 2 | implemented | 2026-11-16 |

## Supervised_Service_Health_Test (php) - the rsx:health "Supervised Services" row

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-SVC-10 | OK counting the services up; a failing file whose runner is gone ignored; FAIL naming a failing service with its count, last status and since; a stopped service is no failure | php | real runners on a scratch state directory; a ghost file | the row's status and detail | implemented | 2026-11-10 |
| DOCKER-SVC-11 | no state directory is INFO | php | an absent directory | INFO | implemented | 2026-11-10 |


## Image_Contract_Test (php) - the image contract: an image older than its checkout is named

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-IMG-01 | an image recording an older contract is named with the remedy, identically by the PHP class and the bash library, and the "Container Image" row FAILs with it | php | scratch requirement (2), record (1, built_from), marker (target=prod) | the sentence; bash rc 0 and the same text; FAIL, remediation names `build.sh prod` | implemented | 2026-11-16 |
| DOCKER-IMG-02 | `built_from=unknown` reads "built from an unrecorded revision" in both readers | php | record `contract=1`, `built_from=unknown` | the parenthesis; equal sentences | implemented | 2026-11-16 |
| DOCKER-IMG-03 | an equal or newer image is fine; the row is OK naming both numbers and the revision | php | records 2 and 3 against 2 | null; bash rc 1, no output; OK detail | implemented | 2026-11-16 |
| DOCKER-IMG-04 | no record counts as contract 1 with `/opt/rspade/rsx-lockd/lockd-run.sh`, 0 without | php | no record, lockd script present / absent, requirements 1 and 2 | 1 (fine at 1, older at 2), 0 (older at 1); equal sentences | implemented | 2026-11-16 |
| DOCKER-IMG-05 | a record whose `contract=` is missing or not a whole number fails loud naming the file, never "fine" | php | no contract line, `two`, `-1` | PHP throws, row FAIL `<file> is unreadable: contract=<v>`; bash rc 2 with the same `[ERROR]` on stderr | implemented | 2026-11-16 |
| DOCKER-IMG-06 | outside the RSpade container nothing is compared | php | no marker, record 0 | null; bash rc 1; row INFO | implemented | 2026-11-16 |
| DOCKER-IMG-07 | the shipped requirement is well formed, ends in a newline, and its `breaking_change=` names an existing document | php | `resource/docker/image_contract.conf` | contract integer; `breaking_changes/<name>.txt` exists | implemented | 2026-11-16 |
| DOCKER-IMG-08 | the dev and prod stages record the contract in their last instruction; base and docconvert record nothing | php | the Dockerfile, split by stage | as stated | implemented | 2026-11-16 |
| DOCKER-IMG-09 | a built image carries the record with the build.sh revision | manual | `bash build.sh dev`; `docker run --rm --entrypoint cat rspade/rspade-server-dev /opt/rspade/image.conf` | the conf followed by `built_from=` (`unknown` in the monorepo, which has no system/.git; the revision and `+modified` logic checked against a scratch git checkout) | verified by hand 2026-11-16 (dev) | 2026-11-16 |

## Startup_State_Test (php) - the container-started signal and the build/clean refusal while starting

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-START-01 | begin / step / warning / started write the record: status, step, one-line warning, began_at / finished_at, and PID 1's start time read after the last `)` of its stat | php | `startup-state.sh --state-file --proc-root` on a scratch /proc whose comm holds `) ` | starting -> started; pid1_start = field 22; warning on one line | implemented | 2026-11-16 |
| DOCKER-START-02 | the waiter blocks while starting, prints one line per step change, and exits 0 when `started` is written | php | waiter in the background; `started` written once it announced the step | still waiting before; rc 0 after; one "still starting" line | implemented | 2026-11-16 |
| DOCKER-START-03 | a failed start: the waiter exits 1 with the error | php | `failed <msg>` | rc 1, message on stderr | implemented | 2026-11-16 |
| DOCKER-START-04 | no record and PID 1 not the RSpade entrypoint: exit 2 with the documented sentence; a record from an earlier start (pid1_start differs) is ignored; the same record under a matching PID 1 is current | php | scratch /proc with `/sbin/init` | rc 2, 2, 0 | implemented | 2026-11-16 |
| DOCKER-START-05 | `rsx_container_starting()` refuses only for status=starting + matching pid1_start + a process outside PID 1's descendants (docker exec: ppid 0; unreadable chain); never for PID 1, its child or grandchild, a finished or stale record, no record, or no marker | php | scratch /proc tree, scratch record and marker | refusal array for pids 60 and 70; null for the rest | implemented | 2026-11-16 |
| DOCKER-START-06 | the pre-boot hook covers rsx:build and rsx:clean; the image installs rspade-startup and rspade-wait-started in base; the entrypoint begins the record before its first step, records `started` before "RSpade is up" and before a passed command, and die() records `failed` | php | rsx_preboot.php, Dockerfile, entrypoint.sh | as stated | implemented | 2026-11-16 |
| DOCKER-START-07 | a built image carries both commands: `rspade-wait-started` against a scratch record exits 0 on started, 1 on failed, 2 in a container whose PID 1 is not the entrypoint | manual | `bash build.sh dev`; `docker run --rm --entrypoint bash <image> -c ...` | as stated | implemented (manual, 2026-11-16) | 2026-11-16 |
