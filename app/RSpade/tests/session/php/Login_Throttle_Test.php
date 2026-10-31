<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Login_Throttle: the sign-in rate limit on the authentication surface.
 *
 * ISOLATION. The throttle's state is a redis failure log per address bucket and per account,
 * living outside the per-test transaction and outside the process - so every test invents an
 * ADDRESS and an ACCOUNT nobody else uses, passes them explicitly, and registers them for the
 * teardown reset. Nothing here reads the ambient client address except the tests whose
 * SUBJECT is that there isn't one.
 *
 * TIME is never waited on. Rule 1 (the trailing span) is driven live; the sustained-average
 * rule, which only shows itself across minutes, is asserted on the pure decision
 * Login_Throttle::retry_after_for() over synthetic attempt times.
 *
 * NOT COVERED HERE - verify_credentials() THROWING. It throttles on Session::get_client_ip(),
 * which is null in CLI by design, so a PHP test proves only the CLI half of that contract. The
 * throw itself is verified live over HTTP; see tests/session/test_catalog.md.
 */
class Login_Throttle_Test extends Rsx_Test_Abstract
{
    /**
     * [account, ip] pairs touched by these tests, cleared in teardown.
     */
    private static array $buckets_used = [];

    /**
     * The throttle config as the test process found it, restored in teardown.
     */
    private static ?array $original_config = null;

    public static function setup()
    {
        static::$original_config = config('rsx.sessions.login_throttle');
    }

    public static function teardown()
    {
        foreach (static::$buckets_used as [$account, $ip]) {
            Login_Throttle::reset($account, $ip);
        }

        static::$buckets_used = [];

        if (static::$original_config !== null) {
            config(['rsx.sessions.login_throttle' => static::$original_config]);
        }
    }

    /**
     * An IPv4 address nothing else uses, registered for teardown.
     */
    private static function __fresh_ip(): string
    {
        $ip = '203.0.113.' . random_int(1, 254) . '-' . uniqid();
        static::$buckets_used[] = [null, $ip];

        return $ip;
    }

    /**
     * An account nothing else uses, registered for teardown.
     */
    private static function __fresh_account(): string
    {
        return 'throttle_' . uniqid() . '@example.com';
    }

    /**
     * Put the throttle into a known configuration for one test.
     */
    private static function __configure(bool $enabled = true, int $attempts = 3, int $seconds = 60): void
    {
        config(['rsx.sessions.login_throttle' => [
            'enabled' => $enabled,
            'attempts' => $attempts,
            'seconds' => $seconds,
        ]]);
    }

    /**
     * Make one attempt that FAILS, the way a verifier does: checked first, then counted.
     * Reports whether the check refused it.
     */
    private static function __refused(?string $account, string $ip): bool
    {
        static::$buckets_used[] = [$account, $ip];

        try {
            Login_Throttle::require_not_throttled($account, $ip);
        } catch (Auth_Throttled_Exception $e) {
            return true;
        }

        Login_Throttle::record_failure($account, $ip);

        return false;
    }

    // -------------------------------------------------------------------------
    // The limit, live
    // -------------------------------------------------------------------------

    /**
     * `attempts` failures inside `seconds` are allowed; the next attempt is refused and says
     * when to come back, within the span.
     */
    public static function test_the_attempt_after_the_limit_is_refused()
    {
        static::__configure(attempts: 3, seconds: 60);
        $ip = static::__fresh_ip();

        static::__assert_false(static::__refused(null, $ip), 'first');
        static::__assert_false(static::__refused(null, $ip), 'second');
        static::__assert_false(static::__refused(null, $ip), 'third - at the limit');
        static::__assert_true(static::__refused(null, $ip), 'the fourth is refused');

        $retry = Login_Throttle::retry_after_seconds(null, $ip);
        static::__assert_greater_than(0, $retry, 'a wait is reported');
        static::__assert_true($retry <= 60, "and it is inside the span (got {$retry})");
    }

    /**
     * The message is the same for every throttled caller and carries no timing.
     */
    public static function test_the_refusal_message_is_fixed()
    {
        static::__configure(attempts: 1);
        $ip = static::__fresh_ip();

        Login_Throttle::record_failure(null, $ip);

        static::__assert_throws(Auth_Throttled_Exception::class, fn () => Login_Throttle::require_not_throttled(null, $ip), Auth_Throttled_Exception::MESSAGE);
    }

