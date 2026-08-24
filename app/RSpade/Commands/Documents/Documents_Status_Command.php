<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Documents;

use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Time\Rsx_Time;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * rsx:documents:status - the state of the document render pipeline, in one screen.
 *
 * Four questions an operator asks when a thumbnail is still a placeholder, answered in order:
 * how much is queued or failed (render), how much text extraction is owed, how full the rendition
 * cache is, and whether the worker is actually scheduled to run.
 *
 * Read-only and always exit 0 - "silent success" does not apply to a command whose entire product
 * IS the output.
 */
class Documents_Status_Command extends Command
{
    protected $signature = 'rsx:documents:status';

    protected $description = 'Report the document render pipeline state (render, extraction, rendition cache, worker)';

    public function handle(): int
    {
        $stats = Document_Render_Service::get_statistics();

        $this->line('Document render pipeline status');
        $this->line('  rendering enabled  : ' . (config('rsx.libreoffice.enabled', true) ? 'yes' : 'no') . '  (rsx.libreoffice.enabled)');
        $this->line('  extraction enabled : ' . (config('rsx.search.enabled', true) ? 'yes' : 'no') . '  (rsx.search.enabled)');
        $this->newLine();

        $this->__print_render_states($stats['render']);
        $this->newLine();

        $this->__print_extraction($stats['extraction'], (int) $stats['unindexed']);
        $this->newLine();

        $this->__print_renditions($stats['renditions']);
        $this->newLine();

        $this->__print_worker_schedule();

        return 0;
    }

    /**
     * Blob render states, one row per enum entry - INCLUDING the zero rows. A state that is
     * missing from the table reads as "not possible here"; a state showing 0 reads as "nothing is
     * in it right now", which is the fact being reported.
     *
     * @param array $render_counts label => count, in enum order (get_statistics builds it by
     *                             walking render_status_id__enum()).
     * @return void
     */
    private function __print_render_states(array $render_counts): void
    {
        $rows = [];
        foreach ($render_counts as $label => $count) {
            $rows[] = [$label, $count];
        }

        $this->line('Blob render state (_file_storage.render_status_id):');
        $this->table(['State', 'Blobs'], $rows);
    }

    /**
     * Text extraction: the queue (is_indexed=0, which has no index row at all) followed by the
     * terminal outcomes recorded in _search_indexes.
     *
     * @param array $extraction_counts label => count.
     * @param int $unindexed
     * @return void
     */
    private function __print_extraction(array $extraction_counts, int $unindexed): void
    {
        $rows = [['Queued (is_indexed=0)', $unindexed]];
        foreach ($extraction_counts as $label => $count) {
            $rows[] = [$label, $count];
        }

        $this->line('Text extraction (_search_indexes):');
        $this->table(['State', 'Blobs'], $rows);
    }

    /**
     * The PDF rendition cache - the shared product of a render, LRU-evicted under quota.
     *
     * @param array $renditions File_Rendition_Service::get_statistics().
     * @return void
     */
    private function __print_renditions(array $renditions): void
    {
        $this->line('PDF rendition cache (storage/rsx-renditions):');

        if (empty($renditions['exists'])) {
            $this->table(['Metric', 'Value'], [['Directory', 'not created yet (nothing rendered)']]);

            return;
        }

        $rows = [
            ['Files', number_format((int) $renditions['file_count'])],
            ['Size', bytes_to_human((int) $renditions['total_bytes'])],
            ['Quota', bytes_to_human((int) $renditions['max_bytes'])],
            ['Usage', $renditions['usage_percent'] . '%'],
            ['Oldest', $this->__format_mtime($renditions['oldest'])],
            ['Newest', $this->__format_mtime($renditions['newest'])],
        ];

        $this->table(['Metric', 'Value'], $rows);
    }

    /**
     * The one closing line: is the 10-minute sweeper registered, and when does it next run.
     *
     * The tracker row is the #[Schedule] row reconciled by rsx:task:process (class + method, with
     * next_run_at set - see Task_Process_Command::reconcile_schedules). Its ABSENCE is the answer
     * to "why has nothing rendered for an hour" often enough to earn a line of its own.
     *
     * @return void
     */
    private function __print_worker_schedule(): void
    {
        $tracker = DB::table('_tasks')
            ->where('class', Document_Render_Service::class)
            ->where('method', 'render_pending')
            ->whereNotNull('next_run_at')
            ->first();

        if (!$tracker) {
            $this->line('Worker schedule: not registered - run php artisan rsx:task:process');

            return;
        }

        $next = Rsx_Time::format_datetime($tracker->next_run_at);
        $this->line("Worker schedule: {$tracker->cron_expression}, next run {$next}");
    }

    /**
     * Format a filesystem mtime (unix seconds) as a datetime plus its age.
     *
     * @param int|null $mtime
     * @return string
     */
    private function __format_mtime(?int $mtime): string
    {
        if ($mtime === null) {
            return '-';
        }

        $iso = date('c', $mtime);

        return Rsx_Time::format_datetime($iso) . ' (' . Rsx_Time::relative($iso) . ')';
    }
}
