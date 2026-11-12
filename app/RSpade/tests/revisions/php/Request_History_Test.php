<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\RequestHistory\Request_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;
use App\RSpade\Lib\Revisions\Revision_Cleanup_Service;
use App\RSpade\Tests\Revisions\Php\Request_History_Fixture_Api_Controller;
use App\RSpade\Tests\Revisions\Php\Request_History_Fixture_Controller;

/**
 * The writer request history and replay by _request_ref (Request_History): Ajax calls through
 * the browser transport (Ajax::handle_batch_request()) and external API requests through
 * Rsx_Front_Controller::handle(), against fixture endpoints, acting as the baseline user on
 * site 1. Every write is a framework site-scoped row (_email_blocked_addresses); each fixture
 * counts its runs, so a replay (no run) is told from an execution.
 *
 * Cross-process serialization of two identical requests is Request_History_Concurrency_Test.
 */
class Request_History_Test extends Rsx_Test_Abstract
{
    private const FIXTURE = 'Request_History_Fixture_Controller';

    public static function setup(): void
    {
        static::__begin();
    }

    public static function teardown(): void
    {
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
    }

    // =============================================================================
    // Ajax replay
    // =============================================================================

    public static function test_ajax_same_ref_resent_returns_the_stored_answer_with_replayed_and_the_original_timing()
    {
        static::__begin();

        $email = static::__email();
        $ref = static::__ref();

        $first = static::__ajax('block', ['email' => $email, '_request_ref' => $ref]);
        $second = static::__ajax('block', ['email' => $email, '_request_ref' => $ref]);

        static::__assert_true($first['_success'], 'the first call ran: ' . json_encode($first));
        static::__assert_false(isset($first['_replayed']), 'a fresh answer is not marked replayed');
        static::__assert_true($second['_success']);
        static::__assert_true($second['_replayed'] ?? false, 'the resend is answered from the history');
        static::__assert_equals($first['_ajax_return_value'], $second['_ajax_return_value'], 'with the stored value');
        static::__assert_equals($first['_duration_ms'], $second['_duration_ms'], 'and the original duration');
        static::__assert_equals($first['_completed_at'], $second['_completed_at'], 'and the original completion time');
        static::__assert_equals(1, Request_History_Fixture_Controller::$runs['block'], 'the endpoint ran once');
        static::__assert_equals(1, Email_Blocked_Address_Model::where('email', $email)->count(), 'one write');

        // The raw envelope keeps an empty object an empty object on replay.
        $raw = static::__call_raw('block', ['email' => $email, '_request_ref' => $ref]);
        static::__assert_contains('"empty":{}', $raw, 'an empty object in the stored value is replayed as {}');
    }

