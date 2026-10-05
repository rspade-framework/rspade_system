<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Session;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Session\Rsx_Session_Cookie;
use App\RSpade\Core\Session\Rsx_Signed_Url;
use App\RSpade\Core\Session\Session;

/**
 * Session_Link - the linked-session handshake: three one-time GET legs that make a portal
 * served from its OWN host (PORTAL_URL) use the SAME _sessions row as the staff browser
 * that started "View as Client".
 *
 * Why it is needed: on a separate host the browser holds a separate `rsx` cookie, and a
 * cookie for one host cannot be set from the other. Cross-host POST is refused (Rsx_Csrf's
 * Origin check) and cross-host fetch is blocked (CSP connect-src), so the only transport is
 * top-level GET navigation - a chain of redirects, each carrying a one-time code.
 *
 *   1. portal host  <prefix>/_session_link/open      open()
 *        Redeem code 1. Set the nonce cookie `rsx_link` on the portal host (only its
 *        SHA-256 is stored). Mint code 2 bound to (staff row, nonce hash, intent) and
 *        redirect to the staff host.
 *   2. staff host   /_session_link/confirm           confirm()
 *        Redeem code 2. REQUIRE the browser's own staff cookie to name the bound row (the
 *        same browser owns the staff session), the row's staff user to be the recorded
 *        impersonator, and the staff realm's `can_impersonate` check to pass for them
 *        NOW. Apply the impersonation to the row. Mint code 3 bound to (row, nonce hash)
 *        and redirect to the portal host.
 *   3. portal host  <prefix>/_session_link/complete  complete()
 *        Redeem code 3. REQUIRE the `rsx_link` cookie to hash to the bound nonce (the
 *        browser that reached leg 1 is the one at leg 3). Session_Link_Controller then
 *        points this host's `rsx` cookie at the row (Session::_clone_session_to_this_host)
 *        and lands on the portal.
 *
 * The portal cookie is therefore set on the LAST leg, after both hosts have proved it is
 * the same browser. A failure on any leg links nothing and answers one generic page.
 *
 * CODES. 256-bit random, stored only as a SHA-256 hash in _session_links, each bound to
 * one leg, and redeemed by an atomic burn (__redeem) - a code works once, on its own leg,
 * inside its window. Every URL is also signed (Rsx_Signed_Url) over (leg, code, target
 * host), so a code moved to another leg or the other host is refused before any lookup.
 * The session token NEVER appears in a URL, and the intent never travels in one either:
 * it lives on the link row.
 */
class Session_Link
{
    public const LEG_OPEN = 1;
    public const LEG_CONFIRM = 2;
    public const LEG_COMPLETE = 3;

    /**
     * How long a code - and the nonce cookie - stays redeemable, in seconds.
     *
     * A SECURITY WINDOW, set by the owner, and NOT an operation timeout: nothing waits on
     * it and no running work is cut short by it. It bounds how long a minted-but-unused
     * link (a leaked URL, an abandoned tab) remains an open door. Each leg is one redirect,
     * so a browser crosses all three in well under a second; a window that lapses simply
     * means the user clicks "View as Client" again.
     */
    public const EXPIRY_SECONDS = 120;

    /**
     * The browser-bound nonce cookie, set by leg 1 and required by leg 3 on the portal host.
     */
    public const NONCE_COOKIE = 'rsx_link';

    /**
     * The one answer every refusal gives, on every leg.
     */
    public const FAILURE_MESSAGE = 'This link has expired or was already used.';

    private const PATHS = [
        self::LEG_OPEN => '/_session_link/open',
        self::LEG_CONFIRM => '/_session_link/confirm',
        self::LEG_COMPLETE => '/_session_link/complete',
    ];

    // =========================================================================
    // Starting the handshake
    // =========================================================================

    /**
     * Mint leg 1 of a "View as Client" handshake for a staff browser's session row and
     * return its URL (absolute, on the portal's origin).
     *
     * FRAMEWORK INTERNAL - for Portal_Session::begin_impersonation_from_staff().
     *
     * @param int $session_id The staff browser's _sessions row
     * @param int $portal_user_id
     * @param int $portal_site_id
     * @param int $impersonator_user_id Staff users.id
     * @return string
     */
    public static function begin_impersonation(int $session_id, int $portal_user_id, int $portal_site_id, int $impersonator_user_id): string
    {
        $code = static::__mint(self::LEG_OPEN, $session_id, null, [
            'portal_user_id' => $portal_user_id,
            'portal_site_id' => $portal_site_id,
            'impersonator_user_id' => $impersonator_user_id,
        ]);

        return static::__leg_url(self::LEG_OPEN, $code);
    }

