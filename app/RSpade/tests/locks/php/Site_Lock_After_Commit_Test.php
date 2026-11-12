<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Database\Rsx_DatabaseTransactionsManager;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Files\File_Blob_Locks;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;
use App\RSpade\Tests\Locks\Php\Site_Lock_After_Commit_Fixture_Controller;

/**
 * After a write lock's transaction COMMITS, nothing fails the request: an after-commit callback
 * that throws is reported and the callbacks after it still run (Rsx_DatabaseTransactionsManager), the
 * writer / write section / #[Manual_Lock] request answers what it returned, TransactionCommitted
 * fires (File_Blob_Locks releases on it), and a request reference replays. A throw BEFORE the
 * commit still rolls back. With Laravel's stock manager on the connection, Site_Lock alone still
 * reads the decremented level as a commit and answers success, never "rolled back".
 *
 * Commits real rows (callbacks run only on a real outermost commit), so the class opts out of
 * the per-test transaction and starts from a clean baseline.
 */
class Site_Lock_After_Commit_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    private const SITE_ID = 1;
    private const FIXTURE = 'Site_Lock_After_Commit_Fixture_Controller';
    private const EMAIL_PREFIX = 'site-lock-after-commit-';

    public static function setup(): void
    {
        static::__begin();
    }

    public static function teardown(): void
    {
        Event::forget(MessageLogged::class);
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        DB::table('_email_blocked_addresses')->where('email', 'like', self::EMAIL_PREFIX . '%')->delete();
    }

    public static function test_the_framework_manager_is_bound_on_the_connection()
    {
        $manager = (fn () => $this->transactionsManager)->call(DB::connection());

        static::__assert_true($manager instanceof Rsx_DatabaseTransactionsManager, 'the connection runs its callbacks through Rsx_DatabaseTransactionsManager');
    }

    public static function test_a_writer_answers_its_result_when_an_after_commit_callback_throws()
    {
        static::__begin();
        $email = static::__email();
        $committed = 0;
        Event::listen(TransactionCommitted::class, function () use (&$committed) {
            $committed++;
        });

        $reports = static::__reports(function () use ($email, &$result) {
            $result = Ajax::internal(self::FIXTURE, 'writer_after_commit_throws', ['email' => $email]);
        });

        static::__assert_equals(['email' => $email], $result, 'the endpoint\'s result is the answer');
        static::__assert_true(static::__exists($email), 'the write committed');
        static::__assert_equals(1, Site_Lock_After_Commit_Fixture_Controller::$second_ran, 'the callback after the throwing one ran');
        static::__assert_equals(1, count($reports), 'one failure was reported: ' . json_encode($reports));
        static::__assert_contains(Site_Lock_After_Commit_Fixture_Controller::CALLBACK_MESSAGE, $reports[0], 'naming what threw');
        static::__assert_contains('Site_Lock_After_Commit_Fixture_Controller.php:', $reports[0], 'and where the callback was declared');
        static::__assert_true($committed >= 1, 'TransactionCommitted fired');
        static::__assert_equals(0, DB::transactionLevel(), 'no transaction is left open');
        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'the write lock was released');
    }

    public static function test_a_throw_before_the_commit_still_rolls_back_and_runs_no_callback()
    {
        static::__begin();
        $email = static::__email();

        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'writer_after_commit_throws', ['email' => $email, 'throw' => true]), 'threw before its commit');

        static::__assert_false(static::__exists($email), 'the write was rolled back');
        static::__assert_equals(0, Site_Lock_After_Commit_Fixture_Controller::$second_ran, 'no after-commit callback ran');
        static::__assert_equals(0, DB::transactionLevel());
    }

    public static function test_a_manual_lock_request_answers_its_result_when_an_after_commit_callback_throws()
    {
        static::__begin();
        $email = static::__email();

        $reports = static::__reports(function () use ($email, &$result) {
            $result = Ajax::internal(self::FIXTURE, 'manual_after_commit_throws', ['email' => $email]);
        });

        static::__assert_equals(['email' => $email], $result);
        static::__assert_true(static::__exists($email), 'the request transaction committed');
        static::__assert_equals(1, Site_Lock_After_Commit_Fixture_Controller::$second_ran);
        static::__assert_equals(1, count($reports), 'one failure was reported');
        static::__assert_equals(0, DB::transactionLevel());
    }

    public static function test_a_write_section_returns_its_result_when_an_after_commit_callback_throws()
    {
        static::__begin();
        $email = static::__email();

        $reports = static::__reports(function () use ($email, &$result) {
            $result = Site_Lock::write(self::SITE_ID, function () use ($email) {
                Site_Lock_After_Commit_Fixture_Controller::block($email);
                Site_Lock_After_Commit_Fixture_Controller::register_callbacks();

                return 'section result';
            });
        });

        static::__assert_equals('section result', $result);
        static::__assert_true(static::__exists($email));
        static::__assert_equals(1, Site_Lock_After_Commit_Fixture_Controller::$second_ran);
        static::__assert_equals(1, count($reports));
        static::__assert_equals([], RsxLocks::site_lock_hold_counts());
    }

    public static function test_an_api_writer_answers_2xx_with_its_body_when_an_after_commit_callback_throws()
    {
        static::__begin();
        $key = static::__api_key();
        $email = static::__email();

        $reports = static::__reports(function () use ($key, $email, &$answer) {
            $answer = static::__api($key, ['email' => $email]);
        });

        static::__assert_equals(200, $answer['status'], 'a success: ' . json_encode($answer['body']));
        static::__assert_equals($email, $answer['body']['email'] ?? null, 'with the endpoint\'s body');
        static::__assert_true(static::__exists($email));
        static::__assert_equals(1, Site_Lock_After_Commit_Fixture_Controller::$second_ran);
        static::__assert_equals(1, count($reports));
    }

    public static function test_a_request_reference_replays_after_a_commit_whose_callback_threw()
    {
        static::__begin();
        $email = static::__email();
        $ref = 'ref-' . bin2hex(random_bytes(8));

        $first = static::__ajax('writer_after_commit_throws', ['email' => $email, '_request_ref' => $ref]);
        $second = static::__ajax('writer_after_commit_throws', ['email' => $email, '_request_ref' => $ref]);

        static::__assert_true($first['_success'], 'answered as a success: ' . json_encode($first));
        static::__assert_equals(['email' => $email], $first['_ajax_return_value']);
        static::__assert_true($second['_replayed'] ?? false, 'the resend is answered from the history: ' . json_encode($second));
        static::__assert_equals(1, Email_Blocked_Address_Model::where('email', $email)->count(), 'written once');
    }

    public static function test_a_deferred_blob_lock_is_released_after_a_commit_whose_callback_threw()
    {
        static::__begin();
        $hash = hash('sha256', random_bytes(16));
        $name = File_Blob_Locks::name($hash);

        $reports = static::__reports(function () use ($hash, $name) {
            DB::beginTransaction();
            File_Blob_Locks::exclusive($hash, function () {
                DB::afterCommit(function () {
                    throw new RuntimeException(Site_Lock_After_Commit_Fixture_Controller::CALLBACK_MESSAGE);
                });
            });
            static::__assert_true(RsxLocks::holds(RsxLocks::CLUSTER_LOCK, $name, RsxLocks::WRITE_LOCK), 'the hold waits for the commit');
            DB::commit();
        });

        static::__assert_false(RsxLocks::holds(RsxLocks::CLUSTER_LOCK, $name, RsxLocks::WRITE_LOCK), 'the commit released it');
        static::__assert_equals(1, count($reports));
    }

    public static function test_with_the_stock_manager_site_lock_alone_answers_success_and_claims_no_rollback()
    {
        static::__begin();
        $connection = DB::connection();
        $connection->setTransactionManager(new DatabaseTransactionsManager());
        $email = static::__email();

        try {
            $reports = static::__reports(function () use ($email, &$result) {
                $result = Ajax::internal(self::FIXTURE, 'writer_after_commit_throws', ['email' => $email]);
            });
        } finally {
            $connection->setTransactionManager(app('db.transactions'));
        }

        static::__assert_equals(['email' => $email], $result, 'answered as it returned, no rule 3');
        static::__assert_true(static::__exists($email));
        static::__assert_equals(0, Site_Lock_After_Commit_Fixture_Controller::$second_ran, 'the stock manager drops the later callback');
        static::__assert_equals(1, count($reports), 'the throw was reported');
        static::__assert_contains('writer_after_commit_throws\'s commit', $reports[0], 'naming the request whose commit it followed');
        static::__assert_equals(0, DB::transactionLevel());
        static::__assert_equals([], RsxLocks::site_lock_hold_counts());
    }

    // =============================================================================
    // Helpers
    // =============================================================================

    /** setup() runs once per class: each test starts as the baseline user with no callback counted. */
    private static function __begin(): void
    {
        static::__acting_as_user(1);
        Site_Lock_After_Commit_Fixture_Controller::$second_ran = 0;
    }

    /** Run $work; the after-commit failure reports logged meanwhile. */
    private static function __reports(callable $work): array
    {
        $reports = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$reports) {
            if (str_contains($event->message, 'After-commit failure')) {
                $reports[] = $event->message;
            }
        });

        try {
            $work();
        } finally {
            Event::forget(MessageLogged::class);
        }

        return $reports;
    }

    private static function __ajax(string $action, array $params): array
    {
        $calls = [['call_id' => 0, 'controller' => self::FIXTURE, 'action' => $action, 'params' => $params]];
        $request = Request::create('/_ajax/_batch', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['batch_calls' => $calls]));

        return json_decode(Ajax::handle_batch_request($request)->getContent(), true)['C_0'];
    }

    private static function __api_key(): string
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->is_api_access_enabled = 1;
        $user->save();
        static::grant_every_permission(1);

        return Api_Key_Model::generate(1, 'Site lock after-commit probe (test)')['key'];
    }

    /** One POST to the API fixture through the front controller: [status, body (decoded)]. */
    private static function __api(string $key, array $body): array
    {
        $request = Request::create('/api/v1/test-probe/site-lock/after-commit-throws', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body));
        app()->instance('request', $request);

        $response = Rsx_Front_Controller::handle($request);

        static::__acting_as_user(1);

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
    }

    private static function __email(): string
    {
        return self::EMAIL_PREFIX . bin2hex(random_bytes(6)) . '@test.rspade.test';
    }

    private static function __exists(string $email): bool
    {
        return Email_Blocked_Address_Model::where('email', $email)->exists();
    }
}
