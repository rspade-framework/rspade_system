# docker - test catalog

## t1_sizing.sh (cli) and Container_Sizing_Test (php) - container resource sizing

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-FPM-01 | the RAM and RAM + swap ceilings bound every PHP process, php-fpm is held to 4 a core, and the task workers are about a quarter of php-fpm (at least 2); php-fpm never below 2 | cli | `plan` for 2 GB/1 core with and without 2 GB swap, 4 GB/2 cores with and without 4 GB swap, 8 GB/4, 16 GB/8 | php-fpm 2, 4, 7, 8, 16, 32; tasks 2, 2, 2, 2, 4, 8 | implemented | 2026-10-31 |
| DOCKER-FPM-02 | the Redis cap is 32 MB under 1 GB, otherwise max(64, 5%), at most 2 GB; the scratch reservation is at most 2 GB too | cli | 512 MB, 1 GB, 1.2 GB, 32 GB, 64 GB; 128 GB | 32, 64, 64, 1638, 2048; scratch 2048 | implemented | 2026-10-31 |
| DOCKER-FPM-13 | past 64 php-fpm workers the task workers grow by one per 25; the calculation plans at most 140 PHP processes and says so, a count set by hand is never capped and brings no notice; the task workers split one scheduled to two on demand | cli | 16 cores/32 GB, 32/64 GB, 128/512 GB; `PHP_FPM_WORKER_COUNT=300`; `RSX_TASK_SCHEDULED_WORKERS=3` | 64 + 16 (5/11); 122 + 18 (6/12) and the notice; 300 + 25, no notice; 3 scheduled | implemented | 2026-10-31 |
| DOCKER-FPM-03 | the reservation (fixed, scratch, Redis, MySQL on dev, extra) and the three ceilings are reported | cli | `plan 2 4096 4096`, `plan 2 4096 0 dev` | the printed reservation and ceilings | implemented | 2026-10-31 |
| DOCKER-FPM-04 | the split is a quarter web (at least 1, 2 from a total of 4), the rest Ajax; standby 1 to 4 | cli | every plan above | the printed web / ajax / standby values | implemented | 2026-10-31 |
| DOCKER-FPM-05 | every switch replaces its part of the calculation | cli | each of the eight switches | the changed figure | implemented | 2026-10-31 |
| DOCKER-FPM-06 | a switch that is not a whole number in range is refused, naming it; PHP_FPM_WORKER_COUNT needs 2 | cli | `abc`, `0`, `-3`, `2.5`; `PHP_FPM_WORKER_COUNT=1` | non-zero exit naming the switch | implemented | 2026-10-31 |
| DOCKER-FPM-07 | apply prod writes both pools (each key once, nothing else moved), both memory ini files, the Redis cap and the record, all 644, and no MySQL file | cli | a scratch `--root` with copies of the production pools | the files and their values; sizing.json fields | implemented | 2026-10-31 |
| DOCKER-FPM-08 | both task pools come from the project .env when the environment is silent, and the environment wins | cli | `.env` RSX_TASK_MAX_WORKERS 5, then the env 7; `.env` RSX_TASK_SCHEDULED_WORKERS 4 | on_demand 5, then 7; scheduled 4 | implemented | 2026-10-31 |
| DOCKER-FPM-09 | apply dev leaves the pools alone and writes 256 MB and the MySQL buffer pool | cli | a scratch root with the dev pool | pool unchanged; the ini, the cnf; pools_sized false | implemented | 2026-10-31 |
| DOCKER-FPM-10 | missing production pool files stop the start | cli | apply prod against an empty root | non-zero exit, "php-fpm pools were not sized" | implemented | 2026-10-31 |
| DOCKER-FPM-11 | in a built image the step sizes everything at start, php-fpm and redis accept the result, and php -i in the CLI and php-fpm reports the limit | manual | each image started; `php-fpm -t`; `redis-cli CONFIG_rspade_internal GET maxmemory`; `php -r 'echo ini_get("memory_limit");'` | the start line; accepted; the cap; 256M dev, 320M prod (384M with RSPADE_PHP_MEMORY_LIMIT_MB=384) | verified by hand 2026-10-31 (dev and prod) | 2026-10-31 |
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
