<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\Document_Sandbox;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Search\Pdftotext_Text_Extractor;
use App\RSpade\Core\Search\Plain_Text_Extractor;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Search\Search_Index_Service;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Documents\Php\Document_Throwing_Fixture_Renderer;
use App\RSpade\Tests\Documents\Php\Document_Worker_Fixture_Handler;

/**
 * SOFT failures in the document worker's queue: a pass whose renderer or extractor cannot run on
 * this box at all - the tool not installed or not executable, the sandbox image absent, the
 * source blob unreadable - is recorded as a failure but charges no attempt, runs nothing, and is
 * checked again on a doubling backoff (30 minutes, 1 hour, 2 hours ... capped at
 * rsx.documents.soft_retry_max_minutes) that never gives up. Every other failure stays HARD:
 * charged, 30 minutes apart, three attempts, then FAILED for good.
 *
 * The soft check runs in the PARENT (process_storage(), before the charge), so a runtime config
 * override reaches it: a missing tool is produced by pointing rsx.libreoffice.binary_path /
 * rsx.search.pdftotext_binary_path at a file this test writes without the execute bit, and an
 * absent sandbox image through Document_Sandbox::_testing_set_image_present(false). A child
 * process boots its own config, so tests that need a pass to really run arrange for the soft
 * check to pass and accept whatever the child then records.
 *
 * This class COMMITS - the worker's child is another process reading the same database - so it
 * declares the $requires_db_reset pair. Every test starts by emptying the queue so it sees only
 * the blobs it queued. Tests that run a real child are marked CHILD.
 */
