#!/bin/bash
#
# rspade-sizing - size the container's PHP, Redis and (development) MySQL to the machine.
#
#   rspade-sizing apply <dev|prod> [--root=<dir>]
#       Work out the budget for THIS container and write it: the php-fpm pool sizes
#       (production), the php-fpm AND CLI memory_limit, the Redis cap, the development
#       MySQL buffer pool, and /var/lib/rspade/sizing.json, the record of every number and
#       where it came from. The entrypoint runs it before supervisor starts anything.
#       --root prefixes every path the script reads or writes (the test harness).
#
#   rspade-sizing plan <cpu_cores> <memory_mb> <swap_mb> [dev|prod]
#       Print the budget for the given machine and change nothing. The target defaults to
#       prod. The switches below apply to a plan exactly as they do to an apply.
#
# SWITCHES. Each is read from the container's environment (docker run -e, compose
# `environment:`). Unset means calculated; a value that is not a whole number of the
# allowed range stops the start, naming the switch - nothing is silently replaced.
#
#   RSPADE_PHP_MEMORY_LIMIT_MB   L: memory_limit for php-fpm AND the CLI    320 prod, 256 dev
#   RSPADE_PHP_TYPICAL_MB        A: what one PHP process typically holds    128
#   RSPADE_PHP_WORKERS_PER_CORE  the CPU ceiling's factor                   4
#   PHP_FPM_WORKER_COUNT         the php-fpm total, instead of calculated   (2 or more)
#   RSX_TASK_MAX_WORKERS         on-demand task workers; also read from the project .env
#   RSX_TASK_SCHEDULED_WORKERS   scheduled task workers; also read from the project .env
#   REDIS_MAXMEMORY_MB           the Redis cap
#   RSPADE_RESERVED_MB           memory to leave alone besides ours         0
#   MYSQL_INNODB_BUFFER_MB       the development MySQL buffer pool          64
#
set -u

ROOT=""
FIXED_RESERVED_MB=600

die() { echo "[rspade] ERROR: $*" >&2; exit 1; }

# A switch, checked: a whole number of at least $min, or the default when unset. Sets
# <var> to the value and <var>_source to calculated | override.
take_switch() {
    local var="$1" name="$2" default="$3" min="$4"
    local value="${!name:-}"

    if [ -z "$value" ]; then
        printf -v "$var" '%s' "$default"
        printf -v "${var}_source" '%s' calculated

        return
    fi
    case "$value" in
        *[!0-9]*) die "${name} must be a whole number of ${min} or more, got '${value}'." ;;
    esac
    [ "$value" -ge "$min" ] || die "${name} must be a whole number of ${min} or more, got '${value}'."
    printf -v "$var" '%s' "$((10#$value))"
    printf -v "${var}_source" '%s' override
}

# RSX_TASK_MAX_WORKERS from the project .env, when the container environment does not set
# it: the framework reads the same line, so the budget must count what it will run.
project_env_value() {
    local file="${ROOT}/var/www/html/.env"
    [ -f "$file" ] || return 0
    sed -n "s/^$1=//p" "$file" | tr -d '"'"'"' \r' | tail -n1
}

# -----------------------------------------------------------------------------
# 1. The Redis cap.
# -----------------------------------------------------------------------------
#
#   cap = 32                                    under 1 GB of memory
#       = min(2048, max(64, floor(memory / 20))) otherwise (5%, at most 2 GB)
#
# One Redis instance holds everything - the cache, the realtime registry, the counters,
# the performance metrics - under allkeys-lru, so the cap decides how much stays warm,
# never whether anything works: every key in it is derived and rebuilt on a miss. A small
# box cannot spare much; a larger one has more traffic to keep warm, so the cap grows with
# it - up to 2 GB, past which a bigger cache keeps nothing warm that matters and only takes
# memory from PHP.
redis_cap() {
    local memory_mb="$1"
    if [ "$memory_mb" -lt 1024 ]; then
        echo 32
    else
        local share=$(( memory_mb / 20 ))
        [ "$share" -lt 64 ] && share=64
        [ "$share" -gt 2048 ] && share=2048
        echo "$share"
    fi
}