    public static function test_ajax_the_record_is_on_the_writers_transaction_row()
    {
        static::__begin();

        $ref = static::__ref();
        $answer = static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref, 'password' => 'hunter2']);

        $row = DB::table('_transactions')->where('request_ref', $ref)->first();

        static::__assert_not_null($row, 'the reference is recorded');
        static::__assert_equals(self::FIXTURE . '::block', $row->endpoint);
        static::__assert_equals(1, (int) $row->site_id, 'under the request\'s site');
        static::__assert_equals(1, (int) $row->actor_id, 'and its actor');
        static::__assert_equals(200, (int) $row->response_status, 'an Ajax answer is stored with status 200');
        static::__assert_equals(
            json_encode(['_success' => true, '_ajax_return_value' => $answer['_ajax_return_value']]),
            json_encode(json_decode($row->response_body, true)),
            'the body is the envelope outcome, with no side channels'
        );
        static::__assert_equals(64, strlen((string) $row->request_fingerprint));
        static::__assert_contains('"password":"[redacted]"', (string) $row->request_payload, 'the payload is redacted');
        static::__assert_false(str_contains((string) $row->request_payload, '_request_ref'), 'and carries no reference');
        static::__assert_not_null($row->completed_at);
        static::__assert_equals($answer['_duration_ms'], (int) $row->duration_ms, 'the envelope carries the recorded timing');
    }

    public static function test_ajax_a_different_body_with_the_same_ref_is_refused_and_runs_nothing()
    {
        static::__begin();

        $ref = static::__ref();
        static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref]);

        $other = static::__email();
        $answer = static::__ajax('block', ['email' => $other, '_request_ref' => $ref]);

        static::__assert_false($answer['_success']);
        static::__assert_equals(Ajax::ERROR_REQUEST_REF_REUSED, $answer['error_code']);
        static::__assert_equals($ref, $answer['_request_ref'], 'the refusal echoes the reference');
        static::__assert_equals(1, Request_History_Fixture_Controller::$runs['block'], 'nothing ran');
        static::__assert_false(Email_Blocked_Address_Model::where('email', $other)->exists(), 'nothing was written');
    }

    public static function test_ajax_a_same_endpoint_reuse_names_the_original_completion_and_answer()
    {
        static::__begin();

        $ref = static::__ref();
        $first = static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref]);

        $answer = static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref]);

        static::__assert_equals(Ajax::ERROR_REQUEST_REF_REUSED, $answer['error_code'] ?? null, json_encode($answer));
        static::__assert_equals($first['_completed_at'], $answer['metadata']['completed_at'] ?? null, 'the original completion');
        static::__assert_equals($first['_ajax_return_value'], $answer['metadata']['original'] ?? null, 'the original answer, as the call returned it');

        // The stored answer is sent as stored: an empty object stays {}.
        $raw = static::__call_raw('block', ['email' => static::__email(), '_request_ref' => $ref]);
        static::__assert_contains('"empty":{}', $raw, 'an empty object in the original answer is sent as {}');
    }

    public static function test_ajax_a_same_endpoint_reuse_after_pruning_names_only_the_completion()
    {
        static::__begin();

        $ref = static::__ref();
        $first = static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref]);
        DB::table('_transactions')->where('request_ref', $ref)->update(['response_body' => null, 'response_status' => null, 'request_payload' => null]);

        $answer = static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref]);

        static::__assert_equals(Ajax::ERROR_REQUEST_REF_REUSED, $answer['error_code'] ?? null, json_encode($answer));
        static::__assert_equals(['completed_at' => $first['_completed_at']], $answer['metadata'], 'the completion and nothing else');
    }

    public static function test_ajax_the_same_ref_on_a_different_endpoint_is_refused()
    {
        static::__begin();

        $ref = static::__ref();
        $email = static::__email();
        static::__ajax('block', ['email' => $email, '_request_ref' => $ref]);

        $answer = static::__ajax('block_elsewhere', ['email' => $email, '_request_ref' => $ref]);

        static::__assert_equals(Ajax::ERROR_REQUEST_REF_REUSED, $answer['error_code'] ?? null, json_encode($answer));
        static::__assert_equals([], $answer['metadata'], 'a reuse on another endpoint names nothing of the original');
        static::__assert_false(isset(Request_History_Fixture_Controller::$runs['block_elsewhere']), 'nothing ran');
    }

    public static function test_a_signed_out_caller_s_ref_is_echoed_and_never_honoured()
    {
        static::__begin();
        static::__reset_session();
        static::__acting_as_site(1);

        $ref = static::__ref();
        $first = static::__ajax('writes_nothing', ['_request_ref' => $ref]);
        $second = static::__ajax('writes_nothing', ['_request_ref' => $ref]);

        static::__assert_true($first['_success'] && $second['_success'], json_encode([$first, $second]));
        static::__assert_equals($ref, $second['_request_ref'], 'the reference is echoed');
        static::__assert_false(isset($second['_replayed']), 'never answered from the history');
        static::__assert_equals(2, Request_History_Fixture_Controller::$runs['writes_nothing'], 'the endpoint ran both times');
        static::__assert_false(DB::table('_transactions')->where('request_ref', $ref)->exists(), 'no reference is kept');
    }

    public static function test_another_actor_with_the_same_ref_executes()
    {
        static::__begin();

        $ref = static::__ref();
        static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref]);

        // A second user on the same site: its own, unrelated request.
        $other = static::__second_user();
        Session::impersonate(1, (int) $other->login_user_id, (int) $other->id);
        $answer = static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref]);

        static::__assert_true($answer['_success'], json_encode($answer));
        static::__assert_false(isset($answer['_replayed']), 'not a replay of the other actor\'s request');
        static::__assert_equals(2, Request_History_Fixture_Controller::$runs['block']);
        static::__assert_equals(2, DB::table('_transactions')->where('request_ref', $ref)->count(), 'two records under two actors');
    }

    public static function test_a_reader_with_a_ref_executes_every_time_and_records_nothing()
    {
        static::__begin();

        $ref = static::__ref();
        $before = DB::table('_transactions')->count();

        $first = static::__ajax('read', ['_request_ref' => $ref, 'x' => 1]);
        $second = static::__ajax('read', ['_request_ref' => $ref, 'x' => 1]);

        static::__assert_equals(2, Request_History_Fixture_Controller::$runs['read'], 'the reader ran both times');
        static::__assert_equals($ref, $first['_request_ref'], 'the reference is echoed');
        static::__assert_equals($ref, $second['_request_ref']);
        static::__assert_false(isset($second['_replayed']));
        static::__assert_equals(['x'], $first['_ajax_return_value']['seen'], 'the endpoint never sees _request_ref');
        static::__assert_equals($before, DB::table('_transactions')->count(), 'a reader mints and records nothing');
    }

    public static function test_after_pruning_the_resend_answers_request_response_expired()
    {
        static::__begin();

        $ref = static::__ref();
        $email = static::__email();
        static::__ajax('block', ['email' => $email, '_request_ref' => $ref]);

        $row = DB::table('_transactions')->where('request_ref', $ref)->first();
        DB::table('_transactions')->where('id', $row->id)->update(['created_at' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
        static::__run_task_method(Revision_Cleanup_Service::class, 'prune_request_payloads', ['retention_days' => 7]);

        $answer = static::__ajax('block', ['email' => $email, '_request_ref' => $ref]);

        static::__assert_equals(Ajax::ERROR_REQUEST_RESPONSE_EXPIRED, $answer['error_code'] ?? null, json_encode($answer));
        static::__assert_not_null($answer['metadata']['completed_at'] ?? null, 'naming the original completion');
        static::__assert_equals(1, Request_History_Fixture_Controller::$runs['block'], 'nothing ran');
    }

    public static function test_a_request_that_failed_before_commit_leaves_its_ref_reusable()
    {
        static::__begin();

        $ref = static::__ref();
        $email = static::__email();

        $failed = static::__ajax('block', ['email' => $email, '_request_ref' => $ref, 'throw' => 1]);
        static::__assert_false($failed['_success']);
        static::__assert_equals($ref, $failed['_request_ref'], 'an error echoes the reference');
        static::__assert_false(Email_Blocked_Address_Model::where('email', $email)->exists(), 'the failed write rolled back');
        static::__assert_equals(0, DB::table('_transactions')->where('request_ref', $ref)->count(), 'and recorded nothing');

        $corrected = static::__ajax('block', ['email' => $email, '_request_ref' => $ref]);

        static::__assert_true($corrected['_success'], 'the corrected request runs under the same reference: ' . json_encode($corrected));
        static::__assert_false(isset($corrected['_replayed']));
        static::__assert_true(Email_Blocked_Address_Model::where('email', $email)->exists());
    }

    public static function test_a_returned_coded_failure_commits_its_writes_but_keeps_no_ref()
    {
        static::__begin();

        $ref = static::__ref();
        $email = static::__email();

        $refused = static::__ajax('block', ['email' => $email, '_request_ref' => $ref, 'refuse' => 1]);

        static::__assert_equals(Ajax::ERROR_VALIDATION, $refused['error_code'] ?? null);
        static::__assert_true(Email_Blocked_Address_Model::where('email', $email)->exists(), 'a returned coded result commits');
        static::__assert_equals(0, DB::table('_transactions')->where('request_ref', $ref)->count(), 'its reference is not kept');

        $again = static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref]);
        static::__assert_true($again['_success'], 'the reference stays usable');
    }

    public static function test_an_invalid_ref_is_a_validation_error_that_runs_nothing_and_is_echoed()
    {
        static::__begin();

        $ref = str_repeat('r', Request_History::REF_MAX_LENGTH + 1);

        $answer = static::__ajax('block', ['email' => static::__email(), '_request_ref' => $ref]);

        static::__assert_equals(Ajax::ERROR_VALIDATION, $answer['error_code'] ?? null);
        static::__assert_array_has_key(Request_History::REF_PARAM, $answer['metadata']);
        static::__assert_equals($ref, $answer['_request_ref']);
        static::__assert_false(isset(Request_History_Fixture_Controller::$runs['block']), 'nothing ran');
    }

    public static function test_every_envelope_carries_timing_and_no_ref_field_when_none_was_sent()
    {
        static::__begin();

        foreach (['read' => [], 'writes_nothing' => [], 'block' => ['email' => static::__email()]] as $action => $params) {
            $envelope = static::__ajax($action, $params);

            static::__assert_true(is_int($envelope['_duration_ms'] ?? null), "{$action}: _duration_ms is an integer");
            static::__assert_true((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', (string) ($envelope['_completed_at'] ?? '')), "{$action}: _completed_at is ISO UTC");
            static::__assert_false(array_key_exists('_request_ref', $envelope), "{$action}: no reference sent, none echoed");
            static::__assert_false(is_array($envelope['_ajax_return_value'] ?? null) && array_key_exists('_duration_ms', $envelope['_ajax_return_value']), 'never inside the returned value');
        }
    }

    // =============================================================================
    // The eager mint
    // =============================================================================

    public static function test_a_writer_that_saves_nothing_has_its_row_and_a_reader_none()
    {
        static::__begin();

        $before = DB::table('_transactions')->count();
        static::__ajax('read', []);
        static::__assert_equals($before, DB::table('_transactions')->count(), 'a reader mints nothing');

        $ref = static::__ref();
        static::__ajax('writes_nothing', ['_request_ref' => $ref]);

        $row = DB::table('_transactions')->where('request_ref', $ref)->first();
        static::__assert_not_null($row, 'the writer that saved nothing has its row');
        static::__assert_equals(self::FIXTURE . '::writes_nothing', $row->endpoint);
        static::__assert_equals(0, (int) $row->revision_count);

        $replay = static::__ajax('writes_nothing', ['_request_ref' => $ref]);
        static::__assert_true($replay['_replayed'] ?? false, 'so a retry of a no-change write replays');
        static::__assert_equals(1, Request_History_Fixture_Controller::$runs['writes_nothing']);
    }

    // =============================================================================
    // #[Manual_Lock]
    // =============================================================================

    public static function test_a_manual_lock_request_that_took_a_write_lock_is_recorded_and_replays()
    {
        static::__begin();

        $ref = static::__ref();
        $email = static::__email();

        $first = static::__ajax('manual_block', ['email' => $email, '_request_ref' => $ref]);
        $second = static::__ajax('manual_block', ['email' => $email, '_request_ref' => $ref]);

        static::__assert_true($first['_success'], json_encode($first));
        static::__assert_true($second['_replayed'] ?? false, 'the manual writer replays: ' . json_encode($second));
        static::__assert_equals(1, Request_History_Fixture_Controller::$runs['manual_block']);
        static::__assert_equals(1, Email_Blocked_Address_Model::where('email', $email)->count());
    }

    public static function test_a_manual_lock_request_that_took_no_write_lock_records_nothing()
    {
        static::__begin();

        $ref = static::__ref();
        $before = DB::table('_transactions')->count();

        static::__ajax('manual_reads', ['_request_ref' => $ref]);
        $again = static::__ajax('manual_reads', ['_request_ref' => $ref]);

        static::__assert_equals(2, Request_History_Fixture_Controller::$runs['manual_reads'], 'it ran both times');
        static::__assert_false(isset($again['_replayed']));
        static::__assert_equals($ref, $again['_request_ref']);
        static::__assert_equals($before, DB::table('_transactions')->count(), 'not a writer request: no row');
    }

    // =============================================================================
    // External API
    // =============================================================================

    public static function test_api_same_ref_resent_replays_and_a_changed_body_or_endpoint_is_409()
    {
        static::__begin();

        $key = static::__api_key();
        $ref = static::__ref();
        $email = static::__email();

        $first = static::__api($key, 'POST', '/api/v1/test-probe/history/block', ['email' => $email, '_request_ref' => $ref]);
        $second = static::__api($key, 'POST', '/api/v1/test-probe/history/block', ['email' => $email, '_request_ref' => $ref]);

        static::__assert_equals(200, $first['status'], json_encode($first));
        static::__assert_equals($ref, $first['body']['_request_ref'], 'a success echoes the reference at the top level');
        static::__assert_false(isset($first['body']['_replayed']));
        static::__assert_equals(200, $second['status']);
        static::__assert_true($second['body']['_replayed'] ?? false, 'the resend is a replay: ' . json_encode($second));
        static::__assert_equals($first['body']['run'], $second['body']['run'], 'of the stored body');
        static::__assert_true(is_int($second['body']['_duration_ms'] ?? null), 'with the original duration');
        static::__assert_not_null($second['body']['_completed_at'] ?? null, 'and completion');
        static::__assert_equals($ref, $second['body']['_request_ref']);
        static::__assert_equals(1, Request_History_Fixture_Api_Controller::$runs['block'], 'the endpoint ran once');
        static::__assert_equals(1, Email_Blocked_Address_Model::where('email', $email)->count(), 'one write');

        $row = DB::table('_transactions')->where('request_ref', $ref)->first();
        static::__assert_equals(200, (int) $row->response_status);
        static::__assert_contains($email, (string) $row->request_payload, 'the payload is the request body');

        $changed = static::__api($key, 'POST', '/api/v1/test-probe/history/block', ['email' => static::__email(), '_request_ref' => $ref]);
        static::__assert_equals(409, $changed['status']);
        static::__assert_equals('request_ref_reused', $changed['body']['error']['code'] ?? null);
        static::__assert_equals($ref, $changed['body']['_request_ref'], 'an error echoes the reference');
        static::__assert_equals($first['body']['_completed_at'], $changed['body']['error']['completed_at'] ?? null, 'naming the original completion');
        static::__assert_equals($first['body']['run'], $changed['body']['error']['original']['run'] ?? null, 'and the stored body');

        $elsewhere = static::__api($key, 'POST', '/api/v1/test-probe/history/block-elsewhere', ['email' => $email, '_request_ref' => $ref]);
        static::__assert_equals(409, $elsewhere['status']);
        static::__assert_false(isset($elsewhere['body']['error']['completed_at']), 'another endpoint names nothing of the original');
        static::__assert_false(isset($elsewhere['body']['error']['original']));
        static::__assert_false(isset(Request_History_Fixture_Api_Controller::$runs['block_elsewhere']), 'nothing ran');
    }

    public static function test_api_after_pruning_the_resend_is_410_naming_the_original_completion()
    {
        static::__begin();

        $key = static::__api_key();
        $ref = static::__ref();
        $email = static::__email();
        static::__api($key, 'POST', '/api/v1/test-probe/history/block', ['email' => $email, '_request_ref' => $ref]);

        DB::table('_transactions')->where('request_ref', $ref)->update(['response_body' => null, 'response_status' => null, 'request_payload' => null]);

        $answer = static::__api($key, 'POST', '/api/v1/test-probe/history/block', ['email' => $email, '_request_ref' => $ref]);

        static::__assert_equals(410, $answer['status'], json_encode($answer));
        static::__assert_equals('request_response_expired', $answer['body']['error']['code'] ?? null);
        static::__assert_not_null($answer['body']['error']['completed_at'] ?? null);
    }

    public static function test_api_echo_on_reader_validation_and_auth_failures_and_absent_when_not_sent()
    {
        static::__begin();

        $key = static::__api_key();
        $ref = static::__ref();

        $read = static::__api($key, 'GET', '/api/v1/test-probe/history/read?_request_ref=' . $ref, []);
        static::__assert_equals(200, $read['status'], json_encode($read));
        static::__assert_equals($ref, $read['body']['_request_ref']);
        static::__assert_equals([], $read['body']['params'], 'the endpoint never sees _request_ref');

        $again = static::__api($key, 'GET', '/api/v1/test-probe/history/read?_request_ref=' . $ref, []);
        static::__assert_equals(2, $again['body']['read'], 'a reader runs every time');

        $invalid = static::__api($key, 'POST', '/api/v1/test-probe/history/block', ['email' => static::__email(), '_request_ref' => '']);
        static::__assert_equals(422, $invalid['status']);
        static::__assert_array_has_key('_request_ref', $invalid['body']['error']['fields']);
        static::__assert_equals('', $invalid['body']['_request_ref'], 'echoed as sent');

        $unauthenticated = static::__api('rsx_not_a_key', 'POST', '/api/v1/test-probe/history/block', ['_request_ref' => $ref]);
        static::__assert_equals(401, $unauthenticated['status']);
        static::__assert_equals($ref, $unauthenticated['body']['_request_ref'], 'a 401 echoes the reference');

        $plain = static::__api($key, 'GET', '/api/v1/test-probe/history/read', []);
        static::__assert_false(array_key_exists('_request_ref', $plain['body']), 'no reference sent, none echoed');
    }

    // =============================================================================
    // Helpers
    // =============================================================================

    /** Each test starts as the baseline user with no fixture runs counted. */
    private static function __begin(): void
    {
        static::__acting_as_user(1);
        Request_History_Fixture_Controller::$runs = [];
        Request_History_Fixture_Api_Controller::$runs = [];
    }

    /** A second login identity with a membership on site 1 (rolled back with the test). */
    private static function __second_user(): User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'request-history-other-' . bin2hex(random_bytes(6)) . '@test.rspade.test';
        $login_user->password = Login_User_Model::hash_password('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = 1;
        $user->login_user_id = $login_user->id;
        $user->first_name = 'Request';
        $user->last_name = 'History';
        $user->email = $login_user->email;
        $user->role_id = static::most_privileged_role_id();
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    /** One call through the browser transport; its decoded envelope. */
    private static function __ajax(string $action, array $params): array
    {
        return json_decode(static::__call_raw($action, $params), true)['C_0'];
    }

    private static function __call_raw(string $action, array $params): string
    {
        $calls = [['call_id' => 0, 'controller' => self::FIXTURE, 'action' => $action, 'params' => $params]];
        $request = Request::create('/_ajax/_batch', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['batch_calls' => $calls]));

        return Ajax::handle_batch_request($request)->getContent();
    }

    private static function __api_key(): string
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->is_api_access_enabled = 1;
        $user->save();
        static::grant_every_permission(1);

        return Api_Key_Model::generate(1, 'Request history probe (test)')['key'];
    }

    /** One API request through the front controller: [status, body (decoded)]. */
    private static function __api(string $key, string $method, string $path, array $body): array
    {
        $request = Request::create($path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
        ], $method === 'POST' ? json_encode($body) : null);
        app()->instance('request', $request);

        $response = Rsx_Front_Controller::handle($request);

        // The front controller ended the API identity: act as the baseline user again.
        static::__acting_as_user(1);

        return ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)];
    }

    private static function __email(): string
    {
        return 'request-history-' . bin2hex(random_bytes(6)) . '@test.rspade.test';
    }

    private static function __ref(): string
    {
        return 'ref-' . bin2hex(random_bytes(8));
    }
}
