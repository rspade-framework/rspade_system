<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Cache\RsxCache;

/**
 * Password_Verifier - the password comparison every sign-in front door makes, in equal time
 * whether or not the identity exists.
 *
 * A front door that answered an unknown address at once, and a known one only after a
 * password hash verification (tens of milliseconds of bcrypt, by design), would tell anybody
 * with a stopwatch which addresses hold accounts - the response says null either way, the
 * clock does not. So the unknown-identity path pays the same verification against a dummy
 * hash: verify_unknown() runs Hash::check() and discards the answer, and the caller still
 * refuses.
 *
 * THE DUMMY MUST COST WHAT A REAL HASH COSTS, so it is held to the CURRENT hashing config
 * (config/hashing.php - driver, bcrypt rounds, argon parameters) by Laravel's own judgement,
 * Hash::needsRehash():
 *
 *   1. The shipped literal below is a bcrypt hash at the shipped default cost (10). Under the
 *      default config it is used as-is: no hashing to mint it, no cache round trip.
 *   2. When the config differs (other rounds, an argon driver), a dummy is minted with
 *      Hash::make() and kept in the persistent cache, so a request pays the mint only after a
 *      cache reset - never on every attempt. A PHP static alone would not do: under php-fpm a
 *      static lives for one request, so the mint would be paid on every unknown-address
 *      attempt and reopen the very gap this class closes.
 *
 * The plaintext behind the literal is irrelevant and public: verify_unknown() ignores the
 * result, so typing it for an unknown address still fails.
 */
class Password_Verifier
{
    /**
     * A bcrypt hash at cost 10 - the shipped default (config/hashing.php, BCRYPT_ROUNDS).
     */
    private const DEFAULT_DUMMY_HASH = '$2y$10$Gokadmxuow5G87MJxFXwpuE8xTjPYIQHyPTeNvzULizSBqCXcn.EG';

    private const CACHE_KEY = 'auth:unknown_identity_dummy_hash';

    /**
     * Test observation: how many dummy verifications this process has made.
     */
    public static int $_dummy_verifications = 0;

    private static ?string $_dummy_hash = null;

    /**
     * Does this password match this stored hash? A missing stored hash pays the dummy
     * verification and answers false, so an identity with no password is as slow to refuse
     * as one with a wrong password.
     */
    public static function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            self::verify_unknown($password);

            return false;
        }

        return Hash::check($password, $hash);
    }

    /**
     * Pay one password verification for an identity that does not exist. Always refuses -
     * the caller returns its own "not found" answer after it.
     */
    public static function verify_unknown(string $password): void
    {
        self::$_dummy_verifications++;

        Hash::check($password, self::_dummy_hash());
    }

    /**
     * The dummy hash, matched to the current hashing config (see the class header).
     */
    public static function _dummy_hash(): string
    {
        if (self::$_dummy_hash !== null && !Hash::needsRehash(self::$_dummy_hash)) {
            return self::$_dummy_hash;
        }

        if (!Hash::needsRehash(self::DEFAULT_DUMMY_HASH)) {
            return self::$_dummy_hash = self::DEFAULT_DUMMY_HASH;
        }

        $cached = RsxCache::get_persistent(self::CACHE_KEY);

        if (is_string($cached) && !Hash::needsRehash($cached)) {
            return self::$_dummy_hash = $cached;
        }

        $minted = Hash::make('rsx-unknown-identity');
        RsxCache::set_persistent(self::CACHE_KEY, $minted);

        return self::$_dummy_hash = $minted;
    }
}