# -----------------------------------------------------------------------------
# 2. What is not PHP's.
# -----------------------------------------------------------------------------
#
#   reserved = 600                          fixed
#            + min(2048, max(200, floor(memory / 20))) scratch
#            + ceil(redis_cap * 1.25)       Redis
#            + 192 + innodb_buffer          MySQL (development target only)
#            + RSPADE_RESERVED_MB
#
# FIXED is the operating system and kernel plus every service in the container that is
# not a PHP worker. Measured on a development container (2026-10-31, resident + swapped):
# nginx 12 MB, supervisor 21, the realtime relay 28, the full-page-cache proxy 41,
# rsx-lockd 16, the php-fpm master 26, Redis's own overhead 15 - about 160 MB - plus
# OPcache's shared memory (256 MB configured in production, filled by use) and the
# kernel, init, cron and the shell an operator opens: 600 in all.
#
# SCRATCH is the page cache and headroom everything else draws on - an upload being
# written, a PDF being rendered, the file reads every request makes. A twentieth of the
# machine, never below 200 MB and never above 2 GB - past that a larger box only takes
# memory away from PHP.
#
# REDIS is its cap plus a quarter: allocator fragmentation and client buffers sit outside
# maxmemory.
#
# MYSQL is the development target's own database: its buffer pool plus about 192 MB of
# server, connections and dictionary. A production container's database lives elsewhere.
reserved_mb() {
    local memory_mb="$1" redis_mb="$2" target="$3" innodb_mb="$4" extra_mb="$5"

    local scratch=$(( memory_mb / 20 ))
    [ "$scratch" -lt 200 ] && scratch=200
    [ "$scratch" -gt 2048 ] && scratch=2048
    local redis_reserved=$(( (redis_mb * 5 + 3) / 4 ))
    local mysql=0
    [ "$target" = "dev" ] && mysql=$(( 192 + innodb_mb ))

    echo "$(( FIXED_RESERVED_MB + scratch + redis_reserved + mysql + extra_mb )) ${scratch} ${redis_reserved} ${mysql}"
}

# -----------------------------------------------------------------------------
# 3. How many PHP processes, and the split between php-fpm and the task workers.
# -----------------------------------------------------------------------------
#
#   processes = min(by_ram, by_worst, 140)          every PHP process, php-fpm and tasks
#     by_ram    = floor((memory - reserved) / A)
#     by_worst  = floor((memory + swap - reserved) / L)
#   php_fpm   = the largest count, at most by_cpu (cores * workers_per_core), for which
#               php_fpm + tasks(php_fpm) <= processes - never below 2
#   tasks(n)  = max(2, round(n / 4))                 while n <= 64 (16 task workers)
#             = 16 + floor((n - 64) / 25)            past it
#   scheduled = max(1, round(tasks / 3)),  on_demand = max(1, tasks - scheduled)
#
# Two memory ceilings bound EVERY PHP process, because a task worker is a PHP process of
# the same size as a php-fpm worker:
#
#   - BY RAM: every PHP process, typically, holds A (128 MB) of real memory. This is the
#     number that decides whether the box serves at speed.
#   - BY WORST CASE: every PHP process at its memory_limit L at once must still FIT, in
#     RAM and swap together - swap counted in full. A box that would rather swap than
#     kill is allowed to, and a box with no swap is held to its RAM.
#
# THE PROCESS CAP. However large the machine, the calculation never plans more than
# PROCESS_CAP (140) PHP processes. Every one of them may hold a database connection, and
# 140 is what fits under MySQL's default max_connections (151) with room left for the
# cron tick, commands, migrations, the kill workers and an operator's shell. A box that
# should run more is a decision for a person: they set the counts with the switches below
# AND configure the database server to accept them (rsx:man server_sizing). The cap never
# limits a count somebody set.
#
# Within those limits the processes are split about FOUR php-fpm workers to ONE task
# worker, never fewer than two task workers. The CPU ceiling - past four a core, more
# processes queue for CPU rather than serve - bounds php-fpm, and the task workers follow
# php-fpm's count: a task worker mostly waits on the database, mail or another service, so
# the floor of two must not take a small box's web workers, and past 16 task workers (64
# php-fpm) they grow by one per 25 php-fpm workers - a much larger web tier needs only a
# few more background workers.
#
# The task workers are split ONE SCHEDULED to TWO ON DEMAND, at least one of each. The
# on-demand pool runs dispatched work only; the scheduled pool runs #[Schedule] work and
# takes queued dispatched work first, so both pools serve dispatched work.
#
# The floor is two php-fpm workers - one web, one Ajax, the least that serves a page and
# its data - beside the two task workers. A machine below that floor runs out of memory
# under load, and that is the operator's choice to make with a bigger box or the switches
# above. The kill pool is not counted: a kill worker only delivers a signal and exits.
#
# PHP_FPM_WORKER_COUNT fixes php-fpm, RSX_TASK_SCHEDULED_WORKERS and RSX_TASK_MAX_WORKERS
# fix the two task pools; whatever is not set is still calculated, around what is.
PROCESS_CAP=140

