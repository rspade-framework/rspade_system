<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Dispatch\Php\Route_Params_Fixture_Controller;

/**
 * THE $params ARRAY A ROUTE HANDLER RECEIVES (rsx:man dispatch, THE $params ARRAY).
 *
 * The merge, lowest precedence first: the caller's extra parameters, the GET query string,
 * the route parameters the pattern extracted - so /x/123?id=456 is id 123. The POST body is
 * never merged in; the handler reads it from the Request. Three synthetic keys (_method,
 * _route, _handler) are always present.
 *
 * Driven in process through Dispatcher::dispatch() against Route_Params_Fixture_Controller,
 * whose actions answer the array they were handed.
 */
class Route_Params_Test extends Rsx_Test_Abstract
{
    public static function setup(): void
    {
        static::__reset_session();
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    /**
     * Dispatch $url and return the fixture's JSON answer.
     */
    private static function __dispatch(string $url, string $method = 'GET', array $extra = [], array $body = []): array
    {
        $request = Request::create($url, $method, $body);
        app()->instance('request', $request);

        $response = Dispatcher::dispatch($url, $method, $extra, $request);

        static::__assert_equals(200, $response->getStatusCode(), "{$method} {$url} dispatched: " . substr((string) $response->getContent(), 0, 300));

        return json_decode($response->getContent(), true);
    }

    public static function test_a_route_parameter_beats_the_same_named_query_parameter()
    {
        $params = static::__dispatch('/_test/params/123?id=456&sort=asc')['params'];

        static::__assert_equals('123', $params['id'], 'the URL segment wins');
        static::__assert_equals('asc', $params['sort'], 'other query parameters arrive');
    }

    public static function test_the_query_beats_extra_parameters_and_the_route_beats_both()
    {
        $params = static::__dispatch(
            '/_test/params/123?sort=asc',
            'GET',
            ['sort' => 'from_extra', 'id' => 'from_extra', 'only_extra' => 'kept']
        )['params'];

        static::__assert_equals('asc', $params['sort'], 'the query beats an extra parameter');
        static::__assert_equals('123', $params['id'], 'the route beats an extra parameter');
        static::__assert_equals('kept', $params['only_extra'], 'an extra parameter nothing overrides arrives');
    }

    public static function test_the_post_body_is_not_in_params()
    {
        $answer = static::__dispatch('/_test/params/123?sort=asc', 'POST', [], ['name' => 'Posted Name', 'id' => '777', 'sort' => 'from_body']);

        static::__assert_false(array_key_exists('name', $answer['params']), 'a body field is not a parameter');
        static::__assert_equals('123', $answer['params']['id'], 'a body field never overrides a route parameter');
        static::__assert_equals('asc', $answer['params']['sort'], 'nor a query parameter');
        static::__assert_equals('Posted Name', $answer['body_name'], 'the handler reads the body from the Request');
    }

    public static function test_array_and_empty_query_values()
    {
        $params = static::__dispatch('/_test/params/1?tags[]=a&tags[]=b&filter[status]=active&q=')['params'];

        static::__assert_equals(['a', 'b'], $params['tags']);
        static::__assert_equals(['status' => 'active'], $params['filter']);
        static::__assert_true(array_key_exists('q', $params), 'an empty query value is present');
        static::__assert_true($params['q'] === '' || $params['q'] === null, 'and empty');
    }

    public static function test_parameter_names_are_case_sensitive()
    {
        $params = static::__dispatch('/_test/params/1?ID=2&Sort=up')['params'];

        static::__assert_equals('1', $params['id']);
        static::__assert_equals('2', $params['ID'], 'ID is not id');
        static::__assert_equals('up', $params['Sort']);
        static::__assert_false(array_key_exists('sort', $params));
    }

    public static function test_the_synthetic_keys_are_always_set()
    {
        $params = static::__dispatch('/_test/params/9')['params'];

        static::__assert_equals('GET', $params['_method']);
        static::__assert_equals('/_test/params/:id', $params['_route']);
        static::__assert_equals(Route_Params_Fixture_Controller::class, $params['_handler']);

        static::__assert_equals('POST', static::__dispatch('/_test/params/9', 'POST')['params']['_method']);
    }

    public static function test_an_absent_optional_route_parameter_is_absent()
    {
        $params = static::__dispatch('/_test/params/5/optional')['params'];

        static::__assert_equals('5', $params['id']);
        static::__assert_false(array_key_exists('extra', $params), 'not set, not an empty string');

        static::__assert_equals('x', static::__dispatch('/_test/params/5/optional/x')['params']['extra']);
    }
}
