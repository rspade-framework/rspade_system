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
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Portal_Sso_Identity_Model;
use App\RSpade\Core\Sso\Rsx_Portal_Sso;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Sso\Sso_Identity_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Sso\Php\Fake_Sso_Provider;

/**
 * Federated sign-in in the CLIENT PORTAL realm: Rsx_Portal_Sso, over _portal_sso_identities
 * and Portal_Session, driven end to end through the in-process fake provider.
 *
 * The ceremony engine is the one Rsx_Sso runs, and its state, replay and throttle behaviour is
 * pinned by Sso_Ceremony_Test. What is pinned HERE is what the portal realm adds:
 *  - the realm has its own SWITCH (rsx.sso.portal_enabled), off by default - a provider live
 *    for staff offers nothing on the portal until the application says so;
 *  - the ceremony has its own URLs and its own parked state, so the redirect URI a provider
 *    sees is the portal's callback;
 *  - an unconnected identity fires portal.sso.identity.unlinked and NEVER the staff hook -
 *    an application's staff policy must not govern its clients - and fails closed to the
 *    PORTAL login page when nothing answers;
 *  - a staff link for the same provider account does not sign anybody in on the portal;
 *  - links are site-scoped: one provider account may be a portal user of two sites, and a
 *    lookup sees only the declared site's;
 *  - the portal's admission rule refuses a suspended portal user, and the portal gate hook
 *    receives 'portal_user'.
 */
class Portal_Sso_Test extends Rsx_Test_Abstract
{
    private static int $_site_id = 0;

    private static int $_other_site_id = 0;

    private static array $config_backup = [];

    public static function setup()
    {
        parent::setup();

        static::$_site_id = static::__make_site('home');
        static::$_other_site_id = static::__make_site('other');

        self::__override('rsx.sso.custom', [
            'fake' => [
                'enabled' => true,
                'provider' => Fake_Sso_Provider::class,
                'label' => 'Fake Provider',
                'client_id' => 'FAKE-CLIENT',
                'client_secret' => 'FAKE-SECRET',
            ],
        ]);
        self::__override('rsx.sso.portal_enabled', true);
    }

