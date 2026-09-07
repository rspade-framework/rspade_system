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
 * rsx:api:key:create / :temp / :list - the --read-only flag, end to end.
 *
 * The flag is how a batch job mints itself a credential that CANNOT write, so the round
 * trip is the subject: what was asked for at the shell must be what the row carries and
 * what key:list reports back. A flag that is accepted and silently dropped mints a key with
 * more authority than the operator asked for - the one failure this must not have - and a
 * flag that is reported but not stored is the same failure wearing a disguise.
 *
 * The default matters just as much and is asserted explicitly: omitting --read-only mints a
 * read+write key, because a schema change or a flag rename must never quietly narrow every
 * key a provisioning script makes.
 *
 * Run in-process through Artisan::call() - these commands write ordinary rows on this
 * connection, so the per-test transaction rolls every mint back.
 */
class Api_Key_Read_Only_Cli_Test extends Rsx_Test_Abstract
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

    // -------------------------------------------------------------------------
    // key:create --read-only
    // -------------------------------------------------------------------------

    public static function test_create_read_only_stores_and_reports_the_flag()
    {
        [$code, $created] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli read only',
            '--read-only' => true,
        ]);

        static::__assert_equals(0, $code, 'the flag is accepted');
        static::__assert_true($created['data']['api_key']['read_only'], 'the envelope reports it');

        $model = Api_Key_Model::find((int) $created['data']['api_key']['id']);
        static::__assert_true((bool) $model->read_only, 'and the row carries it');
    }

    public static function test_create_without_the_flag_mints_a_read_write_key()
    {
        [$code, $created] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli read write',
        ]);

        static::__assert_equals(0, $code);
        static::__assert_false($created['data']['api_key']['read_only'], 'the default is read+write');
        static::__assert_false(
            (bool) Api_Key_Model::find((int) $created['data']['api_key']['id'])->read_only
        );
    }

    public static function test_read_only_and_scopes_are_independent()
    {
        // Two narrowings, neither implying the other. A key may be read-only and
        // unrestricted, or scoped and able to write.
        [, $both] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli read only scoped',
            '--read-only' => true,
            '--scope' => ['/api/v1/contacts/*'],
        ]);

        static::__assert_true($both['data']['api_key']['read_only']);
        static::__assert_equals('/api/v1/contacts/*', $both['data']['api_key']['scopes']);

        [, $scoped_only] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli scoped writer',
            '--scope' => ['/api/v1/contacts/*'],
        ]);

        static::__assert_false($scoped_only['data']['api_key']['read_only'], 'a scoped key may still write');
    }

    // -------------------------------------------------------------------------
    // key:temp --read-only
    // -------------------------------------------------------------------------

    public static function test_temp_carries_the_flag_and_still_expires()
    {
        [$code, $created] = static::__json_call('rsx:api:key:temp', [
            '--user' => (string) self::USER_ID,
            '--expires' => '30 minutes',
            '--read-only' => true,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_true($created['data']['api_key']['read_only']);
        static::__assert_not_null($created['data']['api_key']['expires_at'], 'it is still self-expiring');
    }

    // -------------------------------------------------------------------------
    // key:list
    // -------------------------------------------------------------------------

    public static function test_key_list_json_reports_read_only_per_key()
    {
        [, $read_only] = static::__json_call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli listed read only',
            '--read-only' => true,
        ]);

        [$code, $listed] = static::__json_call('rsx:api:key:list', [
            '--user' => (string) self::USER_ID,
        ]);

        static::__assert_equals(0, $code);

        $row = null;
        foreach ($listed['data']['keys'] as $key) {
            if ((int) $key['id'] === (int) $read_only['data']['api_key']['id']) {
                $row = $key;
            }
        }

        static::__assert_not_null($row, 'the key is listed');
        static::__assert_true($row['read_only'], 'key:list --json reports the flag');
    }

    public static function test_key_list_table_carries_an_access_column()
    {
        Artisan::call('rsx:api:key:create', [
            '--user' => (string) self::USER_ID,
            '--name' => 'cli access column',
            '--read-only' => true,
        ]);

        Artisan::call('rsx:api:key:list', ['--user' => (string) self::USER_ID]);
        $output = Artisan::output();

        // The human-readable half of the same answer: an operator diagnosing a 403 reads
        // this column, not the JSON.
        static::__assert_true(str_contains($output, 'Access'), 'the table has an Access column');
        static::__assert_true(str_contains($output, 'read-only'), 'and the read-only key says so');
    }
}
