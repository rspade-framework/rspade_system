<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Cli;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:api:key:create / :temp / :list - the --scope flag, end to end.
 *
 * The round trip is the subject: a scope set typed at a shell must come back out of
 * key:list --json as the same scopes, normalized. Anything that mangles it in between - the
 * array option, the newline join, the normalization, the JSON field - produces a key whose
 * authority is not the one the operator asked for, and only asking the CLI for its own
 * answer catches that.
 *
 * The refusal half matters just as much: a malformed scope must leave NO key behind. A
 * command that mints first and validates after would hand out a credential nobody believes
 * exists.
 *
 * Run in-process through Artisan::call() - these commands read and write ordinary rows on
 * this connection, so the per-test transaction rolls every mint back.
 */
class Api_Key_Scope_Cli_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    /**
     * Run a command, returning [exit_code, decoded_json].
     */
    private static function __json_call(string $command, array $args): array
    {
        $code = Artisan::call($command, $args + ['--json' => true]);

        return [$code, json_decode(Artisan::output(), true)];
    }

    private static function __key_count(): int
    {
        return (int) Api_Key_Model::where('user_id', self::USER_ID)->count();
    }

    // -------------------------------------------------------------------------
    // key:create --scope
    // -------------------------------------------------------------------------

    public static function test_repeatable_scope_round_trips_through_key_list()
    {
        [$code, $created] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli scope round trip',
            // Deliberately messy: surrounding space, a trailing slash, a query string and
            // a duplicate. What comes back must be the canonical form, because that is what
            // the dispatcher reads.
            '--scope' => ['  /api/v1/contacts/*  ', '/api/v1/me/?page=2', '/api/v1/contacts/*'],
        ]);

        static::__assert_equals(0, $code, 'a valid scope set mints');
        static::__assert_true($created['ok'], 'the envelope reports success');

        $expected = "/api/v1/contacts/*\n/api/v1/me";

        static::__assert_equals($expected, $created['data']['api_key']['scopes'], 'create echoes the canonical scopes');

        [$list_code, $listed] = static::__json_call('rsx:api:key:list', [
            '--user' => (string) self::USER_ID,
        ]);

        static::__assert_equals(0, $list_code);

        $row = null;
        foreach ($listed['data']['keys'] as $key) {
            if ((int) $key['id'] === (int) $created['data']['api_key']['id']) {
                $row = $key;
            }
        }

        static::__assert_not_null($row, 'the minted key appears in the listing');
        static::__assert_equals($expected, $row['scopes'], 'key:list --json carries the full scope text');
    }

    public static function test_no_scope_flag_mints_an_unrestricted_key()
    {
        [$code, $created] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli no scope',
        ]);

        static::__assert_equals(0, $code);
        static::__assert_null($created['data']['api_key']['scopes'], 'absent --scope is unrestricted, not an empty scope set');

        $model = Api_Key_Model::find((int) $created['data']['api_key']['id']);
        static::__assert_true($model->is_unrestricted());
    }

    public static function test_one_scope_string_may_carry_its_own_newlines()
    {
        // A provisioning script that already holds a scope set as text should not have to
        // take it apart to pass it.
        [$code, $created] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli newline scope',
            '--scope' => ["/api/v1/contacts\n/api/v1/me"],
        ]);

        static::__assert_equals(0, $code);
        static::__assert_equals(
            "/api/v1/contacts\n/api/v1/me",
            $created['data']['api_key']['scopes']
        );
    }

    // -------------------------------------------------------------------------
    // The refusal - nothing is minted
    // -------------------------------------------------------------------------

    public static function test_a_malformed_scope_exits_one_and_mints_nothing()
    {
        $before = static::__key_count();

        [$code, $error] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli bad scope',
            '--scope' => ['/api/v1/contacts*'],
        ]);

        static::__assert_equals(1, $code, 'a malformed scope is a non-zero exit');
        static::__assert_false($error['ok']);
        static::__assert_equals('scopes_invalid', $error['error']['code']);
        static::__assert_contains(
            'a wildcard must be a whole segment',
            $error['error']['message'],
            'the refusal names the rule that was broken'
        );
        static::__assert_equals($before, static::__key_count(), 'no key was written');
    }

    public static function test_a_malformed_scope_refuses_the_temp_key_too()
    {
        $before = static::__key_count();

        [$code, $error] = static::__json_call('rsx:api:key:temp', [
            '--user' => (string) self::USER_ID,
            '--scope' => ['/not/an/api/path'],
        ]);

        static::__assert_equals(1, $code);
        static::__assert_equals('scopes_invalid', $error['error']['code']);
        static::__assert_equals($before, static::__key_count(), 'no key was written');
    }

    public static function test_the_old_rule_language_is_refused()
    {
        $before = static::__key_count();

        [$code, $error] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli old syntax',
            '--scope' => ['Grant GET /api/v1/contacts/**'],
        ]);

        static::__assert_equals(1, $code);
        static::__assert_equals('scopes_invalid', $error['error']['code']);
        static::__assert_equals($before, static::__key_count(), 'no key was written');
    }

    // -------------------------------------------------------------------------
    // key:temp --scope
    // -------------------------------------------------------------------------

    public static function test_temp_key_carries_its_scope()
    {
        [$code, $created] = static::__json_call('rsx:api:key:temp', [
            '--user' => (string) self::USER_ID,
            '--scope' => ['/api/v1/me'],
        ]);

        static::__assert_equals(0, $code);
        static::__assert_equals('/api/v1/me', $created['data']['api_key']['scopes']);
        static::__assert_not_null($created['data']['api_key']['expires_at'], 'a temp key still expires on its own');
    }
}
