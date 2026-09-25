<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Dispatch\Php;

use ReflectionMethod;
use RuntimeException;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx::Route() URL generation from a route pattern's :tokens.
 *
 * Each token is replaced whole and by name, so ':id' never corrupts ':id_two'; an optional
 * ':x?' is filled when given and dropped with its slash when not; only REQUIRED tokens are
 * demanded. The JS twin (Rsx._generate_url_from_pattern) is pinned by
 * playwright/route_url_tokens.js against the same cases.
 */
class Route_Url_Tokens_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __generate(string $pattern, array $params): string
    {
        $method = new ReflectionMethod(Rsx::class, '_generate_url_from_pattern');

        return $method->invoke(null, $pattern, $params, 'Probe_Controller', 'probe');
    }

    public static function test_a_token_is_replaced_whole_and_by_name()
    {
        static::__assert_equals('/x/1/2', static::__generate('/x/:id/:id_two', ['id' => 1, 'id_two' => 2]));
        static::__assert_equals('/x/2/1', static::__generate('/x/:id_two/:id', ['id' => 1, 'id_two' => 2]));
    }

    public static function test_an_optional_token_is_filled_or_dropped_with_its_slash()
    {
        static::__assert_equals('/list/3', static::__generate('/list/:page?', ['page' => 3]));
        static::__assert_equals('/list', static::__generate('/list/:page?', []));
        static::__assert_equals('/list', static::__generate('/list/:page?', ['page' => null]));
        static::__assert_equals('/a/5/b', static::__generate('/a/:id/b/:tab?', ['id' => 5]));
    }

    public static function test_only_required_tokens_are_demanded()
    {
        static::__assert_throws(RuntimeException::class, function () {
            static::__generate('/x/:id/:page?', []);
        }, '[id]');
    }

    public static function test_extra_params_become_the_query_string()
    {
        static::__assert_equals('/x/1?q=a+b', static::__generate('/x/:id', ['id' => 1, 'q' => 'a b']));
    }

    public static function test_selection_prefers_the_pattern_filling_the_most_tokens()
    {
        $method = new ReflectionMethod(Rsx::class, '_select_best_route');
        $routes = [['pattern' => '/list'], ['pattern' => '/list/:page?'], ['pattern' => '/list/:page/:id']];

        static::__assert_equals('/list/:page?', $method->invoke(null, $routes, ['page' => 2])['pattern']);
        static::__assert_equals('/list/:page/:id', $method->invoke(null, $routes, ['page' => 2, 'id' => 7])['pattern']);
    }
}
