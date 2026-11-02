<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Companion\Companion_ManifestSupport;
use App\RSpade\Core\Companion\Companion_Verifier;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The Node side of the wire contract, computed by the daemon's and the SDK's own modules and
 * compared with PHP's: the keys, both canonical strings, the Redis scope token, the source
 * hash recipe, and the daemon's pure parts (the backoff and the RESP codec).
 */
class Companion_Sdk_Wire_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static ?array $__vectors = null;

    private static function __vectors(): array
    {
        if (static::$__vectors !== null) {
            return static::$__vectors;
        }

        $default = (string) config('database.default');

        $process = new Process(['node', base_path('app/RSpade/tests/companions/resource/wire_vectors.js')]);
        $process->setTimeout(null);
        $process->setInput(json_encode([
            'app_key' => (string) env('APP_KEY'),
            'directory' => base_path('app/RSpade/tests/companions/resource'),
            'database' => DB::connection()->getDatabaseName(),
            'host' => (string) config("database.connections.{$default}.host"),
        ]));
        $process->run();

        static::__assert_true($process->isSuccessful(), 'wire_vectors.js failed: ' . $process->getErrorOutput());

        static::$__vectors = json_decode($process->getOutput(), true);

        return static::$__vectors;
    }

    public static function test_the_instance_secret_matches_php()
    {
        static::__assert_equals(
            Companion_Verifier::instance_secret('hello', 'i-1', '1700000000000'),
            static::__vectors()['instance_secret']
        );
    }

    public static function test_the_daemon_key_matches_php()
    {
        static::__assert_equals(Companion_Verifier::daemon_key(), static::__vectors()['daemon_key']);
    }

    public static function test_the_canonical_strings_match_php()
    {
        static::__assert_equals(
            Companion_Verifier::canonical_string('POST', '/_companion/hello/hello', 'hello', 'i-1', '1700000000000', 'abc', '-', '1700000000', 'n0123456789abcdef', '{}'),
            static::__vectors()['canonical']
        );

        static::__assert_equals(
            Companion_Verifier::canonical_string('POST', '/_companion/_daemon/event', 'companiond', '-', '-', '-', '-', '1700000000', 'n0123456789abcdef', '{}'),
            static::__vectors()['daemon_canonical']
        );
    }

    public static function test_the_redis_scope_token_matches_php()
    {
        static::__assert_equals(Rsx_Connection_Scope::token(), static::__vectors()['scope_token']);
    }

    public static function test_the_source_hash_recipe_matches_the_manifest()
    {
        static::__assert_equals(
            Companion_ManifestSupport::source_hash(base_path('app/RSpade/tests/companions/resource')),
            static::__vectors()['source_hash']
        );
    }

    public static function test_the_contract_mismatch_exit_code()
    {
        static::__assert_equals(75, static::__vectors()['exit_contract_mismatch']);
    }

    public static function test_backoff_doubles_to_its_ceiling_and_a_stable_run_resets_it()
    {
        $vectors = static::__vectors();

        static::__assert_equals([1000, 2000, 4000, 8000, 256000, 300000], $vectors['backoff']);
        static::__assert_equals(4, $vectors['streak_after_short_run']);
        static::__assert_equals(1, $vectors['streak_after_stable_run']);
    }

    public static function test_the_resp_codec()
    {
        $vectors = static::__vectors();

        static::__assert_equals("*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$1\r\nv\r\n", $vectors['resp_command']);
        static::__assert_equals(['hello', 42, null], $vectors['resp_parsed']);
        static::__assert_null($vectors['resp_partial'], 'an incomplete reply waits for more bytes');
    }
}
