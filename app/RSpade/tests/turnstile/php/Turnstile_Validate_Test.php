<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Turnstile\Php;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;

/**
 * Turnstile_Validate_Test - the Rsx_Turnstile::validate() decision matrix, in-process.
 *
 * validate() is the ONE call an endpoint makes, and it has to be correct on both sides of
 * the config switch: with the feature DISABLED it still demands the fixed __turnstile field
 * carrying the 'inactive' sentinel (which is what lets an endpoint call it unconditionally),
 * and with the feature ENABLED it demands a token and a verdict. Either way a failure STOPS
 * the request rather than accumulating into the endpoint's field errors, shaped for the
 * channel the request arrived on.
 *
 * The siteverify round trip itself is not exercised here - $force_verify_result_for_tests
 * substitutes the VERDICT so the enabled-path shaping is testable with no network
 * dependency. The live round trip is http/turnstile_live_verify.sh.
 *
 * The completeness guard (the other half of the contract) is Turnstile_Guard_Test.
 */
class Turnstile_Validate_Test extends Rsx_Test_Abstract
{
    /** Pure logic against config + a synthetic Request: nothing is written to the database. */
    protected static $use_database_transactions = false;

    /** A native full-page POST - the reject renders as flash + redirect back. */
    private const FORM_URI = '/login';

    /** The staff internal-endpoint channel - the reject renders as the ajax json contract. */
    private const AJAX_URI = '/_ajax/Foo_Controller/bar';

    /** Cloudflare's published always-passes dummy site key (documented dev recipe). */
    private const DUMMY_SITE_KEY = '1x00000000000000000000AA';

    /** Cloudflare's published always-passes dummy secret key. */
    private const DUMMY_SECRET_KEY = '1x0000000000000000000000000000000AA';

    /**
     * Config as it was before this class ran, restored around every test.
     *
     * @var array
     */
    private static array $original_config = [];

    public static function setup()
    {
        static::$original_config = [
            'rsx.turnstile.enabled' => config('rsx.turnstile.enabled'),
            'rsx.turnstile.site_key' => config('rsx.turnstile.site_key'),
            'rsx.turnstile.secret_key' => config('rsx.turnstile.secret_key'),
        ];
    }

    public static function teardown()
    {
        static::__restore();
    }

    /**
     * Put the process back exactly as it was found: config, the verdict seam, and the latch.
     * setup()/teardown() run once per CLASS, so every test restores for itself.
     */
    private static function __restore(): void
    {
        config(static::$original_config);
        Rsx_Turnstile::$force_verify_result_for_tests = null;
        Rsx_Turnstile::_reset_request_state();
    }

    /**
     * Configure the feature for one test. Keys default to Cloudflare's dummy pair so the
     * enabled path is reachable; pass null to model a half-configured install.
     */
    private static function __configure(bool $enabled, ?string $site_key = self::DUMMY_SITE_KEY, ?string $secret_key = self::DUMMY_SECRET_KEY): void
    {
        Rsx_Turnstile::_reset_request_state();
        Rsx_Turnstile::$force_verify_result_for_tests = null;

        config([
            'rsx.turnstile.enabled' => $enabled,
            'rsx.turnstile.site_key' => $site_key,
            'rsx.turnstile.secret_key' => $secret_key,
        ]);
    }

    /**
     * Build a POST carrying (or omitting) the Turnstile field.
     *
     * @param string $uri
     * @param string|null $token null omits the field entirely - the "form forgot the widget" case.
     * @return Request
     */
    private static function __post(string $uri, ?string $token): Request
    {
        $body = $token === null ? [] : [Rsx_Turnstile::FIELD => $token];

        return Request::create($uri, 'POST', $body);
    }

    // --- Disabled: the sentinel is mandatory, and it is enough ---

    public static function test_disabled_with_the_sentinel_returns_quietly()
    {
        static::__configure(false);

        try {
            Rsx_Turnstile::validate(static::__post(self::FORM_URI, Rsx_Turnstile::INACTIVE));

            static::__assert_true(
                Rsx_Turnstile::_was_checked(),
                'a completed validate() must leave the request latch set'
            );
        } finally {
            static::__restore();
        }
    }

    public static function test_disabled_with_a_real_token_redirects_back()
    {
        static::__configure(false);

        try {
            // A page rendered while the feature was ENABLED posts a real token after the
            // switch was flipped off. Not accommodated by design: it is rejected, and a
            // reload heals it.
            $exception = static::__assert_throws(HttpResponseException::class, function () {
                Rsx_Turnstile::validate(static::__post(self::FORM_URI, 'a-real-looking-token'));
            });

            $response = $exception->getResponse();
            static::__assert_equals(302, $response->getStatusCode(), 'a native POST rejection redirects back');
            static::__assert_contains(self::FORM_URI, (string) $response->headers->get('Location'));
        } finally {
            static::__restore();
        }
    }

