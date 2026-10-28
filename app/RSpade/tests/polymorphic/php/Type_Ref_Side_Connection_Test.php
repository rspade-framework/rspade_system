<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Polymorphic\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Side_Connection;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A type id is registered on a SIDE CONNECTION (Rsx_Side_Connection), so the _type_refs row
 * commits whatever the caller's transaction does. Before, the row joined the caller's
 * transaction while the id went into memory and Redis at once: a rollback - a failed request,
 * every test's own transaction - left a cached id naming no row, and the next registration
 * minted a different id for the same class.
 *
 * The test runs inside the runner's per-test transaction and opens a nested one of its own, so
 * both rollbacks are exercised.
 */
class Type_Ref_Side_Connection_Test extends Rsx_Test_Abstract
{
    private const FIXTURE = 'Type_Ref_Side_Fixture_Model';

    private static function __forget_fixture(): void
    {
        Rsx_Side_Connection::run(fn ($db) => $db->table('_type_refs')->where('class_name', self::FIXTURE)->delete());
        Type_Ref_Registry::_reset_cached_state();
    }

    private static function __committed_id(): ?int
    {
        $row = Rsx_Side_Connection::run(fn ($db) => $db->table('_type_refs')->where('class_name', self::FIXTURE)->first(['id']));

        return $row === null ? null : (int) $row->id;
    }

    public static function teardown()
    {
        static::__forget_fixture();
    }

    public static function test_a_registration_survives_the_callers_rollback()
    {
        static::__forget_fixture();

        DB::beginTransaction();
        $id = Type_Ref_Registry::class_to_id(self::FIXTURE);
        DB::rollBack();

        static::__assert_equals($id, static::__committed_id(), 'the registry row is committed under the id the caller was handed');

        Type_Ref_Registry::_reset_cached_state();
        static::__assert_equals($id, Type_Ref_Registry::class_to_id(self::FIXTURE), 'a fresh load answers the same id, minting nothing');
        static::__assert_equals(self::FIXTURE, Type_Ref_Registry::id_to_class($id), 'and the id resolves back to the class');
    }

    public static function test_the_side_connection_reaches_the_default_database_and_is_closed()
    {
        $seen = Rsx_Side_Connection::run(fn ($db) => [$db->getName(), $db->getDatabaseName()]);

        static::__assert_equals(DB::connection()->getDatabaseName(), $seen[1], 'the side connection reaches the default connection\'s database');
        static::__assert_true(!array_key_exists($seen[0], DB::getConnections()), 'and is closed when the callback returns');
    }
}
