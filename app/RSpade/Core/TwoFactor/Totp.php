<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

/**
 * Totp - RFC 6238 time-based one-time passwords, and the RFC 4648 base32 the authenticator
 * apps expect a seed in.
 *
 * WHY THIS IS HAND-WRITTEN AND NOT A PACKAGE. The whole algorithm is an HMAC, a truncation
 * and a modulo - the code below is shorter than the composer entry would be, it is checked
 * against the RFC's own test vectors in tests/two_factor, and it has no attack surface to
 * inherit from anybody else's release cadence.
 *
 * THE PARAMETERS ARE FIXED AT THE INTEROPERABLE ONES: SHA1, 6 digits, a 30-second step.
 * They are not configurable and must not become configurable. Every authenticator app in
 * the world reads those three from the otpauth:// URI and several of the popular ones
 * quietly ignore anything else, so a "stronger" choice here does not produce a stronger
 * second factor - it produces a user whose codes never match. SHA1 inside an HMAC is not
 * the broken SHA1: HMAC does not rest on collision resistance.
 *
 * DRIFT AND REPLAY ARE THE TWO REAL PROBLEMS, and verify() is where both are answered:
 * a phone clock is allowed to be one step out in either direction, and a code is refused
 * outright once its timestep has been spent. See verify().
 *
 * Every method is static and pure. Nothing here reads a session, a config value or the
 * database - the caller owns all of that.
 *
 * See: php artisan rsx:man two_factor
 */
class Totp
{
    /**
     * The RFC 4648 base32 alphabet, in value order. Position IS the value.
     */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Seconds per timestep. RFC 6238's own default and the only value the ecosystem
     * reliably honours.
     */
    public const PERIOD = 30;

    /**
     * Digits in a generated code. Six, for the same interoperability reason.
     */
    public const DIGITS = 6;

    /**
     * How many timesteps either side of the present one are accepted, to tolerate a phone
     * whose clock is a little off.
     *
     * This is NOT a timeout: nothing is being given a deadline and nothing fails when it
     * expires. It is the width of a clock-skew tolerance, and one step in each direction is
     * the value RFC 6238 section 5.2 recommends ("we RECOMMEND that at most one time step
     * is allowed as the network delay"). Widening it would multiply the number of codes
     * valid at any instant, which is exactly the number an online guesser is up against.
     */
    public const DRIFT_STEPS = 1;

    /**
     * Bytes of entropy in a generated seed. 20 bytes = 160 bits, the SHA1 block size and
     * the length RFC 4226 section 4 requires ("the shared secret MUST be at least 128 bits,
     * we RECOMMEND 160 bits").
     */
    private const SECRET_BYTES = 20;

    // -------------------------------------------------------------------------
    // Seeds
    // -------------------------------------------------------------------------

