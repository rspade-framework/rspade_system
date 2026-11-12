<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;

/**
 * The external API response contract, end to end through Rsx_Front_Controller::handle():
 *
 *   - every response body is a JSON OBJECT: an endpoint answering null, a list, an empty
 *     array, a scalar, a collection or a JSON list response is refused loudly (the channel's
 *     500, naming the endpoint); an object passes; a non-JSON download passes untouched;
 *   - every object answer carries _duration_ms (int) and _completed_at (ISO, UTC) at the top
 *     level - a success, a 422, a 404, a 401, a 500 - and a recorded writer's answer and its
 *     replay carry the same, originally recorded values;
 *   - a DOCUMENT (Rsx_Api::document()) is the one JSON object sent exactly as written: no
 *     timing fields, no echoed _request_ref;
 *   - a path its own version does not serve but another does is 410 api_version_retired
 *     naming the versions that serve it; a path no version serves, and any path under a
 *     version above every declared one, is 404.
 *
 * Fixture endpoints: Api_Response_Contract_Fixture_Api_Controller, and the versioning
 * fixtures (Api_Versioning_Fixture_Api_Controller, _V2_). Behavior of record:
 * php artisan rsx:man external_api.
 */
class Api_Response_Contract_Test extends Rsx_Test_Abstract
{
    private const ISO_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

    public static function teardown()
    {
        // The container must hold an ordinary console request again: a later class reads
        // the ambient request (its peer address decides who sees diagnostics).
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
    }

    // =========================================================================
    // Object-only bodies
    // =========================================================================

    public static function test_non_object_answers_are_refused_naming_the_endpoint()
    {
        $key = static::__api_key();

        $cases = [
            'null' => 'answer_null',
            'list' => 'answer_list',
            'empty' => 'answer_empty',
            'scalar' => 'answer_scalar',
            'collection' => 'answer_collection',
            'json-list' => 'answer_json_list',
        ];

        foreach ($cases as $path => $method) {
            $answer = static::__api($key, 'GET', '/api/v1/test-probe/contract/' . $path);

            static::__assert_equals(500, $answer['status'], "{$path}: refused as a fault: " . json_encode($answer['body']));
            static::__assert_equals('internal_error', $answer['body']['error']['code'] ?? null, $path);
            static::__assert_contains('Every external API response body is a JSON object', $answer['body']['error']['message'] ?? '', $path);
            static::__assert_contains('Api_Response_Contract_Fixture_Api_Controller::' . $method, $answer['body']['error']['message'] ?? '', "{$path}: names the endpoint");
            static::__assert_timing($answer['body'], "{$path}: the refusal is stamped");
        }
    }

    public static function test_an_object_passes_and_a_download_is_sent_as_it_is()
    {
        $key = static::__api_key();

        $object = static::__api($key, 'GET', '/api/v1/test-probe/contract/object');
        static::__assert_equals(200, $object['status'], json_encode($object['body']));
        static::__assert_true($object['body']['ok'] ?? false);
        static::__assert_equals([1, 2], $object['body']['items'], 'a list inside an object is fine');
        static::__assert_timing($object['body'], 'a success is stamped');

        $download = static::__api_raw($key, 'GET', '/api/v1/test-probe/contract/download');
        static::__assert_equals(200, $download->getStatusCode());
        static::__assert_equals("a,b\n1,2\n", $download->getContent(), 'a download is never stamped');
    }

    public static function test_a_document_is_sent_exactly_as_written()
    {
        $key = static::__api_key();

        $answer = static::__api_raw($key, 'GET', '/api/v1/test-probe/contract/document?_request_ref=doc-ref');
        static::__assert_equals(200, $answer->getStatusCode(), (string) $answer->getContent());
        static::__assert_contains('application/json', (string) $answer->headers->get('Content-Type'));
        static::__assert_equals(
            ['openapi' => '3.1.0', 'x-probe' => true],
            json_decode((string) $answer->getContent(), true),
            'no _duration_ms, _completed_at or echoed _request_ref'
        );
    }

    // =========================================================================
    // Timing on every answer
    // =========================================================================

