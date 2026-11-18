<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Commands\Database\Check_Indexes_Command;
use App\RSpade\Commands\Database\Index_Advisor;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Database\Php\Check_Indexes_Plain_Fixture_Model;
use App\RSpade\Tests\Database\Php\Check_Indexes_Site_Fixture_Model;

/**
 * rsx:db:check_indexes recommends only indexes that would change a query plan.
 *
 * What a query requires: columns credited to the table they belong to (a model's own
 * qualifier stripped, a joined table's column credited there, an ambiguous one dropped),
 * site_id for a site-scoped model unless the scope is switched off, never a TEXT / JSON
 * column or a leading-wildcard LIKE, and never the primary key (InnoDB clusters on it and
 * appends it to every secondary index). Whether an index serves it: its leading columns are
 * exactly the equality set in any order, or are all in the set and include a SELECTIVE
 * column (not site_id, not a flag / status / enum). What is recommended: equality columns (site_id
 * first), then one range column or the sort columns; identical requirements aggregated with
 * their call sites, low-value ones (only flag / status / enum columns) listed apart, and the
 * existing index that nearly serves one named.
 *
 * Pure logic over Index_Advisor and a fixture CONTEXT (fixture tables as arrays); the model
 * facts the command reads are pinned against two fixture models.
 */