    public static function test_disabled_with_no_field_at_all_redirects_back()
    {
        static::__configure(false);

        try {
            // The field is ALWAYS present when the widget is on the form, so its absence
            // means the form is missing the widget - which is a failure, not a pass.
            $exception = static::__assert_throws(HttpResponseException::class, function () {
                Rsx_Turnstile::validate(static::__post(self::FORM_URI, null));
            });

            static::__assert_equals(302, $exception->getResponse()->getStatusCode());
        } finally {
            static::__restore();
        }
    }

    public static function test_disabled_on_an_ajax_path_gets_the_json_validation_contract()
    {
        static::__configure(false);

        try {
            $exception = static::__assert_throws(HttpResponseException::class, function () {
                Rsx_Turnstile::validate(static::__post(self::AJAX_URI, 'a-real-looking-token'));
            });

            $response = $exception->getResponse();
            $body = (string) $response->getContent();

            static::__assert_equals(200, $response->getStatusCode(), 'the ajax error contract is a 200');
            static::__assert_contains('"_success":false', $body);
            static::__assert_contains('"error_code":"validation"', $body);
            static::__assert_contains('_message', $body, 'the message rides the summary key, never a __turnstile field key');
            static::__assert_contains(Rsx_Turnstile::MESSAGE_FAILED, $body);
        } finally {
            static::__restore();
        }
    }

    // --- Enabled: misconfiguration is a 500, not a form error ---

    public static function test_enabled_without_keys_throws_naming_the_env_vars()
    {
        static::__configure(true, null, null);

        try {
            $exception = static::__assert_throws(RuntimeException::class, function () {
                Rsx_Turnstile::validate(static::__post(self::FORM_URI, 'token'));
            });

            static::__assert_contains('TURNSTILE_SITE_KEY', $exception->getMessage());
            static::__assert_contains('TURNSTILE_SECRET_KEY', $exception->getMessage());
        } finally {
            static::__restore();
        }
    }

    public static function test_enabled_with_only_the_secret_missing_still_throws()
    {
        static::__configure(true, self::DUMMY_SITE_KEY, null);

        try {
            $exception = static::__assert_throws(RuntimeException::class, function () {
                Rsx_Turnstile::validate(static::__post(self::FORM_URI, 'token'));
            });

            static::__assert_contains('TURNSTILE_SECRET_KEY', $exception->getMessage());
            static::__assert_false(
                str_contains($exception->getMessage(), 'TURNSTILE_SITE_KEY'),
                'only the missing key is named'
            );
        } finally {
            static::__restore();
        }
    }

    // --- Enabled: the token itself ---

    public static function test_enabled_with_no_token_stops_with_the_missing_message()
    {
        static::__configure(true);

        try {
            $exception = static::__assert_throws(HttpResponseException::class, function () {
                Rsx_Turnstile::validate(static::__post(self::AJAX_URI, null));
            });

            static::__assert_contains(Rsx_Turnstile::MESSAGE_MISSING, (string) $exception->getResponse()->getContent());
        } finally {
            static::__restore();
        }
    }

    public static function test_enabled_with_the_sentinel_stops_with_the_missing_message()
    {
        static::__configure(true);

        try {
            // A page rendered while the feature was DISABLED posts the sentinel. The mirror
            // image of the stale-token case above, and equally not a pass.
            $exception = static::__assert_throws(HttpResponseException::class, function () {
                Rsx_Turnstile::validate(static::__post(self::AJAX_URI, Rsx_Turnstile::INACTIVE));
            });

            static::__assert_contains(Rsx_Turnstile::MESSAGE_MISSING, (string) $exception->getResponse()->getContent());
        } finally {
            static::__restore();
        }
    }

    public static function test_enabled_with_a_verified_token_passes()
    {
        static::__configure(true);
        Rsx_Turnstile::$force_verify_result_for_tests = true;

        try {
            Rsx_Turnstile::validate(static::__post(self::AJAX_URI, 'a-token'));

            static::__assert_true(Rsx_Turnstile::_was_checked(), 'the latch is set on the passing path too');
        } finally {
            static::__restore();
        }
    }

    public static function test_enabled_with_a_rejected_token_stops_with_the_failed_message()
    {
        static::__configure(true);
        Rsx_Turnstile::$force_verify_result_for_tests = false;

        try {
            $exception = static::__assert_throws(HttpResponseException::class, function () {
                Rsx_Turnstile::validate(static::__post(self::AJAX_URI, 'a-token'));
            });

            $body = (string) $exception->getResponse()->getContent();
            static::__assert_contains(Rsx_Turnstile::MESSAGE_FAILED, $body);
            static::__assert_false(
                str_contains($body, Rsx_Turnstile::MESSAGE_UNAVAILABLE),
                'a rejection must never be reported as "temporarily unavailable"'
            );
        } finally {
            static::__restore();
        }
    }

