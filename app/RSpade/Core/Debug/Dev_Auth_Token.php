<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Debug;

use App\RSpade\Core\Ide\Ide_Bridge_Token;
use App\RSpade\Core\Rsx;

/**
 * Dev_Auth_Token - the ONE implementation of the rsx:debug dev-auth credential.
 *
 * rsx:debug drives a real browser at the running site and needs that browser to arrive
 * as a chosen user. It asserts the identity in headers, signed so that only a process
 * with local disk access to this box can make the assertion. This class both MINTS the
 * assertion (Route_Debug_Command, the standalone Playwright scripts through their node
 * twin) and VERIFIES it (Dispatcher for staff, Portal_Dispatcher for portal) - one
 * class, so the two sides cannot drift.
 *
 * THREAT MODEL. Security is designed for DEVELOPMENT mode, because a development-mode
 * RSpade site may be serving the public right now. So this is designed against an
 * attacker who has read this source and is on the network:
 *
 *   - The signing key is the local-file GRANT SECRET (Ide_Bridge_Token), never APP_KEY.
 *     APP_KEY also encrypts every cookie and rides along in backups and .env copies, so
 *     signing "log in as any user" with it made an APP_KEY disclosure an authentication
 *     bypass. The grant is development-only, lives on disk and nowhere else, and
 *     rotates.
 *   - Every credential carries an EXPIRY and is refused past it. That is a CREDENTIAL
 *     LIFETIME, not a timeout: nothing is aborted when it passes, and no operation is
 *     capped by it - a signed assertion simply stops being accepted. 60 seconds is the
 *     whole distance from mint to navigation (the command mints and immediately spawns
 *     the browser at the URL), so a captured header is useful for a minute rather than
 *     forever.
 *   - Verification exists only in development (Rsx::is_development()). RSX_MODE is the
 *     one mode switch; a sealed build has no grant store at all.
 *
 * THE WIRE FORMAT, byte for byte. Request headers:
 *
 *     X-Dev-Auth-User-Id            staff login_users.id       (staff realm)
 *     X-Dev-Auth-Portal-User-Id     portal_users.id            (portal realm, instead)
 *     X-Dev-Auth-Exp                expiry, unix seconds, decimal digits
 *     X-Dev-Auth-Token              lowercase hex HMAC-SHA256
 *
 * The signed payload is PHP's json_encode of, in this key order:
 *
 *     {"url":"\/contacts","user_id":1,"portal":false,"exp":1757203200}
 *
 * url is the REQUEST URI the assertion is scoped to - path plus query, no fragment (a
 * fragment never reaches the server), and for the portal realm the /_portal prefix is
 * normalized off first. PHP json_encode escapes forward slashes as \/, which a
 * non-PHP minter must reproduce. token = hash_hmac('sha256', payload, <grant secret>).
 *
 * A verifier tries EVERY active grant secret, newest first: rotation and use are not
 * synchronized, and a rotation landing between mint and navigation must not read as a
 * forgery.
 */
class Dev_Auth_Token
{
    /**
     * How long a minted assertion is accepted for, in seconds.
     *
     * A CREDENTIAL LIFETIME, not a timeout - nothing is aborted or capped when it
     * passes; the credential just stops verifying. 60 seconds is enough because the
     * minting process spawns the browser at the signed URL immediately: the only thing
     * that happens between mint and navigation is a chromium launch. It is short
     * because a header captured off the wire, out of a proxy log, or off a shared
     * terminal is a working identity assertion until it expires.
     */
    public const LIFETIME_SECONDS = 60;

    /**
     * The exact payload both sides sign. Key order is part of the wire format.
     *
     * @param string $url
     * @param int $user_id
     * @param bool $is_portal
     * @param int $exp
     * @return string
     */
    public static function payload(string $url, int $user_id, bool $is_portal, int $exp): string
    {
        return json_encode([
            'url' => $url,
            'user_id' => $user_id,
            'portal' => $is_portal,
            'exp' => $exp,
        ]);
    }