    /**
     * The ACCOUNT bucket: attempts naming one account from many addresses are limited together,
     * so rotating addresses buys nothing against one account.
     */
    public static function test_one_account_is_limited_across_addresses()
    {
        static::__configure(attempts: 2);
        $account = static::__fresh_account();

        static::__assert_false(static::__refused($account, static::__fresh_ip()), 'first address');
        static::__assert_false(static::__refused($account, static::__fresh_ip()), 'second address');
        static::__assert_true(static::__refused($account, static::__fresh_ip()), 'a third address is refused for the same account');
        static::__assert_false(static::__refused(static::__fresh_account(), static::__fresh_ip()), 'another account is not');
    }

    /**
     * The ADDRESS bucket: attempts from one address are limited together whatever account they
     * name, so spraying many accounts from one place is limited too.
     */
    public static function test_one_address_is_limited_across_accounts()
    {
        static::__configure(attempts: 2);
        $ip = static::__fresh_ip();

        static::__assert_false(static::__refused(static::__fresh_account(), $ip), 'first account');
        static::__assert_false(static::__refused(static::__fresh_account(), $ip), 'second account');
        static::__assert_true(static::__refused(static::__fresh_account(), $ip), 'a third account from the same address is refused');
    }

    /**
     * An IPv6 address counts by its /64: addresses inside one /64 share a bucket, another /64
     * has its own.
     */
    public static function test_ipv6_addresses_count_by_their_64()
    {
        static::__configure(attempts: 2);
        $prefix = sprintf('2001:db8:%x:%x', random_int(1, 0xffff), random_int(1, 0xffff));
        static::$buckets_used[] = [null, $prefix . '::1'];

        static::__assert_false(static::__refused(null, $prefix . '::1'), 'first host');
        static::__assert_false(static::__refused(null, $prefix . ':0:0:0:2'), 'second host, same /64');
        static::__assert_true(static::__refused(null, $prefix . ':abcd:1234:5678:9abc'), 'a third host in the same /64 is refused');

        $other = sprintf('2001:db8:%x:%x::1', random_int(1, 0xffff), random_int(1, 0xffff));
        static::$buckets_used[] = [null, $other];
        static::__assert_false(static::__refused(null, $other), 'another /64 is not');
    }

    public static function test_address_bucket_normalizes()
    {
        static::__assert_equals('198.51.100.7', Login_Throttle::address_bucket('198.51.100.7'), 'IPv4 is itself');
        static::__assert_equals('198.51.100.7', Login_Throttle::address_bucket('::ffff:198.51.100.7'), 'IPv4-mapped is the IPv4 address');
        static::__assert_equals('2001:db8:1:2::/64', Login_Throttle::address_bucket('2001:db8:1:2:a:b:c:d'), 'IPv6 by /64');
        static::__assert_equals(
            Login_Throttle::address_bucket('2001:0DB8:0001:0002::1'),
            Login_Throttle::address_bucket('2001:db8:1:2:ffff::'),
            'spelling does not matter'
        );
    }

    /**
     * A refused attempt is not a failure, so the span frees on its own: after a refusal, the
     * wait does not grow with each further refused try.
     */
    public static function test_a_refused_attempt_is_not_counted()
    {
        static::__configure(attempts: 1, seconds: 60);
        $ip = static::__fresh_ip();

        Login_Throttle::record_failure(null, $ip);
        $first_wait = Login_Throttle::retry_after_seconds(null, $ip);

        for ($i = 0; $i < 5; $i++) {
            static::__refused(null, $ip);
        }

        static::__assert_true(
            Login_Throttle::retry_after_seconds(null, $ip) <= $first_wait,
            'refused attempts did not extend the wait'
        );
    }

    // -------------------------------------------------------------------------
    // The decision, over synthetic time
    // -------------------------------------------------------------------------

    public static function test_the_trailing_span_rule()
    {
        $now = 1000000.0;

        static::__assert_equals(0, Login_Throttle::retry_after_for([], $now, 8, 60), 'nothing yet');
        static::__assert_equals(
            0,
            Login_Throttle::retry_after_for(array_map(fn ($i) => $now - 50 + $i, range(0, 6)), $now, 8, 60),
            'seven in the span: the eighth is accepted'
        );
        static::__assert_equals(
            10,
            Login_Throttle::retry_after_for(array_map(fn ($i) => $now - 50 + $i, range(0, 7)), $now, 8, 60),
            'eight in the span: wait until the oldest leaves it'
        );
    }

