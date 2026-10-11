<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use Exception;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Files\Document_Sandbox;
use App\RSpade\Core\Files\Document_Stage_Bound;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Rendition_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Libreoffice;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Files\Spreadsheet_Rendition;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Search\Search_Index_Service;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Document_Render_Service - THE background document render pipeline (framework core).
 *
 * ONE worker owns every heavy document operation: the preview rendition (a PDF from soffice,
 * which also feeds the Office-document thumbnail, or a workbook's HTML grid) and the text
 * extraction that makes the document searchable. Nothing renders inside a web request.
 *
 * WHY ONE WORKER. A single Word document needs up to three conversions - a thumbnail, the
 * viewer's rendition, and (to text) search - and renditions and thumbnails are both
 * content-addressed on the deduplicated blob hash, so all three are artifacts of the same
 * bytes. One #[Exclusive] worker produces them in one pass and makes soffice single-threaded
 * by construction: this class is the ONLY caller of the binary for renditions, so there is
 * no pool of invocations to bound.
 *
 * THE QUEUE IS ONE COLUMN on the blob, no side table: _file_storage.process_due_at (see THE
 * WORK QUEUE in File_Storage_Model_Abstract). A blob that owes work carries the time it may
 * next be attempted; render_pending() takes the earliest due blob, again and again, until
 * none is due.
 *
 * ONE DOCUMENT CANNOT STOP THE QUEUE. A document is input the application does not control,
 * and a converter or a spreadsheet reader can run on a damaged one without end. Four rules
 * keep that from costing anything but the document itself:
 *   1. THE ATTEMPT IS CHARGED BEFORE IT STARTS. Taking a blob increments its attempt count
 *      and moves its due time one retry interval ahead, in one guarded UPDATE, before any
 *      work is spawned. Whatever then happens to the attempt - or to this worker - the blob
 *      has been counted and is no longer first in line.
 *   2. ONE CHILD PROCESS PER DOCUMENT. The work runs in `rsx:documents:process_one`, and the
 *      parent waits for it under a hard limit (rsx.documents.kill_seconds) that kills the
 *      child and everything it started.
 *   3. EACH STAGE IS BOUNDED inside the child (rsx.documents.stage_seconds) - the render and
 *      the text extraction separately - so the ordinary runaway is recorded by the child
 *      itself with a precise reason.
 *   4. THREE ATTEMPTS (rsx.documents.max_attempts), a retry interval apart. After the last
 *      the blob leaves the queue FAILED, and only an operator puts it back
 *      (rsx:documents:rerender, rsx:search:reindex). A blob found due with every attempt
 *      already charged is one whose last attempt died with its worker: it is settled FAILED
 *      without being run again.
 * The time limits are sanctioned timeouts, each justified at its config key.
 *
 * A FAILED ATTEMPT LOOKS FAILED. Between attempts and after the last, the blob's render state
 * is FAILED with the reason recorded, so every screen shows the failed-document preview (icon
 * and title) rather than "preparing". Whether another attempt is coming is the queue's
 * answer, not the render state's.
 *
 * A LONG BACKLOG IS DRAINED IN PACED PASSES (render_pending(), THE YIELD): a pass that has run
 * for twenty minutes with work still due sets its own next run ten minutes ahead and returns.
 *
 * Kicked promptly on upload (File_Storage_Model::store_blob() and
 * File_Attachment_Model::request_render's dispatch) and swept every 10 minutes so a killed worker
 * resumes without anyone noticing.
 */
class Document_Render_Service extends Rsx_Service_Abstract
{
    /**
     * THE prompt kick: ask for a render pass now rather than waiting for the 10-minute sweep.
     *
     * One choke point with one guard, called from the two places new work appears (a new blob in
     * File_Storage_Model::store_blob(), and a blob entering PENDING in request_render).
     * rsx.search.enabled is the kick switch - when it is off no worker is spawned on upload, and
     * the state written to the row is still the truth, so the sweeper picks the work up whenever
     * the switch comes back. #[Exclusive] coalesces concurrent kicks into one pass.
     *
     * @return void
     */
    public static function kick(): void
    {
        if (!config('rsx.search.enabled', true)) {
            return;
        }

        // NEVER FROM INSIDE A MIGRATION. A data migration that stores a file reaches here
        // while the schema is still part-way through its history, and a dispatch reads and
        // writes the task tables as they are TODAY: replayed from an empty database, the
        // sample-document import runs long before the task tables take their current shape,
        // and the dispatch died on a column that did not exist yet. Nothing is lost by
        // staying quiet - the blob's row already says what it owes, and the sweep (every 10
        // minutes) renders it.
        if (Manifest::_is_migration_context()) {
            return;
        }

        Task::dispatch('Document_Render_Service', 'render_pending');
    }

    /** process_storage() outcomes. */
    public const OUTCOME_DONE = 'done';
    public const OUTCOME_RETRY = 'retry';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_WAITING = 'waiting';
    public const OUTCOME_TAKEN = 'taken';

    /** The child command that processes one blob, and nothing else. */
    public const CHILD_COMMAND = 'rsx:documents:process_one';

    /**
     * Drain the queue: the earliest due blob, one per iteration, until none is due.
     *
     * THE PASS SETS NO TIMEOUT AND IS UNDER NONE WHILE IT REPORTS: it reports before every
     * document, so the task system's silence limit (rsx.tasks.silent_timeout) never applies to
     * a pass that is working. A long backlog is instead taken in PACED passes: after
     * rsx.documents.drain_yield_after_minutes (20), between two documents and only while work
     * is still due, the pass sets its own next run rsx.documents.drain_resume_after_minutes
     * (10) ahead ($task->run_again_in()), says how many documents remain, and returns
     * successfully - so the scheduled pool's other work gets a turn.
     *
     * EVERY ITERATION MOVES ITS BLOB OUT OF "DUE NOW" - an attempt pushes the due time ahead
     * before it runs, and every other outcome clears or defers it - which is what terminates
     * the loop and what stops one document from being taken twice in a pass.
     *
     * MASTER SWITCHES - the two halves of the pipeline are governed separately.
     * rsx.libreoffice.enabled governs RENDERING through soffice, rsx.search.enabled governs
     * EXTRACTION. A blob whose only owed work is switched off is not attempted and not charged:
     * it is deferred one retry interval, so it waits for the switch behind everything that can
     * be done now. With both off there is nothing to drain.
     *
     * @param Task_Instance $task
     * @param array $params
     * @return null
     */
    #[Task('Render documents: PDF rendition + text extraction, one blob at a time')]
    #[Exclusive]
    #[Schedule('every 10 minutes')]
    public static function render_pending(Task_Instance $task, array $params = [])
    {
        $render_enabled = (bool) config('rsx.libreoffice.enabled', true);
        $extract_enabled = (bool) config('rsx.search.enabled', true);

        if (!$render_enabled && !$extract_enabled) {
            $task->stdout('Document render pipeline disabled (rsx.libreoffice.enabled=false, rsx.search.enabled=false) - nothing to do');
            $task->state(['processed' => 0]);
            $task->summary('The document render pipeline is disabled; nothing was processed.');

            return null;
        }

        // The backlog as it stands now; blobs queued during the pass raise the total as they come.
        $total = File_Storage_Model::work_due_query()->count();
        $processed = 0;
        $failed = 0;
        $retrying = 0;

        [$yield_after_seconds, $resume_after_seconds] = static::drain_pacing();
        $started = microtime(true);
        $taken = 0;

        while (true) {
            if ($task->is_stop_requested()) {
                $task->stdout("Stop requested - stopped after {$processed} document(s): {$retrying} to retry, {$failed} failed.");
                $task->summary("Stopped after {$processed} blob(s): {$retrying} to retry, {$failed} failed.");

                return null;
            }

            $storage = static::next_due();
            if (!$storage) {
                break;
            }

            // THE YIELD. Between documents - never during one - a pass that has been draining
            // for rsx.documents.drain_yield_after_minutes and still has work due hands its
            // worker back: it sets its own next run drain_resume_after_minutes from now and
            // returns, a SUCCESSFUL run. Nothing is cut short and nothing is lost; the rest of
            // the queue is exactly where it was, and the next pass takes it up.
            if ($taken > 0 && microtime(true) - $started >= $yield_after_seconds) {
                $remaining = File_Storage_Model::work_due_query()->count();
                $resume_at = $task->run_again_in($resume_after_seconds);
                $minutes = (int) round((microtime(true) - $started) / 60);

                $task->stdout(
                    "Yielding after {$minutes} minute(s): {$processed} document(s) processed, {$retrying} to retry, {$failed} failed;"
                    . " {$remaining} still waiting. The next pass is set for " . Rsx_Time::format_datetime($resume_at) . '.'
                );
                $task->state(['processed' => $processed, 'retrying' => $retrying, 'failed' => $failed, 'yielded' => true, 'remaining' => $remaining, 'resume_at' => $resume_at]);
                $task->summary("Processed {$processed} blob(s): {$retrying} to retry, {$failed} failed. Yielded with {$remaining} still waiting; the next pass is set.");

                return null;
            }

            $taken++;

            $task->heartbeat();

            // What the line names: the blob, one of the file names it was uploaded under, and
            // what it owed - so the output reads as a list of documents, not of storage ids.
            $owed = array_filter([
                static::__owes_render($storage) ? 'preview' : null,
                (int) $storage->is_indexed === 0 ? 'text extraction' : null,
            ]);
            $label = "#{$storage->id} \"" . static::__representative_file_name($storage) . '" (' . bytes_to_human((int) $storage->size) . ')';

            $task->status("Processing {$label}");
            $task->flush();

            $result = static::process_storage($storage);

            if ($result['outcome'] === self::OUTCOME_TAKEN || $result['outcome'] === self::OUTCOME_WAITING) {
                // Nothing ran: another process holds the blob, or its work is switched off.
                if ($result['outcome'] === self::OUTCOME_WAITING) {
                    $task->stdout("Deferred {$label}: {$result['reason']}");
                }

                continue;
            }

            $processed++;
            $task->progress_count($processed, max($total, $processed));

            if ($result['outcome'] === self::OUTCOME_RETRY) {
                $retrying++;
                $task->stderr(
                    "Attempt {$result['attempt']} of {$result['max_attempts']} failed for {$label}: {$result['reason']}"
                    . ' - will retry at ' . Rsx_Time::format_datetime($result['next_attempt_at'])
                );
            } elseif ($result['outcome'] === self::OUTCOME_FAILED) {
                $failed++;
                $task->stderr(
                    "Failed {$label}: {$result['reason']}"
                    . " - no attempts left ({$result['max_attempts']}), will not be retried."
                    . ' Re-queue with rsx:documents:rerender --storage=' . $storage->id
                );
            } else {
                $task->stdout("Processed {$label}: " . ($owed !== [] ? implode(' + ', $owed) : 'nothing owed') . " -> {$result['render_label']}");
            }
        }

        $task->stdout($processed > 0
            ? "Done: {$processed} document(s) processed, {$retrying} to retry, {$failed} failed."
            : 'No documents were waiting to be rendered or indexed.');

        $task->state(['processed' => $processed, 'retrying' => $retrying, 'failed' => $failed, 'yielded' => false]);
        $task->summary("Processed {$processed} blob(s): {$retrying} to retry, {$failed} failed.");

        return null;
    }

    /**
     * The drain's pacing, validated, in seconds: how long one pass drains before it yields,
     * and how far ahead it sets its next pass when it does (rsx.documents.
     * drain_yield_after_minutes / drain_resume_after_minutes - pacing, not timeouts; see the
     * config key).
     *
     * @return array{0: float, 1: int}
     */
    public static function drain_pacing(): array
    {
        $yield_after = config('rsx.documents.drain_yield_after_minutes');
        $resume_after = config('rsx.documents.drain_resume_after_minutes');

        if (!is_numeric($yield_after) || $yield_after < 0) {
            throw new Exception('rsx.documents.drain_yield_after_minutes must be a number of minutes >= 0, got ' . var_export($yield_after, true));
        }
        if (!is_numeric($resume_after) || $resume_after < 0) {
            throw new Exception('rsx.documents.drain_resume_after_minutes must be a number of minutes >= 0, got ' . var_export($resume_after, true));
        }

        return [(float) $yield_after * 60, (int) round((float) $resume_after * 60)];
    }

    /**
     * Process ONE blob through the queue's rules: charge the attempt, run the document in a
     * child process under the kill limit, then read the row back and settle what happened.
     *
     * The single route from "this blob owes work" to "the work was attempted" - the drain uses
     * it for every blob, and anything else that wants a document processed NOW (a development
     * page, a test) calls it rather than rendering in its own process. It can be called from a
     * web request: the document is never rendered in the caller.
     *
     * It does not ask whether the blob is due YET - the caller chose it - but it must be in the
     * queue (process_due_at set), and the queue's rules hold: a blob with every attempt already
     * charged is settled FAILED without being run.
     *
     * @param File_Storage_Model $storage
     * @return array{outcome: string, reason: ?string, attempt: int, max_attempts: int,
     *               next_attempt_at: ?string, render_label: string}
     *         outcome is one of the OUTCOME_* constants:
     *           DONE     nothing is owed; the blob is out of the queue.
     *           RETRY    the attempt failed and another is scheduled (next_attempt_at).
     *           FAILED   the blob's attempts are spent; it left the queue FAILED.
     *           WAITING  what it owes is switched off; deferred, no attempt charged.
     *           TAKEN    another process charged this attempt first; nothing was done here.
     */
    public static function process_storage(File_Storage_Model $storage): array
    {
        // Outside the queue the worker has nothing to do for a blob - including a FAILED one whose
        // attempts are spent. Putting it back is requeue_render() / mark_work_owed().
        if ($storage->process_due_at === null) {
            return static::__result(self::OUTCOME_DONE, $storage, null);
        }

        $max_attempts = File_Storage_Model::work_max_attempts();
        $owes_render = static::__owes_render($storage);
        $owes_text = (int) $storage->is_indexed === 0;

        if (!$owes_render && !$owes_text) {
            $storage->close_work();

            return static::__result(self::OUTCOME_DONE, $storage, null);
        }

        // What this pass can actually attempt. A pass that runs extracts whatever text is owed,
        // so the extraction switch only decides whether text ALONE is a reason to run one.
        $can_render = $owes_render && static::__can_render($storage);
        $can_extract = $owes_text && ($can_render || config('rsx.search.enabled', true));

        if (!$can_render && !$can_extract) {
            $storage->defer_work();

            return static::__result(
                self::OUTCOME_WAITING,
                $storage,
                $owes_render ? 'rendering is switched off (rsx.libreoffice.enabled)' : 'text extraction is switched off (rsx.search.enabled)'
            );
        }

        // Due, with every attempt already charged: the last one was charged and never settled,
        // which only happens when the process that ran it died. It is not run again.
        if ((int) $storage->process_attempt_count >= $max_attempts) {
            $reason = "attempt {$max_attempts} of {$max_attempts} never reported: the process running it ended before recording an outcome";
            static::__record_failure($storage, $reason, true);
            static::__leave_queue($storage, true);
            static::notify_attachments($storage);

            return static::__result(self::OUTCOME_FAILED, $storage, $reason);
        }

        // CHARGE FIRST. From here on the blob has been counted and is one retry interval away
        // from the front of the queue, whatever becomes of this process or its child.
        if (!$storage->charge_work_attempt()) {
            return static::__result(self::OUTCOME_TAKEN, $storage, null);
        }

        $kill_seconds = static::kill_seconds();
        $output = [];

        // The child talks to the database and the file store THIS process talks to, and joins
        // its lock group (Rsx_Artisan). Its own output is diagnostic only: the row is the result.
        $exit_code = Rsx_Artisan::run_bounded(
            self::CHILD_COMMAND,
            array_merge([(string) $storage->id], Rsx_Project_Paths::child_flags()),
            $kill_seconds,
            $output,
            ['DB_DATABASE' => (string) config('database.connections.' . config('database.default') . '.database')]
        );

        $fresh = File_Storage_Model::find($storage->id);
        if (!$fresh) {
            // The blob was released while its child ran; there is no row to settle.
            return static::__result(self::OUTCOME_DONE, $storage, null);
        }

        return static::__reconcile($fresh, $exit_code, $output);
    }

    /**
     * Settle a blob after its child returned - cleanly, with an error, or killed. The ROW is
     * what the child reported: anything it still owes that this pass could have produced means
     * the attempt failed.
     *
     * @param File_Storage_Model $storage The row as it stands now.
     * @param int $exit_code Rsx_Artisan::run_bounded()'s answer.
     * @param array<int, string> $output The child's output lines.
     * @return array See process_storage().
     */
    private static function __reconcile(File_Storage_Model $storage, int $exit_code, array $output): array
    {
        $max_attempts = File_Storage_Model::work_max_attempts();

        $render_failed = static::__owes_render($storage) && static::__can_render($storage);
        $text_failed = (int) $storage->is_indexed === 0;

        if (!$render_failed && !$text_failed) {
            static::__leave_queue($storage, false);

            return static::__result(self::OUTCOME_DONE, $storage, null);
        }

        // WHY it failed. A child that ended normally recorded its own reasons (render_error, the
        // FAILED index row) and they stand. One that was killed or crashed recorded nothing for
        // the stage it was in, so the parent says what it saw.
        $child_reason = null;
        if ($exit_code === Rsx_Artisan::KILLED_AT_LIMIT) {
            $child_reason = 'killed after ' . static::kill_seconds() . ' seconds';
        } elseif ($exit_code !== 0) {
            $last_line = trim((string) end($output));
            $child_reason = "the document process exited with status {$exit_code}" . ($last_line !== '' ? ": {$last_line}" : '');
        }

        if ($child_reason !== null) {
            static::__record_failure($storage, $child_reason, false);
        } elseif ($render_failed && (int) $storage->render_status_id === File_Storage_Model::RENDER_STATUS_PENDING) {
            static::__record_failure($storage, 'the document process ended without recording an outcome', false);
        }

        $reason = $render_failed
            ? (string) $storage->render_error
            : (string) (Search_Index_Model::forModel('File_Storage_Model', $storage->id)->value('error') ?? 'text extraction failed');

        if ((int) $storage->process_attempt_count >= $max_attempts) {
            // The last attempt: what is still missing is failed for good.
            if ($text_failed) {
                Search_Index_Service::settle_failure($storage, $reason);
            }
            static::__leave_queue($storage, true);
            static::notify_attachments($storage);

            return static::__result(self::OUTCOME_FAILED, $storage, $reason);
        }

        // The charge already scheduled the next attempt; the blob stays in the queue as it is.
        static::notify_attachments($storage);

        return static::__result(self::OUTCOME_RETRY, $storage, $reason);
    }

    /**
     * Record a failure the child did not record itself, on every part of the work this blob
     * still owes: the render becomes FAILED with the reason, and owed text gets a FAILED row.
     *
     * @param File_Storage_Model $storage
     * @param string $reason
     * @param bool $final True when the blob's attempts are spent: owed text stops being owed.
     * @return void
     */
    private static function __record_failure(File_Storage_Model $storage, string $reason, bool $final): void
    {
        if (static::__owes_render($storage) && static::__can_render($storage)) {
            $storage->render_status_id = File_Storage_Model::RENDER_STATUS_FAILED;
            $storage->render_error = $reason;
            $storage->rendered_at = null;
            $storage->save();
        }

        if ((int) $storage->is_indexed === 0) {
            Search_Index_Service::record_failure($storage, $reason);

            if ($final) {
                Search_Index_Service::settle_failure($storage, $reason);
            }
        }
    }

    /**
     * Take a settled blob out of the queue - unless it still owes a rendition that is only
     * waiting for LibreOffice to be switched on, which keeps it in the queue, deferred.
     *
     * @param File_Storage_Model $storage
     * @param bool $attempts_spent See File_Storage_Model::close_work().
     * @return void
     */
    private static function __leave_queue(File_Storage_Model $storage, bool $attempts_spent): void
    {
        if ((int) $storage->render_status_id === File_Storage_Model::RENDER_STATUS_PENDING && !static::__can_render($storage)) {
            $storage->defer_work();

            return;
        }

        $storage->close_work($attempts_spent);
    }

    /**
     * @param string $outcome
     * @param File_Storage_Model $storage
     * @param string|null $reason
     * @return array See process_storage().
     */
    private static function __result(string $outcome, File_Storage_Model $storage, ?string $reason): array
    {
        return [
            'outcome' => $outcome,
            'reason' => $reason,
            'attempt' => (int) $storage->process_attempt_count,
            'max_attempts' => File_Storage_Model::work_max_attempts(),
            'next_attempt_at' => $storage->process_due_at,
            'render_label' => (string) $storage->render_status_id__label,
        ];
    }

    /**
     * True when a rendition is owed: never attempted (PENDING), or attempted and FAILED. Only
     * meaningful for a blob that is in the queue - a FAILED blob outside it is failed for good.
     *
     * @param File_Storage_Model $storage
     * @return bool
     */
    private static function __owes_render(File_Storage_Model $storage): bool
    {
        $status = (int) $storage->render_status_id;

        return $status === File_Storage_Model::RENDER_STATUS_PENDING || $status === File_Storage_Model::RENDER_STATUS_FAILED;
    }

    /**
     * True when this blob's rendition can be produced on this box right now.
     *
     * A SPREADSHEET RENDERS WITHOUT LIBREOFFICE, so the master switch does not gate it:
     * PhpSpreadsheet reads the workbook in-process. A box with soffice absent or disabled still
     * previews its workbooks, and only loses the formats that genuinely need it.
     *
     * @param File_Storage_Model $storage
     * @return bool
     */
    private static function __can_render(File_Storage_Model $storage): bool
    {
        return (bool) config('rsx.libreoffice.enabled', true)
            || Spreadsheet_Rendition::handles_extension(static::representative_extension($storage));
    }

    /**
     * How long the parent waits for one document's child before killing it
     * (rsx.documents.kill_seconds - a sanctioned timeout, justified at its config key).
     *
     * @return int
     */
    public static function kill_seconds(): int
    {
        $seconds = (int) config('rsx.documents.kill_seconds');

        if ($seconds < 1) {
            shouldnt_happen('rsx.documents.kill_seconds must be a positive number of seconds');
        }

        return $seconds;
    }

    /**
     * A file name this blob was uploaded under, for a line of output: the earliest
     * attachment's, deleted ones included, or '(no attachment)' for a blob nothing names.
     */
    private static function __representative_file_name(File_Storage_Model $storage): string
    {
        // Cross-site blob, no site context in a task: see notify_attachments().
        $name = File_Attachment_Model::without_site_scope(
            fn () => File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->orderBy('id')
                ->value('file_name')
        );

        return $name !== null && $name !== '' ? (string) $name : '(no attachment)';
    }

    /**
     * The earliest due blob, or null when none is due: WHERE process_due_at <= NOW(3)
     * ORDER BY process_due_at, id LIMIT 1 - the front of idx_process_due.
     *
     * @return File_Storage_Model|null
     */
    public static function next_due(): ?File_Storage_Model
    {
        return File_Storage_Model::work_due_query()->orderBy('process_due_at')->orderBy('id')->first();
    }

    /**
     * Render ONE blob in THIS process: produce its rendition if one is owed, extract its text if
     * that is owed, record the outcome on the row, and tell every screen showing it to look
     * again.
     *
     * THE UNIT OF WORK the child command runs (rsx:documents:process_one), and what a test calls
     * to exercise a render directly. It is not the route for processing a document from
     * application code - that is process_storage(), which runs this in a child under the kill
     * limit and keeps the queue's books. This method writes the OUTCOME (render state, index
     * row) and never the queue columns.
     *
     * A rendition is owed when the render state is PENDING or FAILED: FAILED is what an earlier
     * attempt left behind, and the caller running the blob again is the retry.
     *
     * Each stage runs under the stage bound (Document_Stage_Bound), so this is command-line
     * only. It NEVER throws for a document's sake: a per-blob \Throwable - a stage that ran past
     * its bound included - becomes FAILED with the message recorded.
     *
     * @param File_Storage_Model $storage
     * @return void
     */
    public static function render_storage(File_Storage_Model $storage): void
    {
        Document_Stage_Bound::require_cli('Document_Render_Service::render_storage()');

        $owes_render = static::__owes_render($storage);

        // Resolved once, and only when a render is actually owed - it is a query.
        $extension = $owes_render ? static::representative_extension($storage) : null;
        $is_spreadsheet = $owes_render && Spreadsheet_Rendition::handles_extension($extension);

        // A spreadsheet renders without LibreOffice - see __can_render().
        $needs_render = $owes_render && ($is_spreadsheet || config('rsx.libreoffice.enabled', true));
        $needs_extract = (int) $storage->is_indexed === 0;

        $rendition_path = null;

        try {
            $source_path = $storage->get_full_path();
            if (!file_exists($source_path)) {
                // The blob should always be resident (content-addressed local cache); a missing
                // file is a genuine fault. Let the extractor record its own FAILED index row
                // first - that is the row rsx:search:reindex --failed and the audit read - then
                // throw so the render state settles FAILED too.
                if ($needs_extract) {
                    Search_Index_Service::extract_storage($storage, null);
                }

                throw new Exception('source blob missing on disk: ' . $source_path);
            }

            if ($needs_render) {
                $rendition_path = $is_spreadsheet
                    ? File_Preview_Controller::sheet_rendition_cache_path($storage)
                    : File_Preview_Controller::rendition_cache_path($storage);

                // Short-circuit: the rendition already exists - a re-queued blob can find the
                // file of an earlier render still in the cache. No conversion runs.
                if (!file_exists($rendition_path)) {
                    // THE RENDER STAGE, under its bound (rsx.documents.stage_seconds).
                    Document_Stage_Bound::run('render', function () use ($is_spreadsheet, $source_path, $extension, $rendition_path) {
                        if (static::$_testing_render_stage !== null) {
                            (static::$_testing_render_stage)();
                        }

                        if ($is_spreadsheet) {
                            Spreadsheet_Rendition::render($source_path, $rendition_path);
                        } else {
                            static::__convert_to_pdf($source_path, $extension, $rendition_path);
                        }
                    });
                }
            } elseif ((int) $storage->render_status_id === File_Storage_Model::RENDER_STATUS_RENDERED) {
                // Already rendered, but extraction may still be owed: hand the extractor the
                // rendition when it is genuinely on disk.
                $existing = File_Preview_Controller::rendition_cache_path($storage);
                $rendition_path = file_exists($existing) ? $existing : null;
            }

            if ($needs_extract) {
                Search_Index_Service::extract_storage($storage, $rendition_path);
            }
        } catch (\Throwable $e) {
            $storage->render_status_id = File_Storage_Model::RENDER_STATUS_FAILED;
            $storage->render_error = $e->getMessage();
            $storage->rendered_at = null;
            $storage->save();

            // Extraction is still OWED when the failure happened before it ran (the
            // conversion threw first). Attempt it now: extract_storage() records its own
            // outcome, and a document whose rendition cannot be made may still have text
            // that can be read - or fails extraction too, and that failure is then RECORDED
            // where reindex --failed can see it.
            if ((int) $storage->is_indexed === 0) {
                Search_Index_Service::extract_storage($storage, null);
            }

            static::notify_attachments($storage);

            return;
        }

        if ($needs_render) {
            $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
            $storage->rendered_at = Rsx_Time::now_iso();
            $storage->render_error = null;
            $storage->save();

            // The thumbnail cache for these bytes is now WRONG. Every entry in it was rasterized
            // before a rendition existed, which means it is the generic extension icon written
            // under the real cache key - poisoned, and never replaced by a later success because
            // a cache hit is a cache hit. Purge them so the next request rasterizes the rendition.
            static::purge_thumbnail_cache($storage);
        }

        static::notify_attachments($storage);
    }

    /**
     * TEST SEAM: a callable run at the start of the render stage, inside its bound, so a test
     * can make a render take as long as it needs to. Null outside tests.
     *
     * @var callable|null
     */
    public static $_testing_render_stage = null;

    /**
     * Publish a realtime frame for every attachment referencing this blob, so open pages swap
     * their placeholder for the real thumbnail without a reload.
     *
     * The frame goes on the ATTACHMENTS, not the blob: a realtime frame from a CLI task carries
     * the row's site_id (Realtime_Emissions::_resolve_site_id) and _file_storage has none, while
     * an attachment does. Subscribers therefore watch by attachment id, which is also the identity
     * <Attachment_Thumbnail> is built around.
     *
     * PUBLIC because it is not only the worker's business: rsx:documents:rerender flips a single
     * blob back to PENDING with a set UPDATE (which emits nothing - _file_storage is not a
     * realtime model), and the open pages showing that document must fall back to the placeholder
     * immediately. One notification, spelled once.
     *
     * @param File_Storage_Model $storage
     * @return void
     */
    public static function notify_attachments(File_Storage_Model $storage): void
    {
        // One deduplicated blob may back attachments across several sites, and this task carries
        // no site context at all, so read without the site scope - otherwise the frames that
        // matter most (another tenant's open page) would never be sent.
        File_Attachment_Model::without_site_scope(function () use ($storage) {
            $attachments = File_Attachment_Model::where('file_storage_id', $storage->id)->result_set();

            foreach ($attachments as $attachment) {
                $attachment->realtime_emit();
            }
        });
    }

    /**
     * Delete every cached thumbnail derived from this blob's bytes.
     *
     * Cache filenames embed the blob hash ({preset}_{hash}_{ext}.webp and
     * {type}_{w}x{h}_{hash}_{ext}.webp), so one glob per cache directory catches all of them
     * regardless of preset, size or the extension of the attachment that requested them.
     *
     * @param File_Storage_Model $storage
     * @return int Number of cache files deleted.
     */
    public static function purge_thumbnail_cache(File_Storage_Model $storage): int
    {
        $deleted = 0;
        $root = Rsx_File_Paths::thumbnails_root();

        foreach (['preset', 'dynamic'] as $cache_type) {
            foreach (glob($root . '/' . $cache_type . '/*_' . $storage->hash . '_*.webp') as $file) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * The file extension to stage this blob under for soffice, borrowed from an attachment that
     * references it (a content-addressed blob has no extension of its own, and soffice detects the
     * input format from the filename). Prefers an attachment whose extension is a known document
     * type - a blob shared by a .docx and a .zip upload converts as the docx - then any extension.
     *
     * @param File_Storage_Model $storage
     * @return string|null null for an orphan blob (no referencing attachment).
     */
    public static function representative_extension(File_Storage_Model $storage): ?string
    {
        $document_extensions = array_keys(config('rsx.files.document_mime_by_extension', []));

        // Cross-site blob, no site context in a task: see notify_attachments().
        return File_Attachment_Model::without_site_scope(function () use ($storage, $document_extensions) {
            // withTrashed(): the render is keyed on the deduplicated blob, so a representative
            // extension is valid regardless of any one attachment's soft-delete state.
            $preferred = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereIn('file_extension', $document_extensions)
                ->orderBy('id')
                ->value('file_extension');
            if ($preferred !== null && $preferred !== '') {
                return $preferred;
            }

            $any = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereNotNull('file_extension')
                ->where('file_extension', '!=', '')
                ->orderBy('id')
                ->value('file_extension');

            return ($any === null || $any === '') ? null : $any;
        });
    }

    /**
     * A human-recognizable file name for this blob, borrowed from a referencing attachment, for
     * operator output (rsx:documents:failed). A content-addressed blob has no name of its own, so
     * this answers "which document is #4711" the only way there is: whatever an upload called it.
     *
     * Same reasoning as representative_extension(): prefer a known document type, fall back to
     * any name, and read withTrashed()/without_site_scope because the blob is shared and the
     * question is about bytes, not about any one attachment's visibility.
     *
     * @param File_Storage_Model $storage
     * @return string|null null for an orphan blob (no referencing attachment).
     */
    public static function representative_file_name(File_Storage_Model $storage): ?string
    {
        $document_extensions = array_keys(config('rsx.files.document_mime_by_extension', []));

        return File_Attachment_Model::without_site_scope(function () use ($storage, $document_extensions) {
            $preferred = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereIn('file_extension', $document_extensions)
                ->orderBy('id')
                ->value('file_name');
            if ($preferred !== null && $preferred !== '') {
                return $preferred;
            }

            $any = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereNotNull('file_name')
                ->where('file_name', '!=', '')
                ->orderBy('id')
                ->value('file_name');

            return ($any === null || $any === '') ? null : $any;
        });
    }

    /**
     * Convert a source document to PDF via headless LibreOffice, publishing atomically into the
     * rendition cache. Fail loud - render_storage() turns a throw into a recorded FAILED.
     *
     * @param string $source_path Resident blob path (extensionless, content-addressed).
     * @param string|null $extension The representative attachment's real extension.
     * @param string $cache_path Destination path for the published PDF rendition.
     * @return void
     * @throws Exception
     */
    protected static function __convert_to_pdf(string $source_path, ?string $extension, string $cache_path): void
    {
        // Sandboxed, the binary name resolves on the image's PATH and there is nothing to
        // discover on the host; unsandboxed, it is the host path discovery finds.
        if (Document_Sandbox::is_docker()) {
            $soffice = 'soffice';
        } else {
            $soffice = Libreoffice::find_soffice();
            if ($soffice === null) {
                throw new Exception('LibreOffice (soffice) is not installed or not configured');
            }
        }

        // Lazy-create the rendition cache directory.
        $dir = dirname($cache_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Private work dir - LibreOffice needs an isolated user profile and an output dir.
        $work_dir = sys_get_temp_dir() . '/rsx_soffice_pdf_' . bin2hex(random_bytes(8));
        if (!mkdir($work_dir, 0700, true) && !is_dir($work_dir)) {
            throw new Exception("Failed to create LibreOffice work dir: {$work_dir}");
        }

        try {
            // Stage the extensionless blob under its real extension so soffice reliably detects the
            // input format; soffice names its output after this basename.
            $safe_ext = preg_replace('/[^a-z0-9]/i', '', (string) $extension);
            if ($safe_ext === '') {
                $safe_ext = 'bin';
            }
            $staged = $work_dir . '/source.' . $safe_ext;
            if (!copy($source_path, $staged)) {
                throw new Exception('Failed to stage source document for PDF rendition');
            }

            // Every path here is inside the work dir, which the sandbox mounts at its own
            // absolute path - so ONE argv is correct both on the host and in the container.
            $command = Document_Sandbox::command([
                $soffice,
                '-env:UserInstallation=file://' . $work_dir . '/profile',
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $work_dir,
                $staged,
            ], $work_dir);

            // soffice runs inside the render stage and is bounded with it: a converter that
            // wedges on a malformed document is stopped when the stage's limit expires.
            $process = Document_Stage_Bound::run_process($command);

            if (!$process->isSuccessful()) {
                throw new Exception('LibreOffice PDF conversion failed: ' . trim($process->getErrorOutput()));
            }

            $pdf_path = $work_dir . '/source.pdf';
            if (!file_exists($pdf_path)) {
                throw new Exception('LibreOffice produced no PDF rendition');
            }

            // Atomic publish: copy into a temp file BESIDE the cache path (same filesystem), then
            // rename over the destination. The work dir may be on a different filesystem, so a
            // direct rename from there is not guaranteed atomic.
            $tmp = $cache_path . '.tmp.' . bin2hex(random_bytes(4));
            if (!copy($pdf_path, $tmp)) {
                throw new Exception('Failed to write rendition temp file');
            }
            if (!rename($tmp, $cache_path)) {
                @unlink($tmp);
                throw new Exception('Failed to publish rendition file');
            }
        } finally {
            rmdir_recursive($work_dir);
        }
    }

    /**
     * rsx:health probe: how much render/extraction work is due, waiting for a retry, or failed.
     *
     * A public static `#[Health_Check('label')]` (bare marker attribute - never a defined class)
     * returning a row per Health_Check_Runner's contract. Read-only. This is the SINGLE backlog
     * probe for the document pipeline - rendition and extraction are one worker and one queue,
     * so two probes would be two ways of saying the same thing.
     *
     * @return array
     */
    #[Health_Check('Document Render Backlog')]
    public static function render_backlog(): array
    {
        $render_enabled = (bool) config('rsx.libreoffice.enabled', true);
        $extract_enabled = (bool) config('rsx.search.enabled', true);

        if (!$render_enabled && !$extract_enabled) {
            return ['status' => 'INFO', 'detail' => 'disabled by config (rsx.libreoffice.enabled=false, rsx.search.enabled=false)'];
        }

        $queue = static::queue_statistics();
        $extract_failed = Search_Index_Model::where('indexable_type', 'File_Storage_Model')
            ->where('status_id', Search_Index_Model::STATUS_FAILED)
            ->count();

        $summary = "due={$queue['due']}, retrying={$queue['retrying']}, failed={$queue['failed']}, extract_failed={$extract_failed}";

        // A large queue of DUE work means the one-at-a-time worker is not keeping up (or not
        // running). Blobs waiting out a retry interval are not backlog: nothing can take them yet.
        if ($queue['due'] > 500) {
            return [
                'status' => 'WARN',
                'detail' => $summary . ' - large backlog',
                'remediation' => 'the render worker may not be running - check rsx:task:process and php artisan rsx:documents:status',
            ];
        }

        if ($queue['failed'] > 0 || $queue['retrying'] > 0 || $extract_failed > 0) {
            return [
                'status' => 'WARN',
                'detail' => $summary,
                'remediation' => 'inspect with php artisan rsx:documents:status (and rsx:documents:failed); a retrying document is retried by the worker, a failed one is re-queued with rsx:documents:rerender --failed',
            ];
        }

        return ['status' => 'OK', 'detail' => $summary];
    }

    /**
     * The work queue in numbers.
     *
     * @return array{due: int, retrying: int, deferred: int, failed: int, next_retry_at: ?string}
     *   due            may be taken now.
     *   retrying       a failed attempt is waiting out its retry interval.
     *   deferred       owes work that is switched off; offered again later, nothing charged.
     *   failed         render FAILED and out of the queue: attempts spent, an operator's to re-queue.
     *   next_retry_at  when the earliest waiting blob comes due, or null.
     */
    public static function queue_statistics(): array
    {
        $waiting = fn () => File_Storage_Model::where('process_due_at', '>', DB::raw('NOW(3)'));

        return [
            'due' => File_Storage_Model::work_due_query()->count(),
            'retrying' => $waiting()->where('process_attempt_count', '>', 0)->count(),
            'deferred' => $waiting()->where('process_attempt_count', 0)->count(),
            'failed' => File_Storage_Model::where('render_status_id', File_Storage_Model::RENDER_STATUS_FAILED)
                ->whereNull('process_due_at')
                ->count(),
            'next_retry_at' => $waiting()->min('process_due_at'),
        ];
    }

    /**
     * Counts and cache figures for rsx:documents:status.
     *
     * @return array
     */
    public static function get_statistics(): array
    {
        $render_counts = [];
        foreach (File_Storage_Model::render_status_id__enum() as $id => $definition) {
            $render_counts[$definition['label']] = File_Storage_Model::where('render_status_id', $id)->count();
        }

        $extraction_counts = [];
        foreach (Search_Index_Model::status_id__enum() as $id => $definition) {
            $extraction_counts[$definition['label']] = Search_Index_Model::where('indexable_type', 'File_Storage_Model')
                ->where('status_id', $id)
                ->count();
        }

        return [
            'queue' => static::queue_statistics(),
            'render' => $render_counts,
            'extraction' => $extraction_counts,
            'unindexed' => File_Storage_Model::where('is_indexed', 0)->count(),
            'renditions' => File_Rendition_Service::get_statistics(),
        ];
    }
}
