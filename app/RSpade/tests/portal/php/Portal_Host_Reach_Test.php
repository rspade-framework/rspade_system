<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Portal\Php;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api_Bearer;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Csp\Rsx_Csp;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Rsx_Csrf;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The framework's file and report endpoints answer in the PORTAL realm, wherever PORTAL_URL
 * puts the portal: the #[Portal_Route] twins exist on the same handlers as the staff rows, a
 * portal-realm request for them classifies and resolves in the portal table (under the
 * prefix on the application host, and anywhere on a portal host of its own), and every URL
 * the framework builds for a portal page points there (Rsx_Portal::internal_url(), the CSP
 * report-uri).
 *
 * Layouts are driven through config('rsx.portal.url') overrides and synthetic requests, as
 * Portal_Url_Test does; the request channel is reset in teardown.
 */
class Portal_Host_Reach_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

    private const PORTAL_HOST = 'portal.example.test';

    /**
     * Every framework endpoint a portal page uses, by pattern, with the one handler both
     * route tables must name.
     */
    private const TWINS = [
        '/_upload' => 'File_Attachment_Controller::upload',
        '/_icon_by_extension/:extension' => 'File_Attachment_Controller::icon_by_extension',
        '/_download/:key' => 'File_Attachment_Controller::download_file',
        '/_inline/:key' => 'File_Attachment_Controller::inline',
        '/_download_zip/:key' => 'File_Attachment_Controller::download_multiple_zip',
        '/_thumbnail/preset/:key/:preset_name' => 'File_Attachment_Controller::thumbnail_preset',
        '/_thumbnail/dynamic/:key/:type/:width/:height?' => 'File_Attachment_Controller::thumbnail',
        '/_preview/pdf/:key' => 'File_Preview_Controller::pdf_rendition',
        '/_preview/sheet/:key' => 'File_Preview_Controller::sheet_rendition',
        '/_preview/pdfjs.mjs' => 'File_Preview_Controller::pdfjs',
        '/_preview/pdf_worker.mjs' => 'File_Preview_Controller::pdf_worker',
        '/_csp-report' => 'Csp_Report_Controller::report',
    ];

    private static $saved_portal_url;

    public static function setup()
    {
        self::$saved_portal_url = config('rsx.portal.url');
    }

    public static function teardown()
    {
        config(['rsx.portal.url' => self::$saved_portal_url]);
        Rsx_Request_Channel::reset();
    }

    private static function __app_origin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Classify a request, then resolve its realm path in its realm's table. Returns
     * [realm, realm_path, 'Class::method' or null].
     */
    private static function __reach(string $url, string $method = 'GET'): array
    {
        Rsx_Request_Channel::classify(Request::create($url, $method));

        $realm = Rsx_Request_Channel::realm();
        $realm_path = Rsx_Request_Channel::realm_path();

        $route = Dispatcher::resolve_url_to_route(
            $realm_path,
            $method,
            $realm === Rsx_Request_Channel::REALM_PORTAL ? Auth_Gates::REALM_PORTAL : Auth_Gates::REALM_STAFF
        );

        $handler = $route === null ? null : class_basename((string) $route['class']) . '::' . $route['method'];

        return [$realm, $realm_path, $handler];
    }

    // =========================================================================
    // The twins exist
    // =========================================================================

    public static function test_every_framework_endpoint_is_in_both_route_tables_on_one_handler()
    {
        $staff = Manifest::get_routes();
        $portal = Manifest::get_full_manifest()['data']['portal_routes'] ?? [];

        foreach (self::TWINS as $pattern => $handler) {
            static::__assert_array_has_key($pattern, $staff, "{$pattern} is a staff route");
            static::__assert_array_has_key($pattern, $portal, "{$pattern} is a portal route");

            static::__assert_equals($handler, class_basename((string) $staff[$pattern]['class']) . '::' . $staff[$pattern]['method']);
            static::__assert_equals($handler, class_basename((string) $portal[$pattern]['class']) . '::' . $portal[$pattern]['method'], "{$pattern}: the portal row names the same handler");
            static::__assert_equals($staff[$pattern]['methods'] ?? ['GET'], $portal[$pattern]['methods'] ?? ['GET'], "{$pattern}: the same verbs");
        }
    }

    // =========================================================================
    // A portal-realm request reaches them
    // =========================================================================

    public static function test_under_the_default_prefix_they_are_portal_requests()
    {
        config(['rsx.portal.url' => '']);
        $app = static::__app_origin();

        static::__assert_equals(
            ['portal', '/_thumbnail/dynamic/k/fit/100', 'File_Attachment_Controller::thumbnail'],
            static::__reach($app . '/_portal/_thumbnail/dynamic/k/fit/100?v=3')
        );
        static::__assert_equals(['portal', '/_inline/k', 'File_Attachment_Controller::inline'], static::__reach($app . '/_portal/_inline/k'));
        static::__assert_equals(['portal', '/_preview/pdf/k', 'File_Preview_Controller::pdf_rendition'], static::__reach($app . '/_portal/_preview/pdf/k'));
        static::__assert_equals(['portal', '/_csp-report', 'Csp_Report_Controller::report'], static::__reach($app . '/_portal/_csp-report', 'POST'));

        // The bare path on the application host stays the staff route.
        static::__assert_equals(['staff', '/_inline/k', 'File_Attachment_Controller::inline'], static::__reach($app . '/_inline/k'));
    }

    public static function test_on_a_portal_host_of_its_own_they_are_portal_requests()
    {
        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/']);
        $portal = 'https://' . self::PORTAL_HOST;

        static::__assert_equals(
            ['portal', '/_thumbnail/preset/k/profile', 'File_Attachment_Controller::thumbnail_preset'],
            static::__reach($portal . '/_thumbnail/preset/k/profile')
        );
        static::__assert_equals(['portal', '/_download/k', 'File_Attachment_Controller::download_file'], static::__reach($portal . '/_download/k'));
        static::__assert_equals(['portal', '/_download_zip/k', 'File_Attachment_Controller::download_multiple_zip'], static::__reach($portal . '/_download_zip/k'));
        static::__assert_equals(['portal', '/_preview/pdfjs.mjs', 'File_Preview_Controller::pdfjs'], static::__reach($portal . '/_preview/pdfjs.mjs'));
        static::__assert_equals(['portal', '/_csp-report', 'Csp_Report_Controller::report'], static::__reach($portal . '/_csp-report', 'POST'));
    }

    public static function test_on_a_portal_host_with_a_prefix_they_are_under_it()
    {
        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/x']);
        $portal = 'https://' . self::PORTAL_HOST;

        static::__assert_equals(['portal', '/_inline/k', 'File_Attachment_Controller::inline'], static::__reach($portal . '/x/_inline/k'));
        static::__assert_equals(['portal', '/_csp-report', 'Csp_Report_Controller::report'], static::__reach($portal . '/x/_csp-report', 'POST'));
    }

    // =========================================================================
    // The URLs a portal page is handed point there
    // =========================================================================

    public static function test_internal_url_follows_the_realm_of_the_request()
    {
        config(['rsx.portal.url' => '']);
        $app = static::__app_origin();

        $attachment = new File_Attachment_Model();
        $attachment->key = 'k';

        Rsx_Request_Channel::classify(Request::create($app . '/dashboard'));
        static::__assert_equals('/_inline/k', Rsx_Portal::internal_url('/_inline/k'), 'a staff request is unchanged');
        static::__assert_equals('/_inline/k', $attachment->get_url());
        static::__assert_equals('/_download/k', $attachment->get_download_url());

        Rsx_Request_Channel::classify(Request::create($app . '/_portal/documents'));
        static::__assert_equals('/_portal/_inline/k', Rsx_Portal::internal_url('/_inline/k'), 'a portal request is under the prefix');
        static::__assert_equals('/_portal/_inline/k', $attachment->get_url());
        static::__assert_equals('/_portal/_download/k', $attachment->get_download_url());

        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/']);
        Rsx_Request_Channel::classify(Request::create('https://' . self::PORTAL_HOST . '/documents'));
        static::__assert_equals('/_inline/k', $attachment->get_url(), 'at the root of its own host there is no prefix');

        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/x']);
        Rsx_Request_Channel::classify(Request::create('https://' . self::PORTAL_HOST . '/x/documents'));
        static::__assert_equals('/x/_download/k', $attachment->get_download_url());
    }

    public static function test_the_csp_report_uri_names_the_realms_own_collector()
    {
        config(['rsx.portal.url' => '']);

        static::__assert_equals('/_csp-report', Rsx_Csp::report_path('staff'));
        static::__assert_equals('/_portal/_csp-report', Rsx_Csp::report_path('portal'));

        $policy = Rsx_Csp::compose('portal');
        if ($policy !== null) {
            static::__assert_contains('report-uri /_portal/_csp-report', $policy['value']);
        }

        config(['rsx.portal.url' => 'https://' . self::PORTAL_HOST . '/']);
        static::__assert_equals('/_csp-report', Rsx_Csp::report_path('portal'), 'at the root of its own host');
    }

    public static function test_the_portal_collector_is_exempt_from_csrf_and_nothing_else_is()
    {
        config(['rsx.portal.url' => '']);
        $foreign = ['HTTP_ORIGIN' => 'https://elsewhere.example'];

        // A browser's report carries whatever Origin it carries, and no token.
        Rsx_Csrf::enforce(Request::create(static::__app_origin() . '/_portal/_csp-report', 'POST', [], [], [], $foreign));
        Rsx_Csrf::enforce(Request::create(static::__app_origin() . '/_csp-report', 'POST', [], [], [], $foreign));

        static::__assert_throws(HttpResponseException::class, function () use ($foreign) {
            Rsx_Csrf::enforce(Request::create(static::__app_origin() . '/_portal/_upload', 'POST', [], [], [], $foreign));
        });
    }

    public static function test_an_api_key_is_refused_in_the_portal_realm()
    {
        config(['rsx.portal.url' => '']);
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer rsx_not_a_real_key'];

        $request = Request::create(static::__app_origin() . '/_portal/_inline/k', 'GET', [], [], [], $bearer);
        Rsx_Request_Channel::classify($request);
        $response = Rsx_Api_Bearer::authenticate_web_request($request);

        static::__assert_not_null($response, 'a key on a portal-realm file route is answered');
        static::__assert_equals(404, $response->getStatusCode(), 'the API does not exist on the portal');
        static::__assert_contains('not_found', (string) $response->getContent());

        // Without a key the portal request is untouched: its gates decide.
        $request = Request::create(static::__app_origin() . '/_portal/_inline/k');
        Rsx_Request_Channel::classify($request);
        static::__assert_null(Rsx_Api_Bearer::authenticate_web_request($request));
    }
}
