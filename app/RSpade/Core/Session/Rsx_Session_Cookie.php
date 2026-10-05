<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Session;

use App\RSpade\Core\Rsx;

/**
 * The name and attributes of the session cookie.
 *
 * There is exactly ONE session cookie, emitted by Session and shared by the staff app
 * and the client portal alike - a session identifies a BROWSER, not an experience. This
 * class is the single home for its NAME (name()) and its SECURITY attributes: path,
 * domain, HttpOnly, SameSite and the Secure flag. Nothing else spells the name.
 *
 * THE NAME IS `__Host-rsx` WHENEVER THE COOKIE IS SECURE. A browser accepts a cookie
 * with the `__Host-` prefix only when it is Secure, has Path=/ and carries NO Domain,
 * and only from a secure origin - so no sibling subdomain (`Domain=example.com`), no
 * narrower path (`Path=/login`, which a browser sends first and PHP keeps first), and no
 * plain-http response can plant or shadow it. That closes session fixation by cookie
 * tossing: the token is minted once and never rotated at login (rsx:man session), so a
 * planted token would otherwise become the victim's signed-in session.
 *
 * `rsx` (no prefix) is used ONLY on a development-mode request over plain http, the one
 * case where the cookie cannot be Secure (below) and a browser would therefore REJECT a
 * `__Host-` cookie outright, leaving http://localhost unable to hold a session. Such a
 * request has no transport security to protect in the first place. The two names are
 * never read interchangeably: a secure request reads only `__Host-rsx`, so a planted
 * plain `rsx` cookie is invisible to it.
 *
 * THE SECURE FLAG. RSpade assumes upstream SSL termination and APP_URL must be
 * https, so a real request is always secure and the cookie is always Secure -
 * EXCEPT in development mode, where the flag follows the request's actual scheme.
 * The development-only headless harness (rsx:debug) drives a real browser over
 * plain http on the loopback interface; a Secure cookie is DROPPED by the browser
 * on such a page, which silently leaves every subsequent Ajax call unauthenticated
 * and makes an honest end-to-end page check impossible. Following the scheme costs
 * nothing on an https dev page (isSecure() is true, so the flag is set exactly as
 * before) and is unreachable in debug/production mode, where the flag is
 * unconditionally true regardless of what the request claims.
 */
class Rsx_Session_Cookie
{
    /** The session cookie's name on a secure request (every request outside development). */
    public const SECURE_NAME = '__Host-rsx';

    /** The session cookie's name on a development-mode plain-http request only. */
    public const INSECURE_NAME = 'rsx';

    /**
     * The session cookie's name for THIS request - read, set and cleared under it alike.
     *
     * @return string SECURE_NAME when the cookie is Secure, INSECURE_NAME otherwise
     */
    public static function name(): string
    {
        return static::is_secure() ? self::SECURE_NAME : self::INSECURE_NAME;
    }

    /**
     * The setcookie() options array for a session cookie.
     *
     * @param int $expires Absolute unix expiry (a past value clears the cookie)
     * @return array
     */
    public static function options(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'domain' => '', // Current domain only
            'secure' => static::is_secure(),
            'httponly' => true, // No JavaScript access
            'samesite' => 'Lax', // CSRF protection
        ];
    }

    /**
     * Whether the session cookie carries the Secure attribute.
     *
     * Always true outside development mode. In development it follows the request
     * scheme, so the plain-http loopback harness can hold a session (see the class
     * docblock).
     *
     * The X-Forwarded-Proto read is deliberate and does NOT depend on the trusted-proxy
     * configuration: RSpade assumes an upstream SSL terminator, and the terminated path
     * is exactly the one that stamps that header, while the loopback harness is exactly
     * the one that does not. Reading it UNTRUSTED is safe here because it can only ADD
     * the Secure attribute - the direction that restricts the cookie, never relaxes it -
     * and only on a development-mode host.
     */
    public static function is_secure(): bool
    {
        if (!Rsx::is_development()) {
            return true;
        }

        $request = request();

        return $request->isSecure() || $request->header('X-Forwarded-Proto') === 'https';
    }
}
