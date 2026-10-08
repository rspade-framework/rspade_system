#!/bin/bash
#
# rspade-php-fpm-workers - size the php-fpm pools to the machine (production target).
#
#   rspade-php-fpm-workers apply [pool_dir]
#       Work out the pool sizes for THIS container and write them into the php-fpm pool
#       files of the installed PHP version (or the www.conf and ajax.conf in pool_dir).
#       The entrypoint runs it before supervisor starts php-fpm.
#
#   rspade-php-fpm-workers plan <cpu_cores> <memory_mb> <swap_mb> [worker_count]
#       Print the sizes for the given machine and change nothing. worker_count, when
#       given, is used as the total instead of being calculated.
#
# PHP_FPM_WORKER_COUNT in the container's environment (docker run -e, compose
# `environment:`) sets the total explicitly; `apply` then skips the calculation and only
# derives the split and the standby counts from it.
#
set -u

# The memory one php-fpm worker may use: memory_limit in the production php.ini
# (php/php-prod.ini). The two numbers are one fact - change them together.
WORKER_MB=320

# -----------------------------------------------------------------------------
# The total worker count for a machine.
# -----------------------------------------------------------------------------
#
#   count = max(1, min(cores * 3,
#                      floor( (memory_mb - 1000) * 0.9 / 320
#                             + floor(min(swap_mb, 1000) / 450)
#                             - 4 )))
#
# WHY IT IS SHAPED THIS WAY. A php-fpm worker is, at worst, memory_limit of RAM, so the
# number a box can run is a question about RAM before it is one about anything else:
#
#   - (memory - 1000 MB) * 0.9: the first 1000 MB and a further tenth of the rest are
#     not ours. They are the operating system, its page cache, and room to breathe.
#     Swap is excluded from "memory" - a worker that lives in swap is not serving.
#   - / 320: how many workers fit in what is left, each at its memory limit.
#   - + up to 2 for swap: a box with swap can absorb a brief overshoot, so it is
#     trusted with one more worker per 450 MB of swap, counting at most 1000 MB of it.
#   - - 4: one worker's worth of RAM for Redis and the other RSpade services that run
#     beside php-fpm, and three for the background task workers, which are PHP
#     processes of the same size.
#   - min(cores * 3): past three workers a core, more workers queue for CPU rather
#     than serve, whatever the RAM allows.
#   - max(1): a box too small for the arithmetic still has to answer.
#
worker_count() {
    local cores="$1" memory_mb="$2" swap_mb="$3"

    awk -v cores="$cores" -v mem="$memory_mb" -v swap="$swap_mb" -v worker="$WORKER_MB" 'BEGIN {
        counted_swap = swap < 1000 ? swap : 1000
        by_memory = (mem - 1000) * 0.9 / worker + int(counted_swap / 450) - 4
        by_memory = (by_memory >= 0) ? int(by_memory) : -1
        by_cpu = cores * 3
        count = by_memory < by_cpu ? by_memory : by_cpu
        print (count < 1 ? 1 : count)
    }'
}

# -----------------------------------------------------------------------------
# The split between the two pools, and each pool's standby.
# -----------------------------------------------------------------------------
#
# The total is four shares: ONE for the web pool (page requests) and THREE for the Ajax
# pool - a page is one request and then every piece of data on it is an Ajax call. Web
# rounds down and Ajax rounds up, and each has a floor that wins over the total: 2 web
# workers, so one slow page cannot make the site unreachable, and 1 Ajax worker.
#
# STANDBY is how many idle workers a pool keeps waiting: min(4, floor(pool / 2)), and
# never fewer than 1 - php-fpm's dynamic mode requires a spare worker, and a pool with
# nobody waiting makes the first visitor pay for a process start.
web_workers()  { local n=$(( $1 / 4 ));       [ "$n" -lt 2 ] && n=2; echo "$n"; }
ajax_workers() { local n=$(( ($1 * 3 + 3) / 4 )); [ "$n" -lt 1 ] && n=1; echo "$n"; }
standby_for()  { local n=$(( $1 / 2 ));       [ "$n" -gt 4 ] && n=4; [ "$n" -lt 1 ] && n=1; echo "$n"; }

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

mode="${1:-}"

case "$mode" in
    plan)
        [ $# -ge 4 ] || { echo "usage: $0 plan <cpu_cores> <memory_mb> <swap_mb> [worker_count]" >&2; exit 2; }
        total="${5:-$(worker_count "$2" "$3" "$4")}"
        ;;
    apply)
        explicit="${PHP_FPM_WORKER_COUNT:-}"
        if [ -n "$explicit" ]; then
            case "$explicit" in
                ''|*[!0-9]*|0) echo "[rspade] ERROR: PHP_FPM_WORKER_COUNT must be a whole number of 1 or more, got '${explicit}'." >&2; exit 1 ;;
            esac
            total="$explicit"
            source_note="PHP_FPM_WORKER_COUNT"
        else
            cores="$(detect_cores)"
            memory_mb="$(detect_memory_mb)"
            swap_mb="$(detect_swap_mb)"
            total="$(worker_count "$cores" "$memory_mb" "$swap_mb")"
            source_note="${cores} cores, ${memory_mb} MB memory, ${swap_mb} MB swap"
        fi
        ;;
    *)
        echo "usage: $0 apply [pool_dir] | plan <cpu_cores> <memory_mb> <swap_mb> [worker_count]" >&2
        exit 2
        ;;
esac

web="$(web_workers "$total")"
ajax="$(ajax_workers "$total")"
web_standby="$(standby_for "$web")"
ajax_standby="$(standby_for "$ajax")"

if [ "$mode" = "plan" ]; then
    echo "total=${total} web=${web} web_standby=${web_standby} ajax=${ajax} ajax_standby=${ajax_standby}"
    exit 0
fi

php_version="${PHP_VERSION:-$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')}"
pool_dir="${2:-/etc/php/${php_version}/fpm/pool.d}"

for pool in www ajax; do
    [ -f "${pool_dir}/${pool}.conf" ] || { echo "[rspade] ERROR: ${pool_dir}/${pool}.conf does not exist; php-fpm pools were not sized." >&2; exit 1; }
done

write_pool "${pool_dir}/www.conf" "$web" "$web_standby"
write_pool "${pool_dir}/ajax.conf" "$ajax" "$ajax_standby"

echo "[rspade] php-fpm sized from ${source_note}: ${total} worker(s) -> web ${web} (${web_standby} standby), ajax ${ajax} (${ajax_standby} standby)."
