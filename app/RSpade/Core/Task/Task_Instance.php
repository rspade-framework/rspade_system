<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Rsx_Temp_Files;
use App\RSpade\Core\Files\Temp_File_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task_Attachment_Model;
use App\RSpade\Core\Task\Task_Notify;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Task_Instance - the `$task` handle every task method receives: how a running task REPORTS.
 *
 * Every report is optional; a task uses whichever describe its work:
 *
 *   heartbeat()                      "still alive" - stamps last_heartbeat_at
 *   status($text)                    one line of status, replacing the last (also a stderr line)
 *   progress($percent)               0-100, two decimals
 *   progress_count($done, $total)    "3 of 257"
 *   eta($seconds)                    seconds from now until done (stored as a moment)
 *   state($array_or_object)          a JSON state object, replacing the last
 *   queue_push() / queue_push_many() the QUEUE: the items the task has ahead of it, one row
 *   queue_pop() / queue_remove()     per item, advanced as the work advances - the report
 *   queue_clear() / queue_depth()    that shows whether a run is moving or stuck
 *   message($text)                   a message for whoever is watching (kept, in order)
 *   stdout($text) / stderr($text)    output lines; echo / print are captured as stdout too
 *   attach_file() / attach_bytes()   a named file for outside consumers to retrieve
 *   summary($text)                   the completion summary
 *
 * and the run's RETURN VALUE is its return code: null, true or 0 is success; false or any
 * other integer is failure (Task_Run_Outcome).
 *
 * WRITE RATE, NOT A TIMEOUT. A task may report on every item of a long loop, and writing each
 * report would be a database write and a realtime frame per item. Reports are therefore held
 * in memory and written together: the first report after a quiet spell is written at once,
 * and further reports within FLUSH_INTERVAL of the last write wait for the first reporting
 * call or is_stop_requested() after the interval, a flush() or the end of the run - whichever
 * comes first. A task
 * about to go quiet for a long step calls flush() so its watchers see the latest state.
 * Attachments are written immediately.
 *
 * Every run - dispatched, scheduled or inline - has its _tasks row, so every report is
 * recorded the same way wherever the task runs. A console runner (rsx:task:run, a #[Command])
 * also hands the instance its own stdout/stderr, and output lines are echoed there live.
 */
#[Instantiatable]
class Task_Instance
{
    /** Seconds between two writes of held reports. A write rate; see the class docblock. */
    const FLUSH_INTERVAL = 0.25;

    /** status_text is a VARCHAR(1000); a longer status is cut to fit. */
    const STATUS_TEXT_MAX = 1000;

    private int $id;
    private string $class;
    private string $method;
    private array $params;
    private ?string $temp_dir = null;

    /** @var resource|null The runner's stdout, or null. */
    private $stdout_sink = null;

    /** @var resource|null The runner's stderr, or null. */
    private $stderr_sink = null;

    /** _tasks columns waiting to be written. */
    private array $pending_row = [];

    /** _task_reports bodies waiting to be written, by kind id. */
    private array $pending_reports = [];

    /** Output lines waiting to be written: [stream_id, line, at]. */
    private array $pending_output = [];

    /** Messages waiting to be written: [body, at]. */
    private array $pending_messages = [];

    /** microtime(true) of the last write; 0 means none yet, so the first report goes at once. */
    private float $last_flush = 0.0;

    /** The status text last reported, so an unchanged status writes no stderr line. */
    private ?string $last_status_text = null;

    /** _tasks.has_queue: the run's queue has held an item, so the queue report exists. */
    private bool $has_queue = false;

    /** Queue items waiting to be appended, JSON-encoded, in order. */
    private array $pending_queue_pushes = [];

    /** Stored queue rows waiting to be removed from the head. */
    private int $pending_queue_pops = 0;

    /** Every stored queue row is waiting to be removed, before the pops and pushes above. */
    private bool $pending_queue_clear = false;

    /**
     * How many rows the stored queue holds, not counting what is waiting above; null until
     * first needed. This instance is the queue's only writer, so it counts once and keeps
     * the number itself.
     */
    private ?int $queue_stored = null;

    private function __construct(int $id, string $class, string $method, array $params)
    {
        $this->id = $id;
        $this->class = $class;
        $this->method = $method;
        $this->params = $params;
    }

    /**
     * The instance for one run's row.
     */
    public static function for_row(Task_Run_Model $row): self
    {
        $instance = new self((int) $row->id, (string) $row->class, (string) $row->method, $row->params ?? []);
        $instance->last_status_text = $row->status_text;
        $instance->has_queue = (bool) $row->has_queue;

        return $instance;
    }

    /**
     * Load the instance for a run by id, or null when the row does not exist.
     */
    public static function find(int $id): ?self
    {
        $row = Task_Run_Model::find($id);

        return $row === null ? null : static::for_row($row);
    }

    /**
     * RUNNER-ONLY: the console streams output lines are echoed to, live. rsx:task:run and the
     * #[Command] aliases pass their own stdout and stderr (stderr null under -q); nothing else
     * sets them, so a task run from a web request or a worker prints to nobody's console.
     *
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function set_console_streams($stdout, $stderr): void
    {
        $this->stdout_sink = $stdout;
        $this->stderr_sink = $stderr;
    }

    // ------------------------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------------------------

    /**
     * Record that the task is alive. Every write of held reports also stamps last_report_at;
     * a heartbeat is the explicit "still working" for a step that reports nothing else.
     */
    public function heartbeat(): void
    {
        $this->pending_row['last_heartbeat_at'] = static::__now();
        $this->__maybe_flush();
    }

    /**
     * Report the task's status as one line of text, replacing the last. A status that differs
     * from the last one is also written to stderr, so the console reads as a narrative.
     */
    public function status(string $text): void
    {
        $text = trim(str_replace(["\r\n", "\r", "\n"], ' ', $text));
        if (mb_strlen($text) > self::STATUS_TEXT_MAX) {
            $text = mb_substr($text, 0, self::STATUS_TEXT_MAX - 3) . '...';
        }

        if ($text === $this->last_status_text) {
            return;
        }

        $this->last_status_text = $text;
        $this->pending_row['status_text'] = $text;
        $this->__append_output(Task_Run_Model::STREAM_STDERR, $text);
        $this->__maybe_flush();
    }

    /**
     * Report progress as a percentage: 0 to 100, kept to two decimals. Values outside the
     * range are clamped.
     */
    public function progress(float $percent): void
    {
        $this->pending_row['progress_percent'] = round(max(0, min(100, $percent)), 2);
        $this->__maybe_flush();
    }

    /**
     * Report progress as a count: $done of $total items.
     */
    public function progress_count(int $done, int $total): void
    {
        if ($total < 0 || $done < 0) {
            throw new \InvalidArgumentException("progress_count() takes non-negative counts, got {$done} of {$total}.");
        }

        $this->pending_row['progress_done'] = $done;
        $this->pending_row['progress_total'] = $total;
        $this->__maybe_flush();
    }

    /**
     * Report the expected time to completion, in seconds from now. Stored as the moment it
     * names, so a viewer counts down without another report.
     */
    public function eta(int $seconds): void
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException("eta() takes a number of seconds from now, got {$seconds}.");
        }

        $this->pending_row['eta_at'] = date('Y-m-d H:i:s', time() + $seconds) . '.000';
        $this->__maybe_flush();
    }

    /**
     * Report the task's state as a JSON object (an array or object), replacing the last.
     *
     * @param array|object $state
     */
    public function state(array|object $state): void
    {
        $this->pending_reports[Task_Run_Model::REPORT_STATE_JSON] = static::__json($state, 'state()');
        $this->__maybe_flush();
    }

    // ------------------------------------------------------------------------------------
    // The queue
    // ------------------------------------------------------------------------------------
    //
    // WHAT THE QUEUE IS FOR. A percentage and a counter cannot tell a run that is stuck from
    // one working through something large; the same items sitting at the head of the queue
    // can. So the queue is advanced as the work advances - per item - and that is affordable
    // because an item is a ROW: a push appends one, a pop removes one, and nothing else moves.
    //
    // IT IS A REPORT, NOT A WORK QUEUE. The task's real work list is its own array or query;
    // these calls describe it to a watcher and return nothing a task may drive itself from.
    //
    // The report comes into being with its first ITEM. A queue that has never held one is not
    // a report the run made - queue_clear() and queue_pop() on it record nothing - and one
    // that has is an empty queue once emptied.

    /**
     * Append one item to the tail of the queue: a string, or an array recorded as JSON.
     */
    public function queue_push(string|array $item): void
    {
        $this->pending_queue_pushes[] = static::__json($item, 'queue_push()');
        $this->__maybe_flush();
    }

    /**
     * Append several items to the tail of the queue, in order. queue_clear() followed by
     * queue_push_many() DECLARES the queue: whatever it held, it now holds exactly these.
     *
     * @param array<int, string|array> $items A list (sequential keys)
     */
    public function queue_push_many(array $items): void
    {
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('queue_push_many() takes a list (sequential keys) of items.');
        }

        foreach ($items as $item) {
            if (!is_string($item) && !is_array($item)) {
                throw new \InvalidArgumentException('A queue item is a string or an array, got ' . get_debug_type($item) . '.');
            }
        }

        foreach ($items as $item) {
            $this->pending_queue_pushes[] = static::__json($item, 'queue_push_many()');
        }

        if ($items !== []) {
            $this->__maybe_flush();
        }
    }

    /**
     * Remove the head of the queue - the oldest item: "the head is done". Nothing happens on
     * an empty queue, and nothing is returned.
     */
    public function queue_pop(): void
    {
        if ($this->__queue_stored_remaining() > 0) {
            $this->pending_queue_pops++;
        } elseif ($this->pending_queue_pushes !== []) {
            array_shift($this->pending_queue_pushes);
        } else {
            return;
        }

        $this->__maybe_flush();
    }

    /**
     * Remove ONE item equal to $item - the earliest - for work finished out of order. The
     * match is the exact value that was pushed (a queue may hold duplicates, and only one
     * goes). Nothing happens when no item matches.
     *
     * Unlike the other queue calls this one is written at once: which row it removes depends
     * on what the queue holds.
     */
    public function queue_remove(string|array $item): void
    {
        $body = static::__json($item, 'queue_remove()');

        $this->flush();
        if (!$this->has_queue) {
            return;
        }

        $row = DB::selectOne(
            'SELECT id FROM _task_queue WHERE task_id = ? AND BINARY body = ? ORDER BY id LIMIT 1',
            [$this->id, $body]
        );
        if ($row === null) {
            return;
        }

        DB::table('_task_queue')->where('id', $row->id)->delete();
        if ($this->queue_stored !== null) {
            $this->queue_stored--;
        }

        Task_Notify::changed($this->id, true, false);
    }

    /**
     * Empty the queue.
     */
    public function queue_clear(): void
    {
        if (!$this->has_queue && $this->pending_queue_pushes === []) {
            return;
        }

        $this->pending_queue_pushes = [];
        $this->pending_queue_pops = 0;
        if ($this->has_queue) {
            $this->pending_queue_clear = true;
            $this->queue_stored = 0;
        }

        $this->__maybe_flush();
    }

    /**
     * How many items the queue holds.
     */
    public function queue_depth(): int
    {
        return $this->__queue_stored_remaining() + count($this->pending_queue_pushes);
    }

    /**
     * Set the completion summary: the text a reader is shown about what the run did.
     */
    public function summary(string $text): void
    {
        $this->pending_reports[Task_Run_Model::REPORT_SUMMARY] = $text;
        $this->__maybe_flush();
    }

    /**
     * Emit a message to whoever is watching the run. Messages are kept, in order, for as long
     * as the run is; a watcher reads the ones after the last it saw.
     */
    public function message(string $text): void
    {
        $this->pending_messages[] = [$text, static::__now()];
        $this->__maybe_flush();
    }

    /**
     * Write to the task's stdout: recorded one row per line, and echoed live to the runner's
     * stdout when there is one.
     */
    public function stdout(string $text): void
    {
        foreach (static::__lines($text) as $line) {
            $this->__append_output(Task_Run_Model::STREAM_STDOUT, $line);
        }
        $this->__maybe_flush();
    }

    /**
     * Write to the task's stderr: recorded one row per line, and echoed live to the runner's
     * stderr when there is one.
     */
    public function stderr(string $text): void
    {
        foreach (static::__lines($text) as $line) {
            $this->__append_output(Task_Run_Model::STREAM_STDERR, $line);
        }
        $this->__maybe_flush();
    }

    /**
     * Attach a file on disk to the run under $name, for the run's initiator to retrieve
     * (Task_Run_Model::attachment($name)). The bytes are copied into the temp file store
     * (Rsx_Temp_Files); the source file is left where it is. Attaching a name again replaces
     * the earlier file.
     */
    public function attach_file(string $name, string $path, ?string $file_name = null, ?string $mime_type = null): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("attach_file('{$name}'): {$path} is not a readable file.");
        }

        $file_name ??= basename($path);
        $this->__attach($name, fn (int $days) => Rsx_Temp_Files::store_file($path, mb_substr($file_name, 0, 255), $mime_type, $days));
    }

    /**
     * Attach bytes the task generated to the run under $name. Attaching a name again
     * replaces the earlier file.
     */
    public function attach_bytes(string $name, string $bytes, string $file_name, ?string $mime_type = null): void
    {
        $this->__attach($name, fn (int $days) => Rsx_Temp_Files::store_bytes($bytes, mb_substr($file_name, 0, 255), $mime_type, $days));
    }

    /**
     * Write every held report now. A task about to spend a long time without reporting calls
     * this so its watchers see where it is.
     */
    public function flush(): void
    {
        $queue_pending = $this->pending_queue_clear || $this->pending_queue_pops > 0 || $this->pending_queue_pushes !== [];

        if ($this->pending_row === [] && $this->pending_reports === [] && $this->pending_output === [] && $this->pending_messages === [] && !$queue_pending) {
            return;
        }

        $now = static::__now();
        $changed = $this->pending_row !== [] || $this->pending_reports !== [] || $this->pending_messages !== [] || $queue_pending;
        $output_written = $this->pending_output !== [];

        $row = $this->pending_row;
        if ($this->pending_queue_pushes !== [] && !$this->has_queue) {
            $row['has_queue'] = 1;
        }
        $row['last_report_at'] = $now;
        $row['updated_at'] = $now;
        DB::table('_tasks')->where('id', $this->id)->update($row);

        foreach ($this->pending_reports as $kind_id => $body) {
            DB::statement(
                'INSERT INTO _task_reports (task_id, kind_id, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE body = VALUES(body), updated_at = VALUES(updated_at)',
                [$this->id, $kind_id, $body, $now, $now]
            );
        }

        if ($this->pending_output !== []) {
            $rows = [];
            foreach ($this->pending_output as [$stream_id, $line, $at]) {
                $rows[] = ['task_id' => $this->id, 'stream_id' => $stream_id, 'line' => $line, 'created_at' => $at, 'updated_at' => $at];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('_task_output')->insert($chunk);
            }
        }

        if ($this->pending_messages !== []) {
            $rows = [];
            foreach ($this->pending_messages as [$body, $at]) {
                $rows[] = ['task_id' => $this->id, 'body' => $body, 'created_at' => $at, 'updated_at' => $at];
            }
            DB::table('_task_messages')->insert($rows);
        }

        // The queue, in the order the calls were made: a clear discards what was stored, pops
        // take from the head of what remains, pushes append. One statement each - a slide of
        // the window is one small DELETE and one small INSERT however many items it covered.
        if ($this->pending_queue_clear) {
            DB::table('_task_queue')->where('task_id', $this->id)->delete();
        }
        if ($this->pending_queue_pops > 0) {
            DB::statement(
                'DELETE FROM _task_queue WHERE task_id = ? ORDER BY id LIMIT ' . $this->pending_queue_pops,
                [$this->id]
            );
        }
        if ($this->pending_queue_pushes !== []) {
            $rows = [];
            foreach ($this->pending_queue_pushes as $body) {
                $rows[] = ['task_id' => $this->id, 'body' => $body, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('_task_queue')->insert($chunk);
            }
            $this->has_queue = true;
        }
        if ($this->queue_stored !== null) {
            $this->queue_stored += count($this->pending_queue_pushes) - $this->pending_queue_pops;
        }

        $this->pending_row = [];
        $this->pending_reports = [];
        $this->pending_output = [];
        $this->pending_messages = [];
        $this->pending_queue_pushes = [];
        $this->pending_queue_pops = 0;
        $this->pending_queue_clear = false;
        $this->last_flush = microtime(true);

        Task_Notify::changed($this->id, $changed, $output_written);
    }

    /**
     * Has a stop been requested for this run (Task_Run_Model::request_stop() / force_stop())?
     *
     * A COOPERATIVE stop: the framework never interrupts a task for a graceful stop. A task
     * that can be stopped calls this between units of work - every batch, every page of a
     * loop - and, when it answers true, finishes cleanly (leaves its data consistent, records
     * what it did) and returns; the run then settles STOPPED. A task that never asks simply
     * runs to completion - unless it was FORCE-stopped, in which case its worker is killed
     * when the grace period ends.
     *
     * Reads the row on every call, so it sees a request made while the task is running - a
     * primary-key read, cheap enough for every item of a loop. Held reports are written at the
     * usual rate (FLUSH_INTERVAL), not on every call: a loop calling heartbeat() and this per
     * item must not turn into a write per item.
     */
    public function is_stop_requested(): bool
    {
        $this->__maybe_flush();

        return DB::table('_tasks')->where('id', $this->id)->value('stop_requested_at') !== null;
    }

    // ------------------------------------------------------------------------------------
    // Identity and scratch space
    // ------------------------------------------------------------------------------------

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_class(): string
    {
        return $this->class;
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_params(): array
    {
        return $this->params;
    }

    /**
     * A temporary directory for this run, created on first use and removed when the run ends.
     *
     * @return string Absolute path
     */
    public function get_temp_dir(): string
    {
        if ($this->temp_dir !== null) {
            return $this->temp_dir;
        }

        $base_temp_dir = Rsx_Project_Paths::tasks_dir();
        if (!is_dir($base_temp_dir)) {
            mkdir($base_temp_dir, 0755, true);
        }

        $this->temp_dir = $base_temp_dir . '/task_' . $this->id;
        if (!is_dir($this->temp_dir)) {
            mkdir($this->temp_dir, 0755, true);
        }

        return $this->temp_dir;
    }

    /**
     * Remove the run's temporary directory. The runner calls this when the run ends.
     */
    public function cleanup_temp_dir(): void
    {
        if ($this->temp_dir === null || !is_dir($this->temp_dir)) {
            return;
        }

        rmdir_recursive($this->temp_dir);
        $this->temp_dir = null;
    }

    // ------------------------------------------------------------------------------------
    // Runner seams
    // ------------------------------------------------------------------------------------

    /**
     * RUNNER-ONLY: record one line the task printed (echo / print / var_dump), captured by the
     * runner's output buffer. The text already reached the runner's own stdout through the
     * buffer, so it is recorded, not echoed again.
     */
    public function _record_captured_stdout(string $line): void
    {
        $this->pending_output[] = [Task_Run_Model::STREAM_STDOUT, $line, static::__now()];
        $this->__maybe_flush();
    }

    /**
     * Append an OPERATOR line - a lifecycle operation someone performed on a run - to that
     * run's output. Written at once, shown as stderr, never echoed to any process's stderr.
     */
    public static function record_operator_line(int $task_id, string $line): void
    {
        $now = static::__now();
        DB::table('_task_output')->insert([
            'task_id' => $task_id,
            'stream_id' => Task_Run_Model::STREAM_OPERATOR,
            'line' => $line,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Task_Notify::changed($task_id, false, true);
    }

    // ------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------

    private function __append_output(int $stream_id, string $line): void
    {
        $this->pending_output[] = [$stream_id, $line, static::__now()];

        $sink = $stream_id === Task_Run_Model::STREAM_STDOUT ? $this->stdout_sink : $this->stderr_sink;
        if ($sink !== null) {
            fwrite($sink, $line . "\n");
            fflush($sink);
        }
    }

    /** Stored queue rows not already waiting to be popped. */
    private function __queue_stored_remaining(): int
    {
        if ($this->queue_stored === null) {
            $this->queue_stored = $this->has_queue
                ? (int) DB::table('_task_queue')->where('task_id', $this->id)->count()
                : 0;
        }

        return $this->queue_stored - $this->pending_queue_pops;
    }

    private function __maybe_flush(): void
    {
        if (microtime(true) - $this->last_flush >= self::FLUSH_INTERVAL) {
            $this->flush();
        }
    }

    /**
     * Store the bytes, then record (or replace) the named attachment inside the blob store's
     * reference scope, so the blob cannot be released before the row pins it. A replaced
     * attachment's blob is released afterwards if nothing else references it.
     *
     * @param callable $store fn (callable $record): File_Storage_Model
     */
    /**
     * Store the file as a temp file and point the run's attachment row under $name at it. The
     * temp file outlives the run's output by a day: Task_Retention_Service deletes it when the
     * output is truncated, and the store's own expiry is only the backstop. A replaced file is
     * deleted at once - nothing else ever holds a temp file's bytes.
     */
    private function __attach(string $name, callable $store): void
    {
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('An attachment name is 1 to 255 characters.');
        }

        $truncate_minutes = (int) config('rsx.tasks.retention.output_truncate_after_minutes', 10080);
        $temp_file = $store((int) ceil($truncate_minutes / 1440) + 1);

        $attachment = Task_Attachment_Model::where('task_id', $this->id)->where('name', $name)->first();
        $previous_temp_file_id = $attachment !== null ? (int) $attachment->temp_file_id : null;
        if ($attachment === null) {
            $attachment = new Task_Attachment_Model();
            $attachment->task_id = $this->id;
            $attachment->name = $name;
        }

        $attachment->temp_file_id = $temp_file->id;
        $attachment->file_name = $temp_file->file_name;
        $attachment->mime_type = mb_substr((string) $temp_file->mime_type, 0, 255);
        $attachment->size = (int) $temp_file->size;
        $attachment->save();

        if ($previous_temp_file_id !== null) {
            $previous = Temp_File_Model::find($previous_temp_file_id);
            if ($previous !== null) {
                Rsx_Temp_Files::delete($previous);
            }
        }

        Task_Notify::changed($this->id, true, false);
    }

    /** @return string[] */
    private static function __lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (str_ends_with($text, "\n")) {
            $text = substr($text, 0, -1);
        }

        return explode("\n", $text);
    }

    private static function __json($value, string $caller): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \InvalidArgumentException("{$caller} was handed a value that cannot be encoded as JSON: " . json_last_error_msg());
        }

        return $json;
    }

    private static function __now(): string
    {
        return now()->format('Y-m-d H:i:s.v');
    }
}
