<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Commands\Database\Check_Indexes_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:db:check_indexes recommends only indexes that would change a query plan.
 *
 * InnoDB clusters every table on its primary key and appends it to every secondary index,
 * so a requirement leading with the primary key is already a primary-key lookup, and one
 * ending with it is exactly the index without it. And an index serves a filter by its
 * LEADING columns: (thread_id) is served by (thread_id, created_at), never by
 * (site_id, thread_id). Advice that ignored either added pointless or duplicate indexes.
 *
 * Pure logic over the command's two public rules.
 */
class Check_Indexes_Advice_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_a_requirement_leading_with_the_primary_key_needs_no_index()
    {
        static::__assert_null(
            Check_Indexes_Command::requirement_without_primary_key(['id', 'site_id'], ['id']),
            '(id, site_id) is a primary-key lookup'
        );
        static::__assert_null(
            Check_Indexes_Command::requirement_without_primary_key(['id'], ['id']),
            '(id) is the primary key itself'
        );
    }

    public static function test_a_trailing_primary_key_is_dropped_from_the_requirement()
    {
        static::__assert_equals(
            ['site_id'],
            Check_Indexes_Command::requirement_without_primary_key(['site_id', 'id'], ['id']),
            '(site_id, id) is what an index on (site_id) already is'
        );
        static::__assert_equals(
            ['user_id', 'site_id'],
            Check_Indexes_Command::requirement_without_primary_key(['user_id', 'site_id'], ['id']),
            'a requirement with no primary-key column is untouched'
        );
    }

    public static function test_an_index_serves_a_requirement_by_its_leading_columns()
    {
        $indexes = [
            ['name' => 'PRIMARY', 'columns' => ['id']],
            ['name' => 'idx_prm_thread', 'columns' => ['thread_id', 'created_at']],
            ['name' => 'idx_site_status', 'columns' => ['site_id', 'status_id']],
        ];

        static::__assert_true(
            Check_Indexes_Command::is_served_by_existing(['thread_id'], $indexes),
            '(thread_id) is served by (thread_id, created_at)'
        );
        static::__assert_true(
            Check_Indexes_Command::is_served_by_existing(['thread_id', 'created_at'], $indexes),
            'and so is the whole of it'
        );
        static::__assert_false(
            Check_Indexes_Command::is_served_by_existing(['status_id'], $indexes),
            '(status_id) is NOT served by (site_id, status_id) - it is not the leading column'
        );
        static::__assert_false(
            Check_Indexes_Command::is_served_by_existing(['thread_id', 'site_id'], $indexes),
            'nor is a requirement longer than any index\'s matching prefix'
        );
    }
}
