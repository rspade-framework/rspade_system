<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Rsx_Container_Sizing - the resource budget the RSpade container chose for this machine.
 *
 * At every start the container's entrypoint runs rspade-sizing (resource/docker/sizing.sh,
 * which holds the formula and its reasoning): it works out the php-fpm pools, the task
 * workers, the memory_limit php-fpm and the CLI share and the Redis cap from the cores,
 * memory and swap it measures - or from the switches it was started with - and records
 * every number, and where each came from, in FILE. This class reads that record.
 *
 * read() answers null outside the RSpade container, or in a container whose image predates
 * the record: nothing sized this box, and a caller falls back to its own default.
 */
class Rsx_Container_Sizing
{
    /** Written by rspade-sizing at every container start. */
    public const FILE = '/var/lib/rspade/sizing.json';

    /**
     * Test seam: read this file instead of FILE. Nothing outside a test run may set it, and a
     * test that sets it must clear it (and call _forget()).
     *
     * @var string|null
     */
    public static ?string $file_for_tests = null;

    /**
     * Test seam: the marker file log_cap_notice() keeps instead of NOTICE_MARKER under the
     * state root. Nothing outside a test run may set it.
     *
     * @var string|null
     */
    public static ?string $notice_marker_for_tests = null;

    /** Under the state root: which sizing record the cap notice was last written for. */
    private const NOTICE_MARKER = 'sizing-cap-notice';

    /** The Laravel log line for a box the process cap held back. */
    public const CAP_NOTICE = 'This machine could run more PHP processes than the automatic sizing plans (%d).'
        . ' Past that cap, set PHP_FPM_WORKER_COUNT, RSX_TASK_SCHEDULED_WORKERS and RSX_TASK_MAX_WORKERS'
        . ' in the container\'s environment and configure the database server to accept that many'
        . ' connections - see php artisan rsx:man server_sizing.';

    /** @var array|false|null false = read, and absent */
    private static $__budget = null;

    /**
     * The recorded budget, as sizing.sh writes it: target, machine {cores, memory_mb,
     * swap_mb}, reserved_mb {total, fixed, scratch, redis, mysql, extra}, php
     * {memory_limit_mb, typical_mb, workers_per_core, ram_mb, ram_and_swap_mb, *_source},
     * ceilings {ram, ram_and_swap, cpu, processes, process_cap, capped, cap_notice, winner},
     * php_fpm {total, web, ajax, ...}, tasks {total, scheduled, scheduled_source, on_demand,
     * on_demand_source}, redis {maxmemory_mb, source}, mysql.
     * Null when nothing sized this box. Read once per process: the record changes only when
     * the container restarts, and so does every process reading it.
     */
    public static function read(): ?array
    {
        if (self::$__budget === null) {
            $file = static::$file_for_tests ?? self::FILE;
            $budget = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$__budget = is_array($budget) ? $budget : false;
        }

        return self::$__budget === false ? null : self::$__budget;
    }

    /**
     * Write CAP_NOTICE to the Laravel log ONCE per container start, when the process cap
     * held this box back and nobody set the worker counts by hand (the record's cap_notice).
     * The sizing step runs before Laravel can boot, so the cron tick calls this: the record
     * is identified by its modification time, and the marker remembers the last one noticed.
     * Answers whether it wrote.
     */
    public static function log_cap_notice(): bool
    {
        $budget = static::read();
        if (empty($budget['ceilings']['cap_notice'])) {
            return false;
        }

        $file = static::$file_for_tests ?? self::FILE;
        $identity = (string) @filemtime($file) . ':' . (string) @filesize($file);
        $marker = static::$notice_marker_for_tests ?? Rsx_Project_Paths::state_path(self::NOTICE_MARKER);

        if (is_file($marker) && trim((string) file_get_contents($marker)) === $identity) {
            return false;
        }

        Log::warning(sprintf(self::CAP_NOTICE, (int) $budget['ceilings']['process_cap']));
        file_put_contents($marker, $identity);

        return true;
    }

    /** Forget what read() read. FRAMEWORK INTERNAL - the test harness. */
    public static function _forget(): void
    {
        self::$__budget = null;
    }
}
