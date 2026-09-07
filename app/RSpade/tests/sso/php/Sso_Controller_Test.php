<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Sso\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Sso\Rsx_Sso_Controller;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Sso\Php\Fake_Sso_Provider;

/**
 * The HTTP surface's own decisions, as opposed to the facade's.
 *
 * Rsx_Sso_Controller is thin by design, so what is worth pinning here is precisely the
 * handful of judgements it makes BEFORE delegating - and every one of them is a security
 * judgement:
 *
 *  - AN UNKNOWN KEY AND A DISABLED ONE ARE THE SAME 404. "microsoft is configured here but
 *    switched off" is a fact about the install that a stranger walking /_sso/ URLs has no
 *    reason to collect, so the two answers are identical and neither says which it was.
 *  - A HALF-CONFIGURED PROVIDER IS NOT SWALLOWED BY THAT 404. The refusal is built on
 *    enabled_providers(), which THROWS when a provider is enabled with a credential missing.
 *    A 404 that quietly absorbed an operator's mistake would be the one failure nobody ever
 *    finds - the button would simply be gone.
 *  - intent=link IS REFUSED, NEVER DOWNGRADED. Turning it into a plain sign-in when the
 *    session expired would sign a user in as whoever owns the provider account, having asked
 *    for nothing of the sort.
 *  - THE APPLE POST LEG DOES NO WORK. Rsx_Csrf::enforce() exempts exactly one path on the
 *    written promise that this leg only 303s to the GET leg. These tests are what keeps that
 *    promise true: they pin that the POST branch resolves no provider, reads no session, and
 *    re-emits a WHITELIST of three parameters rather than passing anything through.
 *
 * The gates themselves are not testable from here - the dispatcher evaluates #[Auth], and
 * calling an endpoint as a static method is what happens AFTER a gate has passed. They are
 * covered over real HTTP in http/sso_http_surface.sh, for the same reason
 * two_factor/http/two_factor_endpoint_gates.sh exists.
 */
class Sso_Controller_Test extends Rsx_Test_Abstract
{
    /** Config keys these tests overwrite, restored in teardown. */
    private static array $config_backup = [];

    public static function setup()
    {
        parent::setup();

        self::__override('rsx.sso.custom', [
            'fake' => [
                'enabled' => true,
                'provider' => Fake_Sso_Provider::class,
                'label' => 'Fake Provider',
                'client_id' => 'FAKE-CLIENT',
                'client_secret' => 'FAKE-SECRET',
            ],
        ]);

        static::__anonymous();
    }

