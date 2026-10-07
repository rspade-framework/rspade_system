<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Files\File_Storage_Model;
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
 *   state_list($items)               a list (a sync queue, a work list), replacing the last
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
 * and further reports within FLUSH_INTERVAL of the last write wait for the next reporting
 * call, is_stop_requested(), flush() or the end of the run - whichever comes first. A task
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

    /** Whether this run's queue report exists (held an item); null until first asked. */
    private ?bool $state_list_reported = null;

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

    /**
     * Report the task's state as a list - a sync queue, a work list - replacing the last. Each
     * item is a string or a JSON value.
     *
     * The report comes into being with its first ITEM: an empty list is not recorded until the
     * run's queue has held something, so "the run has a queue report" means "its queue has or
     * has had items" - and once it has, an emptied queue is recorded as the empty list.
     */
    public function state_list(array $items): void
    {
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('state_list() takes a list (sequential keys); for keyed state use state().');
        }

        if ($this->state_list_reported === null) {
            $this->state_list_reported = DB::table('_task_reports')
                ->where('task_id', $this->id)
                ->where('kind_id', Task_Run_Model::REPORT_STATE_LIST)
                ->exists();
        }
        if ($items === [] && !$this->state_list_reported) {
            return;
        }
        $this->state_list_reported = true;

        $this->pending_reports[Task_Run_Model::REPORT_STATE_LIST] = static::__json($items, 'state_list()');
        $this->__maybe_flush();
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
     * Attach a file on disk to the run under $name, for outside consumers to retrieve
     * (Task_Run_Model::attachment($name)). The bytes are copied into the blob store; the source
     * file is left where it is. Attaching a name again replaces the earlier file.
     */
    public function attach_file(string $name, string $path, ?string $file_name = null, ?string $mime_type = null): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("attach_file('{$name}'): {$path} is not a readable file.");
        }

        $file_name ??= basename($path);
        $mime_type ??= (mime_content_type($path) ?: 'application/octet-stream');
        $size = (int) filesize($path);

        $this->__attach($name, fn (callable $record) => File_Storage_Model::store_blob($path, $record), $file_name, $mime_type, $size);
    }

    /**
     * Attach bytes the task generated to the run under $name. Attaching a name again
     * replaces the earlier file.
     */
    public function attach_bytes(string $name, string $bytes, string $file_name, ?string $mime_type = null): void
    {
        $mime_type ??= ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream');

        $this->__attach($name, fn (callable $record) => File_Storage_Model::store_bytes($bytes, $record), $file_name, $mime_type, strlen($bytes));
    }

    /**
     * Write every held report now. A task about to spend a long time without reporting calls
     * this so its watchers see where it is.
     */
    public function flush(): void
    {
        if ($this->pending_row === [] && $this->pending_reports === [] && $this->pending_output === [] && $this->pending_messages === []) {
            return;
        }

        $now = static::__now();
        $changed = $this->pending_row !== [] || $this->pending_reports !== [] || $this->pending_messages !== [];
        $output_written = $this->pending_output !== [];

        $row = $this->pending_row;
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

        $this->pending_row = [];
        $this->pending_reports = [];
        $this->pending_output = [];
        $this->pending_messages = [];
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
     * Writes any held reports first, and reads the row on every call, so it sees a request
     * made while the task is running.
     */
    public function is_stop_requested(): bool
    {
        $this->flush();

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
    private function __attach(string $name, callable $store, string $file_name, string $mime_type, int $size): void
    {
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('An attachment name is 1 to 255 characters.');
        }

        $previous_storage_id = null;

        $store(function (File_Storage_Model $storage) use ($name, $file_name, $mime_type, $size, &$previous_storage_id) {
            $attachment = Task_Attachment_Model::where('task_id', $this->id)->where('name', $name)->first();
            if ($attachment === null) {
                $attachment = new Task_Attachment_Model();
                $attachment->task_id = $this->id;
                $attachment->name = $name;
            } elseif ((int) $attachment->file_storage_id !== (int) $storage->id) {
                $previous_storage_id = (int) $attachment->file_storage_id;
            }

            $attachment->file_storage_id = $storage->id;
            $attachment->file_name = mb_substr($file_name, 0, 255);
            $attachment->mime_type = mb_substr($mime_type, 0, 255);
            $attachment->size = $size;
            $attachment->save();
        });

        if ($previous_storage_id !== null) {
            File_Disposal_Service::release_blob_if_orphaned($previous_storage_id);
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
