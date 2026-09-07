<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for Realtime::connection_token() / subscribe_token() token minting and
 * the per-topic can_subscribe() enforcement boundary.
 *
 * Pure logic - no database writes (Portal_Session's cli_set_*() setters are
 * in-memory only in CLI mode, no row required), so this class skips the
 * per-test transaction. Each test resets Portal_Session's CLI-mode identity at
 * its start (0 = logged out) since that state is a class static and would
 * otherwise leak between tests, which do not run in isolation from each other.
 */
class Realtime_Token_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __logout_portal(): void
    {
        // reset() is the CLI clear: it drops the declared site AND the identity,
        // returning the facade to virgin state (there is no set_site_id(0) - a
        // declaration is always a real site).
        Portal_Session::reset();
        Rsx_Portal::set_portal_request(false);
    }

    /**
     * Put the process in PORTAL request context with a declared site.
     *
     * connection_token()/_current_site_id() branch on the REALM OF THE REQUEST, not
     * on portal-login state - branching on identity sent every UNAUTHENTICATED portal
     * caller down the staff branch, where get_session_id() MINTS a staff session and
     * sets the staff cookie on a portal response (audit
     * docs.dev/audits/portal_realm_session_audit_2026_08_09.md). CLI reports
     * is_portal_request() false by default, so a portal test has to say so.
     *
     * @param int|null $portal_user_id null = an ANONYMOUS portal visitor, which is
     *                                 the case the old predicate got wrong.
     */
    private static function __enter_portal(int $site_id, ?int $portal_user_id = null): void
    {
        Portal_Session::reset();
        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id($site_id);

        if ($portal_user_id !== null) {
            Portal_Session::cli_set_portal_user_id($portal_user_id);
        }
    }

    /**
     * Split/verify a Realtime token the same way Node's validate_token() does:
     * base64(json_payload).hmac_sha256_hex, signature recomputed with app.key.
     *
     * @return array The decoded payload.
     */
    private static function __decode_token(string $token): array
    {
        $dot = strpos($token, '.');
        static::__assert_not_null($dot, 'token must contain a "." separating payload and signature');

        $payload_b64 = substr($token, 0, $dot);
        $signature = substr($token, $dot + 1);

        $json = base64_decode($payload_b64);
        $expected_signature = hash_hmac('sha256', $json, config('app.key'));
        static::__assert_equals($expected_signature, $signature, 'signature must match HMAC-SHA256 of the payload with app.key');

        return json_decode($json, true);
    }

    // -------------------------------------------------------------------------
    // connection_token()
    // -------------------------------------------------------------------------

    public static function test_connection_token_does_not_throw_when_anonymous()
    {
        static::__logout_portal();

        $token = Realtime::connection_token();
        static::__assert_true(is_string($token) && $token !== '');
    }

    public static function test_connection_token_returns_valid_signed_shape_when_anonymous()
    {
        static::__logout_portal();

        $payload = static::__decode_token(Realtime::connection_token());

        static::__assert_array_has_key('user_id', $payload);
        static::__assert_array_has_key('site_id', $payload);
        static::__assert_array_has_key('session_id', $payload);
        static::__assert_array_has_key('exp', $payload);
        static::__assert_null($payload['user_id'], 'anonymous caller has no user_id');
    }

    public static function test_connection_token_expiry_is_about_sixty_seconds_out()
    {
        static::__logout_portal();

        $payload = static::__decode_token(Realtime::connection_token());

        static::__assert_greater_than(time() + 55, $payload['exp']);
        static::__assert_less_than(time() + 65, $payload['exp']);
    }

    public static function test_connection_token_uses_portal_identity_when_portal_logged_in()
    {
        $portal_user_id = 4242;
        $site_id = 7;

        static::__enter_portal($site_id, $portal_user_id);

        $payload = static::__decode_token(Realtime::connection_token());

        static::__assert_equals('portal', $payload['realm']);
        static::__assert_equals($portal_user_id, $payload['user_id']);
        static::__assert_equals($site_id, $payload['site_id']);

        static::__logout_portal();
    }

    /**
     * THE REGRESSION LOCK for the audit's primary finding. An ANONYMOUS portal
     * request - a public portal page, the invite/register flow, a logged-out tab
     * still holding its cookie - must be minted in the PORTAL realm. The old
     * predicate (Portal_Session::is_logged_in()) sent it to the staff branch.
     */
    public static function test_connection_token_is_portal_realm_for_an_anonymous_portal_request()
    {
        static::__enter_portal(7);

        $payload = static::__decode_token(Realtime::connection_token());

        static::__assert_equals('portal', $payload['realm'], 'realm follows the REQUEST, not the identity');
        static::__assert_equals(7, $payload['site_id'], 'the portal site, not the staff site');
        static::__assert_null($payload['user_id'], 'anonymous portal visitor has no user_id');

        static::__logout_portal();
    }

    /**
     * The other half of the same defect: minting a token must never CREATE a session.
     * Session::get_session_id() ACTIVATES one, so both branches gate it on has_session().
     *
     * There is ONE session per browser, so the id is resolved once, through the facade
     * that owns the row, and only when a session already exists. What is checkable here
     * is that asking for a token leaves BOTH facades exactly as it found them - it
     * mints nothing. The web-side mint is locked out by the http test
     * flash/http/flash_portal_login_cookie.sh, which asserts a portal login emits the
     * ONE session cookie and never a second one.
     */
    public static function test_connection_token_never_creates_a_session()
    {
        static::__enter_portal(7);

        $portal_before = Portal_Session::has_session();
        $staff_before = Session::has_session();

        static::__decode_token(Realtime::connection_token());

        static::__assert_equals($portal_before, Portal_Session::has_session(), 'minting a token must not change the portal session state');
        static::__assert_equals($staff_before, Session::has_session(), 'and must never touch the STAFF facade from a portal request');

        static::__logout_portal();
    }

    /**
     * _current_site_id() must agree with connection_token() or Node's site-match
     * (subscribe payload.site_id vs conn.site_id) rejects every portal subscribe.
     * It used to disagree for exactly the anonymous-portal case above.
     */
    public static function test_subscribe_token_site_matches_the_connection_token_site_when_anonymous_on_the_portal()
    {
        static::__enter_portal(7);

        $connection = static::__decode_token(Realtime::connection_token());
        $subscribe = static::__decode_token(Realtime::subscribe_token('Realtime_Test_Public_Topic', []));

        static::__assert_equals($connection['site_id'], $subscribe['site_id'], 'the two token types must agree on the site');
        static::__assert_equals(7, $subscribe['site_id']);

        static::__logout_portal();
    }

    // -------------------------------------------------------------------------
    // subscribe_token() / can_subscribe() enforcement
    // -------------------------------------------------------------------------

    public static function test_subscribe_token_throws_for_unknown_topic()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            Realtime::subscribe_token('Nonexistent_Realtime_Topic_Xyz', []);
        }, 'topic class not found');
    }

    public static function test_subscribe_token_denied_when_not_logged_in()
    {
        static::__logout_portal();

        static::__assert_throws(\RuntimeException::class, function () {
            Realtime::subscribe_token('Realtime_Test_Private_Topic', ['portal_user_id' => 99]);
        }, 'Permission denied');
    }

    public static function test_subscribe_token_denied_for_someone_elses_filter()
    {
        static::__enter_portal(1, 11);

        static::__assert_throws(\RuntimeException::class, function () {
            Realtime::subscribe_token('Realtime_Test_Private_Topic', ['portal_user_id' => 22]);
        }, 'Permission denied');

        static::__logout_portal();
    }

    public static function test_subscribe_token_allowed_for_own_filter()
    {
        $portal_user_id = 33;
        static::__enter_portal(1, $portal_user_id);

        $payload = static::__decode_token(
            Realtime::subscribe_token('Realtime_Test_Private_Topic', ['portal_user_id' => $portal_user_id])
        );

        static::__assert_equals('Realtime_Test_Private_Topic', $payload['topic']);
        static::__assert_equals(['portal_user_id' => $portal_user_id], $payload['filter']);
        static::__assert_equals(1, $payload['site_id']);

        static::__logout_portal();
    }

    public static function test_subscribe_token_public_topic_allowed_when_anonymous()
    {
        static::__logout_portal();

        $token = Realtime::subscribe_token('Realtime_Test_Public_Topic', []);
        static::__assert_true(is_string($token) && $token !== '');
    }
}
