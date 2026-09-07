<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Sso\Php;

use RuntimeException;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Sso\Php\Fake_Sso_Provider;

/**
 * The roster: which providers are live, what an enabled one is allowed to look like, and
 * what reaches a template.
 *
 * TWO PROPERTIES ARE PINNED HERE and they pull in opposite directions, which is the point.
 *
 * ACTIVITY IS CONFIGURED, NOT DERIVED - the same posture Turnstile takes. Nothing about the
 * mode, the hostname or APP_URL makes a provider live; only 'enabled' does. So a suite that
 * ships with every provider off must see an EMPTY roster, and one that switches a provider
 * on must see it regardless of where the tests are running.
 *
 * A HALF-CONFIGURED ENABLE THROWS, and it names the literal .env keys. The alternative -
 * silently skipping a provider whose credentials are missing - hides an operator's mistake
 * behind a login page that simply has one fewer button, which nobody notices until a user
 * asks where the Google button went. The throw is what makes "enabled" mean something.
 *
 * PURE CONFIGURATION, no database and no session: $use_database_transactions is off.
 * Everything here reads config, and every test puts back what it changed.
 */
class Sso_Providers_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Run $fn with these config keys overridden, and put every one of them back afterwards -
     * on the way out of a passing test AND out of a throwing one.
     *
     * setup()/teardown() are per-CLASS, not per-test, so a test that simply set a key would
     * leak it into every test that follows and make the file order-dependent. That is a
     * particularly nasty failure here, because a leaked half-configured provider makes
     * enabled_providers() throw inside tests that are about something else entirely.
     *
     * @param array $overrides key => value
     * @param callable $fn
     * @return mixed Whatever $fn returned.
     */
    private static function __with_config(array $overrides, callable $fn)
    {
        $backup = [];

        foreach ($overrides as $key => $value) {
            $backup[$key] = config($key);
            config()->set($key, $value);
        }

        try {
            return $fn();
        } finally {
            foreach ($backup as $key => $value) {
                config()->set($key, $value);
            }
        }
    }

    /**
     * The custom-provider entry these tests drive the engine through.
     */
    private static function __fake_entry(array $overrides = []): array
    {
        return [
            'fake' => array_merge([
                'enabled' => true,
                'provider' => Fake_Sso_Provider::class,
                'label' => 'Fake Provider',
                'client_id' => 'FAKE-CLIENT',
                'client_secret' => 'FAKE-SECRET',
            ], $overrides),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Nothing is live until something is switched on. A framework that guessed here - "we
     * found credentials, so we assume you want the button" - would put an unintended sign-in
     * path on a login page.
     */
    public static function test_no_provider_is_live_by_default()
    {
        static::__assert_equals([], Rsx_Sso::enabled_providers(), 'the shipped default is nothing');
        static::__assert_false(Rsx_Sso::is_enabled(), 'and a login page renders no SSO row');
    }

    /**
     * What a template gets: a key, a label, a URL to send the browser to, and a mark. And
     * NOTHING ELSE - no client id, no secret. This array reaches window.rsxapp.sso, so its
     * shape is a security property, not a convenience.
     */
    public static function test_enabled_providers_exposes_only_public_fields()
    {
        static::__with_config(['rsx.sso.custom' => static::__fake_entry()], function () {
            $providers = Rsx_Sso::enabled_providers();

            static::__assert_count(1, $providers, 'exactly the one that is enabled');

            $entry = $providers[0];

            static::__assert_equals(
                ['key', 'label', 'begin_url', 'icon_svg'],
                array_keys($entry),
                'four public fields, and no credential among them'
            );
            static::__assert_equals('fake', $entry['key']);
            static::__assert_equals('Fake Provider', $entry['label'], 'a custom entry names its own label');
            static::__assert_equals('/_sso/fake/begin', $entry['begin_url']);
            static::__assert_true(Rsx_Sso::is_enabled(), 'and the page knows to render the row');
        });
    }

    /**
     * A provider with no shipped brand mark reports an empty string rather than failing. A
     * button with a label and no icon is a working button; an install that will not boot
     * because somebody moved an SVG is not.
     */
    public static function test_a_missing_icon_is_an_empty_string_not_an_error()
    {
        static::__with_config(['rsx.sso.custom' => static::__fake_entry()], function () {
            static::__assert_equals('', Rsx_Sso::enabled_providers()[0]['icon_svg']);
        });
    }

    /**
     * A custom entry may carry its own mark inline, which is how a downstream provider gets
     * one at all - the framework serves no static assets and has no icon of theirs to ship.
     */
    public static function test_a_custom_provider_may_supply_its_own_icon()
    {
        $entry = static::__fake_entry(['icon_svg' => '<svg data-test="fake"></svg>']);

        static::__with_config(['rsx.sso.custom' => $entry], function () {
            static::__assert_equals('<svg data-test="fake"></svg>', Rsx_Sso::enabled_providers()[0]['icon_svg']);
        });
    }

    /**
     * THE HALF-CONFIGURED ENABLE. The message names SSO_GOOGLE_CLIENT_SECRET because that is
     * the string the operator has to go and set; "google is misconfigured" would send them
     * looking.
     */
    public static function test_an_enabled_provider_missing_a_credential_throws_naming_the_env_key()
    {
        static::__with_config([
            'rsx.sso.providers.google' => [
                'enabled' => true,
                'client_id' => 'GOOGLE-CLIENT',
                'client_secret' => '',
            ],
        ], function () {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Rsx_Sso::enabled_providers(),
                'SSO_GOOGLE_CLIENT_SECRET'
            );
        });
    }

    /**
     * Apple needs FOUR credentials, not two, because it has no static client secret - the
     * secret is a JWT minted from the .p8 key on every exchange. All three signing
     * coordinates are named at once so an operator fixes them in one pass.
     */
    public static function test_apple_reports_every_missing_signing_credential_at_once()
    {
        static::__with_config([
            'rsx.sso.providers.apple' => [
                'enabled' => true,
                'client_id' => 'com.example.service',
                'team_id' => '',
                'key_id' => '',
                'private_key' => '',
            ],
        ], function () {
            $thrown = static::__assert_throws(RuntimeException::class, fn () => Rsx_Sso::enabled_providers());

            static::__assert_contains('SSO_APPLE_TEAM_ID', $thrown->getMessage());
            static::__assert_contains('SSO_APPLE_KEY_ID', $thrown->getMessage());
            static::__assert_contains('SSO_APPLE_PRIVATE_KEY', $thrown->getMessage());
        });
    }

    /**
     * A DISABLED provider with no credentials is not an error - that is the shipped state of
     * all five, and it must stay quiet.
     */
    public static function test_a_disabled_provider_with_no_credentials_is_silent()
    {
        static::__with_config([
            'rsx.sso.providers.google' => [
                'enabled' => false,
                'client_id' => null,
                'client_secret' => null,
            ],
        ], function () {
            static::__assert_equals([], Rsx_Sso::enabled_providers());
        });
    }

    /**
     * An unknown key and a disabled key are THE SAME ANSWER to the outside world. "microsoft
     * is configured here but switched off" is a fact about the install, and a stranger
     * poking at /_sso/ URLs has no reason to learn it.
     */
    public static function test_unknown_and_disabled_keys_are_refused_identically()
    {
        static::__assert_throws(RuntimeException::class, fn () => Rsx_Sso::provider('google'));
        static::__assert_throws(RuntimeException::class, fn () => Rsx_Sso::provider('nonesuch'));
    }

    /**
     * A custom entry that names no installed provider class fails loudly at resolution, with
     * the config path in the message. The alternative is a button that 500s on click.
     */
    public static function test_a_custom_entry_naming_no_provider_class_throws()
    {
        static::__with_config([
            'rsx.sso.custom' => [
                'broken' => [
                    'enabled' => true,
                    'provider' => 'Not\\A\\Real\\Provider',
                    'client_id' => 'X',
                    'client_secret' => 'Y',
                ],
            ],
        ], function () {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Rsx_Sso::enabled_providers(),
                'rsx.sso.custom.broken.provider'
            );
        });
    }

    /**
     * Every key in a custom entry that is not framework vocabulary is passed through to the
     * adapter. That pass-through is what lets an Okta base URL or a Keycloak realm reach a
     * driver the framework has never heard of, with no framework change.
     */
    public static function test_custom_entries_pass_their_extra_keys_through_to_the_driver()
    {
        $entry = static::__fake_entry(['base_url' => 'https://okta.example.test', 'realm' => 'staff']);

        static::__with_config(['rsx.sso.custom' => $entry], function () {
            $provider = Rsx_Sso::provider('fake');

            static::__assert_equals(
                ['base_url' => 'https://okta.example.test', 'realm' => 'staff'],
                $provider['extra'],
                'the adapter gets its own keys and nothing of ours'
            );
            static::__assert_equals(Fake_Sso_Provider::class, $provider['driver']);
        });
    }

    /**
     * The callback path is what an operator registers in a provider console, and Apple's is
     * additionally hardcoded into the CSRF exemption list. If the shape ever moves, that
     * exemption silently stops matching - so the two spellings are pinned against each other.
     */
    public static function test_the_apple_callback_constant_matches_the_computed_path()
    {
        static::__assert_equals(Rsx_Sso::callback_path(Rsx_Sso::APPLE), Rsx_Sso::APPLE_CALLBACK_PATH);
        static::__assert_equals('/_sso/apple/callback', Rsx_Sso::APPLE_CALLBACK_PATH);
    }

    /**
     * The window is a SECURITY window and is read from config, not from a constant somewhere
     * in the class.
     */
    public static function test_the_pending_window_comes_from_configuration()
    {
        $minutes = (int) config('rsx.sso.pending_window_minutes');

        static::__assert_greater_than(0, $minutes, 'the window is configured');

        $seconds = strtotime(Rsx_Sso::pending_expires_at()) - time();

        static::__assert_greater_than($minutes * 60 - 2, $seconds, 'not shorter than configured');
        static::__assert_less_than($minutes * 60 + 2, $seconds, 'and not longer');
    }
}