    public static function teardown()
    {
        foreach (self::$config_backup as $key => $value) {
            config()->set($key, $value);
        }

        self::$config_backup = [];

        static::__anonymous();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private static function __override(string $key, $value): void
    {
        if (!array_key_exists($key, self::$config_backup)) {
            self::$config_backup[$key] = config($key);
        }

        config()->set($key, $value);
    }

    /**
     * Back to a clean anonymous browser. setup() and teardown() are per-CLASS, so a test that
     * signs somebody in would otherwise hand the next one an authenticated session.
     */
    private static function __anonymous(): void
    {
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'sso_ctl_' . uniqid() . '@example.com';
        $login_user->password = Hash::make('correct-horse-battery-staple');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    // -------------------------------------------------------------------------
    // The begin route
    // -------------------------------------------------------------------------

    /**
     * The happy path: a live key produces the provider redirect, with the ceremony parked.
     */
    public static function test_begin_redirects_to_the_provider()
    {
        static::__anonymous();

        $response = Rsx_Sso_Controller::begin(Request::create('/_sso/fake/begin', 'GET'), ['provider' => 'fake']);

        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains(Fake_Sso_Provider::AUTHORIZE_URL, $response->getTargetUrl());

        $parked = Session::get_value(Rsx_Sso::STATE_KEY);

        static::__assert_not_null($parked, 'the ceremony was parked before the browser left');
        static::__assert_equals('fake', $parked['provider']);
        static::__assert_equals(Rsx_Sso::INTENT_LOGIN, $parked['intent'], 'no intent means a sign-in');
    }

    /**
     * An unknown key and a DISABLED key are indistinguishable from outside. 'google' is
     * present in the shipped config with enabled=false, which is exactly the case a stranger
     * would use to enumerate what an install has.
     */
    public static function test_unknown_and_disabled_keys_are_the_same_404()
    {
        static::__anonymous();

        $unknown = static::__assert_throws(HttpException::class, function () {
            Rsx_Sso_Controller::begin(Request::create('/_sso/nonesuch/begin', 'GET'), ['provider' => 'nonesuch']);
        });

        $disabled = static::__assert_throws(HttpException::class, function () {
            Rsx_Sso_Controller::begin(Request::create('/_sso/google/begin', 'GET'), ['provider' => 'google']);
        });

        static::__assert_equals(404, $unknown->getStatusCode());
        static::__assert_equals(404, $disabled->getStatusCode(), 'a disabled provider says nothing an unknown one does not');
        static::__assert_equals($unknown->getMessage(), $disabled->getMessage(), 'and neither says which it was');
    }

    /**
     * A missing route segment is a 404 and never a resolution of the empty string.
     */
    public static function test_a_missing_provider_segment_is_refused()
    {
        static::__anonymous();

        $e = static::__assert_throws(HttpException::class, function () {
            Rsx_Sso_Controller::begin(Request::create('/_sso//begin', 'GET'), []);
        });

        static::__assert_equals(404, $e->getStatusCode());
    }

    /**
     * THE MISCONFIGURATION IS NOT SWALLOWED. A provider enabled with a credential missing
     * throws through the 404 refusal naming the literal .env key, because an operator who set
     * SSO_GOOGLE_ENABLED=true and nothing else must hear about it - a silent 404 would leave
     * them staring at a login page with one fewer button and no explanation.
     */
    public static function test_a_half_configured_provider_throws_rather_than_404s()
    {
        static::__anonymous();

        self::__override('rsx.sso.providers.google.enabled', true);
        self::__override('rsx.sso.providers.google.client_id', 'GOOGLE-CLIENT');
        self::__override('rsx.sso.providers.google.client_secret', '');

        try {
            static::__assert_throws(
                RuntimeException::class,
                function () {
                    Rsx_Sso_Controller::begin(Request::create('/_sso/google/begin', 'GET'), ['provider' => 'google']);
                },
                'SSO_GOOGLE_CLIENT_SECRET'
            );
        } finally {
            config()->set('rsx.sso.providers.google.enabled', self::$config_backup['rsx.sso.providers.google.enabled']);
            config()->set('rsx.sso.providers.google.client_id', self::$config_backup['rsx.sso.providers.google.client_id']);
            config()->set('rsx.sso.providers.google.client_secret', self::$config_backup['rsx.sso.providers.google.client_secret']);
        }
    }

    /**
     * intent=link from an anonymous browser is REFUSED, not quietly turned into a sign-in.
     *
     * The downgrade would be the worst outcome of an expired session: a "Connect" button on a
     * settings page would sign the user in as whoever owns the provider account.
     */
    public static function test_link_intent_is_refused_when_nobody_is_signed_in()
    {
        static::__anonymous();

        $e = static::__assert_throws(HttpException::class, function () {
            Rsx_Sso_Controller::begin(
                Request::create('/_sso/fake/begin?intent=link', 'GET'),
                ['provider' => 'fake', 'intent' => 'link']
            );
        });

        static::__assert_equals(403, $e->getStatusCode());
        static::__assert_null(Session::get_value(Rsx_Sso::STATE_KEY), 'and nothing was parked');
    }

    /**
     * The same refusal while impersonating, BEFORE the browser leaves for the provider.
     * Attaching a sign-in provider to somebody else's account is an authentication backdoor
     * wearing a support tool's clothes.
     */
    public static function test_link_intent_is_refused_while_impersonating()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();
        $admin = static::__make_login_user();

        Session::set_login_user_id((int) $login_user->id);
        Session::cli_set_impersonator_login_user_id((int) $admin->id);

        try {
            $e = static::__assert_throws(HttpException::class, function () {
                Rsx_Sso_Controller::begin(
                    Request::create('/_sso/fake/begin?intent=link', 'GET'),
                    ['provider' => 'fake', 'intent' => 'link']
                );
            });

            static::__assert_equals(403, $e->getStatusCode());
            static::__assert_contains('impersonating', $e->getMessage());
        } finally {
            static::__anonymous();
        }
    }

    // -------------------------------------------------------------------------
    // The callback route - the Apple POST leg
    // -------------------------------------------------------------------------

    /**
     * THE PROMISE Rsx_Csrf's exemption IS WRITTEN ON: the POST leg 303s to the same path as a
     * GET, carrying the ceremony, and does nothing else.
     *
     * 303 and not 302 because the browser must switch verbs - the whole point is to arrive as
     * a top-level GET navigation, which is what carries the SameSite=Lax cookie holding the
     * parked state.
     */
    public static function test_the_apple_post_leg_redirects_to_the_get_leg()
    {
        static::__anonymous();

        $response = Rsx_Sso_Controller::callback(
            Request::create('/_sso/apple/callback', 'POST', [
                'code' => 'APPLE-CODE',
                'state' => 'APPLE-STATE',
                'user' => '{"name":{"firstName":"A"}}',
            ]),
            ['provider' => 'apple']
        );

        static::__assert_equals(303, $response->getStatusCode(), 'the browser must switch to GET');

        $target = $response->getTargetUrl();

        static::__assert_contains(Rsx_Sso::APPLE_CALLBACK_PATH . '?', $target);

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        static::__assert_equals('APPLE-CODE', $query['code']);
        static::__assert_equals('APPLE-STATE', $query['state']);
        static::__assert_equals('{"name":{"firstName":"A"}}', $query['user'], 'the one-shot profile blob survives');
    }

    /**
     * It re-emits a WHITELIST, not the request. Anything else a cross-site caller attached is
     * dropped, so the exemption cannot be used to smuggle a parameter into the GET leg.
     */
    public static function test_the_apple_post_leg_carries_only_the_ceremony()
    {
        static::__anonymous();

        $response = Rsx_Sso_Controller::callback(
            Request::create('/_sso/apple/callback', 'POST', [
                'code' => 'APPLE-CODE',
                'state' => 'APPLE-STATE',
                'intent' => 'link',
                'redirect' => 'https://evil.example.com',
                'fake_identity' => '{"id":"smuggled"}',
            ]),
            ['provider' => 'apple']
        );

        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

        static::__assert_equals(['code', 'state'], array_keys($query), 'exactly the ceremony, and nothing else');
    }

    /**
     * IT DOES NO WORK, and that includes resolving the provider: an unknown key still 303s,
     * and dies on the GET leg's 404 like any other. A resolution here would be work performed
     * for an unauthenticated cross-site caller.
     */
    public static function test_the_post_leg_resolves_no_provider_and_parks_nothing()
    {
        static::__anonymous();

        $response = Rsx_Sso_Controller::callback(
            Request::create('/_sso/nonesuch/callback', 'POST', ['code' => 'C', 'state' => 'S']),
            ['provider' => 'nonesuch']
        );

        static::__assert_equals(303, $response->getStatusCode(), 'no 404 - nothing was resolved');
        static::__assert_contains('/_sso/nonesuch/callback', $response->getTargetUrl());
        static::__assert_null(Session::get_value(Rsx_Sso::STATE_KEY), 'and no ceremony was touched');
    }

    /**
     * The route segment is re-encoded rather than trusted into a Location header.
     */
    public static function test_the_post_leg_encodes_the_route_segment()
    {
        static::__anonymous();

        $response = Rsx_Sso_Controller::callback(
            Request::create('/_sso/x/callback', 'POST', []),
            ['provider' => 'a b/c']
        );

        static::__assert_equals('/_sso/a%20b%2Fc/callback', $response->getTargetUrl());
    }

    // -------------------------------------------------------------------------
    // The settings surface
    // -------------------------------------------------------------------------

    /**
     * link_begin hands back a URL rather than redirecting: its caller is an Ajax request, and
     * a redirect answered to XMLHttpRequest is followed by the transport, not by the page.
     */
    public static function test_link_begin_returns_the_link_intent_url()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();
        Session::set_login_user_id((int) $login_user->id);

        try {
            $result = Rsx_Sso_Controller::link_begin(
                Request::create('/_ajax/Rsx_Sso_Controller/link_begin', 'POST'),
                ['provider' => 'fake']
            );

            static::__assert_equals('/_sso/fake/begin?intent=link', $result['url']);
        } finally {
            static::__anonymous();
        }
    }

