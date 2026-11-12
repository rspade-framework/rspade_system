<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Companion\Companion_Dispatcher;
use App\RSpade\Core\Companion\Companion_Service_Abstract;
use App\RSpade\Core\Companion\Companion_Store;
use App\RSpade\Core\Companion\Companion_Verifier;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Env\Rsx_Install_State;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Revisions\Transaction_Model;
use App\RSpade\Tests\Companions\Php\CompanionFixture\Companion_Fixture_Companion_Service;
use App\RSpade\Tests\Companions\Php\Companion_Fixture_Model;

/**
 * The companion realm end to end: requests signed by hand against a hand-written Redis
 * instance record, driven through Rsx_Front_Controller::handle() as the kernel hands a request
 * over. The record is written under the test database's token, so no live daemon can see it.
 *
 * Under CLI a session is always virtual, so a sent cookie is proved ignored over real php-fpm by
 * http/companion_realm.sh.
 */
class Companion_Realm_Test extends Rsx_Test_Abstract
{
    private const NAME = 'companion_fixture';
    private const OTHER = 'companion_fixture_other';
    private const INSTANCE = 'test-instance-1';
    private const OTHER_INSTANCE = 'test-instance-2';
    private const STARTED_AT = '1700000000000';

    public static function setup()
    {
        DB::statement('DROP TABLE IF EXISTS companion_fixtures');
        DB::statement('CREATE TABLE companion_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            created_by_id BIGINT NULL DEFAULT NULL,
            created_by_type BIGINT NULL DEFAULT NULL,
            updated_by_id BIGINT NULL DEFAULT NULL,
            updated_by_type BIGINT NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public static function teardown()
    {
        DB::statement('DROP TABLE IF EXISTS companion_fixtures');

        Companion_Store::connection()->del(
            Companion_Store::instance_key(self::INSTANCE),
            Companion_Store::instance_key(self::OTHER_INSTANCE)
        );

        Companion_Fixture_Companion_Service::$not_ready = null;

        // The container must hold an ordinary console request again.
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
    }

    // =========================================================================
    // A VALID CALL
    // =========================================================================

    public static function test_a_valid_call_reaches_the_endpoint_with_no_browser()
    {
        $response = static::__signed('ping', ['a' => 1]);

        static::__assert_equals(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        static::__assert_equals(['a' => 1], $body['params']);
        static::__assert_equals(1, (int) $body['site_id'], 'an absent site is site 1 on a single tenant install');
        static::__assert_null($body['session_id'], 'no session is minted');
        static::__assert_false($body['has_browser']);
    }

    public static function test_a_null_answer_is_a_204()
    {
        static::__assert_equals(204, static::__signed('nothing')->getStatusCode());
    }

    public static function test_the_request_context_ends_with_the_response()
    {
        static::__signed('ping');

        static::__assert_null(Companion_Dispatcher::current_name());
        static::__assert_null(Companion_Dispatcher::current_actor_id());
    }

    public static function test_a_stopping_or_paused_instance_may_still_call()
    {
        foreach (['stopping', 'paused', 'starting'] as $status) {
            $response = static::__signed('ping', [], ['record' => ['status' => $status]]);

            static::__assert_equals(200, $response->getStatusCode(), "status {$status}");
        }
    }

    public static function test_a_coded_failure_keeps_its_status()
    {
        $response = static::__signed('coded');

        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_equals('not_found', static::__code($response));
    }

    public static function test_a_failing_endpoint_is_a_json_500_with_no_detail()
    {
        $response = static::__signed('fails');

        static::__assert_equals(500, $response->getStatusCode());
        static::__assert_equals('internal_error', static::__code($response));
        static::__assert_false(str_contains($response->getContent(), 'Fixture endpoint failure'), 'the detail stays in the log');
    }

    // =========================================================================
    // THE SIGNATURE
    // =========================================================================

    public static function test_a_wrong_signature_is_refused()
    {
        $response = static::__signed('ping', [], ['signature' => str_repeat('a', 64)]);

        static::__assert_refused($response, 401, 'invalid_signature');
    }

    public static function test_a_missing_signature_is_refused()
    {
        $response = static::__signed('ping', [], ['signature' => null]);

        static::__assert_refused($response, 401, 'invalid_signature');
    }

    public static function test_every_signed_field_is_covered()
    {
        $tampered = [
            'body' => '{"a":2}',
            'site' => '2',
            'contract' => str_repeat('b', 64),
            'timestamp' => (string) (time() + 1),
            'nonce' => 'another-nonce-0123456789',
        ];

        foreach ($tampered as $field => $value) {
            $response = static::__signed('ping', ['a' => 1], ['tamper' => [$field => $value]]);

            static::__assert_refused($response, 401, 'invalid_signature', "tampering with {$field}");
        }
    }

    public static function test_a_secret_from_the_wrong_key_is_refused()
    {
        $response = static::__signed('ping', [], ['secret' => hash_hmac('sha256', 'guess', 'not-the-app-key')]);

        static::__assert_refused($response, 401, 'invalid_signature');
    }

    // =========================================================================
    // THE WINDOW AND THE NONCE
    // =========================================================================

    public static function test_a_stale_timestamp_is_refused()
    {
        foreach ([-61, 61] as $offset) {
            $response = static::__signed('ping', [], ['timestamp' => (string) (time() + $offset)]);

            static::__assert_refused($response, 401, 'stale_timestamp', "offset {$offset}");
        }
    }

    public static function test_a_timestamp_inside_the_window_passes()
    {
        foreach ([-50, 50] as $offset) {
            $response = static::__signed('ping', [], ['timestamp' => (string) (time() + $offset)]);

            static::__assert_equals(200, $response->getStatusCode(), "offset {$offset}");
        }
    }

    public static function test_a_replayed_nonce_is_refused()
    {
        $nonce = 'replay-' . bin2hex(random_bytes(12));

        static::__assert_equals(200, static::__signed('ping', [], ['nonce' => $nonce])->getStatusCode());

        static::__assert_refused(static::__signed('ping', [], ['nonce' => $nonce]), 401, 'replayed_nonce');
    }

    public static function test_a_refused_request_burns_no_nonce()
    {
        $nonce = 'unburnt-' . bin2hex(random_bytes(12));

        static::__assert_refused(static::__signed('ping', [], ['nonce' => $nonce, 'signature' => str_repeat('a', 64)]), 401, 'invalid_signature');

        static::__assert_equals(200, static::__signed('ping', [], ['nonce' => $nonce])->getStatusCode());
    }

    // =========================================================================
    // LIVENESS AND THE CONTRACT
    // =========================================================================

    public static function test_an_instance_with_no_record_is_not_live()
    {
        static::__assert_refused(static::__signed('ping', [], ['no_record' => true]), 401, 'instance_not_live');
    }

    public static function test_an_ended_instance_is_not_live()
    {
        foreach (['exited', 'crashed', 'killed', 'waiting'] as $status) {
            $response = static::__signed('ping', [], ['record' => ['status' => $status]]);

            static::__assert_refused($response, 401, 'instance_not_live', "status {$status}");
        }
    }

    public static function test_a_record_for_another_lifetime_is_not_live()
    {
        $response = static::__signed('ping', [], ['record' => ['started_at' => '1700000000001']]);

        static::__assert_refused($response, 401, 'instance_not_live');
    }

    public static function test_a_contract_mismatch_is_refused()
    {
        $response = static::__signed('ping', [], ['contract' => str_repeat('c', 64)]);

        static::__assert_refused($response, 409, 'contract_mismatch');
    }

    public static function test_redis_unavailable_refuses_with_503()
    {
        static::__assert_refused(static::__signed('ping', [], ['maintenance' => true]), 503, 'unavailable');
    }

    // =========================================================================
    // THE SITE
    // =========================================================================

    public static function test_multi_tenancy_refuses_a_request_naming_no_site()
    {
        static::__assert_refused(static::__signed('ping', [], ['tenancy' => 'multi']), 422, 'site_required');
    }

    public static function test_multi_tenancy_runs_in_the_signed_site()
    {
        $site = new Site_Model();
        $site->name = 'Companion second site';
        $site->slug = 'companion-second-site';
        $site->save();

        $response = static::__signed('ping', [], ['site' => (string) $site->id, 'tenancy' => 'multi']);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_equals((int) $site->id, (int) json_decode($response->getContent(), true)['site_id']);
    }

    public static function test_single_tenancy_refuses_another_site()
    {
        static::__assert_refused(static::__signed('ping', [], ['site' => '2']), 403, 'site_not_allowed');
    }

    public static function test_site_zero_is_refused()
    {
        static::__assert_refused(static::__signed('ping', [], ['site' => '0', 'tenancy' => 'multi']), 403, 'site_not_allowed');
    }

    public static function test_a_disabled_site_is_refused()
    {
        DB::table('sites')->where('id', 1)->update(['is_enabled' => 0]);

        static::__assert_refused(static::__signed('ping'), 403, 'site_not_allowed');
    }

    // =========================================================================
    // REALM SEPARATION
    // =========================================================================

    public static function test_a_companion_cannot_reach_another_companions_endpoint()
    {
        // Signing the other companion's URL with its own name: the header disagrees.
        $response = static::__signed('only_mine', [], ['url_name' => self::OTHER]);

        static::__assert_refused($response, 401, 'invalid_signature');

        // Claiming to be the other companion: the record names this one.
        $response = static::__signed('only_mine', [], ['name' => self::OTHER]);

        static::__assert_refused($response, 401, 'instance_not_live');
    }

    public static function test_each_companion_reaches_its_own()
    {
        $response = static::__signed('only_mine', [], ['name' => self::OTHER, 'instance' => self::OTHER_INSTANCE]);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_equals('other', json_decode($response->getContent(), true)['whose']);
    }

    public static function test_an_endpoint_not_in_the_allow_list_is_a_404_after_authentication()
    {
        static::__assert_refused(static::__signed('only_mine'), 404, 'not_found');

        // The same path unsigned says nothing about what exists.
        static::__assert_refused(static::__signed('only_mine', [], ['signature' => null]), 401, 'invalid_signature');
    }

    public static function test_a_staff_session_and_a_portal_session_are_not_consulted()
    {
        static::__acting_as_user(1);

        $response = static::__signed('write_record');

        static::__assert_equals(200, $response->getStatusCode());

        $record = Companion_Fixture_Model::find(json_decode($response->getContent(), true)['id']);

        static::__assert_equals('Companion:' . self::NAME, $record->get_created_by_author()['name']);
    }

    public static function test_the_realm_exports_no_gate_to_a_browser()
    {
        $surface = \App\RSpade\Core\Auth\Auth_Gates::get_surfaces()['Companion_Fixture_Endpoints::ping'] ?? null;

        static::__assert_not_null($surface, 'the endpoint is an indexed surface');
        static::__assert_equals(['companion'], $surface['kinds']);
        static::__assert_equals('companion', $surface['realm']);
        static::__assert_equals(['companion_signature'], $surface['auth']);
        static::__assert_false(\App\RSpade\Core\Auth\Auth_Gates::surface_realm_permits('Companion_Fixture_Endpoints::ping', 'staff'));
        static::__assert_false(\App\RSpade\Core\Auth\Auth_Gates::surface_realm_permits('Companion_Fixture_Endpoints::ping', 'portal'));
    }

    public static function test_only_post_and_the_bare_path()
    {
        $uri = '/_companion/' . self::NAME . '/ping';

        static::__assert_refused(static::__send(Request::create($uri, 'GET')), 405, 'method_not_allowed');
        static::__assert_refused(static::__signed('ping', [], ['query' => '?x=1']), 400, 'invalid_uri');
    }

    public static function test_an_unknown_path_in_the_namespace_is_a_404_not_a_page()
    {
        foreach (['/_companion', '/_companion/a', '/_companion/_daemon/nope', '/_companion/a/b/c'] as $uri) {
            static::__assert_refused(static::__send(Request::create($uri, 'POST')), 404, 'not_found', $uri);
        }
    }

    public static function test_a_body_that_is_not_json_is_refused()
    {
        static::__assert_refused(static::__signed('ping', [], ['raw_body' => 'not json']), 400, 'invalid_json');
    }

    // =========================================================================
    // THE ACTOR
    // =========================================================================

    public static function test_a_write_is_stamped_and_revisioned_as_the_companion()
    {
        $response = static::__signed('write_record', ['name' => 'from a companion']);

        static::__assert_equals(200, $response->getStatusCode());

        $record = Companion_Fixture_Model::find(json_decode($response->getContent(), true)['id']);

        static::__assert_not_null($record->created_by_id, 'the audit pair is stamped');
        static::__assert_equals('Companion:' . self::NAME, $record->get_created_by_author()['name']);

        $transaction = Transaction_Model::orderBy('id', 'desc')->first();

        static::__assert_equals(Transaction_Model::SOURCE_COMPANION, (int) $transaction->source_id);
        static::__assert_equals('Companion:' . self::NAME . '/write_record', $transaction->endpoint);
        static::__assert_equals('Companion', $transaction->source_id__label);
        static::__assert_null($transaction->audit_session_id);
    }

    public static function test_one_actor_row_per_companion()
    {
        static::__signed('write_record');
        static::__signed('write_record');

        static::__assert_equals(1, DB::table('_companion_actors')->where('name', self::NAME)->count());
    }

    public static function test_a_read_only_call_creates_no_actor()
    {
        static::__signed('ping');

        static::__assert_equals(0, DB::table('_companion_actors')->where('name', self::NAME)->count());
    }

    public static function test_a_run_as_dispatcher_task_is_refused_from_a_companion()
    {
        $response = static::__signed('run_as_dispatcher');

        static::__assert_equals(500, $response->getStatusCode(), 'a companion is nobody a dispatcher run can stand in for');
    }

    // =========================================================================
    // THE REQUEST LOG
    // =========================================================================

    public static function test_a_call_is_logged_and_counted_and_its_body_is_not()
    {
        $marker = 'body-marker-' . bin2hex(random_bytes(8));

        static::__signed('ping', ['secret' => $marker]);

        $log = file_get_contents(Rsx_Project_Paths::companion_request_log_file());
        $line = json_decode(trim(substr($log, (int) strrpos(rtrim($log), "\n"))), true);

        static::__assert_equals(self::NAME, $line['name']);
        static::__assert_equals('ping', $line['endpoint']);
        static::__assert_equals(200, $line['status']);
        static::__assert_false(str_contains($log, $marker), 'the body is never recorded');

        $stats = Companion_Store::connection()->hGetAll(Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi')));

        static::__assert_greater_than(0, (int) ($stats['ping:calls'] ?? 0));
    }

    public static function test_the_sdk_probe_is_logged_and_counted_without_an_error()
    {
        $probe = Companion_Service_Abstract::SDK_PROBE_ENDPOINT;
        $key = Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi'));
        $before = Companion_Store::connection()->hGetAll($key);

        $response = static::__signed($probe);

        static::__assert_refused($response, 404, 'not_found', 'the probe answers the 404 the SDK wants');

        $log = file_get_contents(Rsx_Project_Paths::companion_request_log_file());
        $line = json_decode(trim(substr($log, (int) strrpos(rtrim($log), "\n"))), true);
        static::__assert_equals($probe, $line['endpoint']);
        static::__assert_equals(404, $line['status']);
        static::__assert_null($line['code'], 'the log line carries no error code');

        $after = Companion_Store::connection()->hGetAll($key);
        static::__assert_equals((int) ($before[$probe . ':calls'] ?? 0) + 1, (int) ($after[$probe . ':calls'] ?? 0), 'the call is counted');
        static::__assert_equals((int) ($before[$probe . ':errors'] ?? 0), (int) ($after[$probe . ':errors'] ?? 0), 'and is not an error');
    }

    public static function test_another_unknown_endpoint_is_still_an_error()
    {
        $key = Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi'));
        $before = (int) (Companion_Store::connection()->hGet($key, 'no_such_endpoint:errors') ?: 0);

        static::__assert_refused(static::__signed('no_such_endpoint'), 404, 'not_found');

        static::__assert_equals($before + 1, (int) Companion_Store::connection()->hGet($key, 'no_such_endpoint:errors'));
    }

    public static function test_a_refused_call_is_logged_and_not_counted()
    {
        $key = Companion_Store::request_stats_key('unsigned_probe', gmdate('YmdHi'));

        static::__send(Request::create('/_companion/unsigned_probe/ping', 'POST'));

        static::__assert_equals(0, Companion_Store::connection()->exists($key));
    }

    // =========================================================================
    // THE DAEMON
    // =========================================================================

    public static function test_the_daemon_is_told_a_companion_is_ready()
    {
        $response = static::__daemon('/_companion/_daemon/ready/' . self::NAME);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_equals(['ready' => true, 'scope' => 'companion', 'reason' => null], json_decode($response->getContent(), true));
    }

    public static function test_ready_to_run_gives_its_reason()
    {
        Companion_Fixture_Companion_Service::$not_ready = 'No connection is configured';

        $body = json_decode(static::__daemon('/_companion/_daemon/ready/' . self::NAME)->getContent(), true);

        static::__assert_false($body['ready']);
        static::__assert_equals('No connection is configured', $body['reason']);

        Companion_Fixture_Companion_Service::$not_ready = null;
    }

    public static function test_a_throwing_ready_to_run_is_not_ready()
    {
        Companion_Fixture_Companion_Service::$throw_when_asked = true;

        $response = static::__daemon('/_companion/_daemon/ready/' . self::NAME);
        $body = json_decode($response->getContent(), true);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_false($body['ready']);
        static::__assert_false(str_contains($response->getContent(), 'Fixture readiness failure'));

        Companion_Fixture_Companion_Service::$throw_when_asked = false;
    }

    public static function test_an_unready_install_answers_before_the_companion_is_asked()
    {
        Companion_Fixture_Companion_Service::$not_ready = 'never reached';

        DB::table('sites')->where('id', '>', 0)->update(['deleted_at' => now()]);

        $body = json_decode(static::__daemon('/_companion/_daemon/ready/' . self::NAME)->getContent(), true);

        static::__assert_false($body['ready']);
        static::__assert_equals('install', $body['scope']);
        static::__assert_equals('no site is provisioned', $body['reason']);

        Companion_Fixture_Companion_Service::$not_ready = null;
    }

    public static function test_an_undeclared_companion_is_not_ready()
    {
        $body = json_decode(static::__daemon('/_companion/_daemon/ready/no_such_companion')->getContent(), true);

        static::__assert_false($body['ready']);
    }

    public static function test_the_daemon_key_is_required()
    {
        $response = static::__daemon('/_companion/_daemon/ready/' . self::NAME, '', ['key' => str_repeat('d', 64)]);

        static::__assert_refused($response, 401, 'invalid_signature');

        // An instance's secret is not the daemon's.
        $secret = Companion_Verifier::instance_secret(self::NAME, self::INSTANCE, self::STARTED_AT);
        $response = static::__daemon('/_companion/_daemon/ready/' . self::NAME, '', ['key' => $secret]);

        static::__assert_refused($response, 401, 'invalid_signature');
    }

    public static function test_the_daemon_key_opens_no_endpoint()
    {
        $uri = '/_companion/' . self::NAME . '/ping';

        static::__assert_refused(static::__daemon($uri), 401, 'invalid_signature');
    }

    public static function test_a_daemon_nonce_replay_is_refused()
    {
        $nonce = 'daemon-replay-' . bin2hex(random_bytes(10));
        $uri = '/_companion/_daemon/ready/' . self::NAME;

        static::__assert_equals(200, static::__daemon($uri, '', ['nonce' => $nonce])->getStatusCode());
        static::__assert_refused(static::__daemon($uri, '', ['nonce' => $nonce]), 401, 'replayed_nonce');
    }

    public static function test_the_event_verb_requires_a_name()
    {
        static::__assert_refused(static::__daemon('/_companion/_daemon/event', '{}'), 400, 'invalid_event');
    }

    public static function test_the_event_verb_answers_204()
    {
        $body = json_encode(['name' => self::NAME, 'code' => 'throttled', 'message' => 'Slow down']);

        static::__assert_equals(204, static::__daemon('/_companion/_daemon/event', $body)->getStatusCode());
    }

    public static function test_companion_error_reaches_a_handler_with_its_payload()
    {
        $received = [];
        Event_Registry::_set_test_handlers('companion.error', [function ($data) use (&$received) {
            $received[] = $data + ['acting_companion' => Companion_Dispatcher::current_name(), 'site_id' => Session::get_site_id()];
        }]);

        try {
            $body = json_encode([
                'name' => self::NAME,
                'instance_id' => self::INSTANCE,
                'code' => 'graph_throttled',
                'message' => 'Retry-After 120s',
                'severity' => 'warn',
                'context' => ['retry_after' => 120],
            ]);
            static::__assert_equals(204, static::__daemon('/_companion/_daemon/event', $body)->getStatusCode());

            static::__daemon('/_companion/_daemon/event', json_encode(['name' => self::NAME, 'code' => 'crashed']));
        } finally {
            Event_Registry::_clear_test_handlers();
        }

        static::__assert_count(2, $received, 'one action per event');
        static::__assert_equals([
            'name' => self::NAME,
            'instance_id' => self::INSTANCE,
            'code' => 'graph_throttled',
            'message' => 'Retry-After 120s',
            'severity' => 'warn',
            'context' => ['retry_after' => 120],
            'acting_companion' => self::NAME,
            'site_id' => 1,
        ], $received[0], 'the six documented keys, run as the companion on the install site');
        static::__assert_equals('error', $received[1]['severity'], 'severity defaults to error');
        static::__assert_equals([], $received[1]['context'], 'context defaults to empty');
        static::__assert_null($received[1]['instance_id']);
    }

    // =========================================================================
    // INSTALL STATE
    // =========================================================================

    public static function test_the_migrated_baseline_is_ready()
    {
        static::__assert_null(Rsx_Install_State::not_ready_reason());
        static::__assert_true(Rsx_Install_State::is_ready());
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function __write_record(string $instance_id, string $name, array $overrides = []): void
    {
        Companion_Store::connection()->hMSet(Companion_Store::instance_key($instance_id), array_merge([
            'name' => $name,
            'started_at' => self::STARTED_AT,
            'status' => 'running',
            'host' => 'test',
            'pid' => '1',
        ], $overrides));
    }

    /**
     * Sign and send one instance call. $options overrides what is signed, what is sent or both:
     *
     *   name, url_name, instance, contract, site, timestamp, nonce, secret   what is signed
     *   record         fields overriding the instance record written for the call
     *   no_record      true removes the instance record instead
     *   tenancy        rsx.tenancy for the call
     *   maintenance    true sends it inside the maintenance window, Redis being down
     *   signature      sent in place of the computed one (null omits the header)
     *   tamper         field => value sent instead of what was signed
     *   query          appended to the URI, and signed
     *   raw_body       the body instead of the encoded params
     */
    private static function __signed(string $endpoint, array $params = [], array $options = []): \Symfony\Component\HttpFoundation\Response
    {
        $name = $options['name'] ?? self::NAME;
        $url_name = $options['url_name'] ?? $name;
        $instance = $options['instance'] ?? self::INSTANCE;
        $contract = $options['contract'] ?? (Manifest::get_companions()[$name]['endpoints_hash'] ?? '');
        $site = $options['site'] ?? '';
        $timestamp = $options['timestamp'] ?? (string) time();
        $nonce = $options['nonce'] ?? 'nonce-' . bin2hex(random_bytes(12));
        $body = $options['raw_body'] ?? (empty($params) ? '' : json_encode($params));
        $uri = "/_companion/{$url_name}/{$endpoint}" . ($options['query'] ?? '');

        $secret = $options['secret'] ?? Companion_Verifier::instance_secret($name, $instance, self::STARTED_AT);
        $signature = hash_hmac('sha256', Companion_Verifier::canonical_string(
            'POST', $uri, $name, $instance, self::STARTED_AT, $contract, $site === '' ? '-' : $site, $timestamp, $nonce, $body
        ), $secret);

        if (array_key_exists('signature', $options)) {
            $signature = $options['signature'];
        }

        static::__write_record(self::INSTANCE, self::NAME, $options['record'] ?? []);
        static::__write_record(self::OTHER_INSTANCE, self::OTHER);

        if (!empty($options['no_record'])) {
            Companion_Store::connection()->del(Companion_Store::instance_key($instance));
        }

        $tamper = $options['tamper'] ?? [];

        $server = [
            'HTTP_X_RSX_COMPANION' => $name,
            'HTTP_X_RSX_COMPANION_INSTANCE' => $instance,
            'HTTP_X_RSX_COMPANION_STARTED_AT' => self::STARTED_AT,
            'HTTP_X_RSX_COMPANION_CONTRACT' => $tamper['contract'] ?? $contract,
            'HTTP_X_RSX_COMPANION_TIMESTAMP' => $tamper['timestamp'] ?? $timestamp,
            'HTTP_X_RSX_COMPANION_NONCE' => $tamper['nonce'] ?? $nonce,
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        if (($tamper['site'] ?? $site) !== '') {
            $server['HTTP_X_RSX_COMPANION_SITE'] = $tamper['site'] ?? $site;
        }

        if ($signature !== null) {
            $server['HTTP_X_RSX_COMPANION_SIGNATURE'] = $signature;
        }

        config(['rsx.tenancy' => $options['tenancy'] ?? 'single']);
        Framework_Maintenance::$force_active_for_tests = !empty($options['maintenance']) ? true : null;

        try {
            return static::__send(Request::create($uri, 'POST', [], [], [], $server, $tamper['body'] ?? $body));
        } finally {
            config(['rsx.tenancy' => 'single']);
            Framework_Maintenance::$force_active_for_tests = null;
        }
    }

    /**
     * Sign and send one daemon call.
     */
    private static function __daemon(string $uri, string $body = '', array $options = []): \Symfony\Component\HttpFoundation\Response
    {
        $timestamp = (string) time();
        $nonce = $options['nonce'] ?? 'nonce-' . bin2hex(random_bytes(12));
        $key = $options['key'] ?? Companion_Verifier::daemon_key();

        $signature = hash_hmac('sha256', Companion_Verifier::canonical_string(
            'POST', $uri, 'companiond', '-', '-', '-', '-', $timestamp, $nonce, $body
        ), $key);

        return static::__send(Request::create($uri, 'POST', [], [], [], [
            'HTTP_X_RSX_COMPANION_TIMESTAMP' => $timestamp,
            'HTTP_X_RSX_COMPANION_NONCE' => $nonce,
            'HTTP_X_RSX_COMPANION_SIGNATURE' => $signature,
            'REMOTE_ADDR' => '127.0.0.1',
        ], $body));
    }

    private static function __send(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        app()->instance('request', $request);

        return Rsx_Front_Controller::handle($request);
    }

    private static function __code($response): ?string
    {
        return json_decode($response->getContent(), true)['error']['code'] ?? null;
    }

    private static function __assert_refused($response, int $status, string $code, string $context = ''): void
    {
        static::__assert_equals($status, $response->getStatusCode(), trim("status {$context} " . $response->getContent()));
        static::__assert_equals($code, static::__code($response), trim("code {$context}"));
    }
}
