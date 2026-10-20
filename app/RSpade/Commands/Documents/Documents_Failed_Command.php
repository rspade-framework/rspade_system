<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Documents;

use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Time\Rsx_Time;
use Illuminate\Console\Command;

/**
 * rsx:documents:failed - what the document pipeline could not process, why, and what happens next.
 *
 * SOFT failures come first, in a table of their own: blobs whose last pass could not run at all
 * because a tool or the source file is unavailable (soffice or pdftotext missing, the sandbox
 * image absent, the blob unreadable). They cost no attempt and the worker keeps checking them on
 * a growing interval, so the remedy is to fix what the reason names - nothing needs re-queueing.
 *
 * Every other failure is HARD: a failed attempt is retried by the worker a few times before the
 * blob is left FAILED for an operator, so every row says where its blob stands: the attempts
 * charged, and either the time of the next attempt or that none is coming. Two tables, because
 * the two halves of the pipeline have two different remedies once the attempts are spent:
 *   render failures    -> rsx:documents:rerender --failed
 *   extraction failures -> rsx:search:reindex --failed
 *
 * Read-only, always exit 0 - an install with failures is not itself a command failure.
 */
class Documents_Failed_Command extends Command
{
    protected $signature = 'rsx:documents:failed
                            {--limit=50 : Rows to show per table (0 = all)}';

    protected $description = 'List documents whose render or text extraction FAILED, with the recorded reason';

    /** Longest render_error / error excerpt printed before it is cut short. */
    private const ERROR_EXCERPT_LENGTH = 80;

    public function handle(): int
    {
        // A caller-chosen N: the default keeps a terminal readable, and every header states the
        // FULL count so a truncated table can never be mistaken for the whole story.
        $limit = (int) $this->option('limit');

        $this->__print_soft_failures($limit);
        $this->newLine();
        $this->__print_render_failures($limit);
        $this->newLine();
        $this->__print_extraction_failures($limit);

        return 0;
    }

    /**
     * The query for SOFT-failed blobs: in the queue, with a soft failure on record
     * (File_Storage_Model::is_soft_failed()).
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function __soft_failed_query()
    {
        return File_Storage_Model::whereNotNull('process_due_at')->where('process_soft_failure_count', '>', 0);
    }

    /**
     * Blobs whose last pass could not run: a tool or the source file is unavailable. Whatever
     * they owed - a rendition, their text, or both - they are listed here once, and not in the
     * two tables of hard failures below.
     *
     * @param int $limit 0 for every row.
     * @return void
     */
    private function __print_soft_failures(int $limit): void
    {
        $total = $this->__soft_failed_query()->count();

        if ($total === 0) {
            $this->line('[OK] No documents waiting on a tool or source file.');

            return;
        }

        $query = $this->__soft_failed_query()->orderBy('id');
        $storages = $limit > 0 ? $query->limit($limit)->get() : $query->result_set();

        $rows = [];
        $shown = 0;
        foreach ($storages as $storage) {
            // The same reason is recorded on every part the pass owed: the render's when one was
            // owed, the index row's otherwise.
            $reason = (int) $storage->render_status_id === File_Storage_Model::RENDER_STATUS_FAILED
                ? (string) $storage->render_error
                : (string) Search_Index_Model::forModel('File_Storage_Model', $storage->id)->value('error');

            $rows[] = [
                $storage->id,
                substr((string) $storage->hash, 0, 12),
                Document_Render_Service::representative_file_name($storage) ?? '(orphan blob)',
                $this->__excerpt($reason),
                (int) $storage->process_soft_failure_count,
                Rsx_Time::format_datetime($storage->process_due_at),
            ];
            $shown++;
        }

        $this->line("{$total} document(s) could not be processed - tool or source unavailable (no attempt charged; checked again automatically):");
        $this->table(['Storage', 'Hash', 'File name', 'Reason', 'Checks failed', 'Next check'], $rows);

        if ($shown < $total) {
            $this->line("  showing {$shown} of {$total} - use --limit=0 for all");
        }

        $this->line('  Fix what the reason names; the worker processes them at the next check.');
    }

