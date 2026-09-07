<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Totp;

/**
 * Totp is a hand-written RFC 6238 implementation, so the RFC's own test vectors are what
 * makes that defensible - these tests are the reason the framework does not carry a package
 * for thirty lines of HMAC.
 *
 * TWO THINGS ARE PINNED HERE, and they are different in kind:
 *
 *  - CORRECTNESS: code_for() against every SHA1 vector in RFC 6238 Appendix B. The vectors
 *    publish 8-digit codes; RSpade issues 6, which is the same dynamic truncation reduced
 *    modulo 10^6, so the expected value is the last six digits of the published one.
 *
 *  - THE SECURITY RULES verify() adds on top: the +-1 clock-skew window, the refusal of
 *    anything further out, and the REPLAY FLOOR that refuses a timestep at or below the
 *    last one accepted. The replay rule is the one worth having a test for - it is invisible
 *    in normal use and its absence would only ever be discovered by an attacker.
 *
 * PURE LOGIC, no database: $use_database_transactions is off. Nothing here reads a session,
 * a config value or a row.
 *
 * TIME: the drift and replay tests derive their timesteps from the CURRENT clock rather than
 * a fixed instant, because verify() reads time() itself and has no injection seam. That is
 * deliberate in the subject - a clock seam on a TOTP verifier is an attack surface - so the
 * tests bend instead.
 */
