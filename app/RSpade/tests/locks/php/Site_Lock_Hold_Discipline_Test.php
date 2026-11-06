<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Email_Blocked_Address_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Locks\Php\Site_Lock_Cli_Fixture_Command;
use App\RSpade\Tests\Locks\Php\Site_Lock_Fixture_Service;

/**
 * Holding a site lock well: the write exception (Site_Lock::write_exception() and its token
 * form), the public lock-state queries (held_mode(), in_write_exception(), in_bypass()), the
 * rolling-back error answer (Rollback_Response) and the read-to-write upgrade refusal on every
 * path - plus the framework file routes reading under a read section, where a gate handler
 * records under a write exception.
 *
 * In one process against the running rsx-lockd, acting as the baseline user on site 1. The row
 * written is the framework's site-scoped Email_Blocked_Address_Model. Every test runs inside the
 * harness's per-test transaction: "commits at once" (autocommit) is observed as a row that
 * survives the throw of the code around it, which rolls back nothing of its own.
 */
class Site_Lock_Hold_Discipline_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;
    private const OTHER_SITE_ID = 987652;
    private const FIXTURE = 'Site_Lock_Exception_Fixture_Controller';

    /** What the test download gate handler saw: held_mode() and whether its record landed. */
    private static array $gate_seen = [];

    public static function setup(): void
    {
        static::__acting_as_user(1);
        static::$gate_seen = [];
        Artisan::registerCommand(new Site_Lock_Cli_Fixture_Command());
    }

    public static function teardown(): void
    {
        Event_Registry::_clear_test_handlers();
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();

        foreach ([0, self::SITE_ID, self::OTHER_SITE_ID] as $site_id) {
            RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . $site_id);
        }
    }

    // =============================================================================
    // The write exception
    // =============================================================================

    public static function test_a_write_exception_permits_a_write_with_no_lock_and_no_transaction()
    {
        $email = static::__email();
        $level = DB::transactionLevel();

        static::__in_window(function () use ($email, $level) {
            $inside = Site_Lock::write_exception('site lock test', function () use ($email) {
                static::__block($email);

                return ['level' => DB::transactionLevel(), 'held' => Site_Lock::held_mode(), 'in' => Site_Lock::in_write_exception()];
            });

            static::__assert_equals($level, $inside['level'], 'a write exception opens no transaction');
            static::__assert_null($inside['held'], 'and takes no lock');
            static::__assert_true($inside['in'], 'in_write_exception() answers inside it');
            static::__assert_false(Site_Lock::in_write_exception(), 'and not after it');

            static::__assert_throws(RuntimeException::class, fn () => static::__block(static::__email()), 'write_exception');
        });

        static::__assert_true(static::__exists($email), 'the recorded row landed');
    }

    public static function test_a_write_exception_requires_a_reason()
    {
        static::__assert_throws(RuntimeException::class, fn () => Site_Lock::write_exception('  ', fn () => null), 'requires a reason');
        static::__assert_throws(RuntimeException::class, fn () => Site_Lock::acquire_write_exception(''), 'requires a reason');
        static::__assert_false(Site_Lock::in_write_exception(), 'a refused acquisition opened nothing');
    }

    public static function test_nested_write_exceptions_permit_writes_until_the_last_release()
    {
        static::__in_window(function () {
            $outer = Site_Lock::acquire_write_exception('site lock test outer');
            $inner = Site_Lock::acquire_write_exception('site lock test inner');

            Site_Lock::release_write_exception($inner);
            static::__block(static::__email());
            static::__assert_true(Site_Lock::in_write_exception(), 'the inner release did not end the outer exception');

            Site_Lock::write_exception('site lock test scoped', fn () => static::__block(static::__email()));
            static::__assert_true(Site_Lock::in_write_exception(), 'nor did a scoped one inside it');

            Site_Lock::release_write_exception($outer);
            static::__assert_false(Site_Lock::in_write_exception(), 'the last release ended it');
            static::__assert_throws(RuntimeException::class, fn () => static::__block(static::__email()), 'without site 1\'s write lock');

            // Releases may come in any order; an unknown or released token is refused.
            $first = Site_Lock::acquire_write_exception('site lock test first');
            $second = Site_Lock::acquire_write_exception('site lock test second');
            Site_Lock::release_write_exception($first);
            static::__assert_true(Site_Lock::in_write_exception(), 'released out of order, the second is still open');
            Site_Lock::release_write_exception($second);
            static::__assert_throws(RuntimeException::class, fn () => Site_Lock::release_write_exception($second), 'no write exception with this token');
        });
    }

    public static function test_inside_a_write_section_the_record_rides_the_transaction_and_rolls_back()
    {
        $email = static::__email();

        static::__assert_throws(RuntimeException::class, function () use ($email) {
            Site_Lock::write(self::SITE_ID, function () use ($email) {
                Site_Lock::write_exception('site lock test', fn () => static::__block($email));

                throw new RuntimeException('abandon the section after recording');
            });
        }, 'abandon the section');

        static::__assert_false(static::__exists($email), 'the recorded row went with the rollback');
    }

    public static function test_a_site_change_inside_a_write_exception_is_refused()
    {
        Site_Lock::write_exception('site lock test', function () {
            static::__assert_throws(RuntimeException::class, fn () => Session::set_temporary_site_id(self::SITE_ID), 'inside a Site_Lock write exception');
            static::__assert_throws(RuntimeException::class, fn () => Session::set_site_id(self::SITE_ID), 'site lock test');
            static::__assert_throws(RuntimeException::class, fn () => RsxAuth::enter_site(self::SITE_ID), 'inside a Site_Lock write exception');
        });

        Session::set_site_id(self::SITE_ID);
        static::__assert_equals(self::SITE_ID, (int) Session::get_site_id(), 'the site is settable once it is released');
    }

    // =============================================================================
    // The public lock-state queries
    // =============================================================================

    public static function test_held_mode_in_write_exception_and_in_bypass_answer_each_state()
    {
        static::__assert_null(Site_Lock::held_mode(), 'nothing held');
        static::__assert_null(Site_Lock::held_mode(self::SITE_ID));
        static::__assert_false(Site_Lock::in_write_exception());
        static::__assert_false(Site_Lock::in_bypass());

        Site_Lock::read(self::SITE_ID, function () {
            static::__assert_equals('read', Site_Lock::held_mode(), 'a read section, asked with no site');
            static::__assert_equals('read', Site_Lock::held_mode(self::SITE_ID), 'and for its site');
            static::__assert_null(Site_Lock::held_mode(self::OTHER_SITE_ID), 'and nothing on another site');
        });

        Site_Lock::write(self::SITE_ID, function () {
            static::__assert_equals('write', Site_Lock::held_mode());
            Site_Lock::read(self::SITE_ID, fn () => static::__assert_equals('write', Site_Lock::held_mode(self::SITE_ID), 'a read inside a write is the write hold'));
            static::__assert_false(Site_Lock::in_bypass(self::SITE_ID), 'a write lock is not a bypass');
        });

        $token = RsxLocks::site_read_lock(self::SITE_ID);
        static::__assert_equals('read', Site_Lock::held_mode(), 'a raw read hold counts');
        RsxLocks::release_lock($token);

        Site_Lock::bypass(self::SITE_ID, 'site lock test', function () {
            static::__assert_true(Site_Lock::in_bypass(), 'any bypass');
            static::__assert_true(Site_Lock::in_bypass(self::SITE_ID), 'the bypass covers its site');
            static::__assert_false(Site_Lock::in_bypass(self::OTHER_SITE_ID), 'and no other');
            static::__assert_null(Site_Lock::held_mode(), 'a bypass is not a lock');
        });

        Site_Lock::bypass_all_sites('site lock test', fn () => static::__assert_true(Site_Lock::in_bypass(self::OTHER_SITE_ID), 'an all-sites bypass covers every site'));

        Site_Lock::write_exception('site lock test', function () {
            static::__assert_true(Site_Lock::in_write_exception());
            static::__assert_null(Site_Lock::held_mode(), 'a write exception is not a lock');
            static::__assert_false(Site_Lock::in_bypass(), 'nor a bypass');
            static::__assert_true(Site_Lock::has_write_authority(self::SITE_ID), 'but it is write authority');
        });

        static::__assert_null(Site_Lock::held_mode());
        static::__assert_false(Site_Lock::in_bypass());
        static::__assert_false(Site_Lock::in_write_exception());
    }

    // =============================================================================
    // Every surface: a hook, a task, a command, an endpoint
    // =============================================================================

    public static function test_a_hook_records_under_a_write_exception_and_one_left_open_fails_it()
    {
        $email = static::__email();

        Site_Lock::_run_hook('Site_Lock_Hold_Discipline_Test::pre_dispatch()', fn () => Site_Lock::write_exception('site lock test', fn () => static::__block($email)));
        static::__assert_true(static::__exists($email), 'a hook records under a write exception');

        $message = static::__refusal(fn () => Site_Lock::_run_hook('Site_Lock_Hold_Discipline_Test::pre_dispatch()', fn () => Site_Lock::acquire_write_exception('hook left open')));

        static::__assert_contains('Site_Lock_Hold_Discipline_Test::pre_dispatch()', $message, 'the failure names the hook');
        static::__assert_contains("'hook left open'", $message, 'and the exception');
        static::__assert_false(Site_Lock::in_write_exception(), 'which was released');

        static::__assert_throws(RuntimeException::class, fn () => Site_Lock::_run_hook('Site_Lock_Hold_Discipline_Test::pre_dispatch()', function () {
            Site_Lock::acquire_write_exception('hook left open and threw');

            throw new RuntimeException('the hook\'s own failure');
        }), 'the hook\'s own failure');
        static::__assert_false(Site_Lock::in_write_exception(), 'a hook that threw keeps its exception, and the leak is released');
    }

    public static function test_a_task_records_under_a_write_exception_and_one_left_open_fails_it()
    {
        $email = static::__email();

        static::__run_task_method(Site_Lock_Fixture_Service::class, 'write_under_exception', ['email' => $email]);
        static::__assert_true(static::__exists($email), 'the task recorded under a write exception');

        static::__assert_throws(
            RuntimeException::class,
            fn () => static::__run_task_method(Site_Lock_Fixture_Service::class, 'leak_exception'),
            'left open by a task'
        );
        static::__assert_false(Site_Lock::in_write_exception(), 'the task\'s exception was released');
        static::__assert_false(Site_Lock::_is_enforcing(), 'and its window closed');
    }

    public static function test_a_command_records_under_a_write_exception_and_one_left_open_fails_it()
    {
        $email = static::__email();

        static::__assert_equals(0, Artisan::call('rsx_test:site_lock_cli_fixture', ['mode' => 'exception', 'email' => $email]));
        static::__assert_true(static::__exists($email), 'the command recorded under a write exception');

        $leaked = static::__email();
        static::__assert_throws(
            RuntimeException::class,
            fn () => Artisan::call('rsx_test:site_lock_cli_fixture', ['mode' => 'leak_exception', 'email' => $leaked]),
            'left open by a command'
        );
        static::__assert_true(static::__exists($leaked), 'its write committed at once (autocommit) - nothing rolls it back');
        static::__assert_false(Site_Lock::in_write_exception(), 'the command\'s exception was released');
        static::__assert_false(Site_Lock::_is_enforcing(), 'and its window closed');
    }

    public static function test_a_reader_endpoint_records_under_a_write_exception_and_stays_a_reader()
    {
        $email = static::__email();

        $state = Ajax::internal(self::FIXTURE, 'reader_records', ['email' => $email]);

        static::__assert_equals('read', $state['held_mode'], 'the endpoint ran under its read lock throughout');
        static::__assert_true(static::__exists($email), 'the record landed');
        static::__assert_null(Site_Lock::held_mode(), 'the read lock was released');
    }

    public static function test_outside_a_transaction_the_record_survives_the_reader_that_throws()
    {
        $email = static::__email();

        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'reader_records', ['email' => $email, 'then' => 'throw']), 'threw after recording');

        static::__assert_true(static::__exists($email), 'committed at once: the reader\'s throw rolls nothing back');
    }

    public static function test_a_manual_handler_holding_nothing_records_under_a_write_exception()
    {
        $email = static::__email();

        $state = Ajax::internal(self::FIXTURE, 'manual_records', ['email' => $email]);

        static::__assert_null($state['held_mode'], 'the manual handler held no lock');
        static::__assert_true(static::__exists($email), 'and recorded');
    }

    public static function test_inside_a_writer_the_record_is_rolled_back_when_the_writer_throws()
    {
        $email = static::__email();

        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'writer_records_then_throws', ['email' => $email]), 'threw after recording');

        static::__assert_false(static::__exists($email), 'the record rode the writer\'s transaction and went with it');
    }

    public static function test_an_endpoint_ending_with_a_write_exception_open_fails_naming_it()
    {
        $email = static::__email();

        $message = static::__refusal(fn () => Ajax::internal(self::FIXTURE, 'reader_records', ['email' => $email, 'then' => 'leak']));

        static::__assert_contains('Ajax ' . self::FIXTURE . '::reader_records', $message, 'the failure names the endpoint');
        static::__assert_contains("'site lock test: never released'", $message, 'and the exception left open');
        static::__assert_false(Site_Lock::in_write_exception(), 'which was released');
        static::__assert_null(Site_Lock::held_mode(), 'and the read lock with it');
    }

    // =============================================================================
    // A file gate handler run from the framework's read surfaces
    // =============================================================================

    public static function test_a_download_gate_handler_records_under_a_write_exception_from_the_framework_file_surfaces()
    {
        config(['rsx.search.enabled' => false]);

        try {
            $attachment = File_Attachment_Model::create_from_string(
                'site lock gate fixture ' . bin2hex(random_bytes(12)) . "\n",
                'site_lock_gate.txt',
                ['site_id' => self::SITE_ID]
            );

            static::__record_downloads_in_the_gate();

            // An Ajax endpoint: the content gates run in its read section.
            $text = Ajax::internal('File_Preview_Controller', 'get_extracted_text', ['attachment_id' => $attachment->id]);
            static::__assert_true(isset($text['status']), 'get_extracted_text answered: ' . json_encode($text));

            // A file route: /_download/:key, through the front controller.
            $request = Request::create('/_download/' . $attachment->key, 'GET');
            app()->instance('request', $request);
            $response = Rsx_Front_Controller::handle($request);
            static::__assert_equals(200, $response->getStatusCode(), 'the download was served');
        } finally {
            config(['rsx.search.enabled' => true]);
        }

        static::__assert_count(2, static::$gate_seen, 'the download gate ran once per surface');

        foreach (static::$gate_seen as $index => $seen) {
            static::__assert_equals('read', $seen['held_mode'], "surface {$index}: the gate ran inside the read section");
            static::__assert_true(static::__exists($seen['email']), "surface {$index}: the gate's record landed");
        }

        static::__assert_null(Site_Lock::held_mode(), 'nothing is held once the file is answered');
    }

    // =============================================================================
    // No upgrade, on every path
    // =============================================================================

    public static function test_the_read_to_write_upgrade_is_refused_on_every_path()
    {
        // Site_Lock::write() inside Site_Lock::read() - with or without a write exception.
        Site_Lock::read(self::SITE_ID, function () {
            static::__assert_throws(RuntimeException::class, fn () => Site_Lock::write(self::SITE_ID, fn () => null), 'while holding its READ lock');
            static::__assert_throws(RuntimeException::class, fn () => Site_Lock::write_exception('site lock test', fn () => Site_Lock::write(self::SITE_ID, fn () => null)), 'while holding its READ lock');
        });

        // A reader endpoint opening a write section, from inside a write exception.
        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'reader_sections_in_exception'), 'while holding its READ lock');

        // A writer reached through Ajax::internal() from a reader - with or without an exception.
        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal('Site_Lock_Dispatch_Fixture_Controller', 'reader_calls_writer'), 'while holding its READ lock');
        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'reader_calls_writer_in_exception'), 'while holding its READ lock');

        // A gate handler run inside a framework read section.
        config(['rsx.search.enabled' => false]);

        try {
            $attachment = File_Attachment_Model::create_from_string('site lock upgrade fixture ' . bin2hex(random_bytes(12)) . "\n", 'site_lock_upgrade.txt', ['site_id' => self::SITE_ID]);

            Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn ($data) => true]);
            Event_Registry::_set_test_handlers('file.download.authorize', [fn ($data) => Site_Lock::write(self::SITE_ID, fn () => true)]);

            static::__assert_throws(RuntimeException::class, fn () => Ajax::internal('File_Preview_Controller', 'get_extracted_text', ['attachment_id' => $attachment->id]), 'while holding its READ lock');
        } finally {
            config(['rsx.search.enabled' => true]);
        }

        static::__assert_null(Site_Lock::held_mode(), 'every refusal released its read lock');
    }

    // =============================================================================
    // Rollback_Response: an error answer that rolls back
    // =============================================================================

    public static function test_a_rollback_response_undoes_the_writer_and_answers_the_returned_envelope()
    {
        $returned_email = static::__email();
        $thrown_email = static::__email();

        $returned = static::__ajax_envelope('writer_answers_error', ['email' => $returned_email, 'how' => 'return']);
        $thrown = static::__ajax_envelope('writer_answers_error', ['email' => $thrown_email, 'how' => 'throw']);

        static::__assert_true(static::__exists($returned_email), 'a RETURNED error commits what the writer wrote');
        static::__assert_false(static::__exists($thrown_email), 'a THROWN Rollback_Response rolls it back');

        static::__assert_false($thrown['_success']);
        static::__assert_equals('validation', $thrown['error_code']);
        static::__assert_true(isset($thrown['_duration_ms'], $thrown['_completed_at']), 'stamped like any envelope');
        static::__assert_equals(static::__comparable($returned), static::__comparable($thrown), 'the client receives the same envelope either way');

        // In-process, it surfaces as the coded exception the returned value would.
        $email = static::__email();
        static::__assert_throws(AjaxFormErrorException::class, fn () => Ajax::internal(self::FIXTURE, 'writer_answers_error', ['email' => $email, 'how' => 'throw']), 'Row 2 is invalid');
        static::__assert_false(static::__exists($email), 'and the write is gone');

        // An HTTP response is no Ajax answer, thrown or returned.
        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'writer_rolls_back_with_http_response'), 'an HTTP response, to an Ajax call');
    }

    public static function test_a_rollback_response_from_an_api_writer_undoes_it_and_answers_the_returned_body()
    {
        $key = static::__api_key();
        $returned_email = static::__email();
        $thrown_email = static::__email();

        $returned = static::__api($key, ['email' => $returned_email, 'how' => 'return']);
        $thrown = static::__api($key, ['email' => $thrown_email, 'how' => 'throw']);

        static::__assert_true(static::__exists($returned_email), 'a RETURNED error commits what the writer wrote');
        static::__assert_false(static::__exists($thrown_email), 'a THROWN Rollback_Response rolls it back');

        static::__assert_equals(422, $thrown['status'], 'the returned value\'s status: ' . json_encode($thrown['body']));
        static::__assert_equals($returned['status'], $thrown['status']);
        static::__assert_true(isset($thrown['body']['_duration_ms'], $thrown['body']['_completed_at']), 'stamped like any API body');
        static::__assert_equals(static::__comparable($returned['body']), static::__comparable($thrown['body']), 'the same body either way');
    }

    // =============================================================================
    // Helpers
    // =============================================================================

    /** A download gate that records the access under a write exception, noting what it saw. */
    private static function __record_downloads_in_the_gate(): void
    {
        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn ($data) => true]);
        Event_Registry::_set_test_handlers('file.download.authorize', [function ($data) {
            $email = static::__email();

            Site_Lock::write_exception('site lock test: record the download', fn () => static::__block($email));

            static::$gate_seen[] = ['held_mode' => Site_Lock::held_mode(), 'email' => $email];

            return true;
        }]);
    }

    /** One browser-transport call to the exception fixture; its envelope. */
    private static function __ajax_envelope(string $action, array $params): array
    {
        $calls = [['call_id' => 0, 'controller' => self::FIXTURE, 'action' => $action, 'params' => $params]];
        $request = Request::create('/_ajax/_batch', 'POST', ['batch_calls' => $calls]);

        return json_decode(Ajax::handle_batch_request($request)->getContent(), true)['C_0'];
    }

    /** An answer with its per-request clock fields removed. */
    private static function __comparable(array $answer): array
    {
        unset($answer['_duration_ms'], $answer['_completed_at'], $answer['_server_time']);

        return $answer;
    }

    private static function __api_key(): string
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->is_api_access_enabled = 1;
        $user->save();
        static::grant_every_permission(1);

        return Api_Key_Model::generate(1, 'Site lock hold discipline probe (test)')['key'];
    }

    /** One POST to the API fixture through the front controller: [status, body (decoded)]. */
    private static function __api(string $key, array $body): array
    {
        $request = Request::create('/api/v1/test-probe/site-lock/answer-error', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body));
        app()->instance('request', $request);

        $response = Rsx_Front_Controller::handle($request);

        // The front controller ended the API identity: act as the baseline user again.
        static::__acting_as_user(1);

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
    }

    private static function __in_window(callable $work): void
    {
        Site_Lock::_enter_enforcement('Site_Lock_Hold_Discipline_Test window');

        try {
            $work();
        } finally {
            Site_Lock::_leave_enforcement();
        }
    }

    /** The message of the RuntimeException $work throws (the test fails if it throws none). */
    private static function __refusal(callable $work): string
    {
        return static::__assert_throws(RuntimeException::class, $work)->getMessage();
    }

    private static function __email(): string
    {
        return 'site-lock-hold-' . bin2hex(random_bytes(6)) . '@test.rspade.test';
    }

    private static function __block(string $email): void
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'site lock hold discipline test';
        $row->save();
    }

    private static function __exists(string $email): bool
    {
        return Email_Blocked_Address_Model::where('email', $email)->exists();
    }
}
