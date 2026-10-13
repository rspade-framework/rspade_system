<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Dispatch\AssetHandler;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Support\Rsx_Fingerprint;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * TWO KEYS, AND WHICH EVENT MOVES WHICH.
 *
 *   Manifest::get_build_key()   the CODE key. Names the build. Nothing from the database
 *                               enters it, and asking for it never touches the database.
 *   Manifest::get_state_key()   the CACHE-BREAK key: md5(code key . applied migrations).
 *
 * A migration is recorded as a row in the migrations table and changes no source file, so
 * it must move the state key and leave the code key - and therefore the build, its seal and
 * its full-page-cache namespace - exactly where they were. That is what lets `migrate` run
 * on a sealed box with no rebuild.
 *
 * The state key costs ONE query per process, on the first ask, and nothing on a path that
 * never asks: a boot does not, and neither does the asset channel.
 *
 * The migrations row each test adds is real and is removed in a finally, so these run
 * outside the per-test transaction: the fingerprint is read on the same connection and a
 * rolled-back insert would prove nothing about a committed one.
 */
class Manifest_State_Key_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const PROBE_MIGRATION = '9999_12_31_235959_manifest_state_key_probe';

    /**
     * Run $fn with one more row in the migrations table.
     */
    private static function __with_probe_migration(callable $fn): void
    {
        $table = config('database.migrations', 'migrations');

        DB::table($table)->insert(['migration' => self::PROBE_MIGRATION, 'batch' => 999999]);

        try {
            Manifest::reset_state_key();
            $fn();
        } finally {
            DB::table($table)->where('migration', self::PROBE_MIGRATION)->delete();
            Manifest::reset_state_key();
        }
    }

    /**
     * Run $fn and return every SQL statement issued while it ran.
     *
     * @return array<int, string>
     */
    private static function __queries_during(callable $fn): array
    {
        $queries = [];
        $listening = true;

        // Laravel has no unlisten; the flag is what stops the closure collecting.
        DB::listen(function ($query) use (&$queries, &$listening) {
            if ($listening) {
                $queries[] = $query->sql;
            }
        });

        try {
            $fn();
        } finally {
            $listening = false;
        }

        return $queries;
    }

    public static function test_the_state_key_is_the_code_key_combined_with_the_applied_migrations()
    {
        Manifest::reset_state_key();

        static::__assert_equals(
            md5(Manifest::get_build_key() . Rsx_Fingerprint::applied_migrations()),
            Manifest::get_state_key()
        );
        static::__assert_true(
            (bool) preg_match('/^[0-9a-f]{32}$/', Manifest::get_state_key()),
            'the state key is 32 hex characters, the same shape as the code key'
        );
        static::__assert_not_equals(
            Manifest::get_build_key(),
            Manifest::get_state_key(),
            'the two keys are different values even on a database with nothing applied'
        );
    }

    public static function test_a_migration_moves_the_state_key_and_leaves_the_code_key()
    {
        Manifest::reset_state_key();

        $code_before = Manifest::get_build_key();
        $state_before = Manifest::get_state_key();

        static::__with_probe_migration(function () use ($code_before, $state_before) {
            static::__assert_equals(
                $code_before,
                Manifest::get_build_key(),
                'a migrations row changes no source file, so the code key cannot move'
            );
            static::__assert_not_equals(
                $state_before,
                Manifest::get_state_key(),
                'the applied set moved, so the state key did'
            );
        });

        static::__assert_equals($state_before, Manifest::get_state_key(), 'and it is a pure function of the applied set');
    }

    public static function test_the_code_key_never_touches_the_database()
    {
        $queries = static::__queries_during(fn () => Manifest::get_build_key());

        static::__assert_equals([], $queries, 'get_build_key() issued no statement');
    }

    public static function test_the_state_key_is_one_query_then_a_memo()
    {
        Manifest::reset_state_key();

        $first = static::__queries_during(fn () => Manifest::get_state_key());

        static::__assert_count(
            1,
            $first,
            "the first ask is exactly one statement - no table-existence probe beside it:\n  " . implode("\n  ", $first)
        );
        static::__assert_contains(config('database.migrations', 'migrations'), $first[0]);

        $computations = Manifest::$_state_key_computations;
        $again = static::__queries_during(function () {
            Manifest::get_state_key();
            Manifest::get_state_key();
        });

        static::__assert_equals([], $again, 'every later ask is answered from the memo');
        static::__assert_equals($computations, Manifest::$_state_key_computations);
    }

    public static function test_the_memo_holds_until_it_is_reset()
    {
        Manifest::reset_state_key();
        $before = Manifest::get_state_key();
        $table = config('database.migrations', 'migrations');

        DB::table($table)->insert(['migration' => self::PROBE_MIGRATION, 'batch' => 999999]);

        try {
            static::__assert_equals(
                $before,
                Manifest::get_state_key(),
                'a process keeps the key it computed - it does not re-ask the database per call'
            );

            Manifest::reset_state_key();

            static::__assert_not_equals($before, Manifest::get_state_key(), 'reset_state_key() makes the next ask read the database');
        } finally {
            DB::table($table)->where('migration', self::PROBE_MIGRATION)->delete();
            Manifest::reset_state_key();
        }
    }

    /**
     * A database with no migrations table is a fresh install, and answers the fingerprint
     * of the empty set - from the one query, with no probe for the table first.
     */
    public static function test_a_missing_migrations_table_is_the_no_migrations_fingerprint()
    {
        $configured = config('database.migrations');

        try {
            config(['database.migrations' => 'zz_state_key_test_no_such_table']);

            $fingerprint = null;
            $queries = static::__queries_during(function () use (&$fingerprint) {
                $fingerprint = Rsx_Fingerprint::applied_migrations();
            });

            static::__assert_equals(md5(''), $fingerprint);
            static::__assert_true(
                count($queries) <= 1,
                "no table-existence probe is issued:\n  " . implode("\n  ", $queries)
            );
        } finally {
            config(['database.migrations' => $configured]);
        }
    }

    /**
     * "No such table" is the ONE failure the fingerprint answers. Anything else the query
     * can fail with is a defect to see, not a database with nothing applied.
     */
    public static function test_any_other_query_failure_propagates()
    {
        $configured = config('database.migrations');

        try {
            // A backtick in the name closes the identifier early: a syntax error (1064).
            config(['database.migrations' => 'zz_state_key` test']);

            static::__assert_throws(QueryException::class, fn () => Rsx_Fingerprint::applied_migrations());

            Manifest::reset_state_key();
            static::__assert_throws(QueryException::class, fn () => Manifest::get_state_key());
        } finally {
            config(['database.migrations' => $configured]);
            Manifest::reset_state_key();
        }
    }

    /**
     * THE ONE DEGRADED PATH. With the database unreachable the request is already failing;
     * the state key answers the code key alone so an error page can still be rendered, and
     * says so in the log - once, because the degraded answer is memoized like any other.
     *
     * Staged with a connection of its own that points at a port nothing listens on, made
     * the default for the duration; the box's database is never touched.
     */
    public static function test_an_unreachable_database_answers_the_code_key_and_logs_it()
    {
        $default = config('database.default');
        $unreachable = config("database.connections.{$default}");
        $unreachable['host'] = '127.0.0.1';
        $unreachable['port'] = '1';
        $unreachable['unix_socket'] = '';

        $logged = [];
        $listening = true;
        Log::listen(function ($message) use (&$logged, &$listening) {
            if ($listening && str_contains($message->message, 'state key')) {
                $logged[] = $message->message;
            }
        });

        try {
            config(['database.connections.rsx_state_key_unreachable' => $unreachable]);
            config(['database.default' => 'rsx_state_key_unreachable']);
            DB::setDefaultConnection('rsx_state_key_unreachable');

            Manifest::reset_state_key();

            static::__assert_equals(
                Manifest::get_build_key(),
                Manifest::get_state_key(),
                'with no database to ask, the state key is the code key alone'
            );
            static::__assert_count(1, $logged, 'and the log says so');

            Manifest::get_state_key();
            static::__assert_count(1, $logged, 'once per process - the degraded answer is memoized');
        } finally {
            $listening = false;
            config(['database.default' => $default]);
            DB::setDefaultConnection($default);
            DB::purge('rsx_state_key_unreachable');
            Manifest::reset_state_key();
        }

        static::__assert_not_equals(
            Manifest::get_build_key(),
            Manifest::get_state_key(),
            'back on the real connection the key carries the applied migrations again'
        );
    }

    /**
     * Run an artisan command in a child with the manifest probe on and return how many
     * times it computed a state key.
     */
    private static function __child_state_key_computations(string $command): int
    {
        $output = [];

        Rsx_Artisan::run($command, ['--_manifest-report-cold'], $output);

        foreach ($output as $line) {
            if (preg_match('/^MANIFEST_STATE_KEY_COMPUTATIONS=(\d+)$/', trim($line), $matches)) {
                return (int) $matches[1];
            }
        }

        static::__fail("the child did not report its state key probe line:\n" . implode("\n", $output));
    }

    /**
     * BOOTING computes no state key. Every process boots - one that only serves a compiled
     * asset included - so anything on the boot path that asked would put the
     * applied-migrations query on every request the box serves.
     */
    public static function test_booting_computes_no_state_key()
    {
        static::__assert_equals(
            0,
            static::__child_state_key_computations('rsx:manifest:get_build_key'),
            'a boot that prints the code key never asked for the state key'
        );
    }

    public static function test_a_process_that_asks_computes_it_once()
    {
        static::__assert_equals(1, static::__child_state_key_computations('rsx:manifest:get_state_key'));
    }

    /**
     * The asset channel's pipeline, driven in process: answering a /_compiled/ request
     * computes no state key and issues no statement.
     */
    public static function test_the_asset_channel_computes_no_state_key()
    {
        Manifest::reset_state_key();
        $computations = Manifest::$_state_key_computations;

        $path = '/_compiled/No_Such_Bundle__app.00000000.js';
        $response = null;

        $queries = static::__queries_during(function () use ($path, &$response) {
            $response = AssetHandler::serve_build_artifact($path, Request::create($path, 'GET'));
        });

        static::__assert_equals(404, $response->getStatusCode(), 'sanity: the handler answered the request');
        static::__assert_equals($computations, Manifest::$_state_key_computations, 'no state key was computed');
        static::__assert_equals([], $queries, 'and the database was not asked anything');
    }
}
