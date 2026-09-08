<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use App\RSpade\Core\Debug\Dev_Auth_Token;
use App\RSpade\Core\Ide\Ide_Bridge_Token;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Dev_Auth_Token - the credential rsx:debug's browser presents to assert an identity.
 *
 * The properties under test are the security ones, and every one of them was a hole
 * before this class existed: the credential is keyed on the local development GRANT
 * (not APP_KEY, which also encrypts every cookie), it EXPIRES, it is refused outside
 * development, it is scoped to one URL / user / realm, and a grant that rotation has
 * retired no longer opens anything.
 *
 * ONE VERIFIER, TESTED ONCE. Dispatcher::__handle_dev_auth and
 * Portal_Dispatcher::__handle_dev_auth both call Dev_Auth_Token::verify() - the realm
 * is an argument, not a second implementation - so proving the verifier here proves it
 * for both realms. The last test pins the realm separation itself.
 *
 * Each test points the grant machinery at a throwaway bridge directory under
 * storage/rsx-tmp, so the real storage/rsx-ide-bridge grant (which the live rsx:debug
 * uses) is never touched.
 */
class Dev_Auth_Token_Test extends Rsx_Test_Abstract
{
    // Filesystem + config behavior only - no database.
    protected static $use_database_transactions = false;

    // Any URL string: the credential is an HMAC over the payload, so the value is opaque
    // to Dev_Auth_Token and never has to resolve to a route. A framework path is used so
    // the class names no application surface.
    private const URL = '/_sys';
    private const USER_ID = 1;

    /** Absolute bridge dirs created during the run, cleaned up in teardown. */
    private static $created_dirs = [];

    public static function teardown()
    {
        foreach (self::$created_dirs as $dir) {
            self::__rmrf($dir);
        }
        self::$created_dirs = [];
        Rsx::clear_mode_cache();
    }

    /**
     * A fresh, empty grant store the class will use for this test.
     */
    private static function __fresh_store(): string
    {
        $relative = 'storage/rsx-tmp/dev_auth_test_' . random_hash(8);
        config([
            'rsx.ide_integration.bridge_path' => $relative,
            'rsx.ide_integration.enabled' => true,
        ]);
        $abs = dirname(storage_path()) . '/' . $relative;
        self::$created_dirs[] = $abs;

        return $abs;
    }

    private static function __rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $f = $dir . '/' . $entry;
            is_dir($f) ? self::__rmrf($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    // -----------------------------------------------------------------------------
    // The happy path, and the rotation window
    // -----------------------------------------------------------------------------

    public static function test_verifies_against_the_newest_grant()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        $minted = Dev_Auth_Token::mint(self::URL, self::USER_ID, false);
        self::__assert_not_null($minted, 'mint() must produce a credential when a grant exists');

        self::__assert_null(
            Dev_Auth_Token::verify(self::URL, self::USER_ID, false, (string) $minted['exp'], $minted['token']),
            'a freshly minted credential must verify'
        );
    }

    public static function test_verifies_against_the_previous_grant_after_a_rotation()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        // Minted from the newest secret, then a rotation lands underneath it - which is
        // routine, and must never read as a forgery.
        $minted = Dev_Auth_Token::mint(self::URL, self::USER_ID, false);
        Ide_Bridge_Token::rotate();