    /**
     * A key that is not live is refused the same way here as at the route, and without naming
     * whether it is unknown or switched off.
     */
    public static function test_link_begin_refuses_a_provider_that_is_not_live()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();
        Session::set_login_user_id((int) $login_user->id);

        try {
            $result = Rsx_Sso_Controller::link_begin(
                Request::create('/_ajax/Rsx_Sso_Controller/link_begin', 'POST'),
                ['provider' => 'google']
            );

            static::__assert_instance_of(Error_Response::class, $result, 'a disabled provider cannot be connected');
            static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_error_code());
        } finally {
            static::__anonymous();
        }
    }

    /**
     * The endpoint's own impersonation refusal, the other half of the facade's rule. An
     * impersonator who could attach or strip a provider account has turned impersonation into
     * an authentication backdoor.
     */
    public static function test_the_mutating_endpoints_refuse_while_impersonating()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();
        $admin = static::__make_login_user();

        Session::set_login_user_id((int) $login_user->id);
        Session::cli_set_impersonator_login_user_id((int) $admin->id);

        try {
            static::__assert_throws(
                RuntimeException::class,
                function () {
                    Rsx_Sso_Controller::identity_unlink(
                        Request::create('/_ajax/Rsx_Sso_Controller/identity_unlink', 'POST'),
                        ['id' => 1]
                    );
                },
                'impersonating'
            );

            static::__assert_throws(
                RuntimeException::class,
                function () {
                    Rsx_Sso_Controller::link_begin(
                        Request::create('/_ajax/Rsx_Sso_Controller/link_begin', 'POST'),
                        ['provider' => 'fake']
                    );
                },
                'impersonating'
            );
        } finally {
            static::__anonymous();
        }
    }

    /**
     * identity_unlink with nothing named is a validation refusal, not a delete of row zero.
     */
    public static function test_identity_unlink_requires_an_id()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();
        Session::set_login_user_id((int) $login_user->id);

        try {
            $result = Rsx_Sso_Controller::identity_unlink(
                Request::create('/_ajax/Rsx_Sso_Controller/identity_unlink', 'POST'),
                []
            );

            static::__assert_instance_of(Error_Response::class, $result);
            static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_error_code());
        } finally {
            static::__anonymous();
        }
    }

    /**
     * identities_list is the facade's list for the SIGNED-IN identity and never for one named
     * by an argument - there is no parameter it would read.
     */
    public static function test_identities_list_answers_for_the_signed_in_identity()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();
        Session::set_login_user_id((int) $login_user->id);

        try {
            $result = Rsx_Sso_Controller::identities_list(
                Request::create('/_ajax/Rsx_Sso_Controller/identities_list', 'POST'),
                ['login_user_id' => 999999]
            );

            static::__assert_equals([], $result, 'a fresh identity has no connections');
        } finally {
            static::__anonymous();
        }
    }
}
