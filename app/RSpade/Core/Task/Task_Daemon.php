<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Kill_Worker;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Task_Daemon - the application's side of rspade-taskd, the task scheduler daemon the RSpade
 * container runs (system/app/RSpade/resource/docker/rspade-taskd/).
 *
 * Outside the container the scheduler is the cron line, `* * * * * php artisan
 * rsx:task:process`, a tick every minute. In the container the daemon runs the SAME tick only
 * when there is something for it to do: at the moment the tick says the next piece of work is
 * due (next_wake_ms(), printed under --_report-next-wake), and whenever the application POKES
 * it because that moment may have moved earlier. The tick is unchanged otherwise - it reaps,
 * reconciles and spawns exactly as under cron - so the two schedulers are one implementation.
 *
 * THE DUE RULES live here too, because the tick, the worker and the next-wake answer must agree
 * on them: a SCHEDULE (and the pending run a schedule produced) is due SCHEDULE_LEAD_SECONDS
 * before its time, so a wake a moment early still runs the 3:00 run; a DISPATCHED run is due at
 * its time exactly, so a short #[Debounce] delay is never cut short.
 */
class Task_Daemon
{
    /** The daemon's socket (rspade-taskd.js DEFAULTS.socket). */
    public const SOCKET = '/run/rspade-taskd/taskd.sock';

    /** The file every RSpade container carries; the daemon runs only there. */
    public const CONTAINER_MARKER = '/.rspade_container';

    /**
     * How early a schedule is due. A timer wake, a clock correction or a tick that lands a
     * moment before 3:00 must still run the 3:00 run. Taking it a few seconds early is
     * harmless; missing it until the next wake is not.
     */
    public const SCHEDULE_LEAD_SECONDS = 5;

    /**
     * While anything is running (or a kill is owed on this host) the tick is wanted at least
     * this often: it is what reaps a run whose worker died and enforces the silence limit
     * (rsx.tasks.silent_timeout) and a run's own timeout, at the one-minute granularity the
     * cron line has always given them.
     */
    public const BUSY_RECHECK_SECONDS = 60;

    /**
     * Tell the daemon the next due moment may have moved earlier: a run was dispatched, a
     * schedule's next run was set, a worker exited, the code was rebuilt, the task system was
     * turned on. Nothing is waited for and nothing is answered.
     *
     * A no-op outside the container, under the test suite (no process there spawns workers -
     * Task::spawning_workers()), and when no daemon answers: every caller already does its own
     * work without it (a dispatch spawns its worker itself), and the daemon's own timer covers
     * the rest. A connect to a unix socket nobody listens on is refused at once.
     */
    public static function poke(): void
    {
        if (!Task::spawning_workers() || !file_exists(self::CONTAINER_MARKER) || !file_exists(self::SOCKET)) {
            return;
        }

        $socket = @stream_socket_client('unix://' . self::SOCKET, $errno, $error, 0);
        if ($socket === false) {
            return;
        }

        @fwrite($socket, "poke\n");
        fclose($socket);
    }

    /**
     * The moment a SCHEDULE is due by, as the tick and the worker compare next_run_at.
     */
    public static function schedule_due_cutoff(): string
    {
        return now()->addSeconds(self::SCHEDULE_LEAD_SECONDS)->format('Y-m-d H:i:s.v');
    }

    /**
     * Narrow a query of PENDING runs to the due ones: a dispatched run at its time, a run a
     * schedule produced SCHEDULE_LEAD_SECONDS early.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Query\Builder
     */
    public static function where_pending_due($query)
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $schedule_cutoff = self::schedule_due_cutoff();

        return $query->where(function ($due) use ($now, $schedule_cutoff) {
            $due->where('scheduled_for', '<=', $now)
                ->orWhere(function ($scheduled) use ($schedule_cutoff) {
                    $scheduled->where('origin_id', Task_Run_Model::ORIGIN_SCHEDULED)
                        ->where('scheduled_for', '<=', $schedule_cutoff);
                });
        });
    }

    /**
     * The schedule's next run, advanced from the LATER of now and the time it was due. A
     * schedule claimed a moment early (SCHEDULE_LEAD_SECONDS) must advance past the run it is
     * claiming - computed from now, 2:59:56 would advance a 3:00 schedule to 3:00 again, and
     * the task would run twice.
     */
    public static function next_run_after(Cron_Parser $parser, string $due_at): int
    {
        return $parser->get_next_run_time(max(time(), (int) strtotime($due_at)));
    }

    /**
     * How long until the tick should run again, in milliseconds - or null when nothing is
     * waiting at all (the daemon then sleeps until poked, or its ceiling).
     *
     * The earliest of: the next schedule not yet due, the next pending run not yet due (a
     * deferred dispatch, a #[Debounce] delay, an abandoned run's retry), and - while any run is
     * RUNNING or a kill is owed on this host - BUSY_RECHECK_SECONDS. What is due NOW is not in
     * it: the tick that computes this has just spawned for it, and what it could not spawn for
     * (a full pool) is drained by the workers already running, whose exits poke.
     */
    public static function next_wake_ms(): ?int
    {
        $now_ms = (int) floor(microtime(true) * 1000);
        $candidates = [];

        $schedule = DB::table('_task_schedules')
            ->where('next_run_at', '>', self::schedule_due_cutoff())
            ->min('next_run_at');
        if ($schedule !== null) {
            // A schedule is due SCHEDULE_LEAD_SECONDS early, so that is when to wake for it.
            $candidates[] = static::__ms_until($schedule, $now_ms) - self::SCHEDULE_LEAD_SECONDS * 1000;
        }

        $pending = DB::table('_tasks')
            ->where('status_id', Task_Run_Model::STATUS_PENDING)
            ->where('scheduled_for', '>', now()->format('Y-m-d H:i:s.v'))
            ->min('scheduled_for');
        if ($pending !== null) {
            $candidates[] = static::__ms_until($pending, $now_ms);
        }

        $busy = DB::table('_tasks')->where('status_id', Task_Run_Model::STATUS_RUNNING)->exists()
            || Task_Kill_Worker::has_due_requests(Task_Pool::host());
        if ($busy) {
            $candidates[] = self::BUSY_RECHECK_SECONDS * 1000;
        }

        if ($candidates === []) {
            return null;
        }

        return max(0, min($candidates));
    }

    private static function __ms_until(string $moment, int $now_ms): int
    {
        return (int) floor(\Illuminate\Support\Carbon::parse($moment)->getPreciseTimestamp(3)) - $now_ms;
    }
}
