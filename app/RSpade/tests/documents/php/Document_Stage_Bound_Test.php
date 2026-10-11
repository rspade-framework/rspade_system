<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\Document_Stage_Bound;
use App\RSpade\Core\Files\Document_Stage_Timeout_Exception;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Search\Search_Index_Service;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Documents\Php\Document_Worker_Fixture_Handler;

/**
 * The wall-clock bound on one stage of the document worker (Document_Stage_Bound), and what the
 * two stages - render and text extraction - record when one runs past it.
 *
 * Everything here runs IN THIS PROCESS: the bound is an alarm in whichever command-line process
 * runs the stage, and the test runner is one. The limit is rsx.documents.stage_seconds, read when
 * a stage starts, so each test sets it to one second and the "work" is a loop that would never
 * end. Nothing is proved about speed - only that a stage which does not return is stopped, that
 * the stop is recorded as an ordinary FAILED outcome with its reason, and that the process is
 * left with no alarm armed.
 *
 * The class COMMITS (render_storage() notifies on commit and reads its own writes back), hence
 * the $requires_db_reset pair.
 */
class Document_Stage_Bound_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    public static function setup()
    {
        config(['rsx.documents.stage_seconds' => 1]);
    }

    public static function teardown()
    {
        Document_Render_Service::$_testing_render_stage = null;

        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        config(['rsx.documents.stage_seconds' => 20]);
    }

    private static function __make_blob(string $filename, string $prefix = ''): File_Storage_Model
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        $attachment = File_Attachment_Model::create_from_string(
            $prefix . ' RSpade stage bound test ' . bin2hex(random_bytes(8)),
            $filename,
            ['site_id' => $site_id]
        );
        static::$created_attachment_ids[] = $attachment->id;

        return File_Storage_Model::find($attachment->file_storage_id);
    }

    /**
     * Work that never returns, in PHP statements - what an alarm's handler can interrupt.
     */
    private static function __never_returns(): void
    {
        while (true) {
            usleep(20000);
        }
    }

    /**
     * Seconds left on this process's alarm (0 = none armed). Reading it disarms it, which is
     * exactly what a test that expects none wants.
     */
    private static function __alarm_remaining(): int
    {
        return pcntl_alarm(0);
    }

    // ============================================================================================
    // THE BOUND
    // ============================================================================================

    // DOCUMENTS-STAGE-RETURNS: a stage that finishes hands back its value, and leaves no alarm
    // armed behind it to fire in whatever the process does next.
    public static function test_finished_stage_returns_its_value_and_disarms()
    {
        $value = Document_Stage_Bound::run('render', fn () => 'the rendition');

        static::__assert_equals('the rendition', $value);
        static::__assert_equals(0, static::__alarm_remaining(), 'no alarm is left armed');
    }

    // DOCUMENTS-STAGE-OVERRUN: a stage that does not return is stopped with the dedicated
    // exception, whose message is the reason that gets recorded. Afterwards the process is back
    // to normal: no alarm, and the next stage runs.
    public static function test_overrunning_stage_is_stopped()
    {
        static::__assert_throws(
            Document_Stage_Timeout_Exception::class,
            fn () => Document_Stage_Bound::run('render', fn () => static::__never_returns()),
            'render exceeded 1 seconds'
        );

        static::__assert_equals(0, static::__alarm_remaining(), 'the alarm is cleared after an overrun too');
        static::__assert_equals('next', Document_Stage_Bound::run('text extraction', fn () => 'next'), 'the next stage runs normally');
    }

    // DOCUMENTS-STAGE-FAILURE-PASSES-THROUGH: an ordinary failure inside a stage is the caller's
    // exception, untouched - and still leaves no alarm behind.
    public static function test_failure_inside_a_stage_passes_through_and_disarms()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn () => Document_Stage_Bound::run('render', function () {
                throw new \RuntimeException('the converter said no');
            }),
            'the converter said no'
        );

        static::__assert_equals(0, static::__alarm_remaining(), 'the alarm is cleared when the stage throws');
    }

    // DOCUMENTS-STAGE-BINARY-NEEDS-A-STAGE: an external document binary is only ever started
    // inside a bounded stage; outside one the call is refused, because it would be a converter
    // of hostile input with nothing bounding it.
    public static function test_binary_outside_a_stage_is_refused()
    {
        static::__assert_throws(
            \Throwable::class,
            fn () => Document_Stage_Bound::run_process(['true']),
            'outside a bounded stage'
        );
    }

    // DOCUMENTS-STAGE-STOPS-THE-BINARY: when the stage expires while an external binary is
    // running, the binary is stopped with it - it does not outlive the stage that started it.
    public static function test_expired_stage_stops_its_external_binary()
    {
        // A duration no other process on the box is sleeping for, so the binary can be found by
        // its command line.
        $unique_duration = '86' . random_int(100000, 999999);

        static::__assert_throws(
            Document_Stage_Timeout_Exception::class,
            fn () => Document_Stage_Bound::run('render', fn () => Document_Stage_Bound::run_process(['sleep', $unique_duration])),
            'render exceeded 1 seconds'
        );

        static::__assert_true(
            static::__no_process_with_argument($unique_duration),
            "the binary started inside the stage (sleep {$unique_duration}) is still running after the stage expired"
        );
    }

    /**
     * Wait until no live process carries $argument on its command line. A stopped process is
     * gone a moment AFTER it is signalled, so this polls; the 120 second bound is a wait on a
     * process outside this test's control, and expiry fails the caller's assertion.
     */
    private static function __no_process_with_argument(string $argument): bool
    {
        $deadline = microtime(true) + 120;

        while (microtime(true) < $deadline) {
            $found = false;

            foreach (glob('/proc/[0-9]*/cmdline') as $cmdline_file) {
                $cmdline = @file_get_contents($cmdline_file);
                if ($cmdline !== false && str_contains($cmdline, $argument)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return true;
            }

            usleep(50000);
        }

        return false;
    }

    // ============================================================================================
    // WHAT THE TWO STAGES RECORD
    // ============================================================================================

    // DOCUMENTS-STAGE-RENDER-TIMEOUT-RECORDED: a render that runs past the bound lands in the unit
    // of work's per-document catch like any other failure: FAILED, with the reason naming the
    // stage and the limit.
    public static function test_render_stage_timeout_is_recorded_failed()
    {
        $storage = static::__make_blob('never_renders.docx');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, (int) $storage->render_status_id, 'precondition: a rendition is owed');

        // Extraction is the other stage and not the subject here.
        $storage->is_indexed = 1;
        $storage->save();

        Document_Render_Service::$_testing_render_stage = fn () => static::__never_returns();

        try {
            Document_Render_Service::render_storage($storage);
        } finally {
            Document_Render_Service::$_testing_render_stage = null;
        }

        $reloaded = File_Storage_Model::find($storage->id);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_FAILED, (int) $reloaded->render_status_id, 'the render is FAILED');
        static::__assert_equals('render exceeded 1 seconds', (string) $reloaded->render_error, 'the reason names the stage and the limit');
        static::__assert_equals(0, static::__alarm_remaining(), 'no alarm is left armed');
    }

    // DOCUMENTS-STAGE-EXTRACT-TIMEOUT-RECORDED: an extraction that runs past the bound is a FAILED
    // index row with its reason - and, being a failure rather than an answer, leaves the text
    // OWED so the worker's attempt counter decides whether it is tried again.
    public static function test_extraction_stage_timeout_is_recorded_failed_and_stays_owed()
    {
        $storage = static::__make_blob('never_extracts.txt', Document_Worker_Fixture_Handler::MARKER_SLOW);

        $index = Search_Index_Service::extract_storage($storage);

        static::__assert_equals(Search_Index_Model::STATUS_FAILED, (int) $index->status_id, 'the extraction is FAILED');
        static::__assert_equals('text extraction exceeded 1 seconds', (string) $index->error, 'the reason names the stage and the limit');
        static::__assert_equals(0, (int) File_Storage_Model::find($storage->id)->is_indexed, 'the text is still owed');
        static::__assert_equals(0, static::__alarm_remaining(), 'no alarm is left armed');
    }

    // DOCUMENTS-STAGE-BOTH-IN-ONE-PASS: each stage has its OWN bound. A pass whose render
    // overruns still gets a full extraction stage afterwards, and each failure is recorded where
    // it belongs.
    public static function test_each_stage_is_bounded_separately_in_one_pass()
    {
        $storage = static::__make_blob('both_overrun.docx', Document_Worker_Fixture_Handler::MARKER_SLOW);

        Document_Render_Service::$_testing_render_stage = fn () => static::__never_returns();

        try {
            Document_Render_Service::render_storage($storage);
        } finally {
            Document_Render_Service::$_testing_render_stage = null;
        }

        $reloaded = File_Storage_Model::find($storage->id);
        static::__assert_equals('render exceeded 1 seconds', (string) $reloaded->render_error, 'the render stage hit its bound');

        $index = Search_Index_Model::forModel('File_Storage_Model', $storage->id)->first();
        static::__assert_not_null($index, 'the extraction stage still ran, after the render had failed');
        static::__assert_equals('text extraction exceeded 1 seconds', (string) $index->error, 'and hit a bound of its own');
    }
}