    // =========================================================================
    // The three legs (Session_Link_Controller applies the results)
    // =========================================================================

    /**
     * Leg 1, on the portal host.
     *
     * @param Request $request
     * @return array{redirect: string, nonce: string}|null null = refused
     */
    public static function open(Request $request): ?array
    {
        $link = static::__redeem_presented($request, self::LEG_OPEN);

        if ($link === null) {
            return null;
        }

        $nonce = bin2hex(random_bytes(32));
        $code = static::__mint(self::LEG_CONFIRM, $link['session_id'], hash('sha256', $nonce), $link['payload']);

        return [
            'redirect' => static::__leg_url(self::LEG_CONFIRM, $code),
            'nonce' => $nonce,
        ];
    }

    /**
     * Leg 2, on the staff host.
     *
     * @param Request $request
     * @return string|null The leg-3 URL, or null = refused
     */
    public static function confirm(Request $request): ?string
    {
        $link = static::__redeem_presented($request, self::LEG_CONFIRM);

        if ($link === null) {
            return null;
        }

        // The SAME browser owns the staff session: its own staff cookie names the bound row.
        $token = (string) $request->cookies->get(Rsx_Session_Cookie::name(), '');
        $browser_row = $token === '' ? null : Session::find_by_token($token);

        if ($browser_row === null || (int) $browser_row->id !== $link['session_id']) {
            return null;
        }

        $payload = $link['payload'];
        $portal_user_id = (int) ($payload['portal_user_id'] ?? 0);
        $portal_site_id = (int) ($payload['portal_site_id'] ?? 0);
        $impersonator_user_id = (int) ($payload['impersonator_user_id'] ?? 0);

        if ($portal_user_id <= 0 || $portal_site_id <= 0 || $impersonator_user_id <= 0) {
            return null;
        }

        // The staff identity on that row is still the one who asked, and may still do it.
        $staff_user = Session::get_user();

        if ($staff_user === null || (int) $staff_user->id !== $impersonator_user_id) {
            return null;
        }

        if (!Auth_Gates::evaluate('can_impersonate', Auth_Gates::REALM_STAFF)) {
            return null;
        }

        if (!Session::_apply_portal_impersonation($link['session_id'], $portal_user_id, $portal_site_id, $impersonator_user_id)) {
            return null;
        }

        $code = static::__mint(self::LEG_COMPLETE, $link['session_id'], $link['nonce_hash'], []);

        return static::__leg_url(self::LEG_COMPLETE, $code);
    }

    /**
     * Leg 3, on the portal host.
     *
     * @param Request $request
     * @return int|null The session row this host's cookie must now name, or null = refused
     */
    public static function complete(Request $request): ?int
    {
        $link = static::__redeem_presented($request, self::LEG_COMPLETE);

        if ($link === null || $link['nonce_hash'] === null) {
            return null;
        }

        $nonce = (string) $request->cookies->get(self::NONCE_COOKIE, '');

        if ($nonce === '' || !hash_equals($link['nonce_hash'], hash('sha256', $nonce))) {
            return null;
        }

        return $link['session_id'];
    }

    // =========================================================================
    // The nonce cookie
    // =========================================================================

