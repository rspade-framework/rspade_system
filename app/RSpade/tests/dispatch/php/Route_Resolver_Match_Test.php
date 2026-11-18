<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use App\RSpade\Core\Dispatch\RouteResolver;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * INBOUND ROUTE MATCHING: RouteResolver, as rsx:man dispatch (ROUTE MATCHING) and rsx:man
 * routing (Parameter Syntax, Parameter Values, Trailing Slashes) state it.
 *
 * Every page request is resolved by sort_by_priority() over the route table and then
 * match_with_query() per pattern, so a regression here misroutes or 404s a page with no
 * error at all. Pure functions over strings; no route table, no database.
 */
class Route_Resolver_Match_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_an_exact_pattern_matches_only_itself()
    {
        static::__assert_equals([], RouteResolver::match('/contacts', '/contacts'), 'a match with no parameters is an empty array');

        foreach (['/contacts/1', '/contact', '/contactsx', '/', '/other/contacts'] as $url) {
            static::__assert_false(RouteResolver::match($url, '/contacts'), "{$url} is not /contacts");
        }
    }

    public static function test_a_named_parameter_is_one_segment()
    {
        static::__assert_equals(['id' => '42'], RouteResolver::match('/users/42', '/users/:id'));
        static::__assert_false(RouteResolver::match('/users', '/users/:id'), 'a required parameter is required');
        static::__assert_false(RouteResolver::match('/users/42/edit', '/users/:id'), 'a parameter never spans a slash');
    }

    public static function test_several_parameters_are_named_in_order()
    {
        static::__assert_equals(
            ['company' => 'acme', 'division' => 'sales', 'id' => '123'],
            RouteResolver::match('/reports/acme/sales/123', '/reports/:company/:division/:id')
        );
        static::__assert_equals(['id' => '5'], RouteResolver::match('/users/5/edit', '/users/:id/edit'));
        static::__assert_false(RouteResolver::match('/users/5/view', '/users/:id/edit'));
    }

    public static function test_an_absent_optional_parameter_is_omitted_entirely()
    {
        static::__assert_equals([], RouteResolver::match('/posts', '/posts/:id?'), 'absent: the key is not set, not an empty string');
        static::__assert_equals(['id' => '7'], RouteResolver::match('/posts/7', '/posts/:id?'));
        static::__assert_false(RouteResolver::match('/posts/7/8', '/posts/:id?'));
    }

    public static function test_a_wildcard_captures_the_remainder()
    {
        static::__assert_equals(['wildcard' => 'a/b/c.txt'], RouteResolver::match('/files/a/b/c.txt', '/files/*'));
        static::__assert_equals(['wildcard' => 'x'], RouteResolver::match('/files/x', '/files/*'));
        static::__assert_false(RouteResolver::match('/other/x', '/files/*'));
    }

    public static function test_mixed_segments_split_on_the_literal_dot()
    {
        static::__assert_equals(['id' => 'report', 'ext' => 'pdf'], RouteResolver::match('/files/report.pdf', '/files/:id.:ext'));
        static::__assert_equals(
            ['id' => 'report.v2', 'ext' => 'pdf'],
            RouteResolver::match('/files/report.v2.pdf', '/files/:id.:ext'),
            'the last dot separates the extension'
        );
        static::__assert_false(RouteResolver::match('/files/report', '/files/:id.:ext'), 'no dot, no match');
    }

    public static function test_a_trailing_slash_is_ignored_on_url_and_pattern()
    {
        static::__assert_equals([], RouteResolver::match('/users/', '/users'));
        static::__assert_equals([], RouteResolver::match('/users', '/users/'));
        static::__assert_equals([], RouteResolver::match('/users/', '/users/'));
        static::__assert_equals(['id' => '9'], RouteResolver::match('/users/9/', '/users/:id'));
        static::__assert_equals([], RouteResolver::match('/', '/'), 'the root is itself');
    }

    public static function test_matching_is_case_sensitive()
    {
        static::__assert_false(RouteResolver::match('/Users', '/users'));
        static::__assert_false(RouteResolver::match('/USERS/5', '/users/:id'));
        static::__assert_equals(['id' => 'AbC'], RouteResolver::match('/users/AbC', '/users/:id'), 'a value keeps its case');
    }

    public static function test_a_value_is_urldecoded_after_matching()
    {
        static::__assert_equals(['id' => 'a b'], RouteResolver::match('/users/a%20b', '/users/:id'));
        static::__assert_equals(['id' => 'a b'], RouteResolver::match('/users/a+b', '/users/:id'), "'+' decodes to a space (urldecode)");
        static::__assert_equals(['id' => 'a/b'], RouteResolver::match('/users/a%2Fb', '/users/:id'), "'%2F' matches as one segment and arrives as '/'");
        static::__assert_equals(['id' => 'a.b-c_d~e'], RouteResolver::match('/users/a.b-c_d~e', '/users/:id'), 'any character except / is part of a value');
    }

    public static function test_match_ignores_a_query_string()
    {
        static::__assert_equals(['id' => '5'], RouteResolver::match('/users/5?id=6&x=1', '/users/:id'));
    }

    public static function test_match_with_query_merges_the_query_under_the_route_parameters()
    {
        static::__assert_equals(
            ['id' => '123', 'sort' => 'asc'],
            RouteResolver::match_with_query('/users/123?id=456&sort=asc', '/users/:id'),
            'the route parameter wins over the same-named query parameter'
        );

        static::__assert_equals(
            ['tags' => ['a', 'b'], 'filter' => ['status' => 'active'], 'id' => '1'],
            RouteResolver::match_with_query('/users/1?tags[]=a&tags[]=b&filter[status]=active', '/users/:id'),
            'array query parameters arrive as arrays'
        );

        static::__assert_equals(['q' => '', 'id' => '1'], RouteResolver::match_with_query('/users/1?q=', '/users/:id'), 'an empty query value is present and empty');
        static::__assert_false(RouteResolver::match_with_query('/other/1?id=2', '/users/:id'), 'no path match, whatever the query');
    }

    public static function test_compile_pattern_names_every_parameter_in_order()
    {
        $compiled = RouteResolver::compile_pattern('/files/:id.:ext');

        static::__assert_equals(['id', 'ext'], $compiled['params']);
        static::__assert_equals('/files/:id.:ext', $compiled['pattern']);
        static::__assert_equals(1, preg_match($compiled['regex'], '/files/a.b'));

        static::__assert_equals(['section', 'wildcard'], RouteResolver::compile_pattern('/docs/:section/*')['params']);
    }

    public static function test_specificity_not_declaration_order_decides()
    {
        $cases = [
            'an exact pattern beats a parameter' => [['/users/:id', '/users/settings'], '/users/settings'],
            'a parameter beats a wildcard' => [['/files/*', '/files/:id'], '/files/:id'],
            'more segments beat fewer' => [['/a/:b', '/a/:b/:c'], '/a/:b/:c'],
            'a required parameter beats an optional one' => [['/list/:page?', '/list/:page'], '/list/:page'],
            'a deep exact pattern beats a deep parameter' => [['/a/:b/c', '/a/b/c'], '/a/b/c'],
        ];

        foreach ($cases as $what => [$patterns, $first]) {
            static::__assert_equals($first, RouteResolver::sort_by_priority($patterns)[0], $what);
            static::__assert_equals($first, RouteResolver::sort_by_priority(array_reverse($patterns))[0], "{$what} (reversed input)");
        }

        static::__assert_equals(
            ['/users/settings', '/users/:id/edit', '/users/:id', '/users/*'],
            RouteResolver::sort_by_priority(['/users/*', '/users/:id', '/users/settings', '/users/:id/edit']),
            'a wildcard route is tried last'
        );
    }

    public static function test_the_documented_score_terms()
    {
        static::__assert_equals(1200, RouteResolver::get_pattern_priority('/users/settings'), 'exact +1000, two segments +200');
        static::__assert_equals(195, RouteResolver::get_pattern_priority('/users/:id'), 'two segments, one required parameter -5');
        static::__assert_equals(150, RouteResolver::get_pattern_priority('/files/*'), 'two segments, one wildcard -50');
        static::__assert_equals(190, RouteResolver::get_pattern_priority('/posts/:id?'), 'two segments, one optional parameter -10 (never also -5)');
        static::__assert_equals(285, RouteResolver::get_pattern_priority('/a/:x/:y?'), 'three segments, one required -5, one optional -10');
    }
}