    /**
     * Sign a payload with one grant secret.
     *
     * @param string $url
     * @param int $user_id
     * @param bool $is_portal
     * @param int $exp
     * @param string $secret
     * @return string Lowercase hex.
     */
    public static function sign(string $url, int $user_id, bool $is_portal, int $exp, string $secret): string
    {
        return hash_hmac('sha256', self::payload($url, $user_id, $is_portal, $exp), $secret);
    }

    /**
     * Mint an assertion for one URL and identity, signed with the NEWEST grant secret.
     *
     * The caller is responsible for having ensured the grant store exists
     * (Ide_Bridge_Token::ensure_grant_store()) - this returns null rather than minting
     * anything when no grant is established, because there is no weaker credential to
     * reach for.
     *
     * @param string $url
     * @param int $user_id
     * @param bool $is_portal
     * @return array{token: string, exp: int}|null
     */
    public static function mint(string $url, int $user_id, bool $is_portal): ?array
    {
        $secrets = Ide_Bridge_Token::active_secrets();
        if (empty($secrets)) {
            return null;
        }

        $exp = time() + self::LIFETIME_SECONDS;

        return [
            'token' => self::sign($url, $user_id, $is_portal, $exp, $secrets[0]),
            'exp' => $exp,
        ];
    }

    /**
     * Verify a presented assertion.
     *
     * Returns null when it is GOOD, or a human-readable rejection reason - the caller
     * names it through console_debug, because a PRESENTED token that fails is always a
     * bug and never a legitimate anonymous request.
     *
     * @param string $url The request URI the assertion must be scoped to.
     * @param int $user_id
     * @param bool $is_portal
     * @param string|null $presented_exp The X-Dev-Auth-Exp header, raw.
     * @param string|null $presented_token The X-Dev-Auth-Token header, raw.
     * @return string|null null = verified.
     */
    public static function verify(
        string $url,
        int $user_id,
        bool $is_portal,
        ?string $presented_exp,
        ?string $presented_token
    ): ?string {
        // Development only. Not "not production": RSX_MODE is the one mode switch, and
        // a debug build is a sealed build with no grant store behind it.
        if (!Rsx::is_development()) {
            return 'dev-auth is development-only and this box is ' . Rsx::get_mode();
        }

        $presented_token = trim((string) $presented_token);
        if ($presented_token === '') {
            return 'no X-Dev-Auth-Token header';
        }

        $presented_exp = trim((string) $presented_exp);
        if ($presented_exp === '' || !ctype_digit($presented_exp)) {
            return 'X-Dev-Auth-Exp missing or not a unix timestamp';
        }

        $exp = (int) $presented_exp;
        if ($exp < time()) {
            return 'credential expired at ' . $exp . ' (now ' . time() . ')';
        }

        // The lifetime is enforced HERE, not merely promised by the minter: a credential
        // whose expiry lies further out than one lifetime was not minted by rsx:debug, and
        // accepting it would let a stolen grant secret sign a token that outlives the grant.
        if ($exp > time() + self::LIFETIME_SECONDS) {
            return 'credential lifetime exceeds ' . self::LIFETIME_SECONDS . 's (exp ' . $exp . ', now ' . time() . ')';
        }

        $secrets = Ide_Bridge_Token::active_secrets();
        if (empty($secrets)) {
            return 'no development grant is established (storage/rsx-ide-bridge)';
        }

        // EVERY active secret is tried, newest first. A rotation between mint and
        // navigation is routine and must not read as a forgery.
        foreach ($secrets as $secret) {
            if (hash_equals(self::sign($url, $user_id, $is_portal, $exp, $secret), $presented_token)) {
                return null;
            }
        }

        return 'signature mismatch for URI ' . $url . ' user ' . $user_id;
    }
}
