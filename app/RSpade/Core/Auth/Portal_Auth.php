<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Auth_Password_Reset_Required_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Session\Portal_Login_History;

/**
 * Portal_Auth - the client portal's credential front door: the portal twin of
 * RsxAuth::verify_credentials().
 *
 * One classified place where a portal sign-in form checks an email + password, so an
 * application never assembles the lookup, the hash check, the throttle and the recording
 * itself. Signing in stays Portal_Session::set_portal_user_id() (or a passed second factor,
 * Rsx_Portal_Two_Factor::begin_challenge()); this class touches no session.
 *
 * See rsx:man portal (SIGNING IN) and rsx:man session (the staff contract it mirrors).
 */
class Portal_Auth
{
    /**
     * Check an email + password pair against the portal users of ONE site. Answers the portal
     * user, or null - and signs nobody in.
     *
     * A portal account is a (site, email) pair, so the site is an argument: normally
     * Portal_Session::get_site_id(), the site the application declared for this portal
     * request. The lookup reads exactly that site, whatever tenant the process is scoped to.
     *
     * THE ORDER, and why it is the same as staff:
     *
     *   1. Login_Throttle::require_not_throttled() is the FIRST statement and THROWS
     *      Auth_Throttled_Exception for a client IP that has spent its failure budget (one
     *      budget across both realms) - before the lookup, so a locked-out address cannot probe
     *      which accounts exist.
     *   2. Malformed input (no email or no password) answers null and records nothing.
     *   3. An unknown address and a wrong password both answer null - indistinguishable to the
     *      caller - recorded as Portal_Login_History::record_failure() with
     *      STATUS_FAILED_NOT_FOUND / STATUS_FAILED_PASSWORD (counters, the throttle, one log
     *      line; no row). A soft-deleted portal user is NOT_FOUND through the model's own scope.
     *   4. A portal user flagged password_reset_required THROWS
     *      Auth_Password_Reset_Required_Exception, carrying the portal user - only now, once the
     *      password matched, so it discloses nothing to a guesser. Recorded as a
     *      _portal_login_history ROW (STATUS_FAILED_PASSWORD_RESET_REQUIRED) EVEN WHEN $record
     *      is false, because the outcome is final; never counted against the throttle.
     *   5. Success: a SUCCESS row when $record is true, and the portal user is answered.
     *
     * $record = false is the opt-out for a caller whose check is not yet a sign-in - a second
     * factor still owed, a test - so a recorded SUCCESS always means full authentication. Such
     * a caller records its own outcomes (a null as Portal_Login_History::record_failure(), a
     * sign-in as record_success(); a passed challenge is recorded by Rsx_Portal_Two_Factor).
     *
     * ACCOUNT STATE IS THE APPLICATION'S. Portal_User_Model::can_login() (status_id,
     * is_verified) is NOT asked here: a login function asks it between this call and the
     * sign-in and words its own refusal ("not verified - check your email" is useful to a
     * person who proved their password, and an application decides what its statuses mean).
     * The framework's other portal doors (a second factor, a passkey, a federated sign-in)
     * refuse an identity can_login() rejects at their own sign-in step.
     *
     * The reset flag refuses the PASSWORD door only: a passkey or a federated sign-in never
     * presents the password, so neither consults it.
     *
     * @param int $site_id The site whose portal users are checked
     * @param array $credentials Requires 'email' and 'password'
     * @param bool $record Whether to record the outcome to Portal_Login_History (default true)
     * @return Portal_User_Model|null The portal user the credentials prove, or null
     * @throws Auth_Throttled_Exception when this client IP is locked out
     * @throws Auth_Password_Reset_Required_Exception when the credentials are correct and the
     *         portal user must reset its password first
     */
    public static function verify_credentials(int $site_id, array $credentials, bool $record = true): ?Portal_User_Model
    {
        Login_Throttle::require_not_throttled();

        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        // Malformed input is not an attempt: nothing to classify, nothing to record.
        if (!$email || !$password) {
            return null;
        }

        $portal_user = Portal_User_Model::without_site_scope(
            fn () => Portal_User_Model::find_by_email($site_id, $email)
        );

        if (!$portal_user) {
            if ($record) {
                Portal_Login_History::record_failure($email, Portal_Login_History::STATUS_FAILED_NOT_FOUND);
            }

            return null;
        }

        if (!Hash::check($password, $portal_user->password)) {
            if ($record) {
                Portal_Login_History::record_failure(
                    $email,
                    Portal_Login_History::STATUS_FAILED_PASSWORD,
                    null,
                    (int) $portal_user->id
                );
            }

            return null;
        }

        if ($portal_user->is_password_reset_required()) {
            Portal_Login_History::record_refusal(
                (int) $portal_user->id,
                $email,
                Portal_Login_History::STATUS_FAILED_PASSWORD_RESET_REQUIRED
            );

            throw new Auth_Password_Reset_Required_Exception($portal_user);
        }

        if ($record) {
            Portal_Login_History::record_success((int) $portal_user->id, $email);
        }

        return $portal_user;
    }
}