    public static function teardown()
    {
        Event_Registry::_clear_test_handlers();

        foreach (self::$config_backup as $key => $value) {
            config()->set($key, $value);
        }

        self::$config_backup = [];

        Login_Throttle::reset('CLI');
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        Session::logout();
        static::__reset_session();
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

    private static function __make_site(string $tag): int
    {
        $site = new Site_Model();
        $site->slug = 'portal-sso-' . $tag . '-' . uniqid();
        $site->name = 'Portal SSO ' . $tag;
        $site->is_enabled = true;
        $site->save();

        return (int) $site->id;
    }

    /**
     * A clean, anonymous portal request on the given site.
     */
    private static function __as_portal(int $site_id): void
    {
        Event_Registry::_clear_test_handlers();
        Login_Throttle::reset('CLI');
        static::__acting_as_site($site_id);
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id($site_id);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
    }

    private static function __make_portal_user(int $site_id, int $status_id = Portal_User_Model::STATUS_ACTIVE): Portal_User_Model
    {
        static::__as_portal($site_id);

        $user = new Portal_User_Model();
        $user->site_id = $site_id;
        $user->email = 'portal_sso_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = $status_id;
        $user->save();

        return $user;
    }

    private static function __identity(string $subject, ?string $email = null): array
    {
        return [
            'id' => $subject,
            'email' => $email,
            'name' => 'Fake Person',
            'avatar' => 'https://fake-provider.test/avatar.png',
        ];
    }

    /**
     * Begin a portal ceremony and run its callback with the given provider identity.
     */
    private static function __ceremony(array $identity)
    {
        Rsx_Portal_Sso::begin('fake');

        $parked = Session::get_value(Rsx_Portal_Sso::STATE_KEY);

        $url = Rsx_Portal_Sso::callback_path('fake') . '?' . http_build_query([
            'code' => 'FAKE-CODE',
            'state' => (string) ($parked['state'] ?? ''),
            Fake_Sso_Provider::IDENTITY_PARAM => json_encode($identity),
        ]);

        return Rsx_Portal_Sso::handle_callback('fake', Request::create($url, 'GET'));
    }

    /**
     * Connect a provider subject to a portal user on that user's own site, through the
     * facade's own linking path.
     */
    private static function __link(Portal_User_Model $portal_user, string $subject): void
    {
        static::__as_portal((int) $portal_user->site_id);

        Event_Registry::_set_test_handlers('portal.sso.identity.unlinked', [
            fn ($identity) => Rsx_Portal_Sso::consume_pending_and_login($portal_user),
        ]);

        static::__ceremony(static::__identity($subject, $portal_user->email));

        Portal_Session::cli_set_portal_user_id(0);
        Event_Registry::_clear_test_handlers();
    }

    // -------------------------------------------------------------------------
    // The switch and the URLs
    // -------------------------------------------------------------------------

    /**
     * Off by default: providers live for staff offer nothing on the portal, and the ceremony
     * refuses to start.
     */
    public static function test_the_portal_realm_is_off_until_switched_on()
    {
        static::__as_portal(static::$_site_id);
        self::__override('rsx.sso.portal_enabled', false);

        static::__assert_equals([], Rsx_Portal_Sso::enabled_providers(), 'no portal roster');
        static::__assert_true(Rsx_Sso::enabled_providers() !== [], 'while staff still has one');
        static::__assert_throws(RuntimeException::class, fn () => Rsx_Portal_Sso::begin('fake'), 'not enabled');

        self::__override('rsx.sso.portal_enabled', true);
    }

    /**
     * The portal ceremony has its own URLs, and the provider is told to come back to the
     * portal's callback - on the portal's host and path, never the staff one.
     */
    public static function test_the_portal_ceremony_uses_the_portal_urls_and_state()
    {
        static::__as_portal(static::$_site_id);

        $roster = Rsx_Portal_Sso::enabled_providers();
        $fake = array_values(array_filter($roster, fn ($p) => $p['key'] === 'fake'))[0];

        static::__assert_equals(Rsx_Portal::portal_path('/_sso/fake/begin'), $fake['begin_url'], 'the portal begin URL');

        $response = Rsx_Portal_Sso::begin('fake');

        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

        static::__assert_equals(Rsx_Portal_Sso::callback_url('fake'), $query['redirect_uri'], 'the portal callback is the redirect URI');
        static::__assert_contains(Rsx_Portal::portal_path('/_sso/fake/callback'), $query['redirect_uri']);
        static::__assert_not_null(Session::get_value(Rsx_Portal_Sso::STATE_KEY), 'parked under the portal key');
        static::__assert_null(Session::get_value(Rsx_Sso::STATE_KEY), 'and not the staff one');
    }

    // -------------------------------------------------------------------------
    // Realm separation
    // -------------------------------------------------------------------------

    /**
     * An unconnected identity fires the PORTAL hook and never the staff one, and with no
     * portal handler it fails closed to the PORTAL login page.
     */
    public static function test_an_unlinked_identity_fires_only_the_portal_hook_and_fails_closed()
    {
        static::__as_portal(static::$_site_id);

        $staff_hook_ran = false;

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [
            function ($identity) use (&$staff_hook_ran) {
                $staff_hook_ran = true;

                return '/staff-policy-ran';
            },
        ]);

        $response = static::__ceremony(static::__identity('subject-unlinked', 'nobody@example.com'));

        static::__assert_false($staff_hook_ran, 'the staff policy never governs a client');
        static::__assert_equals(Rsx_Portal::portal_path('/login'), $response->getTargetUrl(), 'back to the portal login page');
        static::__assert_false(Portal_Session::is_logged_in(), 'nobody is signed in');
        static::__assert_null(Rsx_Portal_Sso::pending(), 'and nothing is left pending');
    }

    /**
     * A STAFF link for the provider account does not sign anybody in on the portal - the
     * portal realm reads only its own table, so to it the account is unconnected.
     */
    public static function test_a_staff_link_does_not_sign_in_on_the_portal()
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'staff_sso_' . uniqid() . '@example.com';
        $login_user->password = Hash::make('correct-horse-battery-staple');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $link = new Sso_Identity_Model();
        $link->login_user_id = $login_user->id;
        $link->provider_key = 'fake';
        $link->provider_user_key = 'subject-staff-only';
        $link->save();

