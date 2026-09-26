<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\SysPanel\Php\Sys_DataGrid_Model_Fixture;
use App\RSpade\Tests\SysPanel\Php\Sys_DataGrid_Rows_Fixture;
use App\RSpade\Tests\SysPanel\Php\Sys_DataGrid_Table_Fixture;

/**
 * _Sys_DataGrid_Abstract::fetch() - the panel grid's server half, over each of its
 * three sources: a Query\Builder, an Eloquent Builder and an in-memory row list.
 *
 * Pinned: the response shape; the fail-closed sort allow-list and order; the
 * per_page clamp; a page past the end pulled back to the last page; the tie-break;
 * model serialization on the Eloquent source; natural ordering with null first on a
 * row list; __transform_records(); and a __build_query() answer that is no source.
 */
class Sys_DataGrid_Fetch_Test extends Rsx_Test_Abstract
{
    /**
     * Five login identities under a fresh email prefix; returns [prefix, ids by email].
     */
    private static function __seed(): array
    {
        $prefix = 'sysgrid-' . random_hash(6) . '-';
        $ids = [];

        foreach (['c', 'a', 'e', 'b', 'd'] as $letter) {
            $login_user = new Login_User_Model();
            $login_user->email = $prefix . $letter . '@rspade.test';
            $login_user->password = Hash::make('x');
            $login_user->is_activated = 1;
            $login_user->is_verified = 1;
            $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
            $login_user->save();

            $ids[$letter] = (int) $login_user->id;
        }

        return [$prefix, $ids];
    }

    private static function __letters(array $records, string $prefix): array
    {
        return array_map(fn ($row) => substr($row['email'], strlen($prefix), 1), $records);
    }

    /**
     * RP-GRID-01 - A Query\Builder source: the response shape, an allowed sort, and
     * rows as associative arrays.
     */
    public static function test_a_query_builder_source_sorts_and_pages()
    {
        [$prefix] = static::__seed();

        $result = Sys_DataGrid_Table_Fixture::fetch(['prefix' => $prefix, 'sort' => 'email', 'order' => 'asc']);

        static::__assert_equals(
            ['records', 'page', 'per_page', 'total', 'total_pages', 'sort', 'order'],
            array_keys($result)
        );
        static::__assert_equals(5, $result['total']);
        static::__assert_equals(3, $result['total_pages']);
        static::__assert_equals(2, $result['per_page']);
        static::__assert_equals(['email', 'asc'], [$result['sort'], $result['order']]);
        static::__assert_equals(['a', 'b'], static::__letters($result['records'], $prefix));
        static::__assert_true(is_array($result['records'][0]), 'a Query\\Builder row arrives as an array');

        $page_two = Sys_DataGrid_Table_Fixture::fetch(['prefix' => $prefix, 'sort' => 'email', 'order' => 'asc', 'page' => 2]);
        static::__assert_equals(['c', 'd'], static::__letters($page_two['records'], $prefix));
    }

    /**
     * RP-GRID-02 - The allow-list is fail-closed: an unlisted sort key (a real column
     * included) and an order that is neither asc nor desc fall back to the defaults.
     */
    public static function test_an_unlisted_sort_and_a_bad_order_fall_back_to_the_defaults()
    {
        [$prefix, $ids] = static::__seed();

        foreach (['password', 'id; DROP TABLE login_users', ''] as $sort) {
            $result = Sys_DataGrid_Table_Fixture::fetch(['prefix' => $prefix, 'sort' => $sort, 'order' => 'sideways', 'per_page' => 3]);

            static::__assert_equals('id', $result['sort'], "sort '{$sort}' was not refused");
            static::__assert_equals('desc', $result['order']);
            static::__assert_equals($ids['d'], (int) $result['records'][0]['id'], 'the default order is id desc');
        }
    }

