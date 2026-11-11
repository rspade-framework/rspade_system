<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Exceptions\Infrastructure_Outage;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\Lock_Timeout_Exception;
use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Locks\Lockd_Connection;
use App\RSpade\Core\Locks\Lockd_Unavailable_Exception;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Models\Email_Blocked_Address_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Locks\Php\Lockd_Outage_Fixture_Controller;

/**
 * The lock daemon is infrastructure, like mysqld: when it is unreachable, or its connection dies
 * under a request, the WHOLE request fails as a 500 on every channel - an Ajax batch (no call of
 * it runs), an API GET and POST alike (internal_error), a #[Reader] page - and a database outage
 * inside an Ajax call answers identically. A task dispatch throws the outage, a task run that
 * meets it settles FAILED. Contention and outage are distinct answers: a probe of a held lock
 * answers false, a probe of an unreachable daemon throws. After a commit, the outage still ends
 * the request (every after-commit callback runs first) and nothing claims a rollback. The
 * maintenance straggler path is unchanged.
 *
 * The daemon is made unreachable by putting a FRESH connection on the transport for the duration
 * of a test (pointed at a loopback port nothing listens on), never by stopping the real daemon -
 * and never by closing the process's own connection, which is whatever this process holds.
 */
class Lockd_Outage_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    private const FIXTURE = 'Lockd_Outage_Fixture_Controller';
    private const EMAIL_PREFIX = 'lockd-outage-';

    /** A non-loopback caller, so a page fault renders the themed screen rather than the debug page. */
    private const SERVER = ['REMOTE_ADDR' => '203.0.113.9'];

    private static ?Lockd_Connection $original_connection = null;
    private static ?array $original_database = null;

    public static function setup(): void
    {
        Lockd_Outage_Fixture_Controller::$closed_port = static::__free_port();
    }

    public static function teardown(): void
    {
        Event::forget(MessageLogged::class);
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        Framework_Maintenance::$force_active_for_tests = null;
        DB::table('_email_blocked_addresses')->where('email', 'like', self::EMAIL_PREFIX . '%')->delete();
    }

    // =============================================================================
    // Contention vs outage
    // =============================================================================

    public static function test_a_probe_of_a_held_lock_answers_false()
    {
        $name = 'lockd_outage_probe_' . bin2hex(random_bytes(4));
        $holder = new Lockd_Connection(null, 'the test holder released its lock');

        try {
            $granted = $holder->request(['op' => 'acquire', 'name' => static::__wire_name($name), 'mode' => 'write', 'timeout' => null]);
            static::__assert_equals('granted', $granted['status'] ?? null, 'another connection holds the lock: ' . json_encode($granted));

            static::__assert_false((new Task_Lock($name, 0))->acquire(), 'contention is the answer false');
            static::__assert_throws(Lock_Timeout_Exception::class, fn () => RsxLocks::named_write_lock($name, 0), 'Failed to acquire WRITE lock');
        } finally {
            $holder->disconnect();
        }
    }

    public static function test_a_probe_of_an_unreachable_daemon_throws_the_outage()
    {
        static::__with_unreachable_daemon(function () {
            $e = static::__assert_throws(Lockd_Unavailable_Exception::class, fn () => (new Task_Lock('lockd_outage_probe', 0))->acquire());

            static::__assert_contains('Cannot reach rsx-lockd at 127.0.0.1:' . Lockd_Outage_Fixture_Controller::$closed_port, $e->getMessage());
            static::__assert_false($e instanceof RuntimeException, 'the outage is never a RuntimeException a contention catch could take');
            static::__assert_true(Infrastructure_Outage::is_outage($e));
        });
    }

    public static function test_a_database_connection_failure_is_an_outage_and_a_query_error_is_not()
    {
        $refused = new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002);
        $gone = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $gone->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        $duplicate = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $duplicate->errorInfo = ['23000', 1062, 'Duplicate entry'];

        static::__assert_true(Infrastructure_Outage::is_outage($refused), 'a refused connect');
        static::__assert_true(Infrastructure_Outage::is_outage(new RuntimeException('wrapped', 0, $gone)), 'a lost connection, wrapped');
        static::__assert_false(Infrastructure_Outage::is_outage($duplicate), 'a statement error is no outage');
        static::__assert_false(Infrastructure_Outage::is_outage(new RuntimeException('Failed to acquire a lock')), 'nor is a plain fault');
    }

    // =============================================================================
    // Every channel: the whole request is a 500
    // =============================================================================

    public static function test_an_ajax_batch_answers_500_and_no_call_runs()
    {
        static::__reset_session();
        Lockd_Outage_Fixture_Controller::$ran = [];

        $response = static::__with_unreachable_daemon(fn () => static::__handle(static::__batch(['reader_records', 'writer_records'])));

        static::__assert_equals(500, $response->getStatusCode(), 'the whole request failed: ' . $response->getContent());
        static::__assert_equals([], Lockd_Outage_Fixture_Controller::$ran, 'neither the reader nor the writer ran');
        static::__assert_equals(Ajax::ERROR_FATAL, json_decode($response->getContent(), true)['error_code'] ?? null);
    }

    public static function test_an_ajax_batch_that_loses_the_database_answers_the_same_500()
    {
        static::__reset_session();
        Lockd_Outage_Fixture_Controller::$ran = [];
        $name = config('database.default');
        $saved = config("database.connections.{$name}");

        try {
            $response = static::__handle(static::__batch(['reader_loses_database', 'reader_records']));
        } finally {
            config(["database.connections.{$name}" => $saved]);
            DB::purge($name);
        }

        static::__assert_equals(500, $response->getStatusCode(), 'the whole request failed: ' . $response->getContent());
        static::__assert_equals(['reader_loses_database'], Lockd_Outage_Fixture_Controller::$ran, 'the call after the outage never ran');
        static::__assert_equals(Ajax::ERROR_FATAL, json_decode($response->getContent(), true)['error_code'] ?? null);
        static::__assert_equals(1, (int) DB::selectOne('select 1 as one')->one, 'the database is back');
    }

    public static function test_an_api_get_and_post_answer_500_with_the_same_code()
    {
        $key = static::__api_key();
        Lockd_Outage_Fixture_Controller::$ran = [];

        [$get, $post] = static::__with_unreachable_daemon(fn () => [
            static::__api($key, 'GET', '/api/v1/test-probe/lockd-outage/read'),
            static::__api($key, 'POST', '/api/v1/test-probe/lockd-outage/write'),
        ]);

        static::__assert_equals(500, $get['status'], 'GET: ' . json_encode($get['body']));
        static::__assert_equals(500, $post['status'], 'POST: ' . json_encode($post['body']));
        static::__assert_equals('internal_error', $get['body']['error']['code'] ?? null);
        static::__assert_equals($get['body']['error']['code'] ?? null, $post['body']['error']['code'] ?? null, 'one code for both');
        static::__assert_equals([], Lockd_Outage_Fixture_Controller::$ran, 'neither endpoint ran');
    }

    public static function test_a_reader_page_renders_the_500_page()
    {
        static::__reset_session();
        Lockd_Outage_Fixture_Controller::$ran = [];

        $response = static::__with_unreachable_daemon(fn () => static::__handle(
            Request::create('/_test/lockd-outage/reader-page', 'GET', [], [], [], static::SERVER)
        ));

        static::__assert_equals(500, $response->getStatusCode());
        static::__assert_equals([], Lockd_Outage_Fixture_Controller::$ran, 'the page handler never ran');
    }

    // =============================================================================
    // Tasks
    // =============================================================================

    public static function test_dispatching_a_debounced_task_throws_the_outage()
    {
        $before = Task_Run_Model::where('method', 'debounced')->count();

        static::__with_unreachable_daemon(function () {
            static::__assert_throws(Lockd_Unavailable_Exception::class, fn () => Task::dispatch('Lockd_Outage_Fixture_Service', 'debounced'));
        });

        static::__assert_equals($before, Task_Run_Model::where('method', 'debounced')->count(), 'nothing was enqueued');
    }

    public static function test_a_task_run_that_meets_the_outage_settles_failed()
    {
        $id = Task::dispatch('Lockd_Outage_Fixture_Service', 'takes_a_lock');

        $run = static::__with_unreachable_daemon(fn () => static::__run_pending_task($id));

        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id, 'the run failed, never "completed"');
    }

    // =============================================================================
    // After the commit
    // =============================================================================

    public static function test_an_outage_after_the_commit_ends_the_request_without_claiming_a_rollback()
    {
        static::__acting_as_user(1);
        Lockd_Outage_Fixture_Controller::$ran = [];
        Lockd_Outage_Fixture_Controller::$second_ran = 0;
        $email = self::EMAIL_PREFIX . bin2hex(random_bytes(6)) . '@test.rspade.test';
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message;
        });

        $calls = [['call_id' => 0, 'controller' => self::FIXTURE, 'action' => 'writer_loses_daemon_after_commit', 'params' => ['email' => $email]]];
        $request = Request::create('/_ajax/_batch', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['batch_calls' => $calls]));

        // A fresh connection to the REAL daemon takes the site's write lock; the endpoint then
        // makes the daemon unreachable, so the after-commit callback and the release meet it.
        $e = static::__with_fresh_connection(Lockd_Connection::default_endpoint(), function () use ($request) {
            return static::__assert_throws(Lockd_Unavailable_Exception::class, fn () => Ajax::handle_batch_request($request));
        });
        Event::forget(MessageLogged::class);

        static::__assert_true(Email_Blocked_Address_Model::where('email', $email)->exists(), 'the write committed');
        static::__assert_equals(1, Lockd_Outage_Fixture_Controller::$second_ran, 'every after-commit callback ran');
        static::__assert_false(str_contains($e->getMessage(), 'rolled back'), 'the outage claims no rollback: ' . $e->getMessage());
        foreach ($logged as $message) {
            static::__assert_false(str_contains($message, 'rolled back'), 'nothing logged a rollback: ' . $message);
            static::__assert_false(str_contains($message, 'After-commit failure'), 'the outage is not contained as a callback failure: ' . $message);
        }
        static::__assert_equals(0, DB::transactionLevel(), 'no transaction is left open');
        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'no site lock is left booked');
    }

    // =============================================================================
    // Maintenance (unchanged)
    // =============================================================================

    public static function test_the_maintenance_straggler_still_takes_the_outage_as_maintenance()
    {
        $lockd = new \ReflectionMethod(RsxLocks::class, '__lockd');
        $forced = new \ReflectionProperty(RsxLocks::class, 'maintenance_forced');

        static::__with_unreachable_daemon(function () use ($lockd, $forced) {
            try {
                Framework_Maintenance::$force_active_for_tests = true;
                static::__assert_null($lockd->invoke(null, ['op' => 'ping']), 'with the flag on disk the caller re-enters on no-op grants');
                static::__assert_true($forced->getValue(), 'and this process is a straggler from now on');
            } finally {
                $forced->setValue(null, false);
                Framework_Maintenance::$force_active_for_tests = null;
            }

            // Without the flag on disk it is the outage.
            static::__assert_throws(Lockd_Unavailable_Exception::class, fn () => $lockd->invoke(null, ['op' => 'ping']), 'Cannot reach rsx-lockd');
        });
    }

    // =============================================================================
    // Helpers
    // =============================================================================

    /** Run $fn with the transport on a fresh connection to a port nothing listens on. */
    private static function __with_unreachable_daemon(callable $fn): mixed
    {
        return static::__with_fresh_connection(['host' => '127.0.0.1', 'port' => Lockd_Outage_Fixture_Controller::$closed_port], $fn);
    }

    /**
     * Run $fn with a FRESH Lockd_Client connection to $endpoint, one connect attempt per dial;
     * the process's own connection (and whatever it holds) is put back untouched afterwards.
     */
    private static function __with_fresh_connection(array $endpoint, callable $fn): mixed
    {
        $property = new \ReflectionProperty(Lockd_Client::class, 'connection');
        $original = $property->getValue();
        $retries = config('rsx.locks.connect_retries');

        $fresh = new Lockd_Connection(null, 'every lock this process held has been released by the daemon');
        $fresh->use_endpoint($endpoint['host'], $endpoint['port']);
        $property->setValue(null, $fresh);
        config(['rsx.locks.connect_retries' => 1]);

        try {
            return $fn();
        } finally {
            $fresh->disconnect();
            $property->setValue(null, $original);
            config(['rsx.locks.connect_retries' => $retries]);
        }
    }

    private static function __wire_name(string $name): string
    {
        return (new \ReflectionMethod(RsxLocks::class, '__wire_name'))->invoke(null, RsxLocks::CLUSTER_LOCK, $name);
    }

    private static function __batch(array $actions): Request
    {
        $calls = [];
        foreach ($actions as $index => $action) {
            $calls[] = ['call_id' => $index, 'controller' => self::FIXTURE, 'action' => $action, 'params' => []];
        }

        return Request::create('/_ajax/_batch', 'POST', ['batch_calls' => $calls], [], [], static::SERVER);
    }

    private static function __handle(Request $request)
    {
        app()->instance('request', $request);

        return Rsx_Front_Controller::handle($request);
    }

    private static function __api_key(): string
    {
        static::__acting_as_user(1);
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->is_api_access_enabled = 1;
        $user->save();
        static::grant_every_permission(1);

        return Api_Key_Model::generate(1, 'Lock daemon outage probe (test)')['key'];
    }

    /** One API request through the front controller: [status, body (decoded)]. */
    private static function __api(string $key, string $method, string $path): array
    {
        $request = Request::create($path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
        ], $method === 'POST' ? '{}' : null);
        $response = static::__handle($request);
        Rsx_Request_Channel::reset();

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
    }

    /** A loopback port nothing is listening on: bound by the OS, then released. */
    private static function __free_port(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        return $port;
    }
}
