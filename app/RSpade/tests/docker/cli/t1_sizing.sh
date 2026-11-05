#!/bin/bash
# t1: sizing.sh - the container sizes PHP, Redis and MySQL to the machine.
#
# The script is the whole rule: the Redis cap from memory; what is reserved for everything
# that is not PHP; the task workers from the cores; the php-fpm total as the lowest of three
# ceilings (typical size in RAM, the memory limit in RAM + swap, workers per core) with a
# floor of 2; ONE pool takes them all, with its spare workers (min_spare ready, max_spare kept warm). It is driven here through `plan`, which
# prints the decision for a given machine, and through `apply --root=`, which writes under a
# scratch root holding COPIES of the shipped production pool files - never the files of the
# box running the test.

TEST_NAME="docker/cli t1 container sizing"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(cd "$DIR/../../../resource/docker" && pwd)"
SCRIPT="$DOCKER_DIR/sizing.sh"

fail() { echo "FAIL: $TEST_NAME - $*"; exit 1; }

# Every switch unset, whatever the environment running the test carries.
SWITCHES="RSPADE_PHP_MEMORY_LIMIT_MB RSPADE_PHP_TYPICAL_MB RSPADE_PHP_WORKERS_PER_CORE PHP_FPM_WORKER_COUNT RSX_TASK_MAX_WORKERS REDIS_MAXMEMORY_MB RSPADE_RESERVED_MB MYSQL_INNODB_BUFFER_MB"
for name in $SWITCHES; do unset "$name"; done

# The machine-readable last line of a plan.
plan_line() { bash "$SCRIPT" plan "$@" | tail -n1; }

plan_is() {
    local expected="$1"; shift
    local actual
    actual="$(plan_line "$@")" || fail "plan $* exited non-zero"
    [ "$actual" = "$expected" ] || fail "plan $*: expected '$expected', got '$actual'"
}

# ---- 1. The worked table: the memory ceilings bound every PHP process, php-fpm is held
# to 3 a core, and the task workers are about a quarter of php-fpm, never below 2. -------
# 2 GB / 1 core / no swap: RAM + swap at 352 MB allows 3 processes - the floors win.
plan_is "total=2 min_spare=2 max_spare=2 tasks=2 scheduled=1 on_demand=1 redis_mb=102 limit_mb=352" 1 2048 0
# The same box with 2 GB of swap: php-fpm at the CPU ceiling (3 a core), tasks at the floor.
plan_is "total=3 min_spare=3 max_spare=3 tasks=2 scheduled=1 on_demand=1 redis_mb=102 limit_mb=352" 1 2048 2048
# 4 GB / 2 cores / no swap: php-fpm at the CPU ceiling, 6, and the floor of 2 tasks.
plan_is "total=6 min_spare=3 max_spare=4 tasks=2 scheduled=1 on_demand=1 redis_mb=204 limit_mb=352" 2 4096 0
# Swap adds nothing once the CPU ceiling is what binds.
plan_is "total=6 min_spare=3 max_spare=4 tasks=2 scheduled=1 on_demand=1 redis_mb=204 limit_mb=352" 2 4096 4096
plan_is "total=12 min_spare=4 max_spare=8 tasks=3 scheduled=1 on_demand=2 redis_mb=409 limit_mb=352" 4 8192 0
plan_is "total=24 min_spare=8 max_spare=16 tasks=6 scheduled=2 on_demand=4 redis_mb=819 limit_mb=352" 8 16384 0

# ---- 2. The Redis cap: 32 under 1 GB, otherwise max(64, 5%). ---------------------------
plan_line 1 512 0 | grep -q "redis_mb=32 "    || fail "a box under 1 GB gets 32 MB of Redis"
plan_line 1 1024 0 | grep -q "redis_mb=64 "   || fail "1 GB gets the 64 MB minimum"
plan_line 1 1200 0 | grep -q "redis_mb=64 "   || fail "1.2 GB (5% = 60) still gets 64 MB"
plan_line 8 32768 0 | grep -q "redis_mb=1638 " || fail "32 GB gets 5%"
plan_line 8 65536 0 | grep -q "redis_mb=2048 " || fail "64 GB is capped at 2 GB"
bash "$SCRIPT" plan 8 131072 0 | grep -q "+ 2048 scratch" || fail "scratch is capped at 2 GB"