    /**
     * RP-GRID-03 - per_page is clamped into [1, max]; a page past the end is the last
     * page, and a page below 1 is the first.
     */
    public static function test_per_page_and_page_are_clamped()
    {
        [$prefix] = static::__seed();

        static::__assert_equals(1, Sys_DataGrid_Table_Fixture::fetch(['prefix' => $prefix, 'per_page' => 0])['per_page']);
        static::__assert_equals(3, Sys_DataGrid_Table_Fixture::fetch(['prefix' => $prefix, 'per_page' => 1000])['per_page']);

        $past = Sys_DataGrid_Table_Fixture::fetch(['prefix' => $prefix, 'page' => 99, 'sort' => 'email', 'order' => 'asc']);
        static::__assert_equals(3, $past['page']);
        static::__assert_equals(['e'], static::__letters($past['records'], $prefix));

        static::__assert_equals(1, Sys_DataGrid_Table_Fixture::fetch(['prefix' => $prefix, 'page' => -5])['page']);

        $none = Sys_DataGrid_Table_Fixture::fetch(['prefix' => 'sysgrid-nobody-']);
        static::__assert_equals([0, 0, 1, []], [$none['total'], $none['total_pages'], $none['page'], $none['records']]);
    }

    /**
     * RP-GRID-04 - A sort on a column every row shares is broken by the tie-break
     * (id desc), so the order is total and pages do not overlap.
     */
    public static function test_the_tie_break_orders_equal_rows()
    {
        [$prefix, $ids] = static::__seed();

        $result = Sys_DataGrid_Table_Fixture::fetch(['prefix' => $prefix, 'sort' => 'status_id', 'order' => 'asc', 'per_page' => 3]);
        $expected = $ids;
        rsort($expected);

        static::__assert_equals(array_slice($expected, 0, 3), array_map(fn ($row) => (int) $row['id'], $result['records']));
    }

    /**
     * RP-GRID-05 - An Eloquent source serializes through the model's toArray(): the
     * hidden password is absent and the enum label and __MODEL are present.
     */
    public static function test_an_eloquent_source_serializes_through_the_model()
    {
        [$prefix] = static::__seed();

        $result = Sys_DataGrid_Model_Fixture::fetch(['prefix' => $prefix, 'sort' => 'email', 'order' => 'desc']);

        static::__assert_equals(5, $result['total']);
        static::__assert_equals(['e', 'd'], static::__letters($result['records'], $prefix));

        $row = $result['records'][0];
        static::__assert_false(array_key_exists('password', $row), 'the model hides the password');
        static::__assert_equals('Login_User_Model', $row['__MODEL'] ?? null);
        static::__assert_true(array_key_exists('status_id__label', $row), 'enum labels ride the payload');
    }

    /**
     * RP-GRID-06 - A row-list source: natural order with null first, the tie-break,
     * paging, the clamp, filtering in __build_query(), and __transform_records().
     */
    public static function test_a_row_list_source_sorts_and_pages_in_memory()
    {
        $ids = fn ($result) => array_column($result['records'], 'id');

        $asc = Sys_DataGrid_Rows_Fixture::fetch(['sort' => 'name', 'order' => 'asc', 'per_page' => 4]);
        static::__assert_equals([5, 3, 2, 1], $ids($asc), 'null first, then a (tie broken id desc), then b');
        static::__assert_equals(5, $asc['total']);
        static::__assert_equals(2, $asc['total_pages']);
        static::__assert_equals('B', $asc['records'][3]['label'], '__transform_records() ran');

        $last = Sys_DataGrid_Rows_Fixture::fetch(['sort' => 'name', 'order' => 'asc', 'per_page' => 4, 'page' => 9]);
        static::__assert_equals([2, [4]], [$last['page'], $ids($last)]);

        $size = Sys_DataGrid_Rows_Fixture::fetch(['sort' => 'size', 'order' => 'desc', 'per_page' => 9]);
        static::__assert_equals(4, $size['per_page'], 'per_page is clamped for a row list too');
        static::__assert_equals([3, 5, 1, 2], $ids($size), 'numbers compare as numbers');

        $default = Sys_DataGrid_Rows_Fixture::fetch(['sort' => 'secret']);
        static::__assert_equals(['id', 'desc', [5, 4]], [$default['sort'], $default['order'], $ids($default)]);

        $filtered = Sys_DataGrid_Rows_Fixture::fetch(['filter' => 'a']);
        static::__assert_equals([2, [3, 2]], [$filtered['total'], $ids($filtered)]);
    }

    /**
     * RP-GRID-07 - A __build_query() answer that is no source fails loud.
     */
    public static function test_a_source_of_the_wrong_type_throws()
    {
        static::__assert_throws(\Throwable::class, fn () => Sys_DataGrid_Rows_Fixture::fetch(['bad' => 1]), '__build_query()');
    }
}