    /**
     * Bursts spaced just outside the span keep rule 1 satisfied; the sustained average refuses
     * them once the limit's worth has accumulated. Eight at once, a gap of 61 s, eight more:
     * sixteen attempts in 61 s is well over 8 per 60 s.
     */
    public static function test_the_sustained_average_rule()
    {
        $now = 1000000.0;
        $first_burst = array_fill(0, 8, $now - 61);
        $second_burst = array_map(fn ($i) => $now - 1 + $i * 0.01, range(0, 6));

        static::__assert_greater_than(
            0,
            Login_Throttle::retry_after_for(array_merge($first_burst, $second_burst), $now, 8, 60),
            'fifteen in 61 s is refused though only seven fall in the trailing 60 s'
        );

        // The same count spread evenly - one every 8 s - averages under the limit.
        $even = array_map(fn ($i) => $now - 8 * (15 - $i), range(0, 14));
        static::__assert_equals(0, Login_Throttle::retry_after_for($even, $now, 8, 60), 'one every 8 s is under 8 per 60 s');
    }

    /**
     * The average only applies once the limit's worth of attempts has been made.
     */
    public static function test_the_average_needs_the_limit_reached()
    {
        $now = 1000000.0;

        static::__assert_equals(
            0,
            Login_Throttle::retry_after_for([$now - 61, $now - 61, $now - 61], $now, 8, 60),
            'three attempts are never averaged'
        );
    }

    // -------------------------------------------------------------------------
    // The switch, and callers with no address
    // -------------------------------------------------------------------------

    /**
     * A sign-in that SUCCEEDS costs nothing: only failures count, so many people signing in
     * from one address (an office behind one NAT) are never throttled by doing so.
     */
    public static function test_successful_attempts_are_not_counted()
    {
        static::__configure(attempts: 2);
        $ip = static::__fresh_ip();

        for ($i = 0; $i < 10; $i++) {
            Login_Throttle::require_not_throttled(static::__fresh_account(), $ip);
        }

        static::__assert_equals(0, Login_Throttle::retry_after_seconds(null, $ip), 'ten checked, none failed, nothing counted');
    }

    public static function test_disabled_counts_and_enforces_nothing()
    {
        static::__configure(enabled: false, attempts: 1);
        $ip = static::__fresh_ip();

        static::__assert_false(static::__refused(null, $ip), 'first');
        static::__assert_false(static::__refused(null, $ip), 'second');
        static::__assert_equals(0, Login_Throttle::retry_after_seconds(null, $ip), 'nothing enforced');
    }

    /**
     * A caller with no remote party is not a caller anyone can throttle: in CLI every command,
     * task and test would otherwise share one bucket.
     */
    public static function test_a_caller_with_no_client_address_is_never_throttled()
    {
        static::__configure(attempts: 1);
        $account = static::__fresh_account();

        Login_Throttle::record_failure($account);
        Login_Throttle::record_failure($account);

        static::__assert_equals(0, Login_Throttle::retry_after_seconds($account), 'nothing counted without an address');
    }

    public static function test_verify_credentials_is_not_throttled_in_cli()
    {
        static::__configure(attempts: 1);
        $email = static::__fresh_account();

        RsxAuth::verify_credentials(['email' => $email, 'password' => 'wrong']);

        static::__assert_null(
            RsxAuth::verify_credentials(['email' => $email, 'password' => 'wrong']),
            'verify_credentials() still answers the credential question rather than refusing'
        );
    }

    public static function test_a_nonsensical_config_fails_loud()
    {
        static::__configure(attempts: 0);
        static::__assert_throws(\Throwable::class, fn () => Login_Throttle::retry_after_seconds(null, static::__fresh_ip()), 'attempts');

        static::__configure(seconds: 0);
        static::__assert_throws(\Throwable::class, fn () => Login_Throttle::retry_after_seconds(null, static::__fresh_ip()), 'seconds');
    }

    // -------------------------------------------------------------------------
    // Uncaught, it is a 429
    // -------------------------------------------------------------------------

    public static function test_the_exception_is_a_429_with_retry_after()
    {
        $e = new Auth_Throttled_Exception(17);

        static::__assert_instance_of(HttpExceptionInterface::class, $e);
        static::__assert_equals(429, $e->getStatusCode());
        static::__assert_equals(17, (int) $e->getHeaders()['Retry-After']);
        static::__assert_equals(17, $e->retry_after_seconds);
    }

    public static function test_ajax_answers_too_many_requests()
    {
        $e = new Auth_Throttled_Exception(5);

        static::__assert_true(Ajax::is_coded_failure($e), 'a coded refusal, not a fault');
        static::__assert_equals('too_many_requests', Ajax::ERROR_TOO_MANY_REQUESTS);
        static::__assert_equals(Auth_Throttled_Exception::MESSAGE, Ajax::get_default_message(Ajax::ERROR_TOO_MANY_REQUESTS));
    }
}
