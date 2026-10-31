<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Session\Session;

/**
 * Rate limit for the authentication surface.
 *
 * WHAT IT COUNTS: FAILED ATTEMPTS - wrong answers, not requests - against two buckets at
 * once: the CLIENT ADDRESS and the ACCOUNT (the email the attempt names). Every attempt is
 * CHECKED first, and either bucket over its limit refuses it. A sign-in that succeeds costs
 * nothing, so an office behind one address signing in at nine o'clock never approaches the
 * limit; guessing and spraying are made of failures, and those are what accumulate. The
 * address bucket stops one source spraying many accounts; the account bucket stops many
 * sources (an IPv6 range, a botnet) working one account. An IPv6 address counts by its /64,
 * the block one subscriber is handed, so rotating addresses inside it buys nothing.
 *
 * THE LIMIT: rsx.sessions.login_throttle - `attempts` failures per `seconds` (8 per 60 by
 * default). Two rules enforce it:
 *   1. the trailing `seconds`: once `attempts` failures fall inside it, attempts are refused;
 *   2. the trailing SUSTAINED_WINDOW_SECONDS: once it holds at least `attempts` failures,
 *      their average rate since the first of them may not exceed `attempts` per `seconds` -
 *      so a client cannot hold the limit by spacing its bursts just outside rule 1.
 * A refused attempt is not a failure: the window frees on its own as time passes.
 *
 * WHY THE LIMIT IS LOOSE. Turnstile and second factors are what protect an account; this is
 * a brake on automated attempts that somehow get past Turnstile, so a flood cannot happen.
 *
 * WHERE IT IS ENFORCED: require_not_throttled() is the FIRST statement of
 * RsxAuth::verify_credentials(), Portal_Auth::verify_credentials(), the second-factor and
 * passkey verifications and the federated sign-in - before any lookup, so a throttled caller
 * learns nothing. It THROWS Auth_Throttled_Exception (an HTTP 429 when nothing catches it); a
 * login function catches it and renders the message as an ordinary form error. Failures
 * arrive through Login_History::record_failure() / Portal_Login_History::record_failure(),
 * which call record_failure() here - so an outcome an application records itself (a bad
 * second factor, an account status) counts without anything extra.
 *
 * A CALLER WITH NO CLIENT ADDRESS IS NEVER THROTTLED. Session::get_client_ip() answers null
 * outside a request - CLI, tasks, tests - and there is no remote party to limit there.
 *
 * FAIL CLOSED. Nothing here catches a cache error: a redis failure propagates and the login
 * fails loud. The ONE tolerated degradation is maintenance mode, where Rsx_Counter answers no
 * events without touching redis - the web tier is answering 503 then anyway.
 *
 * Storage: Rsx_Counter event logs (redis database 3). See rsx:man session, LOGIN THROTTLE.
 */
class Login_Throttle
{
    /**
     * The span rule 2 averages over. Internal: the configured limit is failures per seconds.
     */
    private const SUSTAINED_WINDOW_SECONDS = 600;

    private const IP_KEY_PREFIX = 'login_throttle:ip:';

    private const ACCOUNT_KEY_PREFIX = 'login_throttle:account:';

    /**
     * Refuse an attempt from a client or for an account that is over the limit.
     *
     * Call this BEFORE anything that could confirm or deny a credential - a lookup, a hash
     * comparison, a second-factor check. A throttled caller must learn nothing at all.
     *
     * @param string|null $account The account the attempt names (an email); null when the
     *        attempt names none yet (a passkey or federated sign-in) - the address alone counts
     * @param string|null $ip Client address; defaults to Session::get_client_ip()
     * @return void
     * @throws Auth_Throttled_Exception when the address or the account is over the limit
     */
    public static function require_not_throttled(?string $account = null, ?string $ip = null): void
    {
        $retry_after_seconds = self::retry_after_seconds($account, $ip);

        if ($retry_after_seconds > 0) {
            throw new Auth_Throttled_Exception($retry_after_seconds);
        }
    }

    /**
     * Count one failed attempt against the client's address and, when it names one, the
     * account.
     *
     * Called by Login_History::record_failure() and Portal_Login_History::record_failure().
     * Call it directly only from a path that checks a secret and records through neither (a
     * PIN, an access code).
     *
     * @param string|null $account The account the failed attempt named (an email)
     * @param string|null $ip Client address; defaults to Session::get_client_ip()
     * @return void
     */
    public static function record_failure(?string $account = null, ?string $ip = null): void
    {
        if (!self::_config()['enabled']) {
            return;
        }

        foreach (self::_keys($account, $ip) as $key) {
            Rsx_Counter::record_event($key, self::SUSTAINED_WINDOW_SECONDS);
        }
    }

    /**
     * How long this client (and account) must wait before an attempt is accepted, in seconds.
     * 0 when it would be accepted now.
     *
     * @param string|null $account
     * @param string|null $ip Client address; defaults to Session::get_client_ip()
     * @return int
     */
    public static function retry_after_seconds(?string $account = null, ?string $ip = null): int
    {
        $config = self::_config();

        if (!$config['enabled']) {
            return 0;
        }

        $retry_after_seconds = 0;
        foreach (self::_keys($account, $ip) as $key) {
            $retry_after_seconds = max($retry_after_seconds, self::_retry_after($key, $config));
        }

        return $retry_after_seconds;
    }