# ---- 2b. Past 64 php-fpm, one more task worker per 25; and the 140-process cap. -------
plan_is "total=48 min_spare=16 max_spare=32 tasks=12 scheduled=4 on_demand=8 redis_mb=1638 limit_mb=352" 16 32768 0
plan_is "total=96 min_spare=32 max_spare=64 tasks=17 scheduled=6 on_demand=11 redis_mb=2048 limit_mb=352" 32 65536 0
plan_is "total=122 min_spare=61 max_spare=91 tasks=18 scheduled=6 on_demand=12 redis_mb=2048 limit_mb=352" 128 524288 0
bash "$SCRIPT" plan 128 524288 0 | grep -q "^notice    capped at 140 PHP processes" || fail "a capped box says so"
bash "$SCRIPT" plan 16 32768 0 | grep -q "^notice" && fail "a box under the cap has no notice"
PHP_FPM_WORKER_COUNT=300 plan_line 128 524288 0 | grep -q "^total=300 .* tasks=25 scheduled=8 on_demand=17 " || fail "a set count is never capped"
PHP_FPM_WORKER_COUNT=300 bash "$SCRIPT" plan 128 524288 0 | grep -q "^notice" && fail "a box with counts set by hand has no notice"

# ---- 3. The reservation and the ceilings are reported. --------------------------------
OUT="$(bash "$SCRIPT" plan 2 4096 4096)"
echo "$OUT" | grep -q "reserved  1059 MB = 600 fixed + 204 scratch + 255 redis + 0 mysql + 0 extra" || fail "the reservation: $OUT"
echo "$OUT" | grep -q "ceilings  processes: ram 23, ram+swap 20; php-fpm: cpu 6 -> cpu" || fail "the ceilings: $OUT"
echo "$OUT" | grep -q "tasks     2: 1 scheduled (calculated) + 1 on demand (calculated)" || fail "the task split: $OUT"

# ---- 4. The development target: 320 MB, and MySQL reserved. ---------------------------
OUT="$(bash "$SCRIPT" plan 2 4096 0 dev)"
echo "$OUT" | grep -q "reserved  1315 MB = 600 fixed + 204 scratch + 255 redis + 256 mysql + 0 extra" || fail "dev reservation: $OUT"
echo "$OUT" | tail -n1 | grep -q "total=6 .* tasks=2 .* limit_mb=320$" || fail "dev budget: $OUT"

# ---- 5. Each switch replaces its part of the calculation. -----------------------------
[ "$(PHP_FPM_WORKER_COUNT=10 plan_line 1 1 0 | cut -d' ' -f1-3)" = "total=10 min_spare=3 max_spare=3" ] || fail "PHP_FPM_WORKER_COUNT"
[ "$(PHP_FPM_WORKER_COUNT=2 plan_line 64 65536 0 | cut -d' ' -f1-3)" = "total=2 min_spare=2 max_spare=2" ] || fail "PHP_FPM_WORKER_COUNT=2 is the floor, and the spares never exceed the pool"
REDIS_MAXMEMORY_MB=500 plan_line 2 4096 0 | grep -q "redis_mb=500 "        || fail "REDIS_MAXMEMORY_MB"
RSX_TASK_MAX_WORKERS=6 plan_line 2 4096 4096 | grep -q "^total=6 .* tasks=7 scheduled=1 on_demand=6 " || fail "RSX_TASK_MAX_WORKERS"
RSX_TASK_SCHEDULED_WORKERS=3 plan_line 2 4096 4096 | grep -q "^total=6 .* tasks=4 scheduled=3 on_demand=1 " || fail "RSX_TASK_SCHEDULED_WORKERS"
PHP_FPM_WORKER_COUNT=20 plan_line 2 4096 0 | grep -q "^total=20 .* tasks=5 scheduled=2 on_demand=3 " || fail "PHP_FPM_WORKER_COUNT keeps the quarter"
RSPADE_PHP_MEMORY_LIMIT_MB=512 plan_line 2 4096 0 | grep -q "^total=3 .* tasks=2 .* limit_mb=512$" || fail "RSPADE_PHP_MEMORY_LIMIT_MB"
RSPADE_PHP_WORKERS_PER_CORE=2 plan_line 2 4096 4096 | grep -q "^total=4 .* tasks=2 " || fail "RSPADE_PHP_WORKERS_PER_CORE"
RSPADE_PHP_TYPICAL_MB=256 bash "$SCRIPT" plan 2 4096 4096 | grep -q "ceilings  processes: ram 11," || fail "RSPADE_PHP_TYPICAL_MB"
RSPADE_RESERVED_MB=1000 bash "$SCRIPT" plan 2 4096 4096 | grep -q "+ 1000 extra" || fail "RSPADE_RESERVED_MB"
MYSQL_INNODB_BUFFER_MB=512 bash "$SCRIPT" plan 2 4096 0 dev | grep -q "+ 704 mysql" || fail "MYSQL_INNODB_BUFFER_MB"

