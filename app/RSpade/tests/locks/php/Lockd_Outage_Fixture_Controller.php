<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Tests\Locks\Php\Site_Lock_After_Commit_Fixture_Controller;

/**
 * Surfaces for Lockd_Outage_Test: Ajax readers and writers that record that they ran, one
 * that loses the database mid-call, one that loses the lock daemon after its write (its
 * after-commit callback then needs the daemon), and a #[Reader] page route. Indexed only
 * under a test run.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Lockd_Outage_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** Which endpoints ran, in order. */
    public static array $ran = [];

    /** How many times the after-commit callback that needs no daemon ran. */
    public static int $second_ran = 0;

    /** A loopback port nothing listens on, set by the test. */
    public static int $closed_port = 0;

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_records(Request $request, array $params = [])
    {
        static::$ran[] = 'reader';

        return ['ran' => 'reader'];
    }

    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_records(Request $request, array $params = [])
    {
        static::$ran[] = 'writer';

        return ['ran' => 'writer'];
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_loses_database(Request $request, array $params = [])
    {
        static::$ran[] = 'reader_loses_database';
        static::cut_database();

        return DB::select('select 1 as one');
    }

    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_loses_daemon_after_commit(Request $request, array $params = [])
    {
        static::$ran[] = 'writer_loses_daemon_after_commit';
        Site_Lock_After_Commit_Fixture_Controller::block((string) $params['email']);

        DB::afterCommit(function () {
            RsxLocks::release_lock(RsxLocks::named_write_lock('lockd_outage_fixture_after_commit'));
        });
        DB::afterCommit(function () {
            static::$second_ran++;
        });

        static::cut_daemon();

        return ['email' => $params['email']];
    }

    #[Route('/_test/lockd-outage/reader-page')]
    #[Reader]
    #[Auth('public')]
    public static function reader_page(Request $request, array $params = [])
    {
        static::$ran[] = 'reader_page';

        return ['type' => 'json', 'data' => ['ran' => 'reader_page']];
    }

    /**
     * The lock daemon becomes unreachable for the rest of the process: the transport's open
     * connection is closed (the daemon releases what it granted) and every later request on it
     * dials a port nothing listens on.
     */
    public static function cut_daemon(): void
    {
        $property = new \ReflectionProperty(Lockd_Client::class, 'connection');
        $property->getValue()->use_endpoint('127.0.0.1', static::$closed_port);
    }

    /** The default database connection now dials a port nothing listens on. */
    public static function cut_database(): void
    {
        $name = config('database.default');
        config([
            "database.connections.{$name}.host" => '127.0.0.1',
            "database.connections.{$name}.port" => static::$closed_port,
            "database.connections.{$name}.unix_socket" => '',
        ]);
        DB::purge($name);
    }
}