    public static function test_refusals_and_faults_carry_timing()
    {
        $key = static::__api_key();

        $invalid = static::__api($key, 'GET', '/api/v1/test-probe/contract/needs-int');
        static::__assert_equals(422, $invalid['status']);
        static::__assert_timing($invalid['body'], 'a 422');

        $unknown = static::__api($key, 'GET', '/api/v1/test-probe/contract/no-such-path');
        static::__assert_equals(404, $unknown['status']);
        static::__assert_equals('not_found', $unknown['body']['error']['code'] ?? null);
        static::__assert_timing($unknown['body'], 'a 404');

        $unauthenticated = static::__api('rsx_not_a_key', 'GET', '/api/v1/test-probe/contract/object');
        static::__assert_equals(401, $unauthenticated['status']);
        static::__assert_timing($unauthenticated['body'], 'a 401');

        $fault = static::__api($key, 'GET', '/api/v1/test-probe/contract/fault');
        static::__assert_equals(500, $fault['status']);
        static::__assert_equals('internal_error', $fault['body']['error']['code'] ?? null);
        static::__assert_not_empty($fault['body']['error']['message'] ?? null, 'message is always present');
        static::__assert_timing($fault['body'], 'a thrown 500');
    }

    public static function test_a_recorded_writer_and_its_replay_carry_the_recorded_timing()
    {
        $key = static::__api_key();
        $email = 'api-contract-' . bin2hex(random_bytes(6)) . '@test.rspade.test';
        $ref = 'ref-' . bin2hex(random_bytes(8));

        $first = static::__api($key, 'POST', '/api/v1/test-probe/contract/write', ['email' => $email, '_request_ref' => $ref]);
        static::__assert_equals(200, $first['status'], json_encode($first['body']));
        static::__assert_timing($first['body'], 'the recorded answer');

        $replay = static::__api($key, 'POST', '/api/v1/test-probe/contract/write', ['email' => $email, '_request_ref' => $ref]);
        static::__assert_true($replay['body']['_replayed'] ?? false, 'the resend is a replay: ' . json_encode($replay['body']));
        static::__assert_equals($first['body']['_duration_ms'], $replay['body']['_duration_ms'], 'the original duration');
        static::__assert_equals($first['body']['_completed_at'], $replay['body']['_completed_at'], 'the original completion');
        static::__assert_equals(1, Email_Blocked_Address_Model::where('email', $email)->count(), 'one write');
    }

    // =========================================================================
    // Retired versions
    // =========================================================================

    public static function test_a_path_served_only_by_another_version_is_410()
    {
        $key = static::__api_key();

        // /old is served through v1 only.
        $old = static::__api($key, 'GET', '/api/v2/test-probe/versioning/old');
        static::__assert_equals(410, $old['status'], json_encode($old['body']));
        static::__assert_equals('api_version_retired', $old['body']['error']['code'] ?? null);
        static::__assert_equals(['v1'], $old['body']['error']['versions'] ?? null);
        static::__assert_contains('v1', $old['body']['error']['message'] ?? '');
        static::__assert_timing($old['body'], 'a 410');

        // /items/:id/extra is introduced in v2, matched through its :param.
        $later = static::__api($key, 'GET', '/api/v1/test-probe/contract/items/7/extra');
        static::__assert_equals(410, $later['status'], json_encode($later['body']));
        static::__assert_equals(['v2'], $later['body']['error']['versions'] ?? null);

        // A version above every declared one never existed: 404, not retired.
        $future = static::__api($key, 'GET', '/api/v' . (max(Api_Catalog::get_versions()) + 1) . '/test-probe/versioning/items/7');
        static::__assert_equals(404, $future['status'], json_encode($future['body']));

        // A path no version serves stays 404.
        $none = static::__api($key, 'GET', '/api/v2/test-probe/versioning/never');
        static::__assert_equals(404, $none['status']);
        static::__assert_equals('not_found', $none['body']['error']['code'] ?? null);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function __assert_timing(array $body, string $what): void
    {
        static::__assert_true(is_int($body['_duration_ms'] ?? null), "{$what}: _duration_ms is an int: " . json_encode($body));
        static::__assert_true($body['_duration_ms'] >= 0, "{$what}: _duration_ms is not negative");
        static::__assert_true((bool) preg_match(self::ISO_PATTERN, (string) ($body['_completed_at'] ?? '')), "{$what}: _completed_at is ISO UTC: " . json_encode($body));
    }

    private static function __api_key(): string
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_not_null($user, 'the test baseline carries user 1');

        $user->is_api_access_enabled = 1;
        $user->save();
        static::grant_every_permission(1);

        return Api_Key_Model::generate(1, 'Response contract probe (test)')['key'];
    }

    /** One API request: [status, body (decoded)]. */
    private static function __api(string $key, string $method, string $path, array $body = []): array
    {
        $response = static::__api_raw($key, $method, $path, $body);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true) ?? []];
    }

    private static function __api_raw(string $key, string $method, string $path, array $body = [])
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

        return $response;
    }
}
