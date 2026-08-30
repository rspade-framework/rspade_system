<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Session\Session;

/**
 * Brute-force throttle for the authentication surface.
 *
 * WHAT IT COUNTS: FAILURES, not requests, keyed by CLIENT IP. Two redis keys per address -
 * a failure counter that expires with the window, and a lockout marker written when the
 * counter reaches the budget. A request that authenticates costs nothing, so a real user
 * signing in fifty times a day never approaches the budget; only wrong answers accumulate.
 *
 * WHY PER IP AND NOT PER EMAIL: the attacker chooses the email. Spraying one password across
 * a thousand addresses would never trip a per-email budget, while the address it is sprayed
 * FROM is the one thing the attacker has to keep using. The per-email counter Login_History
 * keeps is still there, still readable, and still not enforced by the framework.
 *
 * WHERE IT IS ENFORCED: RsxAuth::attempt() calls require_not_throttled() as its first
 * statement, before any lookup - so a locked-out address cannot even probe which addresses
 * exist. Login_History::record_failure() calls record_failure() here, which is what makes
 * every recorded failure feed the throttle: password misses, unknown addresses, and the
 * outcomes an application records itself (a second factor, an account status).
 *
 * A CALLER WITH NO CLIENT IP IS NEVER THROTTLED. Session::get_client_ip() answers null
 * outside a request - CLI, tasks, programmatic work - and there is no remote party to
 * throttle there: a command that authenticates a thousand fixtures is not an attack, and
 * throttling it would collapse every such process onto one shared bucket. The web request
 * is the surface being defended, and it always has an address.
 *
 * FAIL CLOSED. Nothing here catches a cache error: a redis failure propagates and the login
 * fails loud rather than proceeding unthrottled. The ONE tolerated degradation is maintenance
 * mode, where redis is deliberately stopped and RsxCache answers 0 / false without touching
 * it - the throttle is then inert, which costs nothing because the web tier is answering 503.
 *
 * Config: rsx.sessions.login_throttle (enabled, attempts, window_minutes, lockout_minutes).
 * See rsx:man session, LOGIN THROTTLE.
 */
class Login_Throttle
{
    /**
     * Per-IP failure counter (persistent namespace - see RsxCache::increment_with_ttl).
     */
    private const FAILURE_KEY_PREFIX = 'login_throttle:failures:ip:';

    /**
     * Per-IP lockout marker. Holds the UNIX TIME the lockout expires, so retry_after_seconds()
     * is one read rather than a TTL introspection, and the key carries the same expiry as a
     * redis TTL so it cleans itself up.
     */
    private const LOCKOUT_KEY_PREFIX = 'login_throttle:lockout:ip:';

    /**
     * Refuse a client that has spent its failure budget.
     *
     * Call this BEFORE anything that could confirm or deny a credential - a lookup, a hash
     * comparison, a second-factor check. A throttled caller must learn nothing at all.
     *
     * @param string|null $ip Client IP; defaults to Session::get_client_ip()
     * @return void
     * @throws Auth_Throttled_Exception when the IP is locked out
     */
    public static function require_not_throttled(?string $ip = null): void
    {
        $retry_after_seconds = self::retry_after_seconds($ip);

        if ($retry_after_seconds > 0) {
            throw new Auth_Throttled_Exception($retry_after_seconds);
        }
    }

    /**
     * Count one authentication failure against the client's IP, locking it out when the
     * budget is spent.
     *
     * Called by Login_History::record_failure(), so an application that records its own
     * failure outcomes (a bad second factor, a disabled account) feeds the throttle without
     * doing anything extra. Call it directly only from a login path that does NOT record
     * through Login_History - the portal's password check is the framework's own example.
     *
     * @param string|null $ip Client IP; defaults to Session::get_client_ip()
     * @return void
     */
    public static function record_failure(?string $ip = null): void
    {
        $config = self::_config();

        if (!$config['enabled']) {
            return;
        }

        $ip = self::_resolve_ip($ip);

        if ($ip === null) {
            return;
        }

        $failures = RsxCache::increment_with_ttl(
            self::FAILURE_KEY_PREFIX . $ip,
            $config['window_minutes'] * 60
        );

        // 0 means the cache is unavailable (maintenance mode) - nothing was counted, so there
        // is nothing to act on.
        if ($failures < $config['attempts']) {
            return;
        }

        $lockout_seconds = $config['lockout_minutes'] * 60;

        RsxCache::set_persistent(
            self::LOCKOUT_KEY_PREFIX . $ip,
            time() + $lockout_seconds,
            $lockout_seconds
        );
    }

    /**
     * How long this client must wait, in seconds. 0 when it is not locked out.
     *
     * @param string|null $ip Client IP; defaults to Session::get_client_ip()
     * @return int
     */
    public static function retry_after_seconds(?string $ip = null): int
    {
        $config = self::_config();

        if (!$config['enabled']) {
            return 0;
        }

        $ip = self::_resolve_ip($ip);

        if ($ip === null) {
            return 0;
        }

        $expires_at = (int) RsxCache::get_persistent(self::LOCKOUT_KEY_PREFIX . $ip, 0);

        if ($expires_at <= 0) {
            return 0;
        }

        return max(0, $expires_at - time());
    }

    /**
     * Clear an address's failure counter and lockout.
     *
     * For a test, and for an operator releasing a customer who locked themselves out.
     * A null IP outside a request clears nothing (nothing was ever counted).
     *
     * @param string|null $ip Client IP; defaults to Session::get_client_ip()
     * @return void
     */
    public static function reset(?string $ip = null): void
    {
        $ip = self::_resolve_ip($ip);

        if ($ip === null) {
            return;
        }

        RsxCache::delete_persistent(self::FAILURE_KEY_PREFIX . $ip);
        RsxCache::delete_persistent(self::LOCKOUT_KEY_PREFIX . $ip);
    }

    /**
     * The client IP this call is about, or null when there is no remote party.
     *
     * @param string|null $ip
     * @return string|null
     */
    private static function _resolve_ip(?string $ip): ?string
    {
        $ip = $ip ?? Session::get_client_ip();

        if ($ip === null || trim($ip) === '') {
            return null;
        }

        return trim($ip);
    }

    /**
     * The validated throttle configuration.
     *
     * A nonsensical value is a broken deployment, not an input to work around: a zero or
     * negative budget would lock every visitor out on their first mistake, and a zero window
     * cannot even be handed to a counter TTL.
     *
     * @return array{enabled: bool, attempts: int, window_minutes: int, lockout_minutes: int}
     */
    private static function _config(): array
    {
        $enabled = (bool) config('rsx.sessions.login_throttle.enabled', true);

        $attempts = (int) config('rsx.sessions.login_throttle.attempts', 10);
        $window_minutes = (int) config('rsx.sessions.login_throttle.window_minutes', 15);
        $lockout_minutes = (int) config('rsx.sessions.login_throttle.lockout_minutes', 15);

        if ($attempts <= 0) {
            shouldnt_happen('rsx.sessions.login_throttle.attempts must be a positive number of failures');
        }

        if ($window_minutes <= 0) {
            shouldnt_happen('rsx.sessions.login_throttle.window_minutes must be a positive number of minutes');
        }

        if ($lockout_minutes <= 0) {
            shouldnt_happen('rsx.sessions.login_throttle.lockout_minutes must be a positive number of minutes');
        }

        return [
            'enabled' => $enabled,
            'attempts' => $attempts,
            'window_minutes' => $window_minutes,
            'lockout_minutes' => $lockout_minutes,
        ];
    }
}
