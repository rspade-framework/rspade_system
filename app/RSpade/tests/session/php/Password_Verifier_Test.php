<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Password_Verifier;
use App\RSpade\Core\Auth\Portal_Auth;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Equal-time refusal at the password front doors (Password_Verifier).
 *
 * An unknown address must cost what a known one costs, or the response time enumerates
 * accounts. Timing is not asserted - a wall-clock ratio is load-dependent and flaky. What is
 * pinned instead is the mechanism, deterministically:
 *
 *  - an unknown address (and a soft-deleted identity, which the lookup also misses) makes
 *    exactly ONE dummy hash verification, at both RsxAuth::verify_credentials() and
 *    Portal_Auth::verify_credentials(), and still answers null;
 *  - a known address makes NONE - it pays the real hash instead;
 *  - the dummy hash is held to the current hashing config (Hash::needsRehash() is false for
 *    it), which is what makes the dummy verification cost the same as a real one;
 *  - typing the dummy's own plaintext for an unknown address still fails.
 *
 * Every call passes record: false, so no failure counter or history row is written and
 * nothing outside the per-test transaction needs cleaning up.
 */
class Password_Verifier_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    public static function teardown(): void
    {
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        Session::logout();
        static::__reset_session();
    }

    private static function __fresh_email(string $suffix): string
    {
        return 'pwverify_' . $suffix . '_' . uniqid() . '@example.com';
    }

    private static function __make_login_user(string $email): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * Run $fn and answer how many dummy verifications it made.
     */
    private static function __dummy_checks_during(callable $fn): int
    {
        $before = Password_Verifier::$_dummy_verifications;
        $fn();

        return Password_Verifier::$_dummy_verifications - $before;
    }

    private static function __as_portal_site(): int
    {
        $site = new Site_Model();
        $site->slug = 'pwverify-' . uniqid();
        $site->name = 'Password Verifier';
        $site->is_enabled = true;
        $site->save();

        Portal_Session::_testing_reset();
        Portal_Session::set_site_id((int) $site->id);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_portal_user_id(0);

        return (int) $site->id;
    }

    // -------------------------------------------------------------------------
    // The dummy hash
    // -------------------------------------------------------------------------

    /**
     * The dummy costs what a real hash costs: Laravel's own judgement of "does this hash match
     * the current driver and cost" accepts it.
     */
    public static function test_dummy_hash_matches_the_current_hashing_config()
    {
        static::__assert_false(
            Hash::needsRehash(Password_Verifier::_dummy_hash()),
            'the dummy hash is at the configured driver and cost'
        );
        static::__assert_equals(
            password_get_info(Hash::make('x'))['algoName'],
            password_get_info(Password_Verifier::_dummy_hash())['algoName'],
            'the dummy hash uses the configured algorithm'
        );
    }

    public static function test_dummy_plaintext_never_authenticates_an_unknown_address()
    {

        static::__assert_null(
            RsxAuth::verify_credentials(
                ['email' => static::__fresh_email('dummyplain'), 'password' => 'rsx-unknown-identity'],
                record: false
            ),
            'the dummy\'s plaintext is not a password for anybody'
        );
    }

    // -------------------------------------------------------------------------
    // Staff front door
    // -------------------------------------------------------------------------

    public static function test_staff_unknown_address_pays_one_dummy_verification()
    {
        $email = static::__fresh_email('unknown');

        $result = 'unset';
        $checks = static::__dummy_checks_during(function () use ($email, &$result) {
            $result = RsxAuth::verify_credentials(['email' => $email, 'password' => self::PASSWORD], record: false);
        });

        static::__assert_null($result, 'an unknown address is still refused');
        static::__assert_equals(1, $checks, 'exactly one dummy hash verification was paid');
    }

    public static function test_staff_soft_deleted_identity_pays_one_dummy_verification()
    {
        $email = static::__fresh_email('deleted');
        static::__make_login_user($email)->delete();

        $result = 'unset';
        $checks = static::__dummy_checks_during(function () use ($email, &$result) {
            $result = RsxAuth::verify_credentials(['email' => $email, 'password' => self::PASSWORD], record: false);
        });

        static::__assert_null($result);
        static::__assert_equals(1, $checks, 'a trashed identity takes the unknown-address path, dummy included');
    }

    public static function test_staff_known_address_pays_the_real_hash_not_the_dummy()
    {
        $email = static::__fresh_email('known');
        static::__make_login_user($email);

        $result = 'unset';
        $checks = static::__dummy_checks_during(function () use ($email, &$result) {
            $result = RsxAuth::verify_credentials(['email' => $email, 'password' => 'not-the-password'], record: false);
        });

        static::__assert_null($result, 'a wrong password is refused');
        static::__assert_equals(0, $checks, 'a known address verifies against its own hash');
    }

    // -------------------------------------------------------------------------
    // Portal front door
    // -------------------------------------------------------------------------

    public static function test_portal_unknown_address_pays_one_dummy_verification()
    {
        $site_id = static::__as_portal_site();
        $email = static::__fresh_email('portal_unknown');

        $result = 'unset';
        $checks = static::__dummy_checks_during(function () use ($site_id, $email, &$result) {
            $result = Portal_Auth::verify_credentials($site_id, ['email' => $email, 'password' => self::PASSWORD], record: false);
        });

        static::__assert_null($result, 'an unknown portal address is still refused');
        static::__assert_equals(1, $checks, 'exactly one dummy hash verification was paid');
    }

    public static function test_portal_known_address_pays_the_real_hash_not_the_dummy()
    {
        $site_id = static::__as_portal_site();
        $email = static::__fresh_email('portal_known');

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = $site_id;
        $portal_user->email = $email;
        $portal_user->set_password(self::PASSWORD);
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        $result = 'unset';
        $checks = static::__dummy_checks_during(function () use ($site_id, $email, &$result) {
            $result = Portal_Auth::verify_credentials($site_id, ['email' => $email, 'password' => 'not-the-password'], record: false);
        });

        static::__assert_null($result);
        static::__assert_equals(0, $checks, 'a known portal address verifies against its own hash');
    }
}