class Document_Soft_Failure_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    /** @var array The shipped renderer map, restored after every override. */
    private static $shipped_renderers = [];

    public static function setup()
    {
        static::$shipped_renderers = config('rsx.documents.renderers');
        static::__empty_queue();
    }

    public static function teardown()
    {
        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        foreach (static::$created_files as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        static::$created_files = [];

        Document_Sandbox::_testing_set_image_present(null);
        config([
            'rsx.search.enabled' => true,
            'rsx.libreoffice.enabled' => true,
            'rsx.libreoffice.sandbox' => 'none',
            'rsx.libreoffice.binary_path' => null,
            'rsx.search.pdftotext_binary_path' => null,
            'rsx.documents.renderers' => static::$shipped_renderers,
            'rsx.documents.max_attempts' => 3,
            'rsx.documents.retry_after_minutes' => 30,
            'rsx.documents.soft_retry_max_minutes' => 10080,
        ]);
        DB::table('_tasks')->where('class', Document_Render_Service::class)->delete();
        DB::table('_task_schedules')->where('class', Document_Render_Service::class)->delete();
    }

    // ============================================================================================
    // HELPERS
    // ============================================================================================

    /**
     * Take every blob out of the queue, so what it offers is only what the current test queued.
     */
    private static function __empty_queue(): void
    {
        DB::statement('UPDATE _file_storage SET process_due_at = NULL WHERE process_due_at IS NOT NULL');
    }

    /**
     * Run $fn with config keys set, restoring each key's previous value afterwards.
     */
    private static function __with_config(array $values, callable $fn)
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = config($key);
        }

        config($values);

        try {
            return $fn();
        } finally {
            config($previous);
        }
    }

    /**
     * A script this test owns: executable or not. Returns its absolute path.
     */
    private static function __scratch_tool(bool $executable): string
    {
        $path = sys_get_temp_dir() . '/rsx_soft_failure_tool_' . bin2hex(random_bytes(6));
        file_put_contents($path, "#!/bin/bash\nexit 0\n");
        chmod($path, $executable ? 0755 : 0644);
        static::$created_files[] = $path;

        return $path;
    }

    /**
     * A fresh blob behind a tracked attachment, re-read from the database. $prefix leads the
     * bytes (a fixture marker, or nothing); a random tail keeps every blob its own.
     */
    private static function __make_blob(string $filename, string $prefix = ''): File_Storage_Model
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        $attachment = File_Attachment_Model::create_from_string(
            $prefix . ' RSpade soft failure test ' . bin2hex(random_bytes(8)),
            $filename,
            ['site_id' => $site_id]
        );
        static::$created_attachment_ids[] = $attachment->id;

        return File_Storage_Model::find($attachment->file_storage_id);
    }

    private static function __fresh(File_Storage_Model $storage): File_Storage_Model
    {
        return File_Storage_Model::find($storage->id);
    }

    /**
     * Write queue and outcome columns directly; process_due_at / process_last_attempt_at take a
     * SQL expression string (the database's clock).
     */
    private static function __force(File_Storage_Model $storage, array $values): File_Storage_Model
    {
        foreach (['process_due_at', 'process_last_attempt_at'] as $time_column) {
            if (array_key_exists($time_column, $values) && $values[$time_column] !== null) {
                $values[$time_column] = DB::raw($values[$time_column]);
            }
        }

        File_Storage_Model::where('id', $storage->id)->update($values);

        return static::__fresh($storage);
    }

    /**
     * Whether the row's due time lies inside a window, judged by the database's own clock.
     */
    private static function __due_between(File_Storage_Model $storage, string $from_sql, string $to_sql): bool
    {
        return (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM _file_storage WHERE id = ? AND process_due_at > {$from_sql} AND process_due_at <= {$to_sql}",
            [$storage->id]
        )->c === 1;
    }

    /**
     * The due time is $minutes from now (to within the minute the test itself takes).
     */
    private static function __assert_due_in_minutes(File_Storage_Model $storage, int $minutes, string $message): void
    {
        static::__assert_true(
            static::__due_between($storage, 'NOW(3) + INTERVAL ' . ($minutes - 1) . ' MINUTE', "NOW(3) + INTERVAL {$minutes} MINUTE"),
            $message . " (expected {$minutes} minutes from now, row says {$storage->process_due_at})"
        );
    }

    private static function __index(File_Storage_Model $storage): ?Search_Index_Model
    {
        return Search_Index_Model::forModel('File_Storage_Model', $storage->id)->first();
    }

    /**
     * A .docx blob that owes its rendition and its text, due now, with no attempt charged.
     */
    private static function __due_docx(string $filename): File_Storage_Model
    {
        $storage = static::__make_blob($filename);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, (int) $storage->render_status_id, 'precondition: a rendition is owed');

        return static::__force($storage, ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND', 'is_indexed' => 0]);
    }

    // ============================================================================================
    // A SOFT FAILURE IS RECORDED, NOT CHARGED
    // ============================================================================================

    // DOCUMENTS-SOFT-SOFFICE-MISSING: soffice not executable. The pass is SOFT_FAILED before the
    // charge: the attempt count and the last-attempt time are untouched, no child ran (no
    // rendition, the index row carries the parent's reason rather than an extractor's), the
    // reason is recorded on the render AND on the owed text, and the blob is due again one
    // retry interval from now with one soft failure on record.
    public static function test_soffice_missing_is_soft_and_charges_nothing()
    {
        static::__empty_queue();

        $storage = static::__force(static::__due_docx('soft_soffice.docx'), ['process_attempt_count' => 1]);
        $not_executable = static::__scratch_tool(false);

        $result = static::__with_config(
            ['rsx.libreoffice.sandbox' => 'none', 'rsx.libreoffice.binary_path' => $not_executable],
            fn () => Document_Render_Service::process_storage($storage)
        );

        static::__assert_equals(Document_Render_Service::OUTCOME_SOFT_FAILED, $result['outcome'], 'the pass is a soft failure: ' . (string) $result['reason']);
        static::__assert_contains('soffice', (string) $result['reason'], 'the reason names the missing tool');
        static::__assert_equals(1, $result['attempt'], 'the result reports the attempts charged - unchanged');
        static::__assert_equals(1, $result['soft_failures'], 'and one soft failure');

        $reloaded = static::__fresh($storage);
        static::__assert_equals(1, (int) $reloaded->process_attempt_count, 'NO attempt was charged');
        static::__assert_null($reloaded->process_last_attempt_at, 'and no attempt time recorded');
        static::__assert_equals(1, (int) $reloaded->process_soft_failure_count, 'one soft failure on record');
        static::__assert_true($reloaded->is_soft_failed(), 'the blob reads as soft-failed');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_FAILED, (int) $reloaded->render_status_id, 'the render reads FAILED, like any failure');
        static::__assert_equals((string) $result['reason'], (string) $reloaded->render_error, 'with the soft reason');
        static::__assert_null($reloaded->rendered_at);
        static::__assert_false(file_exists(File_Preview_Controller::rendition_cache_path($reloaded)), 'no rendition was produced - nothing ran');
        static::__assert_equals(0, (int) $reloaded->is_indexed, 'the text is still owed - a soft failure never settles it');

        $index = static::__index($storage);
        static::__assert_not_null($index, 'the owed text has a recorded outcome');
        static::__assert_equals(Search_Index_Model::STATUS_FAILED, (int) $index->status_id, 'FAILED');
        static::__assert_equals((string) $result['reason'], (string) $index->error, 'with the same soft reason - written by the parent, not by an extractor');
        static::__assert_equals('none', (string) $index->extraction_method, 'no extractor ran');

        static::__assert_due_in_minutes($reloaded, 30, 'the first soft failure is checked again one retry interval later');
        static::__assert_null(Document_Render_Service::next_due(), 'so no pass takes it before then');

        static::__force($storage, ['process_due_at' => null]);
    }

    // DOCUMENTS-SOFT-PDFTOTEXT-MISSING: text owed alone, by a PDF, with pdftotext not executable.
    // Nothing renders; the soft reason lands on the index row; no attempt is charged.
    public static function test_pdftotext_missing_is_soft()
    {
        static::__empty_queue();

        $storage = static::__make_blob('soft_scan.pdf');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_NOT_REQUIRED, (int) $storage->render_status_id, 'precondition: a PDF owes no rendition');
        static::__assert_equals(Pdftotext_Text_Extractor::class, Search_Index_Service::extractor_for_pass($storage, false), 'precondition: its text is pdftotext\'s');
        $storage = static::__force($storage, ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND', 'is_indexed' => 0]);

        $result = static::__with_config(
            ['rsx.libreoffice.sandbox' => 'none', 'rsx.search.pdftotext_binary_path' => static::__scratch_tool(false)],
            fn () => Document_Render_Service::process_storage($storage)
        );

        static::__assert_equals(Document_Render_Service::OUTCOME_SOFT_FAILED, $result['outcome'], 'the pass is a soft failure: ' . (string) $result['reason']);
        static::__assert_contains('pdftotext', (string) $result['reason']);

        $reloaded = static::__fresh($storage);
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'nothing charged');
        static::__assert_equals(1, (int) $reloaded->process_soft_failure_count);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_NOT_REQUIRED, (int) $reloaded->render_status_id, 'no render was owed, none is recorded');
        static::__assert_equals(0, (int) $reloaded->is_indexed, 'the text is still owed');
        static::__assert_equals((string) $result['reason'], (string) static::__index($storage)->error, 'the reason is on the index row');

        static::__force($storage, ['process_due_at' => null]);
    }

    // DOCUMENTS-SOFT-SANDBOX-IMAGE-ABSENT: under the docker sandbox, an absent image (looked for,
    // never built) is a soft failure - whether it is the client or the image that is missing on
    // this host, the pass is not charged.
    public static function test_sandbox_image_absent_is_soft()
    {
        static::__empty_queue();

        $storage = static::__due_docx('soft_sandbox.docx');

        $result = static::__with_config(
            ['rsx.libreoffice.sandbox' => 'docker', 'rsx.libreoffice.sandbox_image' => 'example/soft-failure-test-image:tag'],
            function () use ($storage) {
                Document_Sandbox::_testing_set_image_present(false);
                try {
                    return Document_Render_Service::process_storage($storage);
                } finally {
                    Document_Sandbox::_testing_set_image_present(null);
                }
            }
        );

        static::__assert_equals(Document_Render_Service::OUTCOME_SOFT_FAILED, $result['outcome'], 'the pass is a soft failure: ' . (string) $result['reason']);
        if (Document_Sandbox::client_present()) {
            static::__assert_contains("'example/soft-failure-test-image:tag' is not present", (string) $result['reason'], 'the reason names the image');
        }
        static::__assert_equals(0, (int) static::__fresh($storage)->process_attempt_count, 'nothing charged');

        static::__force($storage, ['process_due_at' => null]);
    }

    // DOCUMENTS-SOFT-SOURCE-UNREADABLE: the tool is fine but the source blob is gone from disk.
    // The renderer's source check makes the pass soft instead of charging a child that would
    // only fail on the missing file.
    public static function test_unreadable_source_is_soft()
    {
        static::__empty_queue();

        $storage = static::__due_docx('soft_source.docx');
        unlink($storage->get_full_path());

        $result = static::__with_config(
            ['rsx.libreoffice.sandbox' => 'none', 'rsx.libreoffice.binary_path' => static::__scratch_tool(true)],
            fn () => Document_Render_Service::process_storage($storage)
        );

        static::__assert_equals(Document_Render_Service::OUTCOME_SOFT_FAILED, $result['outcome'], 'the pass is a soft failure: ' . (string) $result['reason']);
        static::__assert_contains($storage->get_full_path(), (string) $result['reason'], 'the reason names the missing source');
        static::__assert_equals(0, (int) static::__fresh($storage)->process_attempt_count, 'nothing charged');

        static::__force($storage, ['process_due_at' => null]);
    }

    // DOCUMENTS-SOFT-TEXT-ONLY-MISSING-SOURCE: a blob owing only its text, of a type no document
    // extension names (.txt), whose source is gone. With the bytes gone the type cannot be
    // sniffed, so the extractor is chosen from the type the blob was stored as - and the
    // plain-text extractor's source check makes the pass soft instead of charging a child that
    // would only record 'source blob missing on disk'.
    public static function test_text_only_missing_source_is_soft()
    {
        static::__empty_queue();

        $storage = static::__make_blob('soft_text_only.txt');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_NOT_REQUIRED, (int) $storage->render_status_id, 'precondition: a .txt owes no rendition');
        $storage = static::__force($storage, ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND', 'is_indexed' => 0]);
        unlink($storage->get_full_path());

        static::__assert_equals(
            Plain_Text_Extractor::class,
            Search_Index_Service::extractor_for_pass($storage, false),
            'with the source gone the extractor is still the plain-text one'
        );

        $result = Document_Render_Service::process_storage($storage);

        static::__assert_equals(Document_Render_Service::OUTCOME_SOFT_FAILED, $result['outcome'], 'the pass is a soft failure: ' . (string) $result['reason']);
        static::__assert_contains($storage->get_full_path(), (string) $result['reason'], 'the reason names the missing source');

        $reloaded = static::__fresh($storage);
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'nothing charged');
        static::__assert_equals(1, (int) $reloaded->process_soft_failure_count, 'one soft failure on record');
        static::__assert_equals(0, (int) $reloaded->is_indexed, 'the text is still owed');
        static::__assert_equals('none', (string) static::__index($storage)->extraction_method, 'no extractor ran');

        static::__force($storage, ['process_due_at' => null]);
    }

    // DOCUMENTS-SOFT-ANY-PART: one owed part soft-failing makes the whole pass soft. A docx whose
    // renderer is healthy but whose text is owed by an extractor that cannot run (the rendition
    // is already on disk, so its text is pdftotext's) runs nothing - not even the render.
    public static function test_one_soft_part_makes_the_whole_pass_soft()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('soft_text_part.docx'), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_RENDERED,
            'is_indexed' => 0,
            'process_due_at' => 'NOW(3) - INTERVAL 1 SECOND',
        ]);

        $rendition = File_Preview_Controller::rendition_cache_path($storage);
        if (!is_dir(dirname($rendition))) {
            mkdir(dirname($rendition), 0755, true);
        }
        file_put_contents($rendition, "%PDF-1.4\n% soft failure test\n");
        static::$created_files[] = $rendition;

        $result = static::__with_config(
            [
                'rsx.libreoffice.sandbox' => 'none',
                'rsx.libreoffice.binary_path' => static::__scratch_tool(true),
                'rsx.search.pdftotext_binary_path' => static::__scratch_tool(false),
            ],
            fn () => Document_Render_Service::process_storage($storage)
        );

        static::__assert_equals(Document_Render_Service::OUTCOME_SOFT_FAILED, $result['outcome'], 'the text part alone makes the pass soft: ' . (string) $result['reason']);
        static::__assert_contains('pdftotext', (string) $result['reason']);

        $reloaded = static::__fresh($storage);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_RENDERED, (int) $reloaded->render_status_id, 'an existing rendition is not marked failed - no render was owed');
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'nothing charged');

        static::__force($storage, ['process_due_at' => null]);
    }

    // ============================================================================================
    // THE BACKOFF
    // ============================================================================================

    // DOCUMENTS-SOFT-BACKOFF: each consecutive soft failure doubles the wait - 30, 60, 120, 240
    // minutes - and the wait never exceeds rsx.documents.soft_retry_max_minutes (one week). The
    // backoff never ends: a blob far past the cap is still in the queue, still checked weekly.
    public static function test_backoff_doubles_and_is_capped()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('backoff.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']);

        foreach ([30, 60, 120, 240, 480] as $failures_before => $minutes) {
            $storage->record_soft_failure();
            static::__assert_equals($failures_before + 1, (int) $storage->process_soft_failure_count, 'the count goes up by one (the instance is current)');
            static::__assert_due_in_minutes($storage, $minutes, "soft failure " . ($failures_before + 1));
            static::__assert_equals(0, (int) $storage->process_attempt_count, 'and nothing is ever charged');
        }

        // Far past the cap: 30 * 2^9 = 15360 minutes > 10080.
        $storage = static::__force($storage, ['process_soft_failure_count' => 9]);
        $storage->record_soft_failure();
        static::__assert_due_in_minutes($storage, 10080, 'the wait is capped at one week');

        // An absurd run of failures stays at the cap (the exponent is clamped, the arithmetic never overflows).
        $storage = static::__force($storage, ['process_soft_failure_count' => 5000]);
        $storage->record_soft_failure();
        static::__assert_due_in_minutes($storage, 10080, 'still the cap after 5000 soft failures');
        static::__assert_equals(5001, (int) $storage->process_soft_failure_count);

        // The cap is the config's.
        static::__with_config(['rsx.documents.soft_retry_max_minutes' => 100], function () use ($storage) {
            $storage = static::__force($storage, ['process_soft_failure_count' => 2]);
            $storage->record_soft_failure();
            static::__assert_due_in_minutes($storage, 100, 'min(30 * 2^2, 100) = 100');
        });

        static::__force($storage, ['process_due_at' => null, 'process_soft_failure_count' => 0]);
    }

    // DOCUMENTS-SOFT-CAP-VALIDATED: the cap is a whole number of minutes and never below the
    // retry interval the backoff starts from; anything else fails loud.
    public static function test_soft_retry_cap_is_validated()
    {
        static::__assert_equals(10080, File_Storage_Model::work_soft_retry_max_minutes(), 'the shipped cap is one week');

        static::__with_config(['rsx.documents.soft_retry_max_minutes' => 29], function () {
            static::__assert_throws(\Exception::class, fn () => File_Storage_Model::work_soft_retry_max_minutes(), 'must not be below rsx.documents.retry_after_minutes');
        });

        static::__with_config(['rsx.documents.soft_retry_max_minutes' => 'a week'], function () {
            static::__assert_throws(\Exception::class, fn () => File_Storage_Model::work_soft_retry_max_minutes(), 'must be a whole number of minutes');
        });

        static::__with_config(['rsx.documents.soft_retry_max_minutes' => null], function () {
            static::__assert_throws(\Exception::class, fn () => File_Storage_Model::work_soft_retry_max_minutes(), 'must be a whole number of minutes');
        });
    }

    // ============================================================================================
    // A PASS THAT RUNS RESTARTS IT
    // ============================================================================================

    // DOCUMENTS-SOFT-CHARGE-RESETS: charging an attempt ends a backoff in progress and stamps the
    // attempt's time.
    public static function test_charge_resets_the_backoff_and_stamps_the_attempt()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('charged.txt'), [
            'process_due_at' => 'NOW(3) - INTERVAL 1 SECOND',
            'process_soft_failure_count' => 4,
        ]);

        static::__assert_true($storage->charge_work_attempt(), 'the attempt is charged');
        static::__assert_equals(0, (int) $storage->process_soft_failure_count, 'the soft count returns to 0');
        static::__assert_equals(1, (int) $storage->process_attempt_count);
        static::__assert_not_null($storage->process_last_attempt_at, 'the attempt time is recorded');
        static::__assert_equals(
            1,
            (int) DB::selectOne('SELECT COUNT(*) AS c FROM _file_storage WHERE id = ? AND process_last_attempt_at > NOW(3) - INTERVAL 1 MINUTE', [$storage->id])->c,
            'on the database clock, just now'
        );

        static::__force($storage, ['process_due_at' => null]);
    }

    // DOCUMENTS-SOFT-RECOVERY: a soft-failed blob whose environment is repaired runs its next pass
    // like any other - it is charged, the soft count returns to 0 and the attempt time is
    // stamped. The tool is "repaired" in the parent's config; the child then runs whatever this
    // host has, and its outcome (extracted, or a hard failure on these non-PDF bytes) does not
    // matter here - only that the pass ran and was counted. CHILD.
    public static function test_repaired_environment_runs_and_resets_the_backoff()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('recovers.pdf'), ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND', 'is_indexed' => 0]);

        $result = static::__with_config(
            ['rsx.libreoffice.sandbox' => 'none', 'rsx.search.pdftotext_binary_path' => static::__scratch_tool(false)],
            fn () => Document_Render_Service::process_storage($storage)
        );
        static::__assert_equals(Document_Render_Service::OUTCOME_SOFT_FAILED, $result['outcome'], 'precondition: the broken tool soft-fails the pass');

        // The check comes due.
        $storage = static::__force($storage, ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']);

        $result = static::__with_config(
            ['rsx.libreoffice.sandbox' => 'none', 'rsx.search.pdftotext_binary_path' => static::__scratch_tool(true)],
            fn () => Document_Render_Service::process_storage($storage)
        );

        static::__assert_true(
            in_array($result['outcome'], [Document_Render_Service::OUTCOME_DONE, Document_Render_Service::OUTCOME_RETRY], true),
            'the repaired pass ran: ' . $result['outcome'] . ' ' . (string) $result['reason']
        );

        $reloaded = static::__fresh($storage);
        static::__assert_equals(0, (int) $reloaded->process_soft_failure_count, 'the soft count returned to 0');
        static::__assert_not_null($reloaded->process_last_attempt_at, 'the attempt that ran is on record');
        if ($result['outcome'] === Document_Render_Service::OUTCOME_RETRY) {
            static::__assert_equals(1, (int) $reloaded->process_attempt_count, 'a hard failure is charged');
            static::__assert_due_in_minutes($reloaded, 30, 'and retried on the HARD schedule');
        }

        static::__force($storage, ['process_due_at' => null]);
    }

    // ============================================================================================
    // HARD STAYS HARD
    // ============================================================================================

    // DOCUMENTS-SOFT-HARD-UNCHANGED: a document that fails is still charged, still retried 30
    // minutes apart, and still FAILED for good after the third attempt - the soft count never
    // moves. CHILD x3.
    public static function test_hard_failure_is_still_charged_and_ends_failed()
    {
        static::__empty_queue();

        $storage = static::__force(
            static::__make_blob('hard.txt', Document_Worker_Fixture_Handler::MARKER_FAIL),
            ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']
        );

        foreach ([1, 2] as $attempt) {
            $result = Document_Render_Service::process_storage(static::__fresh($storage));

            static::__assert_equals(Document_Render_Service::OUTCOME_RETRY, $result['outcome'], "attempt {$attempt} is a hard failure: " . (string) $result['reason']);

            $reloaded = static::__fresh($storage);
            static::__assert_equals($attempt, (int) $reloaded->process_attempt_count, 'charged');
            static::__assert_equals(0, (int) $reloaded->process_soft_failure_count, 'never counted as soft');
            static::__assert_not_null($reloaded->process_last_attempt_at, 'the attempt time is recorded');
            static::__assert_due_in_minutes($reloaded, 30, 'retried one interval later');

            static::__force($storage, ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']);
        }

        $result = Document_Render_Service::process_storage(static::__fresh($storage));
        static::__assert_equals(Document_Render_Service::OUTCOME_FAILED, $result['outcome'], 'the third hard failure is permanent');

        $reloaded = static::__fresh($storage);
        static::__assert_null($reloaded->process_due_at, 'out of the queue');
        static::__assert_equals(3, (int) $reloaded->process_attempt_count, 'attempts spent');
        static::__assert_false($reloaded->is_soft_failed());
    }

    // DOCUMENTS-SOFT-NO-CHECKS-IS-HARD: a renderer that declares no soft checks never fails
    // softly. Its own failure is recorded as any render failure is; and even with the source
    // gone - which the framework renderers would call soft - the pass is charged, the child
    // fails on the missing file (the hard backstop), and the attempt counts. CHILD.
    public static function test_renderer_without_soft_checks_fails_hard()
    {
        static::__empty_queue();

        $docx_mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        static::__with_config(
            ['rsx.documents.renderers' => array_merge(static::$shipped_renderers, [$docx_mime => 'Document_Throwing_Fixture_Renderer'])],
            function () {
                $storage = static::__force(static::__due_docx('throws.docx'), ['is_indexed' => 1]);
                static::__assert_equals(
                    Document_Throwing_Fixture_Renderer::class,
                    Document_Render_Service::renderer_for_storage($storage),
                    'precondition: the fixture renders this type'
                );
                static::__assert_null(Document_Render_Service::soft_failure_for($storage), 'a renderer with no checks has no soft failure');

                // Its render throws: recorded as a failure like any other, in this process.
                Document_Render_Service::render_storage($storage);
                $rendered = static::__fresh($storage);
                static::__assert_equals(File_Storage_Model::RENDER_STATUS_FAILED, (int) $rendered->render_status_id);
                static::__assert_equals(Document_Throwing_Fixture_Renderer::FAILURE_MESSAGE, (string) $rendered->render_error);
                static::__assert_equals(0, (int) $rendered->process_soft_failure_count, 'never soft');

                // The source vanishes: still no soft check to catch it, so the pass is charged
                // and fails hard in the child.
                unlink($storage->get_full_path());
                static::__assert_null(Document_Render_Service::soft_failure_for($rendered), 'still no soft failure without the source');

                $result = Document_Render_Service::process_storage($rendered);
                static::__assert_equals(Document_Render_Service::OUTCOME_RETRY, $result['outcome'], 'a hard, charged failure: ' . (string) $result['reason']);
                static::__assert_contains('source blob missing on disk', (string) $result['reason'], 'the child\'s backstop names the missing file');

                $reloaded = static::__fresh($storage);
                static::__assert_equals(1, (int) $reloaded->process_attempt_count, 'the attempt counted');
                static::__assert_equals(0, (int) $reloaded->process_soft_failure_count);

                static::__force($storage, ['process_due_at' => null]);
            }
        );
    }

    // DOCUMENTS-SOFT-AFTER-SWITCH: a switched-off renderer is WAITING, not soft - the switch is
    // read before the soft check, and a deferral clears a soft backoff in progress.
    public static function test_switched_off_is_waiting_not_soft()
    {
        static::__empty_queue();

        $storage = static::__force(static::__due_docx('switched_off.docx'), ['is_indexed' => 1, 'process_soft_failure_count' => 3]);

        $result = static::__with_config(
            ['rsx.libreoffice.enabled' => false, 'rsx.libreoffice.binary_path' => static::__scratch_tool(false)],
            fn () => Document_Render_Service::process_storage($storage)
        );

        static::__assert_equals(Document_Render_Service::OUTCOME_WAITING, $result['outcome']);
        $reloaded = static::__fresh($storage);
        static::__assert_equals(0, (int) $reloaded->process_soft_failure_count, 'a deferral is not a soft failure and ends the backoff');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, (int) $reloaded->render_status_id, 'nothing recorded as failed');

        static::__force($storage, ['process_due_at' => null]);
    }

    // ============================================================================================
    // THE QUEUE'S OTHER WRITES
    // ============================================================================================

    // DOCUMENTS-SOFT-PULL-FORWARD: pull_work_forward() makes a waiting blob due now with nothing
    // charged, and touches nothing else - not a blob already due, not one outside the queue.
    public static function test_pull_work_forward_is_guarded()
    {
        static::__empty_queue();

        $waiting = static::__force(static::__make_blob('pull_waiting.txt'), [
            'process_due_at' => 'NOW(3) + INTERVAL 2 HOUR',
            'process_attempt_count' => 1,
            'process_soft_failure_count' => 2,
        ]);
        static::__assert_true($waiting->pull_work_forward(), 'a waiting blob is pulled forward');
        static::__assert_true(static::__due_between($waiting, 'NOW(3) - INTERVAL 1 MINUTE', 'NOW(3)'), 'it is due now (the instance is current)');
        static::__assert_equals(1, (int) $waiting->process_attempt_count, 'nothing charged');
        static::__assert_equals(2, (int) $waiting->process_soft_failure_count, 'the soft count untouched');

        static::__assert_false($waiting->pull_work_forward(), 'a blob already due is not moved');

        $outside = static::__force(static::__make_blob('pull_outside.txt'), ['process_due_at' => null, 'process_attempt_count' => 3]);
        static::__assert_false($outside->pull_work_forward(), 'a blob outside the queue is never put back');
        static::__assert_null(static::__fresh($outside)->process_due_at, 'it stays out');

        static::__force($waiting, ['process_due_at' => null]);
    }

    // DOCUMENTS-SOFT-RESETS: entering the queue (work_owed_values / mark_work_owed) starts the
    // accounting over; leaving it (close_work) ends the backoff but keeps the last attempt's time.
    public static function test_owed_and_close_reset_the_soft_count()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('resets.txt'), [
            'process_due_at' => 'NOW(3) + INTERVAL 1 HOUR',
            'process_soft_failure_count' => 3,
            'process_last_attempt_at' => 'NOW(3) - INTERVAL 1 DAY',
        ]);

        $storage->close_work();
        static::__assert_equals(0, (int) $storage->process_soft_failure_count, 'close_work(): no backoff left running');
        static::__assert_not_null($storage->process_last_attempt_at, 'close_work(): the last attempt stays on record');

        $storage = static::__force($storage, ['process_soft_failure_count' => 3]);
        $storage->mark_work_owed();
        static::__assert_equals(0, (int) $storage->process_soft_failure_count, 'mark_work_owed(): the backoff starts over');
        static::__assert_null($storage->process_last_attempt_at, 'mark_work_owed(): no attempt on record');
        static::__assert_equals(0, (int) $storage->process_attempt_count);

        static::__force($storage, ['process_due_at' => null]);
    }

    // ============================================================================================
    // REPORTING
    // ============================================================================================

    // DOCUMENTS-SOFT-REPORTED: the drain counts a soft failure in its own figure; queue_statistics()
    // gives it its own bucket (due / retrying / soft_failed / deferred partition the queue), and
    // the rsx:health backlog row WARNs naming the cause.
    public static function test_soft_failure_is_reported()
    {
        static::__empty_queue();

        $storage = static::__due_docx('reported.docx');

        $state = static::__with_config(
            ['rsx.libreoffice.sandbox' => 'none', 'rsx.libreoffice.binary_path' => static::__scratch_tool(false)],
            fn () => static::__run_task_method(Document_Render_Service::class, 'render_pending')
        );

        static::__assert_equals(1, (int) ($state['soft_failed'] ?? -1), 'the drain counted one soft failure');
        static::__assert_equals(1, (int) ($state['processed'] ?? -1), 'among the documents it processed');
        static::__assert_equals(0, (int) static::__fresh($storage)->process_attempt_count, 'and charged nothing');

        $queue = Document_Render_Service::queue_statistics();
        static::__assert_equals(1, $queue['soft_failed'], 'one soft-failed blob in the queue');
        static::__assert_equals(0, $queue['retrying'], 'not counted as retrying');
        static::__assert_equals(0, $queue['deferred'], 'nor as deferred');

        $row = Document_Render_Service::render_backlog();
        static::__assert_equals('WARN', $row['status']);
        static::__assert_contains('soft_failed=1', $row['detail']);
        static::__assert_contains('a tool or the source file is unavailable', $row['detail'], 'the row names the cause');

        static::__force($storage, ['process_due_at' => null, 'process_soft_failure_count' => 0, 'render_status_id' => File_Storage_Model::RENDER_STATUS_NOT_REQUIRED]);
    }
}