tasks_for() {
    local php_fpm="$1" tasks
    if [ "$php_fpm" -le 64 ]; then
        tasks=$(( (php_fpm + 2) / 4 ))
        [ "$tasks" -lt 2 ] && tasks=2
    else
        tasks=$(( 16 + (php_fpm - 64) / 25 ))
    fi
    echo "$tasks"
}

budget() {
    local cores="$1" memory_mb="$2" swap_mb="$3" reserved="$4" typical="$5" limit="$6" per_core="$7" php_fpm_set="$8" scheduled_set="$9" on_demand_set="${10}"

    local by_ram=0 by_worst=0
    [ "$memory_mb" -gt "$reserved" ] && by_ram=$(( (memory_mb - reserved) / typical ))
    [ $(( memory_mb + swap_mb )) -gt "$reserved" ] && by_worst=$(( (memory_mb + swap_mb - reserved) / limit ))
    local by_cpu=$(( cores * per_core ))

    local processes="$by_ram" ceiling=ram
    [ "$by_worst" -lt "$processes" ] && { processes="$by_worst"; ceiling=worst; }
    local capped=false
    if [ "$processes" -gt "$PROCESS_CAP" ]; then
        processes="$PROCESS_CAP"
        ceiling=cap
    fi

    # The task workers for a php-fpm count: calculated, then any pool somebody set.
    task_split() {
        local total scheduled on_demand
        total="$(tasks_for "$1")"
        scheduled=$(( (total + 1) / 3 ))
        [ "$scheduled" -lt 1 ] && scheduled=1
        on_demand=$(( total - scheduled ))
        [ "$on_demand" -lt 1 ] && on_demand=1
        [ -n "$scheduled_set" ] && scheduled="$scheduled_set"
        [ -n "$on_demand_set" ] && on_demand="$on_demand_set"
        echo "$scheduled $on_demand"
    }

    local php_fpm scheduled on_demand
    if [ -n "$php_fpm_set" ]; then
        php_fpm="$php_fpm_set"; ceiling=override
        read -r scheduled on_demand <<< "$(task_split "$php_fpm")"
    else
        php_fpm="$by_cpu"
        [ "$processes" -lt "$php_fpm" ] && php_fpm="$processes"
        while true; do
            read -r scheduled on_demand <<< "$(task_split "$php_fpm")"
            [ $(( php_fpm + scheduled + on_demand )) -le "$processes" ] && break
            [ "$php_fpm" -le 2 ] && break
            php_fpm=$(( php_fpm - 1 ))
        done
        if [ $(( php_fpm + scheduled + on_demand )) -gt "$processes" ] || [ "$php_fpm" -lt 2 ]; then
            php_fpm=2
            ceiling=floor
            read -r scheduled on_demand <<< "$(task_split "$php_fpm")"
        elif [ "$php_fpm" -eq "$by_cpu" ]; then
            ceiling=cpu
        fi
        [ "$ceiling" = cap ] && capped=true
    fi

    echo "$php_fpm $scheduled $on_demand $ceiling $by_ram $by_worst $by_cpu $processes $capped"
}

# -----------------------------------------------------------------------------
# 5. The split between the two pools, and each pool's standby.
# -----------------------------------------------------------------------------
#
# The web pool serves page requests and the Ajax pool everything a page asks for after
# it loads, which is most requests: web is a quarter, at least 1 and 2 once the total is
# 4 (so one slow page cannot make the site unreachable); Ajax is the rest.
#
# STANDBY is how many idle workers a pool keeps waiting: min(4, floor(pool / 2)), and
# never fewer than 1 - php-fpm's dynamic mode requires a spare worker, and a pool with
# nobody waiting makes the first visitor pay for a process start.
web_workers()  { local n=$(( $1 / 4 )); [ "$n" -lt 1 ] && n=1; [ "$1" -ge 4 ] && [ "$n" -lt 2 ] && n=2; echo "$n"; }
ajax_workers() { local n=$(( $1 - $2 )); [ "$n" -lt 1 ] && n=1; echo "$n"; }
standby_for()  { local n=$(( $1 / 2 )); [ "$n" -gt 4 ] && n=4; [ "$n" -lt 1 ] && n=1; echo "$n"; }

