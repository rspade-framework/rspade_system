<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT: rsx:build AND rsx:clean REFUSE WHILE THE CONTAINER IS STILL STARTING.
 *
 * The RSpade container's entrypoint works in build/ and tmp/ while it starts: it hands them
 * to the application user (chown -R), and in development it migrates, which boots the
 * framework and may build the manifest there. A build or a clean started from outside at the
 * same moment - `docker compose exec app php artisan rsx:build` straight after `up --detach` -
 * races that work: files it writes after the ownership pass stay root-owned, and the two
 * rewrite the same trees. So both commands refuse until the start has finished, naming the
 * step it is on and the command that waits for it.
 *
 * The record is the entrypoint's (resource/docker/startup-state.sh, /run/rspade-startup).
 * It refuses only when ALL of these hold:
 *   - this is the RSpade container (/.rspade_container);
 *   - the record says status=starting;
 *   - its pid1_start is the live PID 1's start time (a record from an earlier start of the
 *     container means nothing);
 *   - this process does NOT descend from PID 1. The entrypoint's own children (its migrate)
 *     and everything supervisor runs descend from PID 1 and are part of the start; a process
 *     entered with `docker exec` has a parent outside the container's pid namespace, so its
 *     chain of parents reaches 0 without passing 1.
 * No record (an image older than the record, bare metal, a container started some other way)
 * never refuses.
 *
 * Required by bootstrap/rsx_preboot.php for argv[1] rsx:build or rsx:clean, before any boot:
 * a boot during the start can die on a service that is not up yet, which would hide the
 * reason. Plain filesystem reads only; rsx_container_starting() is pure over its paths, so a
 * test calls it with scratch files.
 */

if (!function_exists('rsx_container_starting')) {

/**
 * Whether the container is still starting, from the point of view of process $pid.
 *
 * @param string   $record    The startup record (/run/rspade-startup).
 * @param string   $marker    The container marker (/.rspade_container).
 * @param string   $proc_root The /proc to read PID 1 and the parent chain from.
 * @param int|null $pid       The process asking; null = this one.
 * @return array|null ['step' => ..., 'began_at' => ...] when it must refuse, else null.
 */
function rsx_container_starting(string $record, string $marker, string $proc_root = '/proc', ?int $pid = null): ?array
{
    if (!is_file($marker) || !is_file($record)) {
        return null;
    }

    $values = [];
    foreach (preg_split('/\R/', (string) @file_get_contents($record)) as $line) {
        $at = strpos($line, '=');
        if ($at !== false) {
            $values[substr($line, 0, $at)] = substr($line, $at + 1);
        }
    }

    if (($values['status'] ?? '') !== 'starting') {
        return null;
    }

    $live = rsx_container_starting_pid1_start($proc_root);
    if ($live === null || ($values['pid1_start'] ?? '') !== $live) {
        return null;
    }

    if (rsx_container_starting_descends_from_pid1($proc_root, $pid ?? getmypid())) {
        return null;
    }

    return [
        'step' => (string) ($values['step'] ?? ''),
        'began_at' => (string) ($values['began_at'] ?? ''),
    ];
}

/**
 * Field $field (1-based, as proc(5) numbers them) of <proc_root>/<pid>/stat, or null. The
 * command name (field 2) may hold spaces and parentheses, so fields are counted after its
 * last ')'.
 */
function rsx_container_starting_stat_field(string $proc_root, int $pid, int $field): ?string
{
    $stat = @file_get_contents("{$proc_root}/{$pid}/stat");
    if (!is_string($stat)) {
        return null;
    }
    $close = strrpos($stat, ')');
    if ($close === false) {
        return null;
    }
    $rest = preg_split('/\s+/', trim(substr($stat, $close + 1)));

    return $rest[$field - 3] ?? null;
}

/** PID 1's start time (field 22), or null when it cannot be read. */
function rsx_container_starting_pid1_start(string $proc_root): ?string
{
    return rsx_container_starting_stat_field($proc_root, 1, 22);
}

/**
 * Whether $pid is PID 1 or one of its descendants: the chain of parents (field 4) reaches 1.
 * A chain that reaches 0, cannot be read, or loops is not a descent from PID 1.
 */
function rsx_container_starting_descends_from_pid1(string $proc_root, int $pid): bool
{
    $seen = [];
    while ($pid > 0 && !isset($seen[$pid])) {
        if ($pid === 1) {
            return true;
        }
        $seen[$pid] = true;
        $parent = rsx_container_starting_stat_field($proc_root, $pid, 4);
        if ($parent === null || !ctype_digit($parent)) {
            return false;
        }
        $pid = (int) $parent;
    }

    return false;
}

/**
 * Refuse $command (rsx:build or rsx:clean) and exit 1 while the container is still starting.
 */
function rsx_container_starting_refuse(string $command): void
{
    $starting = rsx_container_starting('/run/rspade-startup', '/.rspade_container');
    if ($starting === null) {
        return;
    }

    $step = $starting['step'] !== '' ? $starting['step'] : 'beginning';
    fwrite(STDERR, "[ERROR] The container is still starting (step: {$step}, since {$starting['began_at']}).\n");
    fwrite(STDERR, "  rsx:build and rsx:clean rewrite build/ and tmp/, which the start is still working in\n");
    fwrite(STDERR, "  (ownership, migrations). Wait for it, then run {$command} again:\n");
    fwrite(STDERR, "      rspade-wait-started\n");
    exit(1);
}

}
