<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Session;

/**
 * Rsx_Signed_Url - an HMAC-SHA256 signature binding a one-time code to WHAT it is for and
 * WHERE it may be presented.
 *
 * A signature covers three things: a PURPOSE (which step of which flow the code opens), the
 * CODE itself, and the HOST the URL is addressed to (lowercased, without a port - two ports
 * on one hostname are one host, as everywhere else in the framework). A code lifted out of
 * one URL and replayed on another step, or on the other host, fails verification before any
 * lookup happens.
 *
 * The key is DERIVED from app.key per purpose (HMAC of 'rsx-signed-url:<purpose>' under
 * app.key), so a signature made for one purpose is never valid for another and app.key
 * itself never signs anything a user can see. Verification is constant-time (hash_equals).
 *
 * A signature proves the URL was minted by this application. It is NOT a replay defence
 * and carries no expiry: the code it covers is what is single-use and short-lived (see
 * Session_Link). Never put a session token in a signed URL - the code is the only secret a
 * URL carries.
 */
class Rsx_Signed_Url
{
    /**
     * The hex signature of (purpose, code, host).
     *
     * @param string $purpose What the code opens (e.g. 'session_link:1')
     * @param string $code The one-time code the URL carries
     * @param string $host The host the URL is addressed to (a port, if any, is ignored)
     * @return string 64 lowercase hex characters
     */
    public static function sign(string $purpose, string $code, string $host): string
    {
        return hash_hmac('sha256', static::__message($purpose, $code, $host), static::__key($purpose));
    }

    /**
     * Whether $signature is the signature of (purpose, code, host). Constant-time.
     *
     * @param string $purpose
     * @param string $code
     * @param string $host The host the URL was PRESENTED on
     * @param string $signature
     * @return bool
     */
    public static function verify(string $purpose, string $code, string $host, string $signature): bool
    {
        if ($code === '' || $signature === '') {
            return false;
        }

        return hash_equals(static::sign($purpose, $code, $host), $signature);
    }

    /**
     * The signed message. Newline-separated: neither a purpose, a host nor a hex code can
     * contain one, so no two inputs share a message.
     */
    private static function __message(string $purpose, string $code, string $host): string
    {
        return $purpose . "\n" . static::__normalize_host($host) . "\n" . $code;
    }

    /**
     * Lowercased, without a port.
     */
    private static function __normalize_host(string $host): string
    {
        $host = strtolower(trim($host));

        // A bracketed IPv6 literal keeps its colons; anything else loses a :port suffix.
        if (str_starts_with($host, '[')) {
            $close = strpos($host, ']');

            return $close === false ? $host : substr($host, 0, $close + 1);
        }

        $colon = strpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }

    /**
     * The per-purpose key, derived from app.key.
     */
    private static function __key(string $purpose): string
    {
        $app_key = (string) config('app.key');

        if ($app_key === '') {
            shouldnt_happen('Rsx_Signed_Url needs app.key (APP_KEY) to be set');
        }

        return hash_hmac('sha256', 'rsx-signed-url:' . $purpose, $app_key, true);
    }
}