        static::__as_portal(static::$_site_id);

        $portal_hook_ran = false;

        Event_Registry::_set_test_handlers('portal.sso.identity.unlinked', [
            function ($identity) use (&$portal_hook_ran) {
                $portal_hook_ran = true;

                return null;
            },
        ]);

        static::__ceremony(static::__identity('subject-staff-only', $login_user->email));

        static::__assert_true($portal_hook_ran, 'to the portal the account is unconnected');
        static::__assert_false(Portal_Session::is_logged_in(), 'nobody is signed in to the portal');
        static::__assert_false(Session::is_logged_in(), 'and nobody is signed in to staff');
    }

    // -------------------------------------------------------------------------
    // Linking and signing in
    // -------------------------------------------------------------------------

    /**
     * The portal hook links the pending identity and signs the portal user in; the link is
     * written on the declared site, and the next ceremony signs straight in.
     */
    public static function test_the_portal_hook_links_and_signs_in_then_the_link_signs_in_directly()
    {
        $portal_user = static::__make_portal_user(static::$_site_id);

        $payload_key = null;

        Event_Registry::_set_test_handlers('portal.sso.login.authorize', [
            function ($payload) use (&$payload_key) {
                $payload_key = isset($payload['portal_user']) ? 'portal_user' : 'other';

                return true;
            },
        ]);
        Event_Registry::_set_test_handlers('portal.sso.identity.unlinked', [
            fn ($identity) => Rsx_Portal_Sso::consume_pending_and_login($portal_user),
        ]);

        static::__ceremony(static::__identity('subject-link', $portal_user->email));

        static::__assert_equals((int) $portal_user->id, (int) Portal_Session::get_portal_user_id(), 'signed in to the portal');
        static::__assert_equals('portal_user', $payload_key, 'the portal gate receives portal_user');

        $row = Portal_Sso_Identity_Model::where('portal_user_id', $portal_user->id)->first();
        static::__assert_not_null($row, 'the link is written');
        static::__assert_equals(static::$_site_id, (int) $row->site_id, 'on the declared site');

        static::__as_portal(static::$_site_id);

        static::__ceremony(static::__identity('subject-link', $portal_user->email));

        static::__assert_equals((int) $portal_user->id, (int) Portal_Session::get_portal_user_id(), 'the link signs straight in');
    }

    /**
     * One provider account may be a portal user of two sites, and a lookup sees only the
     * declared site's link.
     */
    public static function test_links_are_scoped_to_the_declared_site()
    {
        $here = static::__make_portal_user(static::$_site_id);
        $there = static::__make_portal_user(static::$_other_site_id);

        static::__link($there, 'subject-two-sites');
        static::__link($here, 'subject-two-sites');

        static::__assert_equals(
            2,
            Portal_Sso_Identity_Model::where('provider_key', 'fake')
                ->where('provider_user_key', 'subject-two-sites')
                ->count(),
            'one row per site'
        );

        static::__as_portal(static::$_site_id);

        static::__ceremony(static::__identity('subject-two-sites'));

        static::__assert_equals((int) $here->id, (int) Portal_Session::get_portal_user_id(), 'this site\'s portal user, not the other\'s');
    }

    /**
     * The portal's own admission rule refuses a suspended portal user, however valid the
     * link.
     */
    public static function test_a_suspended_portal_user_is_refused()
    {
        $portal_user = static::__make_portal_user(static::$_site_id);

        static::__link($portal_user, 'subject-suspended');

        Portal_User_Model::where('id', $portal_user->id)
            ->update(['status_id' => Portal_User_Model::STATUS_SUSPENDED]);

        static::__as_portal(static::$_site_id);

        $response = static::__ceremony(static::__identity('subject-suspended'));

        static::__assert_equals(Rsx_Portal::portal_path('/login'), $response->getTargetUrl());
        static::__assert_false(Portal_Session::is_logged_in(), 'a suspended portal user signs nobody in');
    }
}
