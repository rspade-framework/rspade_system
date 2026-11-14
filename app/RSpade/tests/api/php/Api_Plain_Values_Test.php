<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Api\Php\Api_Plain_Values_Fixture_Model;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Wrapped_Text;

/**
 * The external API carries plain values: a money value is its decimal string ("7708.80") and a
 * text-type value its stored string, wherever it sits in a body - a returned model, a list, a
 * nested list, a value object placed directly in an array, a toArray() an endpoint built, a
 * 201, a document, a writer's answer and its replay. null stays null; an object that merely
 * resembles an envelope is untouched. Input takes no envelope: a 'money' param refuses one as
 * not an amount. The browser Ajax channel is unchanged - the same record arrives there with
 * its {__MONEY} / {__TEXT} envelopes.
 *
 * Through Rsx_Front_Controller::handle(), as Api_Response_Contract_Test drives the channel.
 * Fixtures: Api_Plain_Values_Fixture_Model (table api_plain_value_fixtures),
 * Api_Plain_Values_Fixture_Api_Controller, Api_Plain_Values_Fixture_Controller (Ajax).
 * Behavior of record: php artisan rsx:man external_api, PLAIN VALUES.
 */
class Api_Plain_Values_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        DB::statement('DROP TABLE IF EXISTS ' . Api_Plain_Values_Fixture_Model::TABLE);
        DB::statement('CREATE TABLE ' . Api_Plain_Values_Fixture_Model::TABLE . ' (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            amount DECIMAL(10,2) NULL,
            body TEXT NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by_id BIGINT NULL DEFAULT NULL,
            created_by_type BIGINT NULL DEFAULT NULL,
            updated_by_id BIGINT NULL DEFAULT NULL,
            updated_by_type BIGINT NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // The column map is read before any class runs; the fixture table joins it here.
        Rsx_Schema::forget();

        // Registered outside the per-test transaction, so a rollback never strands an id.
        Type_Ref_Registry::class_to_id('Api_Plain_Values_Fixture_Model');
    }

    public static function teardown()
    {
        DB::statement('DROP TABLE IF EXISTS ' . Api_Plain_Values_Fixture_Model::TABLE);
        Rsx_Schema::forget();

        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
    }

    // =========================================================================
    // Responses
    // =========================================================================

    public static function test_a_returned_model_carries_plain_strings()
    {
        $key = static::__api_key();
        $id = static::__record('7708.8', '<p>Include the <b>schedule</b>.</p>');

        $answer = static::__api($key, 'GET', "/api/v1/test-probe/plain/record/{$id}");

        static::__assert_equals(200, $answer['status'], $answer['raw']);
        static::__assert_no_envelope($answer['raw']);
        static::__assert_equals('7708.80', $answer['body']['amount'], 'a money column is its two-place decimal string');
        static::__assert_equals('<p>Include the <b>schedule</b>.</p>', $answer['body']['body'], 'a text column is its stored string');
        static::__assert_equals('Api_Plain_Values_Fixture_Model', $answer['body']['__MODEL'], 'the rest of toArray() is unchanged');
    }

    public static function test_null_stays_null()
    {
        $key = static::__api_key();
        $id = static::__record(null, null);

        $answer = static::__api($key, 'GET', "/api/v1/test-probe/plain/record/{$id}");

        static::__assert_equals(200, $answer['status'], $answer['raw']);
        static::__assert_true(array_key_exists('amount', $answer['body']) && $answer['body']['amount'] === null, 'amount is null');
        static::__assert_true(array_key_exists('body', $answer['body']) && $answer['body']['body'] === null, 'body is null');
    }

    public static function test_lists_nested_lists_and_a_hand_built_to_array_are_plain()
    {
        $key = static::__api_key();
        static::__record('1234.5', '<p>one</p>');
        static::__record('-0.05', '<p>two</p>');

        $answer = static::__api($key, 'GET', '/api/v1/test-probe/plain/list');

        static::__assert_equals(200, $answer['status'], $answer['raw']);
        static::__assert_no_envelope($answer['raw']);
        static::__assert_equals(['1234.50', '-0.05'], array_column($answer['body']['items'], 'amount'));
        static::__assert_equals(['<p>one</p>', '<p>two</p>'], array_column($answer['body']['items'], 'body'));
        static::__assert_equals(['1234.50', '-0.05'], array_column($answer['body']['nested']['groups'][0]['records'], 'amount'), 'nested');
        static::__assert_equals('1234.50', $answer['body']['built']['amount'], 'a toArray() the endpoint assembled');
        static::__assert_equals('<p>one</p>', $answer['body']['built']['body']);
    }

    public static function test_values_placed_directly_are_plain_and_look_alikes_are_untouched()
    {
        $key = static::__api_key();

        $answer = static::__api($key, 'GET', '/api/v1/test-probe/plain/values');

        static::__assert_equals(200, $answer['status'], $answer['raw']);
        $body = $answer['body'];
        static::__assert_equals('7708.80', $body['total'], 'a computed money figure: the same shape as a column');
        static::__assert_equals('<p>direct</p>', $body['body'], 'a text value object');
        static::__assert_equals([['price' => '-0.05'], ['price' => null]], $body['lines']);
        static::__assert_null($body['none']);
        static::__assert_equals(['raw' => '<p>x</p>', 'empty' => false], $body['with_raw'], 'an object with a raw key is not an envelope');
        static::__assert_equals(
            ['__TEXT' => 'Text_Fixture_Wrapped_Text', 'raw' => '<p>x</p>', 'empty' => false, 'note' => 'kept'],
            $body['with_more'],
            'an extra key: not the envelope shape, left as it is'
        );
        static::__assert_equals(['__MONEY' => '1.00', 'currency' => 'USD'], $body['money_and_more'], 'an extra key beside __MONEY');
        static::__assert_true(is_int($body['_duration_ms'] ?? null), 'still stamped');
    }

    public static function test_a_201_and_a_document_are_plain()
    {
        $key = static::__api_key();
        $id = static::__record('12', '<p>made</p>');

        $created = static::__api($key, 'GET', "/api/v1/test-probe/plain/created/{$id}");
        static::__assert_equals(201, $created['status'], $created['raw']);
        static::__assert_no_envelope($created['raw']);
        static::__assert_equals('12.00', $created['body']['record']['amount']);

        $document = static::__api($key, 'GET', '/api/v1/test-probe/plain/document');
        static::__assert_equals(200, $document['status'], $document['raw']);
        static::__assert_equals(['x-total' => '12.00', 'x-body' => '<p>doc</p>'], $document['body'], 'a document carries plain values, unstamped');
    }

    // =========================================================================
    // Input
    // =========================================================================

    public static function test_a_write_reads_back_unchanged_and_its_replay_is_plain()
    {
        $key = static::__api_key();
        $id = static::__record('10', '<p>Keep <i>this</i></p>');

        $read = static::__api($key, 'GET', "/api/v1/test-probe/plain/record/{$id}")['body'];
        $ref = 'plain-' . bin2hex(random_bytes(8));

        $write = static::__api($key, 'POST', "/api/v1/test-probe/plain/record/{$id}/update", [
            'amount' => '1234.5',
            'body' => $read['body'],
            '_request_ref' => $ref,
        ]);
        static::__assert_equals(200, $write['status'], $write['raw']);
        static::__assert_no_envelope($write['raw']);
        static::__assert_equals('1234.50', $write['body']['amount'], 'a decimal string is stored canonically');
        static::__assert_equals('<p>Keep <i>this</i></p>', $write['body']['body'], 'the string read, sent back, is stored unchanged');
        static::__assert_equals(
            ['amount' => '1234.50', 'body' => '<p>Keep <i>this</i></p>'],
            static::__stored($id),
            'the table holds the same'
        );

        $replay = static::__api($key, 'POST', "/api/v1/test-probe/plain/record/{$id}/update", [
            'amount' => '1234.5',
            'body' => $read['body'],
            '_request_ref' => $ref,
        ]);
        static::__assert_true($replay['body']['_replayed'] ?? false, 'a replay: ' . $replay['raw']);
        static::__assert_no_envelope($replay['raw']);
        static::__assert_equals('1234.50', $replay['body']['amount'], 'the replayed body is plain too');
    }

    public static function test_an_envelope_is_not_an_amount_and_nothing_is_written()
    {
        $key = static::__api_key();
        $id = static::__record('10', '<p>x</p>');

        foreach ([['__MONEY' => '99.00'], '{"__MONEY": "99.00"}', '12.345'] as $sent) {
            $answer = static::__api($key, 'POST', "/api/v1/test-probe/plain/record/{$id}/update", ['amount' => $sent]);

            static::__assert_equals(422, $answer['status'], json_encode($sent) . ': ' . $answer['raw']);
            static::__assert_equals('validation', $answer['body']['error']['code'] ?? null);
            static::__assert_true(isset($answer['body']['error']['fields']['amount']), 'the error names the field');
        }

        static::__assert_equals(
            Rsx_Money_Exception::MESSAGE_FORMAT,
            static::__api($key, 'POST', "/api/v1/test-probe/plain/record/{$id}/update", ['amount' => ['__MONEY' => '99.00']])['body']['error']['fields']['amount'],
            'the envelope is refused with the message any non-amount gets'
        );
        static::__assert_equals('10.00', static::__stored($id)['amount'], 'nothing was written');
    }

    // =========================================================================
    // Browser Ajax is unchanged
    // =========================================================================

    public static function test_ajax_still_carries_the_envelopes()
    {
        $id = static::__record('7708.8', '<p>ajax</p>');

        $result = Ajax::internal('Api_Plain_Values_Fixture_Controller', 'record', ['id' => $id]);
        $record = json_decode(json_encode($result['record']), true);

        static::__assert_equals(['__MONEY' => '7708.80'], $record['amount']);
        static::__assert_equals(['__TEXT' => 'Text_Fixture_Wrapped_Text', 'raw' => '<p>ajax</p>', 'empty' => false], $record['body']);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function __assert_no_envelope(string $raw): void
    {
        static::__assert_false(str_contains($raw, '__MONEY'), 'no __MONEY anywhere in the body: ' . $raw);
        static::__assert_false(str_contains($raw, '__TEXT'), 'no __TEXT anywhere in the body: ' . $raw);
    }

    /** A saved record; the body assigned as the type's encoded form. */
    private static function __record(?string $amount, ?string $body): int
    {
        $record = new Api_Plain_Values_Fixture_Model();
        $record->amount = $amount;
        $record->body = $body === null ? null : Text_Fixture_Wrapped_Text::from_untrusted_encoded($body);
        $record->save();

        return (int) $record->id;
    }

    /** The stored amount and body, read with plain SQL. */
    private static function __stored(int $id): array
    {
        $row = (array) DB::selectOne('SELECT amount, body FROM ' . Api_Plain_Values_Fixture_Model::TABLE . ' WHERE id = ?', [$id]);

        return ['amount' => $row['amount'], 'body' => $row['body']];
    }

    private static function __api_key(): string
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_not_null($user, 'the test baseline carries user 1');

        $user->is_api_access_enabled = 1;
        $user->save();
        static::grant_every_permission(1);

        return Api_Key_Model::generate(1, 'Plain values probe (test)')['key'];
    }

    /** One API request: [status, body (decoded), raw]. */
    private static function __api(string $key, string $method, string $path, array $body = []): array
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

        $raw = (string) $response->getContent();

        return ['status' => $response->getStatusCode(), 'body' => json_decode($raw, true) ?? [], 'raw' => $raw];
    }
}