    /**
     * Mint a new shared secret, base32 encoded and ready to hand to an authenticator app.
     *
     * random_bytes() and nothing else: this is key material, so the source has to be the
     * CSPRNG. The base32 is not encryption and never was - it exists because a seed has to
     * survive being read off a screen and typed in by hand when a camera is not available.
     *
     * @return string The base32 seed (unpadded, 32 characters for 20 bytes).
     */
    public static function generate_secret(): string
    {
        return self::base32_encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * The otpauth:// URI an authenticator app scans, per the Key URI Format.
     *
     * The LABEL is "issuer:account" and the issuer ALSO rides as a query parameter. That
     * duplication is in the spec, not a mistake: older apps read the label prefix, newer
     * ones read the parameter, and an app that gets only one of them files the account
     * under the wrong heading in the user's list.
     *
     * rawurlencode() throughout, because an email address may contain a '+' and
     * urlencode() would turn it into a space.
     *
     * @param string $secret The base32 seed from generate_secret().
     * @param string $email The account the code belongs to, shown in the app.
     * @param string $issuer The application's name, shown in the app.
     * @return string
     */
    public static function provisioning_uri(string $secret, string $email, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($email);

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    // -------------------------------------------------------------------------
    // Codes
    // -------------------------------------------------------------------------

    /**
     * The code for one specific timestep - RFC 4226 section 5.3 dynamic truncation, over
     * the counter RFC 6238 derives from the clock.
     *
     * The offset is taken from the low nibble of the last byte, four bytes are read from
     * there, the sign bit is masked off (0x7f on the first byte - the RFC works in signed
     * 32-bit arithmetic and a negative would break the modulo), and the result is reduced
     * to DIGITS decimal places and zero padded. A code is a STRING, always: "012345" is a
     * valid code and the integer 12345 is not the same thing.
     *
     * @param string $secret The base32 seed.
     * @param int $timestep The counter value, normally floor(time() / PERIOD).
     * @return string The zero-padded code.
     */
    public static function code_for(string $secret, int $timestep): string
    {
        $key = self::base32_decode($secret);

        // The counter is the 8-byte big-endian timestep. 'J' is 64-bit big-endian.
        $counter = pack('J', $timestep);

        $hash = hash_hmac('sha1', $counter, $key, true);

        $offset = ord($hash[19]) & 0x0f;

        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $code = $binary % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a code the user just typed, against the present timestep and one step either
     * side, and REFUSE any timestep that has already been spent.
     *
     * THE REPLAY RULE IS THE POINT OF THIS METHOD. Without it a code stays usable for the
     * whole time it is displayed plus the drift window - so a code read over a shoulder, or
     * left in a phishing proxy's log, can be presented a second time and works. Refusing
     * every timestep at or below the last accepted one closes that: a code is good exactly
     * once, and the second presentation of it is a failure like any other. The caller is
     * responsible for PERSISTING the returned timestep, which is why this returns it rather
     * than a bare true.
     *
     * The comparison is hash_equals(), not ===. A code is a secret being compared against a
     * value derived from another secret, and a byte-at-a-time comparison leaks how much of
     * the guess was right through its own duration.
     *
     * NOT A TIMEOUT. The drift window and the replay floor bound WHICH CODES ARE VALID,
     * which is the security property TOTP exists to provide. Nothing here gives an
     * operation a deadline.
     *
     * @param string $secret The base32 seed.
     * @param string $code The code the user typed.
     * @param int $last_accepted_timestep The highest timestep already spent for this
     *                                    credential; 0 when none has been.
     * @return int|false The accepted timestep, for the caller to persist, or false.
     */
    public static function verify(string $secret, string $code, int $last_accepted_timestep): int|false
    {
        $code = trim($code);

        // Shape first, so a pasted word never reaches the HMAC. DIGITS decimal digits
        // exactly - not "at least", because a longer string is not a code with something
        // extra on the end, it is not a code.
        if (preg_match('/^[0-9]{' . self::DIGITS . '}$/', $code) !== 1) {
            return false;
        }

        $current = intdiv(time(), self::PERIOD);

        for ($offset = -self::DRIFT_STEPS; $offset <= self::DRIFT_STEPS; $offset++) {
            $timestep = $current + $offset;

            // Already spent. Includes the case where the clock skew window reaches back
            // over a step this credential has used.
            if ($timestep <= $last_accepted_timestep) {
                continue;
            }

            if (hash_equals(self::code_for($secret, $timestep), $code)) {
                return $timestep;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Base32 (RFC 4648)
    // -------------------------------------------------------------------------

    /**
     * Encode binary as unpadded base32.
     *
     * Bits are accumulated into a buffer and drained five at a time. The padding '=' is
     * omitted: the Key URI Format's own examples are unpadded, and an '=' in a URI query
     * value is one more thing to get wrong for no benefit.
     *
     * @param string $binary
     * @return string
     */
    public static function base32_encode(string $binary): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;

        for ($i = 0, $len = strlen($binary); $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($binary[$i]);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::BASE32_ALPHABET[($buffer >> $bits) & 0x1f];
            }
        }

        // A trailing partial group is left-aligned in its five bits.
        if ($bits > 0) {
            $out .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 0x1f];
        }

        return $out;
    }

    /**
     * Decode base32 back to binary.
     *
     * Case-insensitive and tolerant of the '=' padding and of the spaces a user inserts
     * when transcribing a seed by hand. Anything else is a corrupt seed and throws rather
     * than silently decoding to the wrong key - a wrong key produces codes that never
     * match, which is a bug report that costs a day to trace back to here.
     *
     * @param string $base32
     * @return string
     * @throws \RuntimeException When the input contains a character outside the alphabet.
     */
    public static function base32_decode(string $base32): string
    {
        $base32 = strtoupper(str_replace([' ', '='], '', $base32));

        $out = '';
        $buffer = 0;
        $bits = 0;

        for ($i = 0, $len = strlen($base32); $i < $len; $i++) {
            $value = strpos(self::BASE32_ALPHABET, $base32[$i]);

            if ($value === false) {
                throw new \RuntimeException('Not a base32 string: unexpected character at position ' . $i);
            }

            $buffer = ($buffer << 5) | $value;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xff);
            }
        }

        // Leftover bits are the left-aligned remainder of the final group and are discarded.
        return $out;
    }
}
