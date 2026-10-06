<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Openapi;
use App\RSpade\Core\Api\Api_Route_Usage;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Api\Php\Api_Main_Hook_Probe;

/**
 * A two-version resource end to end, on the real manifest: the v1 fixture controller
 * (Api_Versioning_Fixture_Api_Controller - #[Api_Versions(through: 2)], one endpoint revised
 * in v2 with through: 1, one @api-deprecated) beside its v2 sibling
 * (Api_Versioning_Fixture_V2_Api_Controller), both #[Api_Resource('Versioning_Probe')].
 *
 * Covers what the scan-level tests cannot: that a range's rows route for real, that the
 * catalogue lists the two controllers as one resource at each version, that OpenAPI marks
 * exactly the superseded and the declared-deprecated operations, narrows to one version,
 * and that a routed call is recorded in _api_route_usage at most once a minute.
 */
class Api_Versioning_Test extends Rsx_Test_Abstract
{
    private const BASE = '/api/v%d/test-probe/versioning';

    private static function __path(int $version, string $rest = ''): string
    {
        return sprintf(self::BASE, $version) . $rest;
    }

    /** The probe resource's endpoints at $version, as 'pattern' => 'Class::method'. */
    private static function __probe_at(int $version): array
    {
        $group = Api_Catalog::resolve_for_version($version)['Versioning_Probe'] ?? null;
        static::__assert_not_null($group, "the probe resource is listed at v{$version}");

        $out = [];
        foreach ($group['endpoints'] as $endpoint) {
            $out[$endpoint['pattern']] = $endpoint['class'] . '::' . $endpoint['method'];
        }
        ksort($out);

        return $out;
    }

    /**
     * api-ver-01 - the catalogue lists both controllers as ONE resource, and each version
     * shows the range's row at that version's own path, the revision where there is one.
     */
    public static function test_catalogue_groups_both_controllers_per_version()
    {
        static::__assert_true(in_array(2, Api_Catalog::get_versions(), true), 'v2 exists');

        $v1 = static::__probe_at(1);
        static::__assert_equals([
            static::__path(1, '/items') => 'Api_Versioning_Fixture_Api_Controller::list',
            static::__path(1, '/items/:id') => 'Api_Versioning_Fixture_Api_Controller::get',
            static::__path(1, '/old') => 'Api_Versioning_Fixture_Api_Controller::old',
        ], $v1, 'v1 is the v1 controller throughout');

        $v2 = static::__probe_at(2);
        static::__assert_equals([
            static::__path(1, '/old') => 'Api_Versioning_Fixture_Api_Controller::old',
            static::__path(2, '/items') => 'Api_Versioning_Fixture_Api_Controller::list',
            static::__path(2, '/items/:id') => 'Api_Versioning_Fixture_V2_Api_Controller::get',
        ], $v2, 'v2: the ranged list at its v2 path, the revision, the unranged endpoint at v1');

        static::__assert_equals('Versioning Probe', Api_Catalog::resolve_for_version(2)['Versioning_Probe']['name']);
        static::__assert_false(isset(Api_Catalog::resolve_for_version(2)['Api_Versioning_Fixture_V2']), 'no second group for the v2 class');
    }

    /**
     * api-ver-02 - the full OpenAPI document marks deprecated exactly the operation another
     * handler supersedes and the one carrying @api-deprecated (with its note); a range's v1
     * row is not superseded by its own v2 row. Both controllers share one tag.
     */
    public static function test_openapi_deprecation_and_tags()
    {
        $paths = Api_Openapi::document()['paths'];

        $op = fn (string $url) => $paths[$url]['get'] ?? null;

        static::__assert_false(isset($op(static::__path(1, '/items'))['deprecated']), 'a range is not its own successor');
        static::__assert_false(isset($op(static::__path(2, '/items'))['deprecated']));
        static::__assert_true($op(static::__path(1, '/items/{id}'))['deprecated'] ?? false, 'superseded by the v2 handler');
        static::__assert_false(isset($op(static::__path(2, '/items/{id}'))['deprecated']));

        $old = $op(static::__path(1, '/old'));
        static::__assert_true($old['deprecated'] ?? false, '@api-deprecated with no successor');
        static::__assert_contains('Deprecated: Use GET /api/v2/test-probe/versioning/items instead.', $old['description'] ?? '', 'the note is in the description');

        static::__assert_equals(['Versioning_Probe'], $op(static::__path(1, '/items'))['tags']);
        static::__assert_equals(['Versioning_Probe'], $op(static::__path(2, '/items/{id}'))['tags'], 'one tag across both controllers');
    }

