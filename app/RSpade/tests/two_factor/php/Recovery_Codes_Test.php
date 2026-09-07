<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Recovery_Codes;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;

/**
 * Recovery codes are the way back in when the second factor is gone, so the properties that
 * matter are the ones that stop them becoming a way IN generally:
 *
 *  - they are STORED HASHED, never in a form the server can read back;
 *  - a code is CONSUMED ONCE, by deletion, so a code presented twice works once;
 *  - store_for() REPLACES the set rather than appending, so a sheet the user believes they
 *    have superseded stops working;
 *  - the alphabet omits the glyphs a person confuses when typing off paper.
 *
 * Default isolation: every write here is a row, rolled back with the per-test transaction.
 */
class Recovery_Codes_Test extends Rsx_Test_Abstract
{
    /**
     * A live login identity. No password is needed - nothing in this class authenticates.
     */
    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'recovery_' . uniqid() . '@example.com';
        $login_user->password = Hash::make('correct-horse-battery-staple');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    private static function __rows_for(int $login_user_id): int
    {
        return Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_RECOVERY_CODE)
            ->count();
    }

    // -------------------------------------------------------------------------
    // Minting
    // -------------------------------------------------------------------------

    /**
     * A set is COUNT codes in XXXX-XXXX form, all distinct, drawn from the unambiguous
     * alphabet - no 0/O and no 1/I, because these are read off paper by somebody who has
     * just lost their phone.
     */
    public static function test_generate_produces_ten_distinct_unambiguous_codes()
    {
        $codes = Recovery_Codes::generate();

        static::__assert_count(Recovery_Codes::COUNT, $codes, 'ten codes');
        static::__assert_count(Recovery_Codes::COUNT, array_unique($codes), 'all distinct');

        foreach ($codes as $code) {
            static::__assert_equals(
                1,
                preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code),
                'XXXX-XXXX from the unambiguous alphabet: ' . $code
            );
        }
    }

    /**
     * Two sets minted back to back never collide.
     */
    public static function test_generate_does_not_repeat_itself()
    {
        static::__assert_empty(
            array_intersect(Recovery_Codes::generate(), Recovery_Codes::generate()),
            'two sets share nothing'
        );
    }

    // -------------------------------------------------------------------------
    // Storage
    // -------------------------------------------------------------------------

    /**
     * The stored rows are CONFIRMED (there is no ceremony left to prove) and carry a bcrypt
     * hash rather than the code - the plaintext must not be recoverable from the database.
     */
    public static function test_store_for_writes_confirmed_hashed_rows()
    {
        $login_user = static::__make_login_user();
        $codes = Recovery_Codes::generate();

        Recovery_Codes::store_for((int) $login_user->id, $codes);

        static::__assert_equals(Recovery_Codes::COUNT, static::__rows_for((int) $login_user->id), 'ten rows');
        static::__assert_equals(Recovery_Codes::COUNT, Recovery_Codes::remaining((int) $login_user->id));

        $rows = Two_Factor_Credential_Model::where('login_user_id', $login_user->id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_RECOVERY_CODE)
            ->get();

        foreach ($rows as $row) {
            static::__assert_not_null($row->confirmed_at, 'a recovery row is born confirmed');
            static::__assert_true(str_starts_with($row->secret, '$2y$'), 'stored as a bcrypt hash');

            static::__assert_false(
                in_array($row->secret, $codes, true),
                'the plaintext is nowhere in the table'
            );
        }
    }

    /**
     * store_for() REPLACES. A previous sheet the user believes they have superseded must
     * stop working the moment a new one is issued.
     */
    public static function test_store_for_replaces_the_previous_set()
    {
        $login_user = static::__make_login_user();
        $id = (int) $login_user->id;

        $first = Recovery_Codes::generate();
        Recovery_Codes::store_for($id, $first);

        $second = Recovery_Codes::generate();
        Recovery_Codes::store_for($id, $second);

        static::__assert_equals(Recovery_Codes::COUNT, static::__rows_for($id), 'still ten rows, not twenty');

        static::__assert_false(
            Recovery_Codes::consume($id, $first[0]),
            'a code from the superseded sheet no longer works'
        );

        static::__assert_true(
            Recovery_Codes::consume($id, $second[0]),
            'a code from the current sheet works'
        );
    }

    // -------------------------------------------------------------------------
    // Redemption
    // -------------------------------------------------------------------------

    /**
     * CONSUME ONCE. The first presentation succeeds, the second finds no row, and the count
     * drops by exactly one.
     */
    public static function test_a_code_is_consumed_exactly_once()
    {
        $login_user = static::__make_login_user();
        $id = (int) $login_user->id;

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for($id, $codes);

        static::__assert_true(Recovery_Codes::consume($id, $codes[3]), 'the first presentation works');
        static::__assert_equals(Recovery_Codes::COUNT - 1, Recovery_Codes::remaining($id), 'one fewer remains');

        static::__assert_false(Recovery_Codes::consume($id, $codes[3]), 'the second presentation does not');
        static::__assert_equals(Recovery_Codes::COUNT - 1, Recovery_Codes::remaining($id), 'and spends nothing');
    }

    /**
     * Every code in a set is independently redeemable, and redeeming the whole set empties
     * it exactly.
     */
    public static function test_every_code_in_the_set_works_and_the_set_empties()
    {
        $login_user = static::__make_login_user();
        $id = (int) $login_user->id;

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for($id, $codes);

        foreach ($codes as $index => $code) {
            static::__assert_true(Recovery_Codes::consume($id, $code), 'code ' . $index . ' redeems');
        }

        static::__assert_equals(0, Recovery_Codes::remaining($id), 'the set is empty');
        static::__assert_false(Recovery_Codes::consume($id, $codes[0]), 'and nothing is left to redeem');
    }

    /**
     * Formatting is normalized on both sides, so the hyphen and the case a user does or does
     * not reproduce are never the reason a valid code is refused.
     */
    public static function test_formatting_differences_do_not_refuse_a_valid_code()
    {
        $login_user = static::__make_login_user();
        $id = (int) $login_user->id;

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for($id, $codes);

        static::__assert_true(
            Recovery_Codes::consume($id, strtolower($codes[0])),
            'lower case is the same code'
        );

        static::__assert_true(
            Recovery_Codes::consume($id, str_replace('-', '', $codes[1])),
            'without the hyphen is the same code'
        );

        static::__assert_true(
            Recovery_Codes::consume($id, ' ' . str_replace('-', ' ', $codes[2]) . ' '),
            'spaced and padded is the same code'
        );
    }

    /**
     * A code belonging to somebody else does not redeem here, and does not spend a row
     * there either.
     */
    public static function test_a_code_is_scoped_to_its_own_identity()
    {
        $mine = static::__make_login_user();
        $theirs = static::__make_login_user();

        $my_codes = Recovery_Codes::generate();
        Recovery_Codes::store_for((int) $mine->id, $my_codes);

        $their_codes = Recovery_Codes::generate();
        Recovery_Codes::store_for((int) $theirs->id, $their_codes);

        static::__assert_false(
            Recovery_Codes::consume((int) $mine->id, $their_codes[0]),
            'their code does not open my account'
        );

        static::__assert_equals(
            Recovery_Codes::COUNT,
            Recovery_Codes::remaining((int) $theirs->id),
            'and does not spend their row'
        );
    }

    /**
     * Garbage is refused without spending anything. An empty string in particular must not
     * match a row - a blank field is not a recovery code.
     */
    public static function test_garbage_is_refused_and_spends_nothing()
    {
        $login_user = static::__make_login_user();
        $id = (int) $login_user->id;

        Recovery_Codes::store_for($id, Recovery_Codes::generate());

        foreach (['', '   ', '-', 'ZZZZ-ZZZZ', 'not a code at all'] as $garbage) {
            static::__assert_false(Recovery_Codes::consume($id, $garbage), 'refused: "' . $garbage . '"');
        }

        static::__assert_equals(Recovery_Codes::COUNT, Recovery_Codes::remaining($id), 'nothing was spent');
    }

    /**
     * An identity with no set has nothing to redeem, and asking is not an error.
     */
    public static function test_an_identity_with_no_codes_has_none_remaining()
    {
        $login_user = static::__make_login_user();

        static::__assert_equals(0, Recovery_Codes::remaining((int) $login_user->id));
        static::__assert_false(Recovery_Codes::consume((int) $login_user->id, 'ABCD-EFGH'));
    }
}