        self::__assert_equals(
            2,
            count(Ide_Bridge_Token::active_secrets()),
            'a rotation over one grant leaves exactly ACTIVE_GRANTS secrets'
        );
        self::__assert_null(
            Dev_Auth_Token::verify(self::URL, self::USER_ID, false, (string) $minted['exp'], $minted['token']),
            'a credential minted before a rotation must still verify'
        );
    }

    public static function test_a_retired_third_grant_no_longer_verifies()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        $minted = Dev_Auth_Token::mint(self::URL, self::USER_ID, false);

        // Two rotations push the minting secret out of the active pair entirely.
        Ide_Bridge_Token::rotate();
        Ide_Bridge_Token::rotate();

        self::__assert_not_null(
            Dev_Auth_Token::verify(self::URL, self::USER_ID, false, (string) $minted['exp'], $minted['token']),
            'a credential signed by a RETIRED grant must be refused'
        );
    }

    // -----------------------------------------------------------------------------
    // Expiry - a credential lifetime, not a timeout
    // -----------------------------------------------------------------------------

    public static function test_an_expired_credential_is_refused()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        $secret = Ide_Bridge_Token::active_secrets()[0];
        $exp = time() - 1;
        $token = Dev_Auth_Token::sign(self::URL, self::USER_ID, false, $exp, $secret);

        $rejection = Dev_Auth_Token::verify(self::URL, self::USER_ID, false, (string) $exp, $token);
        self::__assert_not_null($rejection, 'an expired credential must be refused');
        self::__assert_contains('expired', $rejection, 'the rejection must name the expiry');
    }

    public static function test_a_lifetime_longer_than_the_minter_uses_is_refused()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        // Correctly signed by a live grant, but with an expiry a whole day out: only a
        // holder of the grant SECRET could produce this, and the verifier must not let
        // that holder mint something that outlives the grant's own rotation.
        $secret = Ide_Bridge_Token::active_secrets()[0];
        $exp = time() + 86400;
        $token = Dev_Auth_Token::sign(self::URL, self::USER_ID, false, $exp, $secret);

        $rejection = Dev_Auth_Token::verify(self::URL, self::USER_ID, false, (string) $exp, $token);
        self::__assert_not_null($rejection, 'a credential with an oversized lifetime must be refused');
        self::__assert_contains('lifetime', $rejection, 'the rejection must name the lifetime cap');
    }

    public static function test_the_expiry_is_signed_and_cannot_be_extended()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        $minted = Dev_Auth_Token::mint(self::URL, self::USER_ID, false);

        // Present the same signature with a later expiry header: the exp is part of the
        // signed payload, so moving it invalidates the signature rather than buying time.
        // The moved value stays INSIDE the lifetime window on purpose (a minted exp is
        // already at the cap, so it is moved earlier) - the cap is its own test; this one
        // proves the SIGNATURE covers the expiry, whichever way it is moved.
        $rejection = Dev_Auth_Token::verify(
            self::URL,
            self::USER_ID,
            false,
            (string) ($minted['exp'] - 30),
            $minted['token']
        );
        self::__assert_not_null($rejection, 'a rewritten X-Dev-Auth-Exp must invalidate the signature');
        self::__assert_contains('signature mismatch', $rejection);
    }

    public static function test_a_missing_or_malformed_exp_is_refused()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        $minted = Dev_Auth_Token::mint(self::URL, self::USER_ID, false);

        foreach ([null, '', 'soon', '-5', '1.5'] as $bad_exp) {
            $rejection = Dev_Auth_Token::verify(self::URL, self::USER_ID, false, $bad_exp, $minted['token']);
            self::__assert_not_null($rejection, 'exp ' . var_export($bad_exp, true) . ' must be refused');
        }
    }

    public static function test_a_missing_token_is_refused()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        self::__assert_not_null(
            Dev_Auth_Token::verify(self::URL, self::USER_ID, false, (string) (time() + 60), null),
            'no token means no verification'
        );
    }

    // -----------------------------------------------------------------------------
    // No grant, wrong box
    // -----------------------------------------------------------------------------

    public static function test_no_grant_store_means_nothing_verifies()
    {
        $dir = self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        $minted = Dev_Auth_Token::mint(self::URL, self::USER_ID, false);

        // Wipe the store the way a purge (or a fresh checkout) leaves it.
        self::__rmrf($dir);

        self::__assert_equals([], Ide_Bridge_Token::active_secrets(), 'an empty store has no secrets');
        self::__assert_null(Dev_Auth_Token::mint(self::URL, self::USER_ID, false), 'nothing to sign with');

        $rejection = Dev_Auth_Token::verify(self::URL, self::USER_ID, false, (string) $minted['exp'], $minted['token']);
        self::__assert_not_null($rejection, 'a credential must not verify against an absent store');
        self::__assert_contains('grant', $rejection);
    }

    public static function test_verification_is_development_only()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        $minted = Dev_Auth_Token::mint(self::URL, self::USER_ID, false);

        // A sealed DEBUG build is the case the old app()->environment() gate missed:
        // Laravel reports 'local' for it, so dev-auth stayed live there.
        foreach ([Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
            Rsx::_testing_set_mode($mode);
            $rejection = Dev_Auth_Token::verify(self::URL, self::USER_ID, false, (string) $minted['exp'], $minted['token']);
            Rsx::clear_mode_cache();

            self::__assert_not_null($rejection, "dev-auth must be refused in {$mode} mode");
            self::__assert_contains('development-only', $rejection);
        }
    }

    // -----------------------------------------------------------------------------
    // Scope: URL, user, realm
    // -----------------------------------------------------------------------------

    public static function test_the_credential_is_scoped_to_one_url_user_and_realm()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        $minted = Dev_Auth_Token::mint(self::URL, self::USER_ID, false);
        $exp = (string) $minted['exp'];

        self::__assert_not_null(
            Dev_Auth_Token::verify('/engagements', self::USER_ID, false, $exp, $minted['token']),
            'a credential for one URL must not verify another'
        );
        self::__assert_not_null(
            Dev_Auth_Token::verify(self::URL, self::USER_ID + 1, false, $exp, $minted['token']),
            'a credential for one user must not verify another'
        );
        self::__assert_not_null(
            Dev_Auth_Token::verify(self::URL, self::USER_ID, true, $exp, $minted['token']),
            'a staff credential must not verify in the portal realm'
        );
    }

    // -----------------------------------------------------------------------------
    // The wire format both sides (and the node minter) reproduce
    // -----------------------------------------------------------------------------

    public static function test_the_signed_payload_is_the_documented_wire_format()
    {
        // Byte for byte: PHP json_encode key order, and its \/ slash escaping. The node
        // minter (system/bin/dev-auth.js) reproduces this string, so a change here
        // silently breaks every standalone Playwright test until it is changed there too.
        self::__assert_equals(
            '{"url":"\\/_sys","user_id":1,"portal":false,"exp":1757203200}',
            Dev_Auth_Token::payload('/_sys', 1, false, 1757203200)
        );
    }

    public static function test_the_lifetime_is_short_and_applied_at_mint()
    {
        self::__fresh_store();
        Ide_Bridge_Token::ensure_grant_store();

        $before = time();
        $minted = Dev_Auth_Token::mint(self::URL, self::USER_ID, false);

        self::__assert_equals(60, Dev_Auth_Token::LIFETIME_SECONDS, 'the owner-approved lifetime is 60 seconds');
        self::__assert_true(
            $minted['exp'] >= $before + Dev_Auth_Token::LIFETIME_SECONDS
                && $minted['exp'] <= time() + Dev_Auth_Token::LIFETIME_SECONDS,
            'exp is stamped at mint time plus the lifetime'
        );
    }
}