# -----------------------------------------------------------------------------
# What this container has.
# -----------------------------------------------------------------------------
# A limit docker put on the container wins over what the host has: /proc and nproc
# report the HOST, and a container confined to 2 GB of a 64 GB machine must be sized for
# the 2 GB. cgroup v2 says "max" where there is no limit.
detect_cores() {
    local quota period
    if read -r quota period < /sys/fs/cgroup/cpu.max 2>/dev/null && [ "$quota" != "max" ] && [ "${period:-0}" -gt 0 ]; then
        local limited=$(( (quota + period - 1) / period ))
        [ "$limited" -lt 1 ] && limited=1
        echo "$limited"

        return
    fi
    nproc
}

detect_memory_mb() {
    local limit
    limit="$(cat /sys/fs/cgroup/memory.max 2>/dev/null || echo max)"
    if [ "$limit" != "max" ]; then
        echo $(( limit / 1048576 ))

        return
    fi
    awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo
}

detect_swap_mb() {
    local limit
    limit="$(cat /sys/fs/cgroup/memory.swap.max 2>/dev/null || echo max)"
    if [ "$limit" != "max" ]; then
        echo $(( limit / 1048576 ))

        return
    fi
    awk '/^SwapTotal:/ { print int($2 / 1024) }' /proc/meminfo
}

# Set `key = value` in a pool file, replacing the line that sets it or appending one.
set_pool_value() {
    local file="$1" key="$2" value="$3"
    if grep -qE "^;?[[:space:]]*${key//./\\.}[[:space:]]*=" "$file"; then
        sed -i -E "s|^;?[[:space:]]*${key//./\\.}[[:space:]]*=.*|${key} = ${value}|" "$file"
    else
        echo "${key} = ${value}" >> "$file"
    fi
}

write_pool() {
    local file="$1" workers="$2" standby="$3"

    set_pool_value "$file" pm dynamic
    set_pool_value "$file" pm.max_children "$workers"
    set_pool_value "$file" pm.start_servers "$standby"
    set_pool_value "$file" pm.min_spare_servers "$standby"
    set_pool_value "$file" pm.max_spare_servers "$standby"
}

# Write a file whole, readable by everyone (MySQL ignores a world-writable config file).
write_file() {
    local file="$1" content="$2"
    mkdir -p "$(dirname "$file")" || die "could not create $(dirname "$file")"
    printf '%s\n' "$content" > "$file" || die "could not write ${file}"
    chmod 644 "$file"
}

# -----------------------------------------------------------------------------
# The budget.
# -----------------------------------------------------------------------------
mode="${1:-}"

case "$mode" in
    plan)
        [ $# -ge 4 ] || { echo "usage: $0 plan <cpu_cores> <memory_mb> <swap_mb> [dev|prod]" >&2; exit 2; }
        cores="$2"; memory_mb="$3"; swap_mb="$4"; target="${5:-prod}"
        ;;
    apply)
        target="${2:-}"
        for arg in "${@:3}"; do
            case "$arg" in
                --root=*) ROOT="${arg#--root=}" ;;
                *) echo "usage: $0 apply <dev|prod> [--root=<dir>]" >&2; exit 2 ;;
            esac
        done
        cores="$(detect_cores)"; memory_mb="$(detect_memory_mb)"; swap_mb="$(detect_swap_mb)"
        ;;
    *)
        echo "usage: $0 apply <dev|prod> [--root=<dir>] | plan <cpu_cores> <memory_mb> <swap_mb> [dev|prod]" >&2
        exit 2
        ;;
esac

case "$target" in
    dev|prod) ;;
    *) echo "usage: the target is dev or prod, got '${target}'" >&2; exit 2 ;;
esac

default_limit=320
[ "$target" = "dev" ] && default_limit=256

