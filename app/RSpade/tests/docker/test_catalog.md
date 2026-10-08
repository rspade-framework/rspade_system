# docker - test catalog

## t1_php_fpm_workers.sh (cli) - php-fpm pool sizing

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| DOCKER-FPM-01 | the total follows the memory formula and never falls below 1 | cli | `plan` for 512 MB, 2, 4, 8 and 16 GB, no swap, ample cores | totals 1, 1, 4, 16, 39 | implemented | 2026-10-08 |
| DOCKER-FPM-02 | swap earns one worker per 450 MB, at most two | cli | 4 GB with 449, 450, 900 and 240000 MB swap | totals 4, 5, 6, 6 | implemented | 2026-10-08 |
| DOCKER-FPM-03 | three workers a core caps the total | cli | 8 GB on 1 and 2 cores | totals 3 and 6 | implemented | 2026-10-08 |
| DOCKER-FPM-04 | the split is 1 web : 3 Ajax, web down and Ajax up, floors 2 and 1, standby 1 to 4 | cli | every plan above | the printed web / ajax / standby values | implemented | 2026-10-08 |
| DOCKER-FPM-05 | an explicit total replaces the calculation and the split is still derived | cli | `plan ... 10`, `40`, `1` | web 2 / ajax 8; 10 / 30; 2 / 1 | implemented | 2026-10-08 |
| DOCKER-FPM-06 | apply writes both pools as dynamic with the planned numbers, each key once, nothing else moved | cli | `PHP_FPM_WORKER_COUNT=10`, copies of the shipped production pool files | the five `pm` values per pool; `user` unchanged; the report line | implemented | 2026-10-08 |
| DOCKER-FPM-07 | with no explicit total it measures the machine | cli | apply with the variable unset | the report names cores, memory and swap | implemented | 2026-10-08 |
| DOCKER-FPM-08 | a total that is not a whole number of 1 or more is refused | cli | `abc`, `0`, `-3`, `2.5` | non-zero exit naming the variable | implemented | 2026-10-08 |
| DOCKER-FPM-09 | missing pool files stop the start | cli | apply against an empty directory | non-zero exit, "php-fpm pools were not sized" | implemented | 2026-10-08 |
| DOCKER-FPM-10 | in the built production image the step sizes both pools, php-fpm accepts the result, and the memory limit is 320 MB | manual | `rspade-php-fpm-workers apply` in the image, measured and with `PHP_FPM_WORKER_COUNT=24`; `php-fpm -t`; `php-fpm -i` | the start line; "configuration file ... test is successful"; `memory_limit => 320M` | verified by hand 2026-10-08 | 2026-10-08 |
| DOCKER-FPM-11 | a limit docker puts on the container (`--memory`, `--cpus`) is what is measured, not the host | manual | the image started with `--memory 4g --cpus 2` | the start line names 2 cores and 4096 MB | NOT verified: the docker available inside the development container cannot apply resource limits | 2026-10-08 |