class Totp_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The RFC 4226 / 6238 test seed: the ASCII string "12345678901234567890", which is what
     * the published vectors are computed against. Totp takes a base32 seed, so the vectors
     * are driven through base32_encode() rather than being restated in base32 by hand - that
     * way a bug in the encoder cannot hide behind a hard-coded constant.
     */
    private const RFC_SECRET_ASCII = '12345678901234567890';

    /**
     * RFC 6238 Appendix B, the SHA1 rows: unix time => the published 8-digit code.
     */
    private const RFC_VECTORS = [
        59 => '94287082',
        1111111109 => '07081804',
        1111111111 => '14050471',
        1234567890 => '89005924',
        2000000000 => '69279037',
        20000000000 => '65353130',
    ];

    private static function __rfc_secret(): string
    {
        return Totp::base32_encode(self::RFC_SECRET_ASCII);
    }

    /**
     * The timestep the clock is in right now - the same expression verify() uses.
     */
    private static function __current_timestep(): int
    {
        return intdiv(time(), Totp::PERIOD);
    }

    // -------------------------------------------------------------------------
    // RFC 6238 correctness
    // -------------------------------------------------------------------------

    /**
     * Every published SHA1 vector. If this fails, the implementation is wrong and no
     * authenticator app on earth will agree with it.
     */
    public static function test_rfc_6238_sha1_vectors()
    {
        $secret = static::__rfc_secret();

        foreach (self::RFC_VECTORS as $unix_time => $published) {
            $timestep = intdiv($unix_time, Totp::PERIOD);
            $expected = substr($published, -Totp::DIGITS);

            static::__assert_equals(
                $expected,
                Totp::code_for($secret, $timestep),
                'RFC 6238 vector at T=' . $unix_time
            );
        }
    }

    /**
     * A code is a zero-padded STRING of exactly DIGITS characters. "012345" is a valid code
     * and the integer 12345 is not the same thing - a caller that compares them loosely gets
     * a login that works four times out of five.
     */
    public static function test_code_is_a_zero_padded_string_of_fixed_length()
    {
        $secret = Totp::generate_secret();

        for ($step = 0; $step < 200; $step++) {
            $code = Totp::code_for($secret, $step);

            static::__assert_true(is_string($code), 'a code is a string');
            static::__assert_equals(Totp::DIGITS, strlen($code), 'a code is always DIGITS long');
            static::__assert_equals(1, preg_match('/^[0-9]+$/', $code), 'a code is all digits');
        }
    }

    // -------------------------------------------------------------------------
    // Seeds and base32
    // -------------------------------------------------------------------------

    /**
     * A generated seed is 160 bits of CSPRNG output in the base32 alphabet, and two of them
     * are never the same.
     */
    public static function test_generate_secret_is_160_bits_of_base32()
    {
        $secret = Totp::generate_secret();

        // 20 bytes = 160 bits = 32 base32 characters.
        static::__assert_equals(32, strlen($secret), '20 bytes encode to 32 base32 characters');
        static::__assert_equals(1, preg_match('/^[A-Z2-7]+$/', $secret), 'RFC 4648 alphabet, unpadded');
        static::__assert_equals(20, strlen(Totp::base32_decode($secret)), 'decodes back to 20 bytes');

        static::__assert_not_equals(
            Totp::generate_secret(),
            Totp::generate_secret(),
            'two seeds are never the same'
        );
    }

    /**
     * base32 round-trips arbitrary binary, and tolerates the spaces and padding a user
     * inserts when transcribing a seed by hand.
     */
    public static function test_base32_round_trips_and_tolerates_human_formatting()
    {
        foreach (['', 'a', 'ab', 'abc', 'abcd', 'abcde', self::RFC_SECRET_ASCII, random_bytes(37)] as $binary) {
            static::__assert_equals(
                bin2hex($binary),
                bin2hex(Totp::base32_decode(Totp::base32_encode($binary))),
                'round trip of ' . strlen($binary) . ' bytes'
            );
        }

        $secret = static::__rfc_secret();
        $spaced = strtolower(chunk_split($secret, 4, ' '));

        static::__assert_equals(
            Totp::base32_decode($secret),
            Totp::base32_decode($spaced),
            'lower case, spaced and padded spellings decode identically'
        );
    }

    /**
     * A seed containing a character outside the alphabet THROWS rather than decoding to
     * plausible bytes. A silently-wrong key produces codes that never match, and that is a
     * bug report which costs a day to trace back to the decoder.
     */
    public static function test_base32_decode_refuses_a_corrupt_seed()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn () => Totp::base32_decode('ABCD1EFG'),
            'base32'
        );
    }

    // -------------------------------------------------------------------------
    // The provisioning URI
    // -------------------------------------------------------------------------

    /**
     * The Key URI Format: the issuer appears TWICE (label prefix and query parameter, as the
     * spec requires) and every component is raw-url-encoded, so a '+' in an address survives
     * as a '+' rather than becoming a space.
     */
    public static function test_provisioning_uri_shape()
    {
        $secret = static::__rfc_secret();
        $uri = Totp::provisioning_uri($secret, 'alice+tag@example.com', 'Acme Inc');

        static::__assert_true(str_starts_with($uri, 'otpauth://totp/'), 'otpauth totp scheme');
        static::__assert_contains('Acme%20Inc:alice%2Btag%40example.com?', $uri, 'label is issuer:account, raw encoded');
        static::__assert_contains('secret=' . $secret, $uri, 'carries the seed');
        static::__assert_contains('issuer=Acme%20Inc', $uri, 'issuer also rides as a parameter');
        static::__assert_contains('algorithm=SHA1', $uri, 'the interoperable algorithm');
        static::__assert_contains('digits=6', $uri, 'the interoperable digit count');
        static::__assert_contains('period=30', $uri, 'the interoperable period');
    }

    // -------------------------------------------------------------------------
    // verify(): drift
    // -------------------------------------------------------------------------

    /**
     * The code for the present timestep is accepted, and verify() RETURNS that timestep so
     * the caller can persist it. A bare true would leave the caller unable to enforce the
     * replay rule at all.
     */
    public static function test_verify_accepts_the_current_timestep_and_returns_it()
    {
        $secret = Totp::generate_secret();
        $now = static::__current_timestep();

        static::__assert_equals(
            $now,
            Totp::verify($secret, Totp::code_for($secret, $now), 0),
            'the current code verifies and reports its timestep'
        );
    }

    /**
     * One step either side is accepted - a phone clock is allowed to be slightly off.
     */
    public static function test_verify_accepts_one_step_of_drift_in_both_directions()
    {
        $secret = Totp::generate_secret();
        $now = static::__current_timestep();

        static::__assert_equals(
            $now - 1,
            Totp::verify($secret, Totp::code_for($secret, $now - 1), 0),
            'a code one step behind is accepted'
        );

        static::__assert_equals(
            $now + 1,
            Totp::verify($secret, Totp::code_for($secret, $now + 1), 0),
            'a code one step ahead is accepted'
        );
    }

    /**
     * Two steps out is refused. The window is a skew tolerance, not a grace period, and
     * widening it multiplies the number of codes an online guesser is up against.
     */
    public static function test_verify_refuses_two_steps_of_drift()
    {
        $secret = Totp::generate_secret();
        $now = static::__current_timestep();

        static::__assert_false(
            Totp::verify($secret, Totp::code_for($secret, $now - 2), 0),
            'two steps behind is outside the window'
        );

        static::__assert_false(
            Totp::verify($secret, Totp::code_for($secret, $now + 2), 0),
            'two steps ahead is outside the window'
        );
    }

    // -------------------------------------------------------------------------
    // verify(): replay
    // -------------------------------------------------------------------------

    /**
     * THE REPLAY RULE. A timestep at or below the last accepted one is refused, so a code
     * that has been used once cannot be used again inside its own validity window - which is
     * exactly the window a shoulder-surfed or proxied code is presented in.
     */
    public static function test_verify_refuses_a_spent_timestep()
    {
        $secret = Totp::generate_secret();
        $now = static::__current_timestep();
        $code = Totp::code_for($secret, $now);

        $accepted = Totp::verify($secret, $code, 0);
        static::__assert_equals($now, $accepted, 'the first presentation is accepted');

        static::__assert_false(
            Totp::verify($secret, $code, $accepted),
            'the same code presented again is refused'
        );
    }

    /**
     * The floor covers the whole drift window, not just the exact step: with the present
     * step already spent, the step BEHIND it is refused too, even though it is inside the
     * skew tolerance.
     */
    public static function test_replay_floor_covers_the_drift_window_beneath_it()
    {
        $secret = Totp::generate_secret();
        $now = static::__current_timestep();

        static::__assert_false(
            Totp::verify($secret, Totp::code_for($secret, $now - 1), $now),
            'a step below the floor is refused even though it is inside the window'
        );

        static::__assert_equals(
            $now + 1,
            Totp::verify($secret, Totp::code_for($secret, $now + 1), $now),
            'the step above the floor is still reachable'
        );
    }

    // -------------------------------------------------------------------------
    // verify(): shape
    // -------------------------------------------------------------------------

    /**
     * Anything that is not exactly DIGITS decimal digits is refused before the HMAC runs. A
     * longer string is not a code with something on the end - it is not a code.
     */
    public static function test_verify_refuses_malformed_codes()
    {
        $secret = Totp::generate_secret();
        $now = static::__current_timestep();
        $valid = Totp::code_for($secret, $now);

        $malformed = [
            '' => 'empty',
            '12345' => 'too short',
            '1234567' => 'too long',
            'abcdef' => 'not numeric',
            '12 45 6' => 'internal spaces',
            '+12345' => 'a sign',
            $valid . '0' => 'a valid code with a digit appended',
        ];

        foreach ($malformed as $code => $why) {
            static::__assert_false(Totp::verify($secret, (string) $code, 0), $why . ' is refused');
        }

        // Surrounding whitespace is NOT malformed - a pasted code often carries it, and
        // refusing that would be a login lost to a formatting difference.
        static::__assert_equals(
            $now,
            Totp::verify($secret, '  ' . $valid . "\n", 0),
            'surrounding whitespace is trimmed, not rejected'
        );
    }

    /**
     * A code from a different seed never verifies, however well-formed it is.
     */
    public static function test_verify_refuses_a_code_from_another_seed()
    {
        $mine = Totp::generate_secret();
        $theirs = Totp::generate_secret();
        $now = static::__current_timestep();

        static::__assert_false(
            Totp::verify($mine, Totp::code_for($theirs, $now), 0),
            'another seed\'s code is not my code'
        );
    }
}