take_switch limit_mb RSPADE_PHP_MEMORY_LIMIT_MB "$default_limit" 1
take_switch typical_mb RSPADE_PHP_TYPICAL_MB 128 1
take_switch per_core RSPADE_PHP_WORKERS_PER_CORE 4 1
take_switch extra_mb RSPADE_RESERVED_MB 0 0
take_switch innodb_mb MYSQL_INNODB_BUFFER_MB 64 1
take_switch redis_mb REDIS_MAXMEMORY_MB "$(redis_cap "$memory_mb")" 1

# The task pools: the container environment, else the project .env line - the framework
# reads the same lines, so the budget must count what it will run.
for name in RSX_TASK_MAX_WORKERS RSX_TASK_SCHEDULED_WORKERS; do
    if [ -z "${!name:-}" ] && [ "$mode" = "apply" ]; then
        printf -v "$name" '%s' "$(project_env_value "$name")"
    fi
done
take_switch on_demand RSX_TASK_MAX_WORKERS "" 1
take_switch scheduled RSX_TASK_SCHEDULED_WORKERS "" 1
take_switch total PHP_FPM_WORKER_COUNT "" 2

read -r reserved scratch_mb redis_reserved_mb mysql_mb <<< "$(reserved_mb "$memory_mb" "$redis_mb" "$target" "$innodb_mb" "$extra_mb")"
read -r total scheduled on_demand ceiling by_ram by_worst by_cpu processes capped <<< "$(budget "$cores" "$memory_mb" "$swap_mb" "$reserved" "$typical_mb" "$limit_mb" "$per_core" "$total" "$scheduled" "$on_demand")"
tasks=$(( scheduled + on_demand ))

# The notice: the cap held this box back, and nobody has set the counts by hand.
cap_notice=false
if [ "$capped" = true ] && [ "$total_source" = calculated ] && [ "$scheduled_source" = calculated ] && [ "$on_demand_source" = calculated ]; then
    cap_notice=true
fi

web="$(web_workers "$total")"
ajax="$(ajax_workers "$total" "$web")"
web_standby="$(standby_for "$web")"
ajax_standby="$(standby_for "$ajax")"

php_ram_mb=$(( memory_mb - reserved ))
[ "$php_ram_mb" -lt 0 ] && php_ram_mb=0
php_worst_mb=$(( memory_mb + swap_mb - reserved ))
[ "$php_worst_mb" -lt 0 ] && php_worst_mb=0

if [ "$mode" = "plan" ]; then
    echo "machine   ${cores} cores, ${memory_mb} MB memory, ${swap_mb} MB swap (target ${target})"
    echo "redis     ${redis_mb} MB (${redis_mb_source})"
    echo "reserved  ${reserved} MB = ${FIXED_RESERVED_MB} fixed + ${scratch_mb} scratch + ${redis_reserved_mb} redis + ${mysql_mb} mysql + ${extra_mb} extra"
    echo "php       memory_limit ${limit_mb} MB, typical ${typical_mb} MB, ${per_core} per core"
    echo "ceilings  processes: ram ${by_ram}, ram+swap ${by_worst}; php-fpm: cpu ${by_cpu} -> ${ceiling}"
    echo "php-fpm   ${total} (${total_source}): web ${web} (${web_standby} standby), ajax ${ajax} (${ajax_standby} standby)"
    echo "tasks     ${tasks}: ${scheduled} scheduled (${scheduled_source}) + ${on_demand} on demand (${on_demand_source})"
    [ "$cap_notice" = true ] && echo "notice    capped at ${PROCESS_CAP} PHP processes - past this, set the counts and size the database (rsx:man server_sizing)"
    echo "total=${total} web=${web} web_standby=${web_standby} ajax=${ajax} ajax_standby=${ajax_standby} tasks=${tasks} scheduled=${scheduled} on_demand=${on_demand} redis_mb=${redis_mb} limit_mb=${limit_mb}"
    exit 0
fi

# -----------------------------------------------------------------------------
# apply: write it.
# -----------------------------------------------------------------------------
php_version="${PHP_VERSION:-$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')}"
php_dir="${ROOT}/etc/php/${php_version}"

# The pools: production only. The development pools keep their small on-demand sizes -
# a developer's box is not sized for traffic - but the budget is still recorded.
if [ "$target" = "prod" ]; then
    pool_dir="${php_dir}/fpm/pool.d"
    for pool in www ajax; do
        [ -f "${pool_dir}/${pool}.conf" ] || die "${pool_dir}/${pool}.conf does not exist; php-fpm pools were not sized."
    done
    write_pool "${pool_dir}/www.conf" "$web" "$web_standby"
    write_pool "${pool_dir}/ajax.conf" "$ajax" "$ajax_standby"