# ---- 6. A switch that is not a whole number in range is refused, naming it. -----------
SCRATCH="$(mktemp -d)"
trap 'rm -rf "$SCRATCH"' EXIT
for bad in abc 0 -3 2.5; do
    if RSPADE_PHP_TYPICAL_MB="$bad" bash "$SCRIPT" plan 1 2048 0 >/dev/null 2>"$SCRATCH/err"; then
        fail "RSPADE_PHP_TYPICAL_MB=$bad was accepted"
    fi
    grep -q "RSPADE_PHP_TYPICAL_MB must be a whole number of 1 or more" "$SCRATCH/err" || fail "refusing '$bad' did not say why"
done
if PHP_FPM_WORKER_COUNT=1 bash "$SCRIPT" plan 1 2048 0 >/dev/null 2>"$SCRATCH/err"; then
    fail "PHP_FPM_WORKER_COUNT=1 was accepted (the floor is two workers)"
fi
grep -q "PHP_FPM_WORKER_COUNT must be a whole number of 2 or more" "$SCRATCH/err" || fail "refusing 1 did not say why"
RSPADE_RESERVED_MB=0 bash "$SCRIPT" plan 1 2048 0 >/dev/null || fail "RSPADE_RESERVED_MB=0 is allowed"

# ---- 7. apply prod: the pool, both memory ini files, the Redis cap, the record. -------
ROOT="$SCRATCH/root"
POOLS="$ROOT/etc/php/8.5/fpm/pool.d"
mkdir -p "$POOLS" "$ROOT/var/www/html"
cp "$DOCKER_DIR/php/www-prod.conf" "$POOLS/www.conf"

OUT="$(PHP_VERSION=8.5 PHP_FPM_WORKER_COUNT=10 REDIS_MAXMEMORY_MB=300 bash "$SCRIPT" apply prod --root="$ROOT")" || fail "apply exited non-zero"
echo "$OUT" | grep -qE "sized for [0-9]+ cores, [0-9]+ MB memory, [0-9]+ MB swap: php-fpm 10, " \
    || fail "apply did not report what it chose: $OUT"

value_of() { grep -E "^$2 = " "$POOLS/$1.conf" | sed 's/.* = //'; }

[ "$(value_of www pm)" = "dynamic" ]              || fail "www pm"
[ "$(value_of www pm.max_children)" = "10" ]      || fail "www max_children"
# The spares depend on the cores this machine has; the plan for the same cores is the oracle
# (the rows above pin the formula itself).
cores="$(echo "$OUT" | sed -nE 's/.*sized for ([0-9]+) cores.*/\1/p')"
expected="$(PHP_FPM_WORKER_COUNT=10 plan_line "$cores" 1 0 | cut -d' ' -f2-3)"
[ "min_spare=$(value_of www pm.min_spare_servers) max_spare=$(value_of www pm.max_spare_servers)" = "$expected" ] \
    || fail "www spares: expected $expected for $cores cores"
