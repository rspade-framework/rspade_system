<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Request_Channel::classify() - the one decision about what kind of request this is.
 *
 * Pure: a synthetic request in, a channel and a realm out. The portal's two deployment
 * shapes are driven through config: a path prefix on the staff host, and a dedicated
 * domain. No DB.
 */
class Request_Channel_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const PORTAL_DOMAIN = 'portal.request-channel-test.invalid';

    public static function teardown()
    {
        Rsx_Request_Channel::reset();
    }

    /**
     * Classify one request and return [channel, realm, portal_host].
     */
    private static function __classify(string $url, string $method = 'GET'): array
    {
        $channel = Rsx_Request_Channel::classify(Request::create($url, $method));

        return [$channel, Rsx_Request_Channel::realm(), Rsx_Request_Channel::is_portal_host()];
    }

    /**
     * Run $fn with the portal on a dedicated domain.
     */
    private static function __with_portal_domain(callable $fn): void
    {
        $original = config('rsx.portal.domain');
        config(['rsx.portal.domain' => self::PORTAL_DOMAIN]);

        try {
            $fn();
        } finally {
            config(['rsx.portal.domain' => $original]);
        }
    }

    public static function test_the_staff_host_channels()
    {
        static::__assert_equals(['asset', 'staff', false], static::__classify('/_compiled/Frontend_Bundle__app.0123abcd.js'));
        static::__assert_equals(['asset', 'staff', false], static::__classify('/_vendor/0123456789abcdef0123456789abcdef_x.woff2'));
        static::__assert_equals(['api', 'staff', false], static::__classify('/api/v1/me'));
        static::__assert_equals(['ajax', 'staff', false], static::__classify('/_ajax/Some_Controller/action', 'POST'));
        static::__assert_equals(['page', 'staff', false], static::__classify('/clients'));
    }

    /**
     * A name the artifact store could never have produced is not an artifact: it is an
     * ordinary page path (and a 404 page).
     */
    public static function test_a_malformed_artifact_name_is_a_page()
    {
        static::__assert_equals('page', static::__classify('/_compiled/../../.env')[0]);
        static::__assert_equals('page', static::__classify('/_vendor/bootstrap.css')[0]);
    }

    /**
     * Ajax is a POST transport; a GET under /_ajax/ is a page request (it 404s).
     */
    public static function test_a_get_under_ajax_is_a_page()
    {
        static::__assert_equals('page', static::__classify('/_ajax/Some_Controller/action')[0]);
    }

    public static function test_the_portal_prefix_is_the_portal_realm()
    {
        $prefix = Rsx_Portal::get_prefix();

        static::__assert_equals(['page', 'portal', false], static::__classify($prefix));
        static::__assert_equals(['page', 'portal', false], static::__classify($prefix . '/dashboard'));
        static::__assert_equals(['ajax', 'portal', false], static::__classify($prefix . '/_ajax/Some_Controller/action', 'POST'));
        static::__assert_true(Rsx_Portal::is_portal_request(), 'the portal predicate reads the classification');

        // A build artifact under the prefix is the same file as without it.
        static::__assert_equals(['asset', 'portal', false], static::__classify($prefix . '/_compiled/Portal_Bundle__app.0123abcd.js'));
        static::__assert_equals('/_compiled/Portal_Bundle__app.0123abcd.js', Rsx_Request_Channel::realm_path());

        // A path that merely starts with the same letters is not under the prefix.
        static::__assert_equals(['page', 'staff', false], static::__classify($prefix . 'x/dashboard'));
        static::__assert_false(Rsx_Portal::is_portal_request());

        // Under a path prefix, /api/ on the staff host is the API; the prefixed spelling
        // is a portal page like any other.
        static::__assert_equals(['api', 'staff', false], static::__classify('/api/v1/me'));
        static::__assert_equals(['page', 'portal', false], static::__classify($prefix . '/api/v1/me'));
    }

    public static function test_a_dedicated_domain_is_the_portal_realm()
    {
        static::__with_portal_domain(function () {
            $base = 'http://' . self::PORTAL_DOMAIN;

            static::__assert_equals(['page', 'portal', true], static::__classify($base . '/dashboard'));
            static::__assert_equals(['ajax', 'portal', true], static::__classify($base . '/_ajax/Some_Controller/action', 'POST'));
            static::__assert_equals(['asset', 'portal', true], static::__classify($base . '/_compiled/Portal_Bundle__app.0123abcd.js'));

            // The API is classified as the API everywhere - a staff identity - and the
            // portal-host fact is what makes it refuse there.
            static::__assert_equals(['api', 'staff', true], static::__classify($base . '/api/v1/me'));

            // The staff host is not the portal, and the prefix means nothing in domain mode.
            static::__assert_equals(['page', 'staff', false], static::__classify('http://localhost/dashboard'));
            static::__assert_equals(['page', 'staff', false], static::__classify('http://localhost' . Rsx_Portal::URL_PREFIX . '/dashboard'));
        });
    }

    /**
     * Outside a request, nothing classified: a staff page, and set_portal_request() is the
     * seam that declares otherwise.
     */
    public static function test_outside_a_request_the_answer_is_staff_until_declared()
    {
        Rsx_Request_Channel::reset();
        static::__assert_equals('page', Rsx_Request_Channel::current());
        static::__assert_false(Rsx_Portal::is_portal_request());

        Rsx_Portal::set_portal_request(true);
        static::__assert_true(Rsx_Portal::is_portal_request());

        Rsx_Portal::set_portal_request(false);
        static::__assert_false(Rsx_Portal::is_portal_request());
    }
}
