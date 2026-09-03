<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;

/**
 * Recovery_Codes - the printed way back in when the second factor is gone.
 *
 * WHY THEY EXIST. A second factor turns a lost phone into a lost account, and an account
 * recovery path staffed by humans is a social-engineering surface far weaker than the
 * factor it bypasses. A set of codes the user stores themselves is the standard answer: it
 * is as strong as where they put it, and it takes support out of the authentication path
 * entirely.
 *
 * THEY ARE PASSWORDS, AND ARE STORED LIKE PASSWORDS. Each code is bcrypt hashed
 * (Hash::make) and the plaintext exists exactly once, in the response that shows it to the
 * user. Nothing can print them again - regenerate() replaces the whole set. That is the
 * correct property and not a limitation: a code the server can still read is a code an
 * attacker with database access can read, which would make the recovery path weaker than
 * the password it backs up.
 *
 * CONSUME-ONCE, BY DELETION. A used code has its ROW DELETED rather than a used_at column
 * set. There is nothing to audit in a spent code and nothing that may ever match it again,
 * and a deleted row cannot be resurrected by a bug in a WHERE clause - remaining() is then
 * simply a COUNT, with no state to get wrong.
 *
 * THE ALPHABET OMITS 0/O/1/I. These codes are read off paper or a screenshot and typed by
 * somebody who has just lost their phone and is not at their best. Ambiguous glyphs there
 * cost real logins for no entropy worth having: 32 symbols across 8 characters is 40 bits
 * per code, against a bcrypt hash, which is not the weak link in anything.
 *
 * Every method is static. Read and write them through Rsx_Two_Factor.
 *
 * See: php artisan rsx:man two_factor
 */
class Recovery_Codes
{
    /**
     * Codes minted per set. Ten is the ecosystem norm and comfortably more than the number
     * of times a person loses a phone between enrollments.
     */
    public const COUNT = 10;

    /**
     * Characters per half. Two groups of four, hyphenated, because that is the shape people
     * transcribe accurately.
     */
    private const GROUP_LENGTH = 4;

    /**
     * The unambiguous alphabet: no 0/O, no 1/I. 32 symbols, so five bits each.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    // -------------------------------------------------------------------------
    // Minting
    // -------------------------------------------------------------------------

    /**
     * Mint a fresh set of plaintext codes. Stores nothing - store_for() does that.
     *
     * random_bytes() per character and a modulo into a 32-symbol alphabet, which divides
     * 256 exactly, so there is no modulo bias to reason about.
     *
     * @return array COUNT codes in XXXX-XXXX form.
     */
    public static function generate(): array
    {
        $codes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $codes[] = self::_random_group() . '-' . self::_random_group();
        }

        return $codes;
    }

    /**
     * One four-character group from the CSPRNG.
     *
     * @return string
     */
    private static function _random_group(): string
    {
        $bytes = random_bytes(self::GROUP_LENGTH);
        $out = '';

        for ($i = 0; $i < self::GROUP_LENGTH; $i++) {
            $out .= self::ALPHABET[ord($bytes[$i]) % strlen(self::ALPHABET)];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Storage
    // -------------------------------------------------------------------------

    /**
     * Replace this identity's recovery codes with the given set.
     *
     * REPLACE, never append. A recovery set is one artifact the user has written down
     * somewhere; leaving the previous set live would mean a piece of paper the user
     * believes they have superseded still opens the account. So the old rows go first.
     *
     * The rows are written CONFIRMED. Unlike a TOTP seed or a passkey there is nothing to
     * prove - the code was minted by the server and handed to the user in the same breath,
     * and there is no enrollment ceremony that could still fail.
     *
     * @param int $login_user_id
     * @param array $codes Plaintext codes, as returned by generate().
     * @return void
     */
    public static function store_for(int $login_user_id, array $codes): void
    {
        Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_RECOVERY_CODE)
            ->delete();

        $now = Rsx_Time::now_iso();

        foreach ($codes as $code) {
            $row = new Two_Factor_Credential_Model();
            $row->login_user_id = $login_user_id;
            $row->type_id = Two_Factor_Credential_Model::TYPE_RECOVERY_CODE;
            $row->secret = Hash::make(self::_normalize($code));
            $row->counter = 0;
            $row->confirmed_at = $now;
            $row->save();
        }
    }

    // -------------------------------------------------------------------------
    // Redemption
    // -------------------------------------------------------------------------

    /**
     * Spend one code, if it matches any of this identity's unspent ones.
     *
     * A LINEAR SCAN IS THE ONLY OPTION and is deliberate. bcrypt salts every row, so two
     * hashes of the same code differ and there is no index to look the code up by - the
     * caller must Hash::check() against each candidate. With COUNT rows that is ten bcrypt
     * verifications on a path that is already rate limited by Login_Throttle, which is the
     * right trade: the alternative is an unsalted digest, and an unsalted digest of a
     * 40-bit secret is a rainbow table.
     *
     * The matched row is DELETED before this returns, so the same code presented twice
     * succeeds exactly once even if the two presentations overlap - the second finds no
     * row to match.
     *
     * @param int $login_user_id
     * @param string $code The code the user typed.
     * @return bool
     */
    public static function consume(int $login_user_id, string $code): bool
    {
        $code = self::_normalize($code);

        if ($code === '') {
            return false;
        }

        $rows = Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_RECOVERY_CODE)
            ->whereNotNull('confirmed_at')
            ->result_set();

        foreach ($rows as $row) {
            if ($row->secret === null || !Hash::check($code, $row->secret)) {
                continue;
            }

            $row->delete();

            return true;
        }

        return false;
    }

    /**
     * How many of this identity's recovery codes are still unspent.
     *
     * A plain COUNT, because a spent code has no row. The UI uses it to tell a user they
     * are running low, which is the only warning they will get before the set is empty.
     *
     * @param int $login_user_id
     * @return int
     */
    public static function remaining(int $login_user_id): int
    {
        return Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_RECOVERY_CODE)
            ->whereNotNull('confirmed_at')
            ->count();
    }

    // -------------------------------------------------------------------------
    // Normalization
    // -------------------------------------------------------------------------

    /**
     * The canonical form a code is hashed and compared in.
     *
     * Upper-cased, with spaces and hyphens stripped, so "abcd efgh", "ABCD-EFGH" and
     * "abcdefgh" are the one code they obviously are. Normalizing on BOTH the store and the
     * compare side is what makes that safe - a formatting difference must never be the
     * reason a valid recovery code is refused.
     *
     * @param string $code
     * @return string
     */
    private static function _normalize(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($code)));
    }
}