    /**
     * Blobs whose rendition failed HARD (a soft-failed blob is in the table above).
     *
     * @param int $limit 0 for every row.
     * @return void
     */
    private function __print_render_failures(int $limit): void
    {
        $base = fn () => File_Storage_Model::where('render_status_id', File_Storage_Model::RENDER_STATUS_FAILED)
            ->where(function ($query) {
                $query->whereNull('process_due_at')->orWhere('process_soft_failure_count', 0);
            });

        $total = $base()->count();

        if ($total === 0) {
            $this->line('[OK] No failed documents.');

            return;
        }

        $query = $base()->orderBy('id');

        // limit=0 means "every failed blob", which is unbounded by definition - iterate it by
        // keyset rather than materializing the whole set.
        $storages = $limit > 0 ? $query->limit($limit)->get() : $query->result_set();

        $rows = [];
        $shown = 0;
        foreach ($storages as $storage) {
            $rows[] = [
                $storage->id,
                substr((string) $storage->hash, 0, 12),
                Document_Render_Service::representative_file_name($storage) ?? '(orphan blob)',
                $this->__excerpt((string) $storage->render_error),
                $this->__attempts($storage),
                $this->__next_attempt($storage),
            ];
            $shown++;
        }

        $retrying = $base()->whereNotNull('process_due_at')->count();
        $spent = $total - $retrying;

        $this->line("{$total} failed document(s): {$retrying} waiting for another attempt, {$spent} out of attempts.");
        $this->table(['Storage', 'Hash', 'File name', 'Error', 'Attempts', 'Next attempt'], $rows);

        if ($shown < $total) {
            $this->line("  showing {$shown} of {$total} - use --limit=0 for all");
        }

        $this->line('  Re-queue with a full set of attempts: php artisan rsx:documents:rerender --failed');
    }

    /**
     * Blobs whose TEXT EXTRACTION failed HARD. A different queue with a different remedy, and a
     * document can easily fail one half while succeeding at the other. A soft-failed blob is in
     * the first table instead.
     *
     * @param int $limit 0 for every row.
     * @return void
     */
    private function __print_extraction_failures(int $limit): void
    {
        $base = Search_Index_Model::where('indexable_type', 'File_Storage_Model')
            ->where('status_id', Search_Index_Model::STATUS_FAILED)
            ->whereNotIn('indexable_id', $this->__soft_failed_query()->select('id')->toBase());

        $total = (clone $base)->count();

        if ($total === 0) {
            $this->line('[OK] No failed text extractions.');

            return;
        }

        $query = (clone $base)->orderBy('indexable_id');
        $indexes = $limit > 0 ? $query->limit($limit)->get() : $query->result_set();

        $rows = [];
        $shown = 0;
        foreach ($indexes as $index) {
            $storage = File_Storage_Model::find($index->indexable_id);

            $rows[] = [
                $index->indexable_id,
                $storage ? substr((string) $storage->hash, 0, 12) : '(gone)',
                $storage ? (Document_Render_Service::representative_file_name($storage) ?? '(orphan blob)') : '(gone)',
                $this->__excerpt((string) $index->error),
                $index->indexed_at === null ? '-' : Rsx_Time::format_datetime($index->indexed_at),
                $storage ? $this->__next_attempt($storage) : '-',
            ];
            $shown++;
        }

        $this->line("{$total} failed text extraction(s):");
        $this->table(['Storage', 'Hash', 'File name', 'Error', 'Last attempt', 'Next attempt'], $rows);

        if ($shown < $total) {
            $this->line("  showing {$shown} of {$total} - use --limit=0 for all");
        }

        $this->line('  Re-queue with a full set of attempts: php artisan rsx:search:reindex --failed');
    }

    /**
     * Attempts charged to this blob, of the attempts it gets.
     *
     * @param File_Storage_Model $storage
     * @return string
     */
    private function __attempts(File_Storage_Model $storage): string
    {
        return (int) $storage->process_attempt_count . ' of ' . File_Storage_Model::work_max_attempts();
    }

    /**
     * When the worker next attempts this blob, or that it will not.
     *
     * @param File_Storage_Model $storage
     * @return string
     */
    private function __next_attempt(File_Storage_Model $storage): string
    {
        return $storage->process_due_at === null
            ? 'none - out of attempts'
            : Rsx_Time::format_datetime($storage->process_due_at);
    }

    /**
     * One line of an error message, cut to a table-friendly width.
     *
     * @param string $message
     * @return string
     */
    private function __excerpt(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));

        if ($message === '') {
            return '(no reason recorded)';
        }

        if (strlen($message) <= self::ERROR_EXCERPT_LENGTH) {
            return $message;
        }

        return substr($message, 0, self::ERROR_EXCERPT_LENGTH - 3) . '...';
    }
}