fi

# The memory limit, one number for php-fpm and the CLI: a task worker, a cron run and a
# command are PHP processes in the same budget as a request.
memory_ini="; Written by rspade-sizing at container start. Change it with RSPADE_PHP_MEMORY_LIMIT_MB.
memory_limit = ${limit_mb}M"
write_file "${php_dir}/fpm/conf.d/95-rspade-memory.ini" "$memory_ini"
write_file "${php_dir}/cli/conf.d/95-rspade-memory.ini" "$memory_ini"

write_file "${ROOT}/etc/redis/rspade-memory.conf" "# Written by rspade-sizing at container start. Change it with REDIS_MAXMEMORY_MB.
maxmemory ${redis_mb}mb"

if [ "$target" = "dev" ]; then
    write_file "${ROOT}/etc/mysql/mysql.conf.d/95-rspade-sizing.cnf" "# Written by rspade-sizing at container start. Change it with MYSQL_INNODB_BUFFER_MB.
[mysqld]
innodb_buffer_pool_size = ${innodb_mb}M"
fi

write_file "${ROOT}/var/lib/rspade/sizing.json" "{
    \"target\": \"${target}\",
    \"machine\": {\"cores\": ${cores}, \"memory_mb\": ${memory_mb}, \"swap_mb\": ${swap_mb}},
    \"reserved_mb\": {\"total\": ${reserved}, \"fixed\": ${FIXED_RESERVED_MB}, \"scratch\": ${scratch_mb}, \"redis\": ${redis_reserved_mb}, \"mysql\": ${mysql_mb}, \"extra\": ${extra_mb}},
    \"php\": {
        \"memory_limit_mb\": ${limit_mb}, \"memory_limit_source\": \"${limit_mb_source}\",
        \"typical_mb\": ${typical_mb}, \"typical_source\": \"${typical_mb_source}\",
        \"workers_per_core\": ${per_core}, \"workers_per_core_source\": \"${per_core_source}\",
        \"ram_mb\": ${php_ram_mb}, \"ram_and_swap_mb\": ${php_worst_mb}
    },
    \"ceilings\": {\"ram\": ${by_ram}, \"ram_and_swap\": ${by_worst}, \"cpu\": ${by_cpu}, \"processes\": ${processes}, \"process_cap\": ${PROCESS_CAP}, \"capped\": ${capped}, \"cap_notice\": ${cap_notice}, \"winner\": \"${ceiling}\"},
    \"php_fpm\": {\"total\": ${total}, \"source\": \"${total_source}\", \"web\": ${web}, \"web_standby\": ${web_standby}, \"ajax\": ${ajax}, \"ajax_standby\": ${ajax_standby}, \"pools_sized\": $([ "$target" = "prod" ] && echo true || echo false)},
    \"tasks\": {\"total\": ${tasks}, \"scheduled\": ${scheduled}, \"scheduled_source\": \"${scheduled_source}\", \"on_demand\": ${on_demand}, \"on_demand_source\": \"${on_demand_source}\"},
    \"redis\": {\"maxmemory_mb\": ${redis_mb}, \"source\": \"${redis_mb_source}\"},
    \"mysql\": {\"innodb_buffer_pool_mb\": $([ "$target" = "dev" ] && echo "${innodb_mb}" || echo null)}
}"

echo "[rspade] sized for ${cores} cores, ${memory_mb} MB memory, ${swap_mb} MB swap: php-fpm ${total} (web ${web}, ajax ${ajax}$([ "$target" = "dev" ] && echo ', development pools unchanged')), ${tasks} task workers (${scheduled} scheduled, ${on_demand} on demand), memory_limit ${limit_mb}M, redis ${redis_mb} MB."
if [ "$cap_notice" = true ]; then
    echo "[rspade] NOTICE: this machine could run more than ${PROCESS_CAP} PHP processes, the most the automatic sizing plans. Past it, set PHP_FPM_WORKER_COUNT, RSX_TASK_SCHEDULED_WORKERS and RSX_TASK_MAX_WORKERS yourself and configure the database server to accept that many connections: php artisan rsx:man server_sizing."
fi
