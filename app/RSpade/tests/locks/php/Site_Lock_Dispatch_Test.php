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
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Email_Blocked_Address_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Locks\Php\Site_Lock_Dispatch_Fixture_Controller;
use App\RSpade\Tests\Locks\Php\Site_Lock_Main_Hook_Probe;

/**
 * The site lock at the dispatch boundary (Site_Lock::_run_request()): what an Ajax endpoint,
 * an Ajax batch and a page route run under, the pre_dispatch / unhandled_route hooks running
 * unlocked but enforced (Site_Lock::_run_hook()), the enforcement window being exactly the handler,
 * the writer's one transaction, the #[Manual_Lock] request transaction, the site change
 * refusal and RsxAuth::enter_site(), and Task::dispatch() from a writer that rolls back.
 *
 * In one process against the running rsx-lockd, acting as the baseline user on site 1. The
 * row written is the framework's site-scoped Email_Blocked_Address_Model. Cross-process
 * contention is Site_Lock_Concurrency_Test.
 */
class Site_Lock_Dispatch_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;
    private const OTHER_SITE_ID = 987654;
    private const FIXTURE = 'Site_Lock_Dispatch_Fixture_Controller';

    public static function setup(): void
    {
        static::__acting_as_user(1);
        Site_Lock_Dispatch_Fixture_Controller::$pre_dispatch_seen = null;
        Site_Lock_Dispatch_Fixture_Controller::$dispatched_task_id = null;
        Site_Lock_Main_Hook_Probe::reset();
    }

    public static function teardown(): void
    {
        Event_Registry::_clear_test_handlers();
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
    }

    // =============================================================================
    // Reader / writer
    // =============================================================================

    public static function test_a_reader_endpoint_that_writes_fatals_naming_the_writer_remedy()
    {
        $email = static::__email();

        $e = static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'reader_writes', ['email' => $email]), '#[Writer]');

        static::__assert_contains('Ajax ' . self::FIXTURE . '::reader_writes', $e->getMessage(), 'the refusal names the endpoint');
        static::__assert_false(static::__exists($email), 'the refused write wrote nothing');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the read lock was released');
    }

    public static function test_a_writer_endpoint_writes_under_its_write_lock_in_one_transaction()
    {
        $email = static::__email();
        $level = DB::transactionLevel();

        $probe = Ajax::internal(self::FIXTURE, 'writer_writes', ['email' => $email]);

        static::__assert_equals('write', $probe['mode'], 'the endpoint ran under the write lock');
        static::__assert_equals(self::SITE_ID, $probe['site_id'], 'on the session\'s site');
        static::__assert_equals($level + 1, $probe['level'], 'inside the request transaction');
        static::__assert_true($probe['enforcing'], 'inside the enforcement window');
        static::__assert_true(static::__exists($email), 'the write committed');
        static::__assert_equals($level, DB::transactionLevel(), 'the transaction is closed');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the write lock was released');
    }

    public static function test_a_writer_that_throws_leaves_nothing_written_and_releases_its_lock()
    {
        $email = static::__email();
        $level = DB::transactionLevel();

        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'writer_writes', ['email' => $email, 'throw' => true]), 'threw after writing');

        static::__assert_false(static::__exists($email), 'the write was rolled back');
        static::__assert_equals($level, DB::transactionLevel(), 'the transaction is closed');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the write lock was released');
    }

    public static function test_a_reader_endpoint_runs_under_the_read_lock_with_no_transaction()
    {
        $level = DB::transactionLevel();

        $probe = Ajax::internal(self::FIXTURE, 'reader_probe');

        static::__assert_equals('read', $probe['mode']);
        static::__assert_equals($level, $probe['level'], 'a reader opens no transaction');
        static::__assert_true($probe['enforcing']);
    }

    // =============================================================================
    // The enforcement window is exactly the handler
    // =============================================================================

    public static function test_pre_dispatch_runs_unlocked_and_enforced_and_the_lock_spans_endpoint_and_post_dispatch()
    {
        $post_seen = null;
        Event_Registry::_set_test_handlers('rsx.post_dispatch', [function () use (&$post_seen) {
            $post_seen = Site_Lock_Dispatch_Fixture_Controller::probe();
        }]);

        $level = DB::transactionLevel();

        try {
            $probe = Ajax::internal(self::FIXTURE, 'writer_probe');
        } finally {
            Event_Registry::_clear_test_handlers();
        }

        $pre_seen = Site_Lock_Dispatch_Fixture_Controller::$pre_dispatch_seen;

        static::__assert_true($pre_seen['enforcing'], 'pre_dispatch runs inside an enforcement window');
        static::__assert_null($pre_seen['mode'], 'pre_dispatch runs before the lock is taken');
        static::__assert_equals([], $pre_seen['holds'], 'holding no site lock at all');
        static::__assert_equals($level, $pre_seen['level'], 'outside the request transaction');
        static::__assert_equals('write', $probe['mode'], 'the endpoint runs under the write lock');
        static::__assert_true($probe['enforcing'], 'the endpoint runs inside the window');
        static::__assert_false($post_seen['enforcing'], 'rsx.post_dispatch runs outside the window');
        static::__assert_equals('write', $post_seen['mode'], 'post_dispatch runs before the release');
        static::__assert_equals($level + 1, $post_seen['level'], 'post_dispatch runs before the commit');
    }

    public static function test_a_site_scoped_write_in_pre_dispatch_without_a_section_fatals_naming_the_hook()
    {
        $email = static::__email();

        $e = static::__assert_throws(
            RuntimeException::class,
            fn () => Ajax::internal(self::FIXTURE, 'writer_probe', ['_pre' => 'write', 'pre_email' => $email]),
            'without site 1\'s write lock'
        );

        static::__assert_contains('Site_Lock_Dispatch_Fixture_Controller::pre_dispatch() for Ajax', $e->getMessage(), 'the refusal names the hook');
        static::__assert_false(static::__exists($email), 'nothing was written');
        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'and nothing is held: the writer never took its lock');
    }

    public static function test_a_write_section_in_pre_dispatch_hands_over_cleanly_to_the_writer_lock()
    {
        $pre_email = static::__email();
        $email = static::__email();

        $probe = Ajax::internal(self::FIXTURE, 'writer_writes', ['_pre' => 'section', 'pre_email' => $pre_email, 'email' => $email]);

        static::__assert_true(static::__exists($pre_email), 'the pre_dispatch section wrote and committed');
        static::__assert_equals('write', $probe['mode'], 'the endpoint then took its own write lock');
        static::__assert_equals([self::SITE_ID . ':' . RsxLocks::WRITE_LOCK], array_keys($probe['holds']), 'holding exactly its own write lock');
        static::__assert_equals(1, $probe['holds'][self::SITE_ID . ':' . RsxLocks::WRITE_LOCK]['count'], 'once: the section\'s hold was gone before the boundary');
        static::__assert_true(static::__exists($email), 'the endpoint wrote');
        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'nothing held after the call');
    }

    public static function test_a_pre_dispatch_that_leaks_a_lock_fails_the_call_and_releases_it()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => Ajax::internal(self::FIXTURE, 'writer_probe', ['_pre' => 'leak']),
            'Site_Lock_Dispatch_Fixture_Controller::pre_dispatch() for Ajax ' . self::FIXTURE . '::writer_probe returned still holding the READ lock on site 1'
        );

        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'the leaked hold was released');
    }

    public static function test_main_pre_dispatch_runs_unlocked_and_enforced()
    {
        Site_Lock_Main_Hook_Probe::reset();
        $email = static::__email();
        Site_Lock_Main_Hook_Probe::$pre_dispatch = fn () => static::__block($email);

        $e = static::__with_probe_main(fn () => static::__assert_throws(
            RuntimeException::class,
            fn () => Dispatcher::ajax_main_pre_dispatch(request(), self::FIXTURE, 'writer_probe'),
            'without site 1\'s write lock'
        ));

        static::__assert_contains('Site_Lock_Main_Hook_Probe::pre_dispatch()', $e->getMessage(), 'the refusal names Main::pre_dispatch');
        static::__assert_false(static::__exists($email), 'nothing was written');

        $email = static::__email();
        Site_Lock_Main_Hook_Probe::$pre_dispatch = fn () => Site_Lock::write(self::SITE_ID, fn () => static::__block($email));

        static::__assert_null(static::__with_probe_main(fn () => Dispatcher::ajax_main_pre_dispatch(request(), self::FIXTURE, 'writer_probe')));
        static::__assert_true(static::__exists($email), 'a write section in Main::pre_dispatch writes');

        $state = Site_Lock_Main_Hook_Probe::$seen['pre_dispatch'][0];
        static::__assert_true($state['enforcing'], 'Main::pre_dispatch runs inside an enforcement window');
        static::__assert_equals([], $state['holds'], 'with no site lock');
    }

    public static function test_a_library_releasing_its_own_raw_holds_inside_a_writer_leaves_the_automatic_lock()
    {
        $email = static::__email();

        $after_library = Ajax::internal(self::FIXTURE, 'writer_uses_library', ['email' => $email]);

        static::__assert_equals('write', $after_library['mode'], 'the endpoint still holds its automatic write lock');
        static::__assert_equals(1, $after_library['holds'][self::SITE_ID . ':' . RsxLocks::WRITE_LOCK]['count'] ?? 0, 'at exactly one hold');
        static::__assert_true(static::__exists($email), 'and its write after the library returned committed');
        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'nothing held after the call');
    }

    public static function test_a_post_dispatch_throw_rolls_the_writer_back()
    {
        $email = static::__email();
        Event_Registry::_set_test_handlers('rsx.post_dispatch', [function () {
            throw new RuntimeException('post_dispatch refused the call');
        }]);

        try {
            static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'writer_writes', ['email' => $email]), 'post_dispatch refused');
        } finally {
            Event_Registry::_clear_test_handlers();
        }

        static::__assert_false(static::__exists($email), 'the endpoint\'s write was rolled back');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the lock was released');
    }

    // =============================================================================
    // Nesting
    // =============================================================================

    public static function test_a_writer_called_from_a_reader_fatals_and_a_writer_hosts_both()
    {
        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'reader_calls_writer'), 'while holding its READ lock');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the reader released its lock');

        $result = Ajax::internal(self::FIXTURE, 'writer_calls_both');

        static::__assert_equals($result['outer']['token'], $result['reader']['token'], 'the nested reader ran under the outer write lock');
        static::__assert_equals($result['outer']['token'], $result['writer']['token'], 'the nested writer ran under the outer write lock');
        static::__assert_equals($result['outer']['level'], $result['writer']['level'], 'the nested writer opened no second transaction');
    }

    // =============================================================================
    // The Ajax batch (A5)
    // =============================================================================

    public static function test_every_call_in_a_batch_takes_its_own_lock_after_its_unlocked_hooks()
    {
        Site_Lock_Main_Hook_Probe::reset();

        $answers = static::__with_probe_main(fn () => static::__batch([
            'reader_probe', 'reader_probe', 'writer_probe', 'reader_probe',
        ]));

        static::__assert_equals(['read', 'read', 'write', 'read'], array_column($answers, 'mode'), 'each call ran under its own mode');
        static::__assert_count(4, array_unique(array_column($answers, 'token')), 'each call took a lock of its own: nothing is shared between calls');

        $main_seen = Site_Lock_Main_Hook_Probe::$seen['pre_dispatch'];

        static::__assert_count(4, $main_seen, 'Main::pre_dispatch ran once per call');

        foreach ($main_seen as $index => $state) {
            static::__assert_true($state['enforcing'], "call {$index}: Main::pre_dispatch is enforced");
            static::__assert_equals([], $state['holds'], "call {$index}: Main::pre_dispatch runs with no site lock held");
        }

        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'nothing held after the batch');
    }

    public static function test_a_failing_call_in_a_batch_leaves_nothing_held()
    {
        $answers = static::__with_probe_main(fn () => static::__batch(['reader_probe', 'reader_writes'], ['email' => static::__email()], true));

        static::__assert_equals('read', $answers[0]['_ajax_return_value']['mode']);
        static::__assert_false($answers[1]['_success'], 'the writing reader failed');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'nothing held after the batch');
    }

    // =============================================================================
    // #[Manual_Lock]: the request transaction (Part B)
    // =============================================================================

    public static function test_a_manual_endpoints_first_raw_write_lock_begins_a_request_transaction_committed_at_return()
    {
        $email = static::__email();
        $level = DB::transactionLevel();

        $warnings = static::__capture_warnings(function () use ($email, &$result) {
            $result = Ajax::internal(self::FIXTURE, 'manual_raw_lock', ['email' => $email]);
        });

        static::__assert_equals($level, $result['before'], 'no transaction before the first write lock');
        static::__assert_equals($level + 1, $result['inside'], 'the grant began the request transaction');
        static::__assert_equals($level + 1, $result['after_release'], 'it holds past the early release');
        static::__assert_true(static::__exists($email), 'committed when the endpoint returned');
        static::__assert_equals($level, DB::transactionLevel(), 'and closed');
        static::__assert_count(1, array_filter($warnings, fn (string $line) => str_contains($line, 'released early')), 'the early release is logged');
    }

    public static function test_a_manual_endpoint_that_throws_rolls_its_request_transaction_back()
    {
        $email = static::__email();

        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'manual_raw_lock', ['email' => $email, 'then' => 'throw']), 'threw after releasing early');

        static::__assert_false(static::__exists($email), 'the write went with the rollback');
    }

    public static function test_a_manual_endpoint_cannot_lock_again_while_its_released_transaction_is_open()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => Ajax::internal(self::FIXTURE, 'manual_raw_lock', ['email' => static::__email(), 'then' => 'relock']),
            'rule 1'
        );

        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'nothing held after the refusal');
    }

    // =============================================================================
    // The site never changes inside a handler; RsxAuth::enter_site() (A4, A10)
    // =============================================================================

    public static function test_changing_the_site_inside_an_ajax_endpoint_fatals()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => Ajax::internal(self::FIXTURE, 'reader_changes_site', ['site_id' => self::OTHER_SITE_ID]),
            'RsxAuth::enter_site'
        );

        static::__assert_equals(self::SITE_ID, (int) Session::get_site_id(), 'the site did not change');
    }

    public static function test_enter_site_from_a_manual_endpoint_enters_under_that_sites_write_lock()
    {
        try {
            $result = Ajax::internal(self::FIXTURE, 'manual_enters_site', ['site_id' => self::OTHER_SITE_ID]);
        } finally {
            static::__acting_as_user(1);
        }

        static::__assert_null($result['before']['mode'], 'a manual endpoint holds no lock');
        static::__assert_equals(self::OTHER_SITE_ID, (int) $result['site_id'], 'the session is on the entered site');
        static::__assert_null($result['after']['mode'], 'the site\'s write lock was released');
        static::__assert_false(RsxLocks::held_site_locks() !== [], 'nothing held after the call');
    }

    public static function test_enter_site_is_refused_while_a_site_lock_is_held()
    {
        static::__acting_as_user(1);

        static::__assert_throws(
            RuntimeException::class,
            fn () => Ajax::internal(self::FIXTURE, 'reader_enters_site', ['site_id' => self::OTHER_SITE_ID]),
            '#[Manual_Lock]'
        );

        static::__assert_throws(
            RuntimeException::class,
            fn () => Site_Lock::read(self::SITE_ID, fn () => \App\RSpade\Core\Auth\RsxAuth::enter_site(self::OTHER_SITE_ID)),
            'refused'
        );

        static::__assert_equals(self::SITE_ID, (int) Session::get_site_id(), 'the site did not change');
    }

    // =============================================================================
    // Effects that wait for the commit
    // =============================================================================

    public static function test_a_task_dispatched_by_a_writer_that_rolls_back_leaves_no_run()
    {
        static::__assert_throws(RuntimeException::class, fn () => Ajax::internal(self::FIXTURE, 'writer_dispatches_then_throws'), 'threw after dispatching');

        $task_id = Site_Lock_Dispatch_Fixture_Controller::$dispatched_task_id;

        static::__assert_true($task_id !== null, 'the endpoint dispatched a run');
        static::__assert_false(DB::table('_tasks')->where('id', $task_id)->exists(), 'the run row went with the rollback');
    }

    // =============================================================================
    // Page routes
    // =============================================================================

    public static function test_a_page_route_runs_its_action_in_the_window_with_no_lock_unless_declared()
    {
        $plain = static::__route('/_test/site-lock/route-probe');

        static::__assert_null($plain['mode'], 'no automatic lock on an undeclared route');
        static::__assert_true($plain['enforcing'], 'the action runs in the enforcement window');

        Site_Lock_Main_Hook_Probe::reset();
        $writer = static::__with_probe_main(fn () => static::__route('/_test/site-lock/route-writer-probe'));

        static::__assert_equals('write', $writer['mode'], 'a #[Writer] route runs under the write lock');
        static::__assert_true($writer['enforcing']);
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'released after the response');

        $main = Site_Lock_Main_Hook_Probe::$seen['pre_dispatch'][0];
        $controller = Site_Lock_Dispatch_Fixture_Controller::$pre_dispatch_seen;

        static::__assert_true($main['enforcing'] && $main['holds'] === [], 'Main::pre_dispatch ran unlocked and enforced');
        static::__assert_true($controller['enforcing'] && $controller['holds'] === [], 'the controller\'s pre_dispatch ran unlocked and enforced, before the route\'s write lock');
    }

    public static function test_unhandled_route_runs_unlocked_and_enforced()
    {
        Site_Lock_Main_Hook_Probe::reset();
        Site_Lock_Main_Hook_Probe::$unhandled_route = fn () => ['type' => 'json', 'data' => Site_Lock_Dispatch_Fixture_Controller::probe()];

        $answer = static::__with_probe_main(fn () => static::__route('/_test/site-lock/no-such-route'));

        static::__assert_true($answer['enforcing'], 'unhandled_route runs inside an enforcement window');
        static::__assert_null($answer['mode'], 'with no automatic lock');
        static::__assert_equals([], $answer['holds'], 'and no site lock held');
    }

    // =============================================================================
    // Helpers
    // =============================================================================

    /**
     * Run actions as ONE browser batch and answer each call's return value (or, with
     * $envelopes, each envelope).
     */
    private static function __batch(array $actions, array $params = [], bool $envelopes = false): array
    {
        $calls = [];
        foreach ($actions as $index => $action) {
            $calls[] = ['call_id' => $index, 'controller' => self::FIXTURE, 'action' => $action, 'params' => $params];
        }

        $request = Request::create('/_ajax/_batch', 'POST', ['batch_calls' => $calls]);
        $answer = json_decode(Ajax::handle_batch_request($request)->getContent(), true);

        $out = [];
        foreach (array_keys($actions) as $index) {
            $envelope = $answer['C_' . $index] ?? null;
            static::__assert_true(is_array($envelope), "call {$index} was answered: " . json_encode($answer));
            static::__assert_true($envelopes || ($envelope['_success'] ?? false), "call {$index} succeeded: " . json_encode($envelope));
            $out[] = $envelopes ? $envelope : $envelope['_ajax_return_value'];
        }

        return $out;
    }

    /** Run with the dispatch tests' Main probe standing in for the application's Main. */
    private static function __with_probe_main(callable $fn): mixed
    {
        $index = &Manifest::$data['data']['php_subclass_index'];
        $original = $index['Main_Abstract'] ?? null;
        $index['Main_Abstract'] = ['Site_Lock_Main_Hook_Probe'];

        try {
            return $fn();
        } finally {
            // What the probe runs belongs to this call only; what it saw stays for the test.
            Site_Lock_Main_Hook_Probe::$pre_dispatch = null;
            Site_Lock_Main_Hook_Probe::$unhandled_route = null;

            if ($original === null) {
                unset($index['Main_Abstract']);
            } else {
                $index['Main_Abstract'] = $original;
            }
        }
    }

    /** GET a fixture page route through the front controller; answer its JSON. */
    private static function __route(string $url): array
    {
        $request = Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        app()->instance('request', $request);

        $response = Rsx_Front_Controller::handle($request);
        $data = json_decode((string) $response->getContent(), true);

        static::__assert_true(is_array($data) && array_key_exists('enforcing', $data), "{$url} answered the probe: " . $response->getStatusCode() . ' ' . substr((string) $response->getContent(), 0, 1500));

        return $data;
    }

    private static function __email(): string
    {
        return 'site-lock-dispatch-' . bin2hex(random_bytes(6)) . '@test.rspade.test';
    }

    private static function __block(string $email): void
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'site lock dispatch test';
        $row->save();
    }

    private static function __exists(string $email): bool
    {
        return Email_Blocked_Address_Model::where('email', $email)->exists();
    }

    private static function __holds(string $type): bool
    {
        return RsxLocks::holds(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . self::SITE_ID, $type);
    }

    /** The WARNING lines logged while $work ran. */
    private static function __capture_warnings(callable $work): array
    {
        $warnings = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });

        try {
            $work();
        } finally {
            Event::forget(MessageLogged::class);
        }

        return $warnings;
    }
}