    /**
     * The nonce cookie leg 1 sets, scoped to the handshake's portal paths.
     *
     * @param string $nonce
     * @return Cookie
     */
    public static function nonce_cookie(string $nonce): Cookie
    {
        return Cookie::create(
            self::NONCE_COOKIE,
            $nonce,
            time() + self::EXPIRY_SECONDS,
            static::__nonce_cookie_path(),
            null,
            Rsx_Session_Cookie::is_secure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }

    /**
     * The expired twin of nonce_cookie(), which removes it.
     *
     * @return Cookie
     */
    public static function forget_nonce_cookie(): Cookie
    {
        return Cookie::create(
            self::NONCE_COOKIE,
            '',
            1,
            static::__nonce_cookie_path(),
            null,
            Rsx_Session_Cookie::is_secure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }

    /**
     * Legs 1 and 3 are both under <prefix>/_session_link on the portal host.
     */
    private static function __nonce_cookie_path(): string
    {
        return Rsx_Portal_Url::prefix() . '/_session_link';
    }

    // =========================================================================
    // Codes
    // =========================================================================

    /**
     * Store a new code for a leg and return it. Only its hash is kept.
     *
     * @param int $leg
     * @param int $session_id
     * @param string|null $nonce_hash
     * @param array $payload
     * @return string The code (64 hex characters)
     */
    private static function __mint(int $leg, int $session_id, ?string $nonce_hash, array $payload): string
    {
        $code = bin2hex(random_bytes(32));

        DB::table('_session_links')->insert([
            'code_hash' => hash('sha256', $code),
            'leg' => $leg,
            'session_id' => $session_id,
            'nonce_hash' => $nonce_hash,
            'payload' => json_encode($payload),
            'expires_at' => now()->addSeconds(self::EXPIRY_SECONDS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $code;
    }

    /**
     * Verify the signature on the presented URL, then redeem its code.
     *
     * The signature is checked against the host the request ARRIVED on, which is how a
     * code addressed to one host is refused on the other.
     *
     * @return array{session_id: int, nonce_hash: ?string, payload: array}|null
     */
    private static function __redeem_presented(Request $request, int $leg): ?array
    {
        $code = $request->query('c');
        $signature = $request->query('s');

        if (!is_string($code) || !is_string($signature) || !preg_match('/^[0-9a-f]{64}$/', $code)) {
            return null;
        }

        if (!Rsx_Signed_Url::verify(static::__purpose($leg), $code, $request->getHost(), $signature)) {
            return null;
        }

        return static::__redeem($leg, $code);
    }

    /**
     * Burn a code ATOMICALLY and return what it was bound to, or null.
     *
     * The shape: inside one transaction, a locking read of the live row (code_hash, leg,
     * expires_at >= now), then a DELETE on the same predicate that must affect exactly one
     * row. Two browsers presenting the same code race on the row lock; the loser's locking
     * read runs after the winner's DELETE commits and finds nothing. The DELETE's own
     * predicate and the affected == 1 requirement make the burn itself the authority: no
     * path returns a payload whose row this call did not delete.
     *
     * @param int $leg
     * @param string $code
     * @return array{session_id: int, nonce_hash: ?string, payload: array}|null
     */
    private static function __redeem(int $leg, string $code): ?array
    {
        $code_hash = hash('sha256', $code);

        return DB::transaction(function () use ($leg, $code_hash) {
            $now = now();

            $row = DB::table('_session_links')
                ->where('code_hash', $code_hash)
                ->where('leg', $leg)
                ->where('expires_at', '>=', $now)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            $deleted = DB::table('_session_links')
                ->where('id', $row->id)
                ->where('code_hash', $code_hash)
                ->where('leg', $leg)
                ->where('expires_at', '>=', $now)
                ->delete();

            if ($deleted !== 1 || $row->session_id === null) {
                return null;
            }

            $payload = json_decode((string) $row->payload, true);

            return [
                'session_id' => (int) $row->session_id,
                'nonce_hash' => $row->nonce_hash,
                'payload' => is_array($payload) ? $payload : [],
            ];
        });
    }

    // =========================================================================
    // URLs
    // =========================================================================

    /**
     * The signed URL of a leg, on the host that serves it: legs 1 and 3 on the portal
     * (Rsx_Portal::portal_path(), prefix-aware and absolute off the portal host), leg 2 on
     * APP_URL's origin.
     */
    private static function __leg_url(int $leg, string $code): string
    {
        if ($leg === self::LEG_CONFIRM) {
            $app = static::__app_address();
            $base = $app['origin'] . self::PATHS[$leg];
            $host = $app['host'];
        } else {
            $base = Rsx_Portal::portal_path(self::PATHS[$leg]);
            $host = Rsx_Portal_Url::host();
        }

        return $base . '?' . http_build_query([
            'c' => $code,
            's' => Rsx_Signed_Url::sign(static::__purpose($leg), $code, $host),
        ]);
    }

    /**
     * APP_URL's origin and host, split by the same parser that derives the portal's
     * address (a blank PORTAL_URL there IS APP_URL's origin). Never the request's host:
     * leg 1 runs on the portal host and must address the staff host.
     *
     * @return array{origin: string, host: string}
     */
    private static function __app_address(): array
    {
        $parsed = Rsx_Portal_Url::parse('', (string) config('app.url'));

        return ['origin' => $parsed['origin'], 'host' => $parsed['host']];
    }

    private static function __purpose(int $leg): string
    {
        return 'session_link:' . $leg;
    }
}