    /**
     * api-ver-03 - document($version) is that version's surface as the catalogue resolves it,
     * info.version names it, and an unknown version throws.
     */
    public static function test_openapi_narrows_to_one_version()
    {
        $doc = Api_Openapi::document(null, 2);
        static::__assert_equals('v2', $doc['info']['version']);

        $urls = array_keys($doc['paths']);
        static::__assert_true(in_array(static::__path(2, '/items'), $urls, true), 'the v2 row of the range');
        static::__assert_true(in_array(static::__path(2, '/items/{id}'), $urls, true), 'the revision');
        static::__assert_true(in_array(static::__path(1, '/old'), $urls, true), 'the unrevised v1 endpoint at its v1 path');
        static::__assert_false(in_array(static::__path(1, '/items'), $urls, true), 'not the v1 row a v2 row replaces');
        static::__assert_false(in_array(static::__path(1, '/items/{id}'), $urls, true), 'not the superseded v1 endpoint');

        static::__assert_throws(\InvalidArgumentException::class, fn () => Api_Openapi::document(null, 99), 'No API version 99');
    }

    /**
     * api-ver-04 - a range's later row routes to the same handler, and the call is recorded
     * in _api_route_usage by its pattern and version.
     */
    public static function test_a_ranged_row_routes_and_records_usage()
    {
        DB::table('_api_route_usage')->delete();

        // API access is per membership and off by default (Session::has_api_access()).
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->is_api_access_enabled = 1;
        $user->save();
        $key = Api_Key_Model::generate(1, 'versioning probe ' . uniqid())['key'];

        $url = static::__path(2, '/items');
        $request = Request::create($url, 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        // The application's own Main::pre_dispatch() is its account policy, not this test's
        // subject: Api_Main_Hook_Probe (which admits every call) stands in for one dispatch.
        $index = &Manifest::$data['data']['php_subclass_index'];
        $original = $index['Main_Abstract'] ?? null;
        $index['Main_Abstract'] = ['Api_Main_Hook_Probe'];
        Api_Main_Hook_Probe::$refuse_with = null;
        try {
            $response = Api_Dispatcher::dispatch($url, 'GET', [], $request);
        } finally {
            if ($original === null) {
                unset($index['Main_Abstract']);
            } else {
                $index['Main_Abstract'] = $original;
            }
        }

        static::__assert_equals(200, $response->getStatusCode(), (string) $response->getContent());
        static::__assert_contains('"handler":"list"', (string) $response->getContent(), 'the v1 handler answers at v2');

        $usage = Api_Route_Usage::last_called_for_version(2);
        static::__assert_equals([$url], array_column($usage, 'pattern'), 'the pattern is recorded under v2');
        static::__assert_equals([], Api_Route_Usage::last_called_for_version(7), 'an unused version has no rows');
    }

    /**
     * api-ver-05 - record() writes a pattern once, leaves it alone inside a minute, and
     * refreshes it once the stored time is more than a minute old.
     */
    public static function test_usage_is_written_at_most_once_a_minute()
    {
        DB::table('_api_route_usage')->delete();
        $pattern = static::__path(1, '/items');

        Api_Route_Usage::record($pattern);
        $first = DB::table('_api_route_usage')->where('pattern', $pattern)->first();
        static::__assert_not_null($first, 'the first call inserts');
        static::__assert_equals(1, (int) $first->version);

        DB::table('_api_route_usage')->where('pattern', $pattern)->update(['last_called_at' => DB::raw('NOW(3) - INTERVAL 30 SECOND')]);
        $held = DB::table('_api_route_usage')->where('pattern', $pattern)->value('last_called_at');
        Api_Route_Usage::record($pattern);
        static::__assert_equals($held, DB::table('_api_route_usage')->where('pattern', $pattern)->value('last_called_at'), 'inside the minute nothing changes');

        DB::table('_api_route_usage')->where('pattern', $pattern)->update(['last_called_at' => DB::raw('NOW(3) - INTERVAL 2 MINUTE')]);
        $aged = DB::table('_api_route_usage')->where('pattern', $pattern)->value('last_called_at');
        Api_Route_Usage::record($pattern);
        $refreshed = DB::table('_api_route_usage')->where('pattern', $pattern)->value('last_called_at');
        static::__assert_greater_than(strtotime($aged), strtotime($refreshed), 'past the minute it is refreshed');

        static::__assert_equals(1, DB::table('_api_route_usage')->where('pattern', $pattern)->count(), 'one row per pattern');
    }
}
