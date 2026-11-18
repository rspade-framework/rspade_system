<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Commands\Database\Index_Advisor;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:db:check_indexes reads the right sources, and reads each query chain's shape.
 *
 * Which files count as a query workload (tests, developer screens and seeders do not), how a
 * file that does not parse is reported rather than skipped, and the step list a fluent chain
 * yields: equality (where =, whereIn, whereNull), range (comparisons, whereBetween,
 * whereNotNull, a prefix LIKE), sort (orderBy, latest, oldest), joins and scope switches -
 * and which chains are abandoned (a dynamic column, an orWhere).
 *
 * Pure logic over Index_Advisor's source functions; every input is fixture source text.
 */
class Check_Indexes_Source_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The steps of the single chain in $code.
     */
    private static function _steps(string $code, int $chain = 0): array
    {
        $parsed = Index_Advisor::parse_source($code);
        static::__assert_null($parsed['error'], 'the fixture parses');

        return $parsed['chains'][$chain]['steps'];
    }

    public static function test_tests_dev_screens_and_seed_directories_are_not_sources()
    {
        static::__assert_equals('test', Index_Advisor::source_exclusion('rsx/tests/Client_Test.php'));
        static::__assert_equals('test', Index_Advisor::source_exclusion('app/RSpade/tests/database/php/X_Test.php'));
        static::__assert_equals('test', Index_Advisor::source_exclusion('rsx/app/billing/tests/billing_test.php'));
        static::__assert_equals('dev', Index_Advisor::source_exclusion('rsx/app/dev/orm/dev_orm_controller.php'));
        static::__assert_equals('seeder', Index_Advisor::source_exclusion('rsx/services/sample_data/sample_data_service.php'));
        static::__assert_equals('seeder', Index_Advisor::source_exclusion('rsx/lib/seeders/demo_seeder.php'));
        static::__assert_null(Index_Advisor::source_exclusion('rsx/app/frontend/clients/clients_controller.php'));
        static::__assert_null(Index_Advisor::source_exclusion('rsx/models/tests_model.php'), 'a FILE named tests is not a tests/ directory');
    }

    public static function test_a_class_whose_only_role_is_seeding_is_a_seeder()
    {
        $command_seeder = <<<'PHP'
<?php
class Demo_Data_Service {
    #[Task('Seed')]
    #[Command('app:seed_demo', 'Seed the demo data')]
    public static function seed($task, array $params = []) {}
}
PHP;
        static::__assert_true(Index_Advisor::parse_source($command_seeder)['is_seeder'], 'every #[Command] is named *:seed*');

        $laravel_seeder = '<?php class Demo_Seeder extends DatabaseSeeder {} class Thing_Seeder extends Seeder {}';
        static::__assert_true(Index_Advisor::parse_source($laravel_seeder)['is_seeder'], 'a Seeder subclass');

        $mixed = <<<'PHP'
<?php
class Maintenance_Service {
    #[Command('app:seed_demo', 'Seed')]
    public static function seed($task, array $params = []) {}
    #[Command('app:purge', 'Purge')]
    public static function purge($task, array $params = []) {}
}
PHP;
        static::__assert_false(Index_Advisor::parse_source($mixed)['is_seeder'], 'a class with any non-seeding command is a workload');

        static::__assert_false(Index_Advisor::parse_source('<?php class Plain_Service {}')['is_seeder']);
    }

    public static function test_a_file_that_does_not_parse_reports_its_error()
    {
        $parsed = Index_Advisor::parse_source('<?php class Broken { public function x( { }');

        static::__assert_not_null($parsed['error'], 'the parse error is returned, never swallowed');
        static::__assert_equals([], $parsed['chains']);
    }

    public static function test_equality_shapes()
    {
        $steps = static::_steps(<<<'PHP'
<?php
Fx_Order_Model::where('a', 1)
    ->where('b', '=', 2)
    ->whereIn('c', [1, 2])
    ->whereNull('d')
    ->whereNotNull('e')
    ->where(['f' => 1, 'g' => 2])
    ->first();
PHP);

        static::__assert_equals(
            [
                ['op' => 'eq', 'column' => 'a'],
                ['op' => 'eq', 'column' => 'b'],
                ['op' => 'eq', 'column' => 'c'],
                ['op' => 'eq', 'column' => 'd'],
                ['op' => 'range', 'column' => 'e'],
                ['op' => 'eq', 'column' => 'f'],
                ['op' => 'eq', 'column' => 'g'],
            ],
            $steps,
            'whereNotNull reads every non-null key - a range; first() contributes nothing'
        );
    }

    public static function test_range_and_sort_shapes()
    {
        $steps = static::_steps(<<<'PHP'
<?php
Fx_Order_Model::where('placed_at', '>=', $since)
    ->whereBetween('total', [1, 2])
    ->orderBy('name')
    ->orderByDesc('code')
    ->latest()
    ->oldest('closed_at')
    ->value('id');
PHP);

        static::__assert_equals(
            [
                ['op' => 'range', 'column' => 'placed_at'],
                ['op' => 'range', 'column' => 'total'],
                ['op' => 'sort', 'column' => 'name'],
                ['op' => 'sort', 'column' => 'code'],
                ['op' => 'sort', 'column' => 'created_at'],
                ['op' => 'sort', 'column' => 'closed_at'],
            ],
            $steps,
            'latest() with no argument sorts by created_at'
        );
    }

    public static function test_a_leading_wildcard_like_is_never_an_index_condition()
    {
        $steps = static::_steps(<<<'PHP'
<?php
Fx_Order_Model::where('name', 'LIKE', '%acme%')
    ->where('code', 'like', "%{$term}")
    ->where('email', 'LIKE', '%' . $term)
    ->where('city', 'LIKE', 'Spring%')
    ->where('state', '!=', 'X')
    ->get();
PHP);

        static::__assert_equals(
            [
                ['op' => 'skip', 'column' => 'name', 'reason' => 'leading_wildcard'],
                ['op' => 'skip', 'column' => 'code', 'reason' => 'leading_wildcard'],
                ['op' => 'skip', 'column' => 'email', 'reason' => 'leading_wildcard'],
                ['op' => 'range', 'column' => 'city'],
                ['op' => 'skip', 'column' => 'state', 'reason' => 'operator'],
            ],
            $steps,
            'only a prefix pattern is a range; != reads no index range'
        );
    }

    public static function test_a_dynamic_column_or_an_or_abandons_the_chain()
    {
        $parsed = Index_Advisor::parse_source(<<<'PHP'
<?php
Fx_Order_Model::where($column, 1)->get();
Fx_Order_Model::where('a', 1)->orWhere('b', 2)->get();
Fx_Order_Model::where('a', 1)->where(function ($q) { $q->where('b', 1)->orWhere('c', 2); })->get();
PHP);

        static::__assert_equals('dynamic_column', $parsed['chains'][0]['abandoned']);
        static::__assert_equals('or', $parsed['chains'][1]['abandoned']);
        static::__assert_null($parsed['chains'][2]['abandoned'], 'an OR inside a nested group does not abandon the outer chain');
        static::__assert_equals([['op' => 'eq', 'column' => 'a']], $parsed['chains'][2]['steps'], 'and the group itself contributes nothing');
        static::__assert_count(3, $parsed['chains'], 'the $q chain inside the group roots at a variable and is not read');
    }

    public static function test_only_the_outermost_call_of_a_chain_is_read_and_self_resolves()
    {
        $parsed = Index_Advisor::parse_source(<<<'PHP'
<?php
class Fx_Order_Model {
    public static function open() {
        return static::where('a', 1)->where('b', 2)->get();
    }
}
PHP);

        static::__assert_count(1, $parsed['chains'], 'one chain, not one per call in it');
        static::__assert_equals('Fx_Order_Model', $parsed['chains'][0]['root'], 'static:: is the enclosing class');
        static::__assert_equals(4, $parsed['chains'][0]['line']);
    }

    public static function test_joins_and_scope_switches()
    {
        $steps = static::_steps(<<<'PHP'
<?php
Fx_Order_Model::join('fx_customers as c', 'c.id', '=', 'fx_orders.customer_id')
    ->leftJoin('fx_regions', function ($join) { $join->on('a', '=', 'b'); })
    ->leftJoin($table, 'x', '=', 'y')
    ->withoutGlobalScopes()
    ->get();
PHP);

        static::__assert_equals(
            [
                ['op' => 'join', 'table' => 'fx_customers', 'alias' => 'c'],
                ['op' => 'join', 'table' => 'fx_regions', 'alias' => 'fx_regions'],
                ['op' => 'join', 'table' => null, 'alias' => null],
                ['op' => 'unscope'],
            ],
            $steps
        );
    }

    public static function test_a_chain_inside_without_site_scope_records_the_unscoping_class()
    {
        $parsed = Index_Advisor::parse_source(<<<'PHP'
<?php
Fx_Order_Model::where('a', 1)->get();
Fx_Customer_Model::without_site_scope(function () {
    return Fx_Order_Model::where('b', 1)->get();
});
PHP);

        $by_line = [];

        foreach ($parsed['chains'] as $chain) {
            $by_line[$chain['line']][] = $chain;
        }

        static::__assert_equals([], $by_line[2][0]['unscoped_by']);
        static::__assert_equals(['Fx_Customer_Model'], $by_line[4][0]['unscoped_by']);
    }
}
