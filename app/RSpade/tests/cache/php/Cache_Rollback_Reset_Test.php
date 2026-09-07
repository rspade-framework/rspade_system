<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Cache\Php;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE REGRESSION TEST for the defect that produced the four-database map.
 *
 * Type_Ref_Registry::class_to_id() lazily INSERTs a _type_refs row and memoizes the map in a
 * process static AND in redis. A transaction rollback removes the row and touches neither
 * memo, so a later id_to_class() answered a class that the database no longer names - and
 * because ids are assigned in insertion order, the next writer reused the id and the answer
 * was not a null but a confidently WRONG class. That is how it showed up: audit *_type
 * columns resolving to Site_Model where Login_User_Model was expected.
 *
 * Owner ruling: if the cache cannot be reset at any moment, the cache is improperly
 * implemented. So RsxCache::clear() and Type_Ref_Registry::_reset_cached_state() now run on
 * EVERY TransactionRolledBack event, at every nesting level, and non-cache state (locks,
 * counters, the reduced-volatility cache) lives on other redis databases so the flush is
 * affordable. See Transaction_Rollback_Cache_Reset.
 */
class Cache_Rollback_Reset_Test extends Rsx_Test_Abstract
{
    public static function teardown(): void
    {
        Type_Ref_Registry::_reset_cached_state();
    }

    /**
     * A model class the registry has not seen yet, so class_to_id() takes its auto-create
     * path. Chosen from the manifest at runtime rather than hard-coded: which classes are
     * registered depends on what the suite happened to touch first.
     */
    private static function __an_unregistered_model_class(): string
    {
        $registered = [];
        foreach (DB::select('SELECT class_name FROM _type_refs') as $row) {
            $registered[$row->class_name] = true;
        }

        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $model) {
            $fqcn = $model['fqcn'] ?? null;

            if (!$fqcn) {
                continue;
            }

            $short = class_basename($fqcn);

            if (isset($registered[$short])) {
                continue;
            }

            if (Manifest::php_is_abstract($fqcn)) {
                continue;
            }

            return $short;
        }

        static::__fail('every model in the manifest is already registered - this test needs an unregistered one');
    }

    /**
     * A ROLLED-BACK type ref must not be resolvable afterwards. Before the fix the registry
     * kept answering, from memory and from redis, for a row that no longer existed.
     *
     * The rollback here is a NESTED one (a savepoint inside the per-test transaction), which
     * is deliberate: a savepoint rollback undoes writes exactly like an outermost one, so the
     * listener must fire at every level.
     */
    public static function test_a_rolled_back_type_ref_is_not_resolvable_afterwards()
    {
        $class_name = static::__an_unregistered_model_class();

        DB::beginTransaction();

        $id = Type_Ref_Registry::class_to_id($class_name);

        static::__assert_greater_than(0, $id, 'the auto-create path minted a type-ref id');
        static::__assert_equals(
            $class_name,
            Type_Ref_Registry::id_to_class($id),
            'sanity: while the transaction is open the registry resolves the new id'
        );

        DB::rollBack();

        static::__assert_equals(
            0,
            (int) DB::selectOne(
                'SELECT COUNT(*) AS c FROM _type_refs WHERE class_name = ?',
                [$class_name]
            )->c,
            'sanity: the rollback removed the row'
        );

        static::__assert_throws(
            RuntimeException::class,
            function () use ($id) {
                Type_Ref_Registry::id_to_class($id);
            },
            'not found in registry'
        );
    }

    /**
     * The same property stated at the cache layer, without the registry: anything memoized
     * before a rollback is gone after it. This is the whole mechanism - a cache entry cannot
     * outlive a transaction that may have written the row it describes.
     */
    public static function test_a_rollback_empties_the_volatile_cache()
    {
        $key = 'rsxtest_rollback_reset_' . uniqid();

        RsxCache::set($key, 'memoized before the rollback');
        static::__assert_equals('memoized before the rollback', RsxCache::get($key), 'sanity: the value is cached');

        DB::beginTransaction();
        DB::rollBack();

        static::__assert_null(
            RsxCache::get($key),
            'every rollback flushes the volatile cache - a memo must never outlive the write it describes'
        );
    }
}