    // --- The Ajax transport delivers the field in $params, not on the Request ---

    public static function test_the_token_may_arrive_in_params()
    {
        static::__configure(true);
        Rsx_Turnstile::$force_verify_result_for_tests = true;

        try {
            // Ajax/batch hand the endpoint its $params; the body was never merged into the
            // Request the plain-route seam sees, so validate() must read both.
            Rsx_Turnstile::validate(
                Request::create(self::AJAX_URI, 'POST'),
                [Rsx_Turnstile::FIELD => 'a-token']
            );

            static::__assert_true(Rsx_Turnstile::_was_checked());
        } finally {
            static::__restore();
        }
    }

    public static function test_params_take_precedence_over_the_request_body()
    {
        static::__configure(false);

        try {
            // $params wins: an endpoint told "this is the submitted value" must be believed
            // over whatever the synthesized Request happens to carry.
            Rsx_Turnstile::validate(
                static::__post(self::AJAX_URI, 'a-real-looking-token'),
                [Rsx_Turnstile::FIELD => Rsx_Turnstile::INACTIVE]
            );
        } finally {
            static::__restore();
        }
    }

    // --- The request latch ---

    public static function test_reset_request_state_clears_the_latch()
    {
        static::__configure(false);

        try {
            Rsx_Turnstile::validate(static::__post(self::FORM_URI, Rsx_Turnstile::INACTIVE));
            static::__assert_true(Rsx_Turnstile::_was_checked(), 'sanity: validate() set the latch');

            Rsx_Turnstile::_reset_request_state();
            static::__assert_false(Rsx_Turnstile::_was_checked(), 'reset clears the latch');

            Rsx_Turnstile::_set_request_checked(true);
            static::__assert_true(Rsx_Turnstile::_was_checked(), 'the restore seam sets it back');
        } finally {
            static::__restore();
        }
    }

    public static function test_a_failing_validation_still_sets_the_latch()
    {
        static::__configure(false);

        try {
            // The guard asks "did the endpoint run the validator", and a validator that ran
            // and REJECTED did run. Without this the guard would fire a second, misleading
            // exception on top of every genuine verification failure.
            static::__assert_throws(HttpResponseException::class, function () {
                Rsx_Turnstile::validate(static::__post(self::FORM_URI, null));
            });

            static::__assert_true(Rsx_Turnstile::_was_checked(), 'a rejected validation still counts as checked');
        } finally {
            static::__restore();
        }
    }

    // --- Testing keys on a production build ---

    /**
     * Force Rsx::get_mode() for one test by writing the private mode cache, returning a
     * restorer. Reflection, because the mode is an .env-backed process fact with no setter -
     * the cache property is the narrowest seam that exists.
     */
    private static function __force_mode(string $mode): callable
    {
        $prop = new \ReflectionProperty(\App\RSpade\Core\Rsx::class, '_cached_mode');
        $prop->setAccessible(true);
        $previous = $prop->getValue();
        $prop->setValue(null, $mode);

        return function () use ($prop, $previous) {
            $prop->setValue(null, $previous);
        };
    }

    public static function test_a_testing_site_key_on_a_production_build_throws()
    {
        static::__configure(true); // dummy pair - both are testing keys
        $restore_mode = static::__force_mode('production');

        try {
            static::__assert_throws(RuntimeException::class, function () {
                Rsx_Turnstile::site_key();
            });
        } finally {
            $restore_mode();
            static::__restore();
        }
    }

    public static function test_a_testing_secret_alone_on_a_production_build_throws()
    {
        // A realistic-shaped site key with a testing SECRET is the sneakier misconfiguration:
        // the widget looks real while the always-pass secret verifies nothing.
        static::__configure(true, '0x4AAAAAAALooksRealEnough', self::DUMMY_SECRET_KEY);
        $restore_mode = static::__force_mode('production');

        try {
            static::__assert_throws(RuntimeException::class, function () {
                Rsx_Turnstile::site_key();
            });
        } finally {
            $restore_mode();
            static::__restore();
        }
    }

    public static function test_testing_keys_are_exempt_in_debug_mode()
    {
        // Debug is a sealed LOCAL test build - exactly where the dummy keys belong.
        static::__configure(true);
        $restore_mode = static::__force_mode('debug');

        try {
            static::__assert_equals(self::DUMMY_SITE_KEY, Rsx_Turnstile::site_key());
        } finally {
            $restore_mode();
            static::__restore();
        }
    }

    public static function test_real_looking_keys_pass_on_a_production_build()
    {
        static::__configure(true, '0x4AAAAAAALooksRealEnough', '0x4AAAAAAARealSecretShape');
        $restore_mode = static::__force_mode('production');

        try {
            static::__assert_equals('0x4AAAAAAALooksRealEnough', Rsx_Turnstile::site_key());
        } finally {
            $restore_mode();
            static::__restore();
        }
    }
}
