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
use App\RSpade\Core\Api\Api_Endpoint_ManifestSupport;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Openapi;
use App\RSpade\Core\Api\Api_Route_Usage;
use App\RSpade\Core\Api\Api_Scopes;
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

    /** The framework's five endpoints, by verb and path key. */
    private const FRAMEWORK_ROUTES = [
        'get /me' => 'Identity_Api_Controller::me',
        'post /files' => 'Files_Api_Controller::upload',
        'get /files/{key}' => 'Files_Api_Controller::info',
        'get /files/{key}/text' => 'Files_Api_Controller::text',
        'get /openapi.json' => 'Openapi_Api_Controller::document',
    ];

    /**
     * Dispatch one GET as the holder of a fresh key (unrestricted unless $scopes), with
     * Api_Main_Hook_Probe standing in as the application's Main for that dispatch (see
     * api-ver-04), refusing with $refuse_with when it is non-null.
     */
    private static function __get_as_key_holder(string $url, ?string $scopes = null, $refuse_with = null)
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->is_api_access_enabled = 1;
        $user->save();
        $key = Api_Key_Model::generate(1, 'versioning probe ' . uniqid(), 'live', null, null, $scopes)['key'];

        return static::__dispatch_get($url, $key, $refuse_with);
    }

    /**
     * Dispatch one GET carrying $key (none when null), with Api_Main_Hook_Probe standing in
     * as the application's Main, refusing with $refuse_with when it is non-null.
     */
    private static function __dispatch_get(string $url, ?string $key, $refuse_with = null)
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1'];
        if ($key !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        }
        $request = Request::create($url, 'GET', [], [], [], $server);

        $index = &Manifest::$data['data']['php_subclass_index'];
        $original = $index['Main_Abstract'] ?? null;
        $index['Main_Abstract'] = ['Api_Main_Hook_Probe'];
        Api_Main_Hook_Probe::$refuse_with = $refuse_with;
        try {
            return Api_Dispatcher::dispatch($url, 'GET', [], $request);
        } finally {
            Api_Main_Hook_Probe::$refuse_with = null;
            if ($original === null) {
                unset($index['Main_Abstract']);
            } else {
                $index['Main_Abstract'] = $original;
            }
        }
    }

    /**
     * api-ver-08 - GET /api/vN/openapi.json answers, at every version the application serves,
     * THAT version's document exactly (Api_Openapi::document(null, N)): 200, application/json,
     * none of the timing fields every other answer carries, and listing its own route.
     */
    public static function test_the_openapi_route_answers_each_versions_document_unstamped()
    {
        foreach (Api_Catalog::get_versions() as $version) {
            $url = "/api/v{$version}/openapi.json";
            $response = static::__get_as_key_holder($url);

            static::__assert_equals(200, $response->getStatusCode(), (string) $response->getContent());
            static::__assert_contains('application/json', (string) $response->headers->get('Content-Type'), "{$url} is JSON");

            $body = json_decode((string) $response->getContent(), true);
            $expected = json_decode(json_encode(Api_Openapi::document(null, $version), JSON_UNESCAPED_SLASHES), true);
            static::__assert_equals($expected, $body, "{$url} is v{$version}'s document exactly");
            static::__assert_equals("v{$version}", $body['info']['version']);
            static::__assert_false(array_key_exists('_duration_ms', $body), "{$url} is unstamped (_duration_ms)");
            static::__assert_false(array_key_exists('_completed_at', $body), "{$url} is unstamped (_completed_at)");
            static::__assert_not_null($body['paths'][$url]['get'] ?? null, "{$url} lists itself");
        }

        static::__assert_true(count(Api_Catalog::get_versions()) >= 2, 'more than one version was exercised');
    }

    /**
     * api-ver-09 - the document's gate is /me's: no key is 401, a key whose user lacks API
     * access (users.is_api_access_enabled) is the uniform 401, and the application's account
     * policy (Main::pre_dispatch) can still refuse it (403 account_refused).
     */
    public static function test_the_openapi_route_refuses_without_api_access()
    {
        $no_key = static::__dispatch_get('/api/v1/openapi.json', null);
        static::__assert_equals(401, $no_key->getStatusCode(), (string) $no_key->getContent());

        $key = Api_Key_Model::generate(1, 'versioning probe ' . uniqid())['key'];
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $user->is_api_access_enabled = 0;
        $user->save();
        try {
            $disabled = static::__dispatch_get('/api/v1/openapi.json', $key);
        } finally {
            $user->is_api_access_enabled = 1;
            $user->save();
        }
        static::__assert_equals(401, $disabled->getStatusCode(), (string) $disabled->getContent());

        $refused = static::__get_as_key_holder('/api/v1/openapi.json', null, 'api_access_not_granted');
        static::__assert_equals(403, $refused->getStatusCode(), (string) $refused->getContent());
        static::__assert_equals('account_refused', json_decode((string) $refused->getContent(), true)['error']['code']);
    }

    /**
     * api-ver-10 - key scopes never refuse the document: a key scoped to one resource reads
     * every version's document (while it is still refused /me), and Api_Scopes reports the
     * route reachable for any scope set.
     */
    public static function test_a_scoped_key_reads_the_openapi_document()
    {
        $scopes = static::__path(1, '/items');

        foreach (Api_Catalog::get_versions() as $version) {
            $response = static::__get_as_key_holder("/api/v{$version}/openapi.json", $scopes);
            static::__assert_equals(200, $response->getStatusCode(), (string) $response->getContent());
        }

        $me = static::__get_as_key_holder('/api/v1/me', $scopes);
        static::__assert_equals(403, $me->getStatusCode(), 'the same key is still scoped everywhere else');
        static::__assert_equals('insufficient_scope', json_decode((string) $me->getContent(), true)['error']['code']);

        static::__assert_true(Api_Scopes::decide($scopes, '/api/v2/openapi.json'), 'decide() grants the document');
        static::__assert_true(Api_Scopes::reaches_route($scopes, '/api/v1/openapi.json'), 'reaches_route() lists the document');
        static::__assert_false(Api_Scopes::decide($scopes, '/api/v1/openapi.json/x'), 'only the document path itself');
        static::__assert_false(Api_Scopes::decide($scopes, '/api/v1/x/openapi.json'), 'only at the version root');
    }

    /**
     * api-ver-06 - the framework's identity and file endpoints follow the application to v2
     * (#[Api_Versions(every: true)]; the suite's fixture makes v2 a served version): the v2
     * OpenAPI document lists all five at /api/v2/... and none at /api/v1/..., each by the same
     * handler, and GET /api/v2/me answers as GET /api/v1/me does.
     */
    public static function test_the_framework_endpoints_are_served_at_every_version()
    {
        $paths = Api_Openapi::document(null, 2)['paths'];

        foreach (self::FRAMEWORK_ROUTES as $route => $handler) {
            [$verb, $path_key] = explode(' ', $route);
            $operation = $paths['/api/v2' . $path_key][$verb] ?? null;
            static::__assert_not_null($operation, "{$route} is in the v2 document at /api/v2");
            static::__assert_false(isset($paths['/api/v1' . $path_key]), "{$route} is not in the v2 document at /api/v1");
            static::__assert_false($operation['deprecated'] ?? false, "{$route} at v2 is not deprecated");

            foreach ([1, 2] as $version) {
                $pattern = '/api/v' . $version . str_replace('{key}', ':key', $path_key);
                $row = Manifest::get_routes()[$pattern] ?? null;
                static::__assert_not_null($row, "{$pattern} routes");
                static::__assert_equals($handler, $row['surface'], "{$pattern} is one handler");
            }
        }

        $v1 = static::__get_as_key_holder('/api/v1/me');
        $v2 = static::__get_as_key_holder('/api/v2/me');
        static::__assert_equals(200, $v2->getStatusCode(), (string) $v2->getContent());
        $strip = fn ($response) => array_diff_key(json_decode((string) $response->getContent(), true), array_flip(['_duration_ms', '_completed_at', 'key']));
        static::__assert_equals($strip($v1), $strip($v2), 'the same identity payload at v1 and v2');
    }

    /**
     * api-ver-07 - an application that serves only v2 retires the framework endpoints' v1
     * address too: the real manifest support rebuilt over the real Identity controller beside
     * the v2 fixture serves /api/v2/me alone, and the dispatcher answers /api/v1/me 410
     * api_version_retired naming v2.
     */
    public static function test_a_version_the_application_retires_retires_the_framework_endpoints()
    {
        $files = [];
        foreach (Manifest::$data['data']['files'] as $file => $metadata) {
            if (in_array($metadata['class'] ?? null, ['Identity_Api_Controller', 'Api_Versioning_Fixture_V2_Api_Controller'], true)) {
                $files[$file] = $metadata;
            }
        }
        static::__assert_count(2, $files, 'both controllers are in the manifest');

        $synthetic = ['data' => [
            'files' => $files,
            'routes' => Manifest::$data['data']['routes'],
            'api_endpoints' => [],
            'php_subclass_index' => Manifest::$data['data']['php_subclass_index'],
        ]];
        Api_Endpoint_ManifestSupport::process($synthetic, array_keys($files), []);

        static::__assert_array_has_key('/api/v2/me', $synthetic['data']['api_endpoints']);
        static::__assert_false(isset($synthetic['data']['api_endpoints']['/api/v1/me']), 'no v1 row');

        $original_routes = Manifest::$data['data']['routes'];
        $original_endpoints = Manifest::$data['data']['api_endpoints'];
        Manifest::$data['data']['routes'] = $synthetic['data']['routes'];
        Manifest::$data['data']['api_endpoints'] = $synthetic['data']['api_endpoints'];
        try {
            $retired = static::__get_as_key_holder('/api/v1/me');
            $served = static::__get_as_key_holder('/api/v2/me');
        } finally {
            Manifest::$data['data']['routes'] = $original_routes;
            Manifest::$data['data']['api_endpoints'] = $original_endpoints;
        }

        static::__assert_equals(410, $retired->getStatusCode(), (string) $retired->getContent());
        $error = json_decode((string) $retired->getContent(), true)['error'];
        static::__assert_equals('api_version_retired', $error['code']);
        static::__assert_equals(['v2'], $error['versions']);
        static::__assert_equals(200, $served->getStatusCode(), (string) $served->getContent());
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