class Check_Indexes_Advice_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Two fixture tables: fx_orders (site-scoped) and fx_customers (not).
     */
    private static function _context(): array
    {
        return [
            'models' => [
                'Fx_Order_Model' => ['table' => 'fx_orders', 'site_scoped' => true, 'scope_holder' => 'Fx_Site_Base'],
                'Fx_Line_Model' => ['table' => 'fx_lines', 'site_scoped' => true, 'scope_holder' => 'Fx_Site_Base'],
                'Fx_Customer_Model' => ['table' => 'fx_customers', 'site_scoped' => false, 'scope_holder' => null],
            ],
            'tables' => [
                'fx_orders' => [
                    'columns' => [
                        'id' => 'integer', 'site_id' => 'integer', 'customer_id' => 'integer',
                        'status_id' => 'integer', 'is_paid' => 'boolean', 'kind' => 'enum',
                        'notes' => 'text', 'payload' => 'json', 'placed_at' => 'datetime',
                        'code' => 'string', 'name' => 'string', 'created_at' => 'datetime',
                    ],
                    'indexes' => [
                        ['name' => 'PRIMARY', 'columns' => ['id']],
                        ['name' => 'idx_site', 'columns' => ['site_id']],
                        ['name' => 'idx_site_customer', 'columns' => ['site_id', 'customer_id']],
                        ['name' => 'idx_code_site', 'columns' => ['code', 'site_id']],
                        ['name' => 'idx_auto_kind', 'columns' => ['kind']],
                    ],
                    'primary_key' => ['id'],
                    'enum_columns' => ['status_id'],
                    'framework' => false,
                ],
                'fx_lines' => [
                    'columns' => ['id' => 'integer', 'site_id' => 'integer', 'order_id' => 'integer', 'name' => 'string'],
                    'indexes' => [['name' => 'PRIMARY', 'columns' => ['id']]],
                    'primary_key' => ['id'],
                    'enum_columns' => [],
                    'framework' => false,
                ],
                'fx_customers' => [
                    'columns' => [
                        'id' => 'integer', 'email' => 'string', 'region_id' => 'integer',
                        'name' => 'string', 'bio' => 'mediumtext',
                    ],
                    'indexes' => [
                        ['name' => 'PRIMARY', 'columns' => ['id']],
                        ['name' => 'idx_region_name', 'columns' => ['region_id', 'name']],
                    ],
                    'primary_key' => ['id'],
                    'enum_columns' => [],
                    'framework' => false,
                ],
            ],
        ];
    }

    /**
     * The requirements of the first chain in a fixture source.
     */
    private static function _requirements(string $code, int $chain = 0): array
    {
        $parsed = Index_Advisor::parse_source("<?php\n" . $code);
        static::__assert_null($parsed['error'], 'the fixture parses');

        return Index_Advisor::chain_requirements($parsed['chains'][$chain], static::_context())['requirements'];
    }

    private static function _req(string $table, array $eq, ?string $range = null, array $sort = []): array
    {
        return ['table' => $table, 'eq' => $eq, 'range' => $range, 'sort' => $sort];
    }

    // ---------------------------------------------------------------------------------
    // Column resolution
    // ---------------------------------------------------------------------------------

    public static function test_the_models_own_qualifier_is_stripped()
    {
        static::__assert_equals(
            [static::_req('fx_customers', ['email'])],
            static::_requirements("Fx_Customer_Model::where('fx_customers.email', \$e)->first();")
        );
    }

    public static function test_a_joined_tables_column_is_credited_to_that_table_never_the_root()
    {
        $requirements = static::_requirements(
            "Fx_Order_Model::join('fx_customers as c', 'c.id', '=', 'fx_orders.customer_id')"
            . "->where('c.region_id', \$r)->where('fx_orders.status_id', 1)->get();"
        );

        static::__assert_equals(
            [
                static::_req('fx_customers', ['region_id']),
                static::_req('fx_orders', ['site_id', 'status_id']),
            ],
            $requirements,
            'the alias resolves to fx_customers; the joined table carries no implicit site_id'
        );
    }

    public static function test_a_column_qualified_with_an_unknown_table_is_dropped()
    {
        static::__assert_equals(
            [static::_req('fx_customers', ['email'])],
            static::_requirements("Fx_Customer_Model::where('fx_elsewhere.region_id', 1)->where('email', \$e)->get();")
        );
    }

    public static function test_an_unqualified_column_in_a_joined_query_is_kept_only_when_the_root_alone_has_it()
    {
        $requirements = static::_requirements(
            "Fx_Order_Model::join('fx_customers', 'fx_customers.id', '=', 'fx_orders.customer_id')"
            . "->where('name', \$n)->where('placed_at', '>', \$d)->get();"
        );

        static::__assert_equals(
            [static::_req('fx_orders', ['site_id'], 'placed_at')],
            $requirements,
            'name is on both tables (dropped); placed_at is the root\'s alone (kept)'
        );

        $unknown_join = static::_requirements("Fx_Order_Model::join(\$table, 'a', '=', 'b')->where('placed_at', '>', \$d)->get();");
        static::__assert_equals(
            [],
            $unknown_join,
            'with a join to an unknown table no unqualified column can be attributed, so nothing is required'
        );
    }

    // ---------------------------------------------------------------------------------
    // Site scope
    // ---------------------------------------------------------------------------------

    public static function test_a_site_scoped_requirement_carries_site_id_and_a_site_index_serves_it()
    {
        $requirements = static::_requirements("Fx_Order_Model::where('customer_id', \$c)->get();");
        static::__assert_equals([static::_req('fx_orders', ['site_id', 'customer_id'])], $requirements);

        $table_info = static::_context()['tables']['fx_orders'];
        static::__assert_equals(
            'idx_site_customer',
            Index_Advisor::serving_index($requirements[0], $table_info['indexes'], $table_info)['name'],
            '(site_id, customer_id) serves customer_id on a site-scoped model'
        );
    }

    public static function test_switching_the_scope_off_removes_site_id()
    {
        static::__assert_equals(
            [static::_req('fx_orders', ['customer_id'])],
            static::_requirements("Fx_Order_Model::withoutGlobalScopes()->where('customer_id', \$c)->get();")
        );

        $code = "Fx_Line_Model::without_site_scope(function () { return Fx_Order_Model::where('customer_id', \$c)->get(); });";
        static::__assert_equals(
            [static::_req('fx_orders', ['customer_id'])],
            static::_requirements($code, 1),
            'a model sharing the scope flag holder suspends it for the whole family'
        );

        $code = "Fx_Customer_Model::without_site_scope(function () { return Fx_Order_Model::where('customer_id', \$c)->get(); });";
        static::__assert_equals(
            [static::_req('fx_orders', ['site_id', 'customer_id'])],
            static::_requirements($code, 1),
            'another model\'s switch does not reach this one'
        );
    }

    // ---------------------------------------------------------------------------------
    // Matching
    // ---------------------------------------------------------------------------------

    public static function test_equality_columns_are_a_set_and_match_an_index_in_any_order()
    {
        $requirement = static::_requirements("Fx_Order_Model::where('code', \$c)->first();")[0];
        static::__assert_equals(['site_id', 'code'], $requirement['eq'], 'canonical order: site_id first');

        $orders = static::_context()['tables']['fx_orders'];
        $customers = static::_context()['tables']['fx_customers'];
        static::__assert_equals('idx_code_site', Index_Advisor::serving_index($requirement, $orders['indexes'], $orders)['name'], '(code, site_id) serves {site_id, code}');

        static::__assert_null(
            Index_Advisor::serving_index(static::_req('fx_customers', ['name']), $customers['indexes'], $customers),
            '(name) is not served by (region_id, name): region_id is not in the set'
        );
        static::__assert_null(
            Index_Advisor::serving_index(static::_req('fx_orders', ['site_id', 'status_id', 'name']), $orders['indexes'], $orders),
            'nor by (site_id) or (site_id, customer_id): no selective column of the set leads'
        );
    }

    public static function test_a_selective_leading_column_in_the_set_serves_it()
    {
        $orders = static::_context()['tables']['fx_orders'];
        $only = fn (array $columns) => [['name' => 'idx', 'columns' => $columns]];
        $site_customer = static::_req('fx_orders', ['site_id', 'customer_id']);

        static::__assert_not_null(
            Index_Advisor::serving_index($site_customer, $only(['customer_id']), $orders),
            '(customer_id) serves {site_id, customer_id}: one customer\'s rows are in one site'
        );
        static::__assert_not_null(
            Index_Advisor::serving_index(static::_req('fx_orders', ['site_id', 'customer_id', 'status_id']), $only(['site_id', 'customer_id', 'placed_at']), $orders),
            '(site_id, customer_id, ...) serves {site_id, customer_id, status_id}: customer_id is selective'
        );
        static::__assert_null(
            Index_Advisor::serving_index($site_customer, $only(['site_id']), $orders),
            '(site_id) alone does not serve {site_id, customer_id}'
        );
        static::__assert_null(
            Index_Advisor::serving_index(static::_req('fx_orders', ['site_id', 'status_id', 'customer_id']), $only(['status_id']), $orders),
            '(status_id) alone does not serve: an enum narrows by a fraction'
        );
        static::__assert_null(
            Index_Advisor::serving_index($site_customer, $only(['name', 'customer_id']), $orders),
            'a selective column behind one outside the set serves nothing'
        );
        static::__assert_not_null(
            Index_Advisor::serving_index(static::_req('fx_orders', ['site_id', 'status_id']), $only(['status_id', 'site_id']), $orders),
            'the exact set serves whatever its columns'
        );
    }

    public static function test_a_trailing_range_or_sort_column_is_optional_for_serving()
    {
        $orders = static::_context()['tables']['fx_orders'];
        $customers = static::_context()['tables']['fx_customers'];

        static::__assert_not_null(Index_Advisor::serving_index(static::_req('fx_orders', ['site_id', 'customer_id'], 'placed_at'), $orders['indexes'], $orders));
        static::__assert_not_null(Index_Advisor::serving_index(static::_req('fx_orders', ['site_id', 'customer_id'], null, ['name']), $orders['indexes'], $orders));
        static::__assert_not_null(
            Index_Advisor::serving_index(static::_req('fx_customers', [], 'region_id'), $customers['indexes'], $customers),
            'a range-only requirement is served by an index leading with its column'
        );
    }

    // ---------------------------------------------------------------------------------
    // Shapes and columns
    // ---------------------------------------------------------------------------------

    public static function test_where_in_and_where_null_are_equality_where_not_null_is_a_range_order_by_is_sort()
    {
        static::__assert_equals(
            [static::_req('fx_orders', ['site_id', 'kind', 'status_id'], null, ['name'])],
            static::_requirements(
                "Fx_Order_Model::whereIn('status_id', [1, 2])->whereNull('kind')->orderBy('name')->get();"
            )
        );
        static::__assert_equals(
            [static::_req('fx_orders', ['site_id', 'status_id'], 'placed_at')],
            static::_requirements(
                "Fx_Order_Model::whereIn('status_id', [1, 2])->whereNotNull('placed_at')->orderBy('name')->get();"
            ),
            'IS NOT NULL is a range, and the sort is dropped behind it'
        );
    }

    public static function test_one_range_column_and_sort_only_without_a_range()
    {
        static::__assert_equals(
            [static::_req('fx_orders', ['site_id', 'customer_id'], 'placed_at')],
            static::_requirements(
                "Fx_Order_Model::where('customer_id', \$c)->where('placed_at', '>', \$a)->where('created_at', '<', \$b)->orderBy('name')->get();"
            ),
            'the first range column only; the sort is dropped behind a range'
        );

        $requirement = static::_req('fx_orders', ['site_id', 'customer_id'], 'placed_at');
        static::__assert_equals(['site_id', 'customer_id', 'placed_at'], Index_Advisor::recommended_shape($requirement));
        static::__assert_equals(
            ['site_id', 'status_id', 'name'],
            Index_Advisor::recommended_shape(static::_req('fx_orders', ['site_id', 'status_id'], null, ['name']))
        );
    }

    public static function test_text_json_and_leading_wildcard_columns_are_never_recommended()
    {
        static::__assert_equals([], static::_requirements("Fx_Customer_Model::where('bio', \$b)->get();"), 'MEDIUMTEXT');
        static::__assert_equals(
            [static::_req('fx_orders', ['site_id', 'customer_id'])],
            static::_requirements("Fx_Order_Model::where('notes', \$n)->where('payload', \$p)->where('customer_id', \$c)->get();"),
            'TEXT and JSON dropped, the rest kept'
        );
        static::__assert_equals([], static::_requirements("Fx_Customer_Model::where('email', 'LIKE', '%@acme.test')->get();"));
        static::__assert_equals(
            [static::_req('fx_customers', [], 'email')],
            static::_requirements("Fx_Customer_Model::where('email', 'LIKE', 'pat%')->get();"),
            'a prefix pattern is a range'
        );
    }

    public static function test_the_primary_key_needs_no_index()
    {
        static::__assert_equals([], static::_requirements("Fx_Order_Model::where('id', \$id)->first();"), '{site_id, id} is a primary-key lookup');
        static::__assert_equals(
            [static::_req('fx_customers', ['email'])],
            static::_requirements("Fx_Customer_Model::where('email', \$e)->where('id', '>', \$after)->orderBy('id')->get();"),
            'a range or sort on the primary key is already in every secondary index'
        );
        static::__assert_equals([], static::_requirements("Fx_Customer_Model::orderBy('name')->get();"), 'a sort alone filters nothing');
    }

    // ---------------------------------------------------------------------------------
    // Usefulness
    // ---------------------------------------------------------------------------------

    public static function test_flag_status_and_enum_requirements_are_low_value()
    {
        $context = static::_context();

        static::__assert_true(Index_Advisor::is_low_value(static::_req('fx_orders', ['site_id', 'status_id']), $context), 'declared enum');
        static::__assert_true(Index_Advisor::is_low_value(static::_req('fx_orders', ['site_id', 'is_paid']), $context), 'boolean');
        static::__assert_true(Index_Advisor::is_low_value(static::_req('fx_orders', ['site_id', 'is_paid', 'kind']), $context), 'MySQL ENUM');
        static::__assert_false(Index_Advisor::is_low_value(static::_req('fx_orders', ['site_id', 'status_id'], 'placed_at'), $context), 'a range makes it selective');
        static::__assert_false(Index_Advisor::is_low_value(static::_req('fx_orders', ['site_id', 'customer_id', 'status_id']), $context), 'a selective column');
    }

    public static function test_identical_requirements_aggregate_and_merge_into_one_index()
    {
        $context = static::_context();
        $items = [
            ['requirement' => static::_req('fx_orders', ['site_id', 'name']), 'source' => 'a.php:1', 'kind' => 'query', 'type' => 'where'],
            ['requirement' => static::_req('fx_orders', ['site_id', 'name']), 'source' => 'b.php:2', 'kind' => 'query', 'type' => 'where'],
            ['requirement' => static::_req('fx_orders', ['site_id', 'name']), 'source' => 'b.php:2', 'kind' => 'query', 'type' => 'where'],
            ['requirement' => static::_req('fx_orders', ['site_id', 'name'], 'placed_at'), 'source' => 'c.php:3', 'kind' => 'query', 'type' => 'where'],
            ['requirement' => static::_req('fx_orders', ['site_id', 'status_id'], 'placed_at'), 'source' => 'd.php:4', 'kind' => 'query', 'type' => 'where'],
            ['requirement' => static::_req('fx_orders', ['site_id', 'status_id']), 'source' => 'e.php:5', 'kind' => 'query', 'type' => 'where'],
            ['requirement' => static::_req('fx_orders', ['site_id', 'is_paid']), 'source' => 'f.php:6', 'kind' => 'query', 'type' => 'where'],
        ];

        $groups = Index_Advisor::aggregate($items);
        static::__assert_count(5, $groups);
        static::__assert_equals(['a.php:1', 'b.php:2'], $groups[Index_Advisor::signature(static::_req('fx_orders', ['site_id', 'name']))]['sources'], 'distinct call sites');

        $diff = Index_Advisor::table_diff('fx_orders', $groups, $context);

        static::__assert_equals(
            [['site_id', 'name', 'placed_at'], ['site_id', 'status_id', 'placed_at']],
            array_column($diff['creates'], 'columns'),
            '(site_id, name) folds into (site_id, name, placed_at)'
        );
        static::__assert_equals(['c.php:3', 'a.php:1', 'b.php:2'], $diff['creates'][0]['sources']);
        static::__assert_equals(['d.php:4', 'e.php:5'], $diff['creates'][1]['sources'], 'a low-value requirement a normal index serves is folded into it');
        static::__assert_equals([['site_id', 'is_paid']], array_column($diff['low_value'], 'columns'), 'the rest is listed apart');
        static::__assert_equals(['idx_auto_kind'], array_column($diff['drops'], 'name'), 'an auto index nothing uses is dropped');
    }

    public static function test_an_index_missing_one_equality_column_is_named_as_nearly_serving()
    {
        $orders = static::_context()['tables']['fx_orders'];
        $requirement = static::_req('fx_orders', ['site_id', 'name']);
        static::__assert_null(Index_Advisor::serving_index($requirement, $orders['indexes'], $orders), 'the subject is unserved');

        $nearly = Index_Advisor::nearly_serving_index($requirement, $orders['indexes']);
        static::__assert_equals('idx_site', $nearly['index']['name']);
        static::__assert_equals('name', $nearly['missing']);
        static::__assert_null(Index_Advisor::nearly_serving_index(static::_req('fx_customers', ['email']), $orders['indexes']), 'a single column is never nearly served');

        $three = static::_req('fx_orders', ['site_id', 'is_paid', 'name']);
        $nearly = Index_Advisor::nearly_serving_index($three, [
            ['name' => 'idx_wide', 'columns' => ['site_id', 'is_paid', 'placed_at']],
            ['name' => 'idx_exact', 'columns' => ['is_paid', 'site_id']],
        ]);
        static::__assert_equals('idx_exact', $nearly['index']['name'], 'an index that is exactly the matched columns is preferred: it can be extended');
        static::__assert_equals(['is_paid', 'site_id'], $nearly['leading']);
    }

    // ---------------------------------------------------------------------------------
    // Model facts and the migration text
    // ---------------------------------------------------------------------------------

    public static function test_model_facts_read_site_scope_its_flag_holder_and_enums()
    {
        $site = Check_Indexes_Command::model_facts(Check_Indexes_Site_Fixture_Model::class);
        static::__assert_equals('check_indexes_site_fixtures', $site['table']);
        static::__assert_true($site['site_scoped']);
        static::__assert_equals('Rsx_Site_Model_Abstract', $site['scope_holder'], 'the class adopting Site_Scoped holds the flag');
        static::__assert_equals(['stage_id'], $site['enum_columns']);
        static::__assert_true($site['framework'], 'its table name is declared in framework source');

        $plain = Check_Indexes_Command::model_facts(Check_Indexes_Plain_Fixture_Model::class);
        static::__assert_false($plain['site_scoped']);
        static::__assert_null($plain['scope_holder']);
        static::__assert_equals([], $plain['enum_columns']);
    }

    public static function test_the_migration_text_alters_each_table_and_names_its_call_sites()
    {
        $body = Index_Advisor::migration_up_body(
            ['fx_orders' => [['columns' => ['site_id', 'name'], 'sources' => ['a.php:1', 'b.php:2']]]],
            ['fx_orders' => [['name' => 'idx_auto_kind', 'columns' => ['kind']]]]
        );

        static::__assert_contains("ALTER TABLE `fx_orders` DROP INDEX `idx_auto_kind`", $body);
        static::__assert_contains("ALTER TABLE `fx_orders` ADD INDEX `idx_auto_site_id_name` (`site_id`, `name`)", $body);
        static::__assert_contains('//   b.php:2', $body);

        $long = Index_Advisor::index_name(['site_id', 'a_very_long_column_name', 'another_very_long_column_name', 'third']);
        static::__assert_equals(64, strlen($long), 'shortened to the identifier limit');
        static::__assert_true(str_starts_with($long, 'idx_auto_site_id_a_very_long'));
    }
}