    /**
     * Clear the failure history of an address and/or an account.
     *
     * For a test, and for an operator releasing a customer - from the command line too: an
     * account is cleared whether or not there is a client address.
     *
     * @param string|null $account
     * @param string|null $ip Client address; defaults to Session::get_client_ip()
     * @return void
     */
    public static function reset(?string $account = null, ?string $ip = null): void
    {
        $keys = [];

        $ip = $ip ?? Session::get_client_ip();
        if ($ip !== null && trim($ip) !== '') {
            $keys[] = self::IP_KEY_PREFIX . self::address_bucket(trim($ip));
        }

        $account = $account === null ? '' : mb_strtolower(trim($account));
        if ($account !== '') {
            $keys[] = self::ACCOUNT_KEY_PREFIX . $account;
        }

        foreach ($keys as $key) {
            Rsx_Counter::reset($key);
        }
    }

    /**
     * The bucket an address counts in: an IPv4 address as itself, an IPv6 address by its /64
     * (an IPv4-mapped IPv6 address as the IPv4 address it carries).
     *
     * @param string $ip
     * @return string
     */
    public static function address_bucket(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * Seconds until an attempt under $key would be accepted (0 = now).
     *
     * @param string $key
     * @param array $config
     * @return int
     */
    private static function _retry_after(string $key, array $config): int
    {
        $times = Rsx_Counter::event_times($key, max($config['seconds'], self::SUSTAINED_WINDOW_SECONDS));

        return self::retry_after_for($times, microtime(true), $config['attempts'], $config['seconds']);
    }

    /**
     * THE DECISION, as a pure function: given the times of the failures in one bucket, how many
     * seconds until an attempt is accepted (0 = now)?
     *
     * Rule 1: at most $limit failures in the trailing $seconds - the wait is until enough of
     * them leave that span for one more to fit. Rule 2: once the trailing
     * SUSTAINED_WINDOW_SECONDS holds $limit failures, their average rate since the first of
     * them may not exceed $limit per $seconds - the wait is until enough time has passed for
     * the average to fall back to the limit, or until the first attempt leaves the window,
     * whichever comes first.
     *
     * @param float[] $times Failures, in seconds, oldest first
     * @param float $now The current time, in seconds
     * @param int $limit Attempts allowed per $seconds
     * @param int $seconds The span the limit is stated over
     * @return int
     */
    public static function retry_after_for(array $times, float $now, int $limit, int $seconds): int
    {
        $times = array_values(array_filter($times, fn ($t) => $t > $now - max($seconds, self::SUSTAINED_WINDOW_SECONDS)));
        $wait = 0.0;

        $recent = array_values(array_filter($times, fn ($t) => $t > $now - $seconds));
        if (count($recent) >= $limit) {
            $wait = max($wait, $recent[count($recent) - $limit] + $seconds - $now);
        }

        $count = count($times);
        if ($count >= $limit) {
            $first = $times[0];
            $elapsed = max($now - $first, $seconds);

            if ($count / $elapsed > $limit / $seconds) {
                $until_average = $first + ($count * $seconds / $limit) - $now;
                $until_expiry = $first + self::SUSTAINED_WINDOW_SECONDS - $now;
                $wait = max($wait, min($until_average, $until_expiry));
            }
        }

        return $wait > 0 ? (int) ceil($wait) : 0;
    }

    /**
     * The event-log keys an attempt is judged by: the address bucket (when there is a client)
     * and the account (when the attempt names one). None at all for a caller with no
     * client address.
     *
     * @param string|null $account
     * @param string|null $ip
     * @return string[]
     */
    private static function _keys(?string $account, ?string $ip): array
    {
        $ip = $ip ?? Session::get_client_ip();

        if ($ip === null || trim($ip) === '') {
            return [];
        }

        $keys = [self::IP_KEY_PREFIX . self::address_bucket(trim($ip))];

        $account = $account === null ? '' : mb_strtolower(trim($account));
        if ($account !== '') {
            $keys[] = self::ACCOUNT_KEY_PREFIX . $account;
        }

        return $keys;
    }

    /**
     * The validated throttle configuration.
     *
     * A nonsensical value is a broken deployment, not an input to work around: a zero budget
     * would refuse every visitor's first attempt, and a zero span cannot hold an attempt.
     *
     * @return array{enabled: bool, attempts: int, seconds: int}
     */
    private static function _config(): array
    {
        $enabled = (bool) config('rsx.sessions.login_throttle.enabled', true);
        $attempts = (int) config('rsx.sessions.login_throttle.attempts', 8);
        $seconds = (int) config('rsx.sessions.login_throttle.seconds', 60);

        if ($attempts <= 0) {
            shouldnt_happen('rsx.sessions.login_throttle.attempts must be a positive number of attempts');
        }

        if ($seconds <= 0) {
            shouldnt_happen('rsx.sessions.login_throttle.seconds must be a positive number of seconds');
        }

        return [
            'enabled' => $enabled,
            'attempts' => $attempts,
            'seconds' => $seconds,
        ];
    }
}