[ "$(value_of www pm.start_servers)" = "$(value_of www pm.min_spare_servers)" ] || fail "www start_servers is min_spare"
for key in pm pm.max_children pm.start_servers pm.min_spare_servers pm.max_spare_servers; do
    [ "$(grep -cE "^${key//./\\.} = " "$POOLS/www.conf")" = "1" ] || fail "www.conf sets $key more than once"
done
[ "$(value_of www user)" = "www-data" ] || fail "apply changed a line it does not own"

for sapi in fpm cli; do
    grep -qx "memory_limit = 352M" "$ROOT/etc/php/8.5/$sapi/conf.d/95-rspade-memory.ini" || fail "the $sapi memory limit"
done
grep -qx "maxmemory 300mb" "$ROOT/etc/redis/rspade-memory.conf" || fail "the Redis cap"
[ ! -e "$ROOT/etc/mysql" ] || fail "a production apply wrote a MySQL file"
[ "$(stat -c %a "$ROOT/etc/redis/rspade-memory.conf")" = "644" ] || fail "written files are 644"

JSON="$ROOT/var/lib/rspade/sizing.json"
php -r '
    $b = json_decode(file_get_contents($argv[1]), true);
    $ok = is_array($b)
        && $b["target"] === "prod"
        && $b["php_fpm"]["total"] === 10 && $b["php_fpm"]["source"] === "override" && $b["php_fpm"]["min_spare"] >= 3 && $b["php_fpm"]["max_spare"] >= $b["php_fpm"]["min_spare"] && $b["php_fpm"]["max_spare"] <= 10 && $b["php_fpm"]["pool_sized"] === true
        && $b["ceilings"]["winner"] === "override"
        && $b["redis"]["maxmemory_mb"] === 300 && $b["redis"]["source"] === "override"
        && $b["php"]["memory_limit_mb"] === 352 && $b["php"]["memory_limit_source"] === "calculated"
        && $b["tasks"]["total"] === $b["tasks"]["scheduled"] + $b["tasks"]["on_demand"]
        && $b["tasks"]["scheduled_source"] === "calculated"
        && $b["ceilings"]["process_cap"] === 140 && is_bool($b["ceilings"]["capped"]) && is_bool($b["ceilings"]["cap_notice"])
        && $b["mysql"]["innodb_buffer_pool_mb"] === null;
    exit($ok ? 0 : 1);
' "$JSON" || fail "sizing.json does not record the budget: $(cat "$JSON")"

# ---- 8. On-demand workers come from the project .env when the environment is silent. --
echo 'RSX_TASK_MAX_WORKERS="5"' > "$ROOT/var/www/html/.env"
PHP_VERSION=8.5 bash "$SCRIPT" apply prod --root="$ROOT" >/dev/null || fail "apply with an .env line"
grep -q '"on_demand": 5, "on_demand_source": "override"' "$JSON" || fail "the .env RSX_TASK_MAX_WORKERS was not counted"
RSX_TASK_MAX_WORKERS=7 PHP_VERSION=8.5 bash "$SCRIPT" apply prod --root="$ROOT" >/dev/null || fail "apply with both"
grep -q '"on_demand": 7,' "$JSON" || fail "the container environment wins over the .env line"
echo 'RSX_TASK_SCHEDULED_WORKERS=4' > "$ROOT/var/www/html/.env"
PHP_VERSION=8.5 bash "$SCRIPT" apply prod --root="$ROOT" >/dev/null || fail "apply with a scheduled .env line"
grep -q '"scheduled": 4, "scheduled_source": "override"' "$JSON" || fail "the .env RSX_TASK_SCHEDULED_WORKERS was not counted"
rm "$ROOT/var/www/html/.env"

# ---- 9. apply dev: the pool untouched, 320 MB, the MySQL buffer pool. ----------------
cp "$DOCKER_DIR/php/www.conf" "$POOLS/www.conf"
BEFORE="$(md5sum < "$POOLS/www.conf")"
PHP_VERSION=8.5 MYSQL_INNODB_BUFFER_MB=96 bash "$SCRIPT" apply dev --root="$ROOT" >/dev/null || fail "apply dev"
[ "$(md5sum < "$POOLS/www.conf")" = "$BEFORE" ] || fail "a development apply changed the pool"
grep -qx "memory_limit = 320M" "$ROOT/etc/php/8.5/cli/conf.d/95-rspade-memory.ini" || fail "the dev CLI memory limit"
grep -qx "innodb_buffer_pool_size = 96M" "$ROOT/etc/mysql/mysql.conf.d/95-rspade-sizing.cnf" || fail "the dev MySQL buffer pool"
grep -q '"pool_sized": false' "$JSON" || fail "the dev record says the pool was not sized"

# ---- 10. A missing production pool file stops the start. ------------------------------
EMPTY="$SCRATCH/empty"
mkdir -p "$EMPTY"
if PHP_VERSION=8.5 bash "$SCRIPT" apply prod --root="$EMPTY" >/dev/null 2>"$SCRATCH/err"; then
    fail "apply prod succeeded with no pool file to write"
fi
grep -q "php-fpm pool was not sized" "$SCRATCH/err" || fail "a missing pool file was not named"

exit 0
