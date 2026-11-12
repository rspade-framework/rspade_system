<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Portal\Portal_User_Model;
use App\RSpade\Lib\Portal\Rsx_Portal;

/**
 * Password_Change - the CAUSE vocabulary of a password change and the one event that reports
 * every change, for both identity realms.
 *
 * THE EVENT. Whenever an EXISTING identity's password changes through its model -
 * Login_User_Model (staff) or Portal_User_Model (client portal), whatever the caller - the
 * framework fires one action AFTER the save commits:
 *
 *   login_user.password.changed     a staff login identity
 *   portal_user.password.changed    a portal user
 *
 * with {identity, cause, actor, audit_session_id, ip_address}. It rides the model's own
 * `updated` hook, so no path can forget it; creating an identity with a password is not a
 * change and fires nothing. A write that bypasses the model (raw SQL, ->raw_bulk()) fires
 * nothing, as it gets none of the model's other consequences.
 *
 * THE CAUSE is stated by the code that sets the password - set_password($password, $cause) -
 * and is one of the CAUSE_* values below. A save that changes the password with no stated
 * cause (a bare ->password assignment, set_password() without one) reports CAUSE_UNKNOWN:
 * the framework never guesses.
 *
 * AFTER COMMIT. The event is registered with the identity's connection's afterCommit(): inside
 * a transaction (a #[Writer], a Site_Lock::write() section, DB::transaction()) it fires once
 * that transaction has committed and never if it rolls back; with no transaction open it fires
 * at once, the row already written. A handler that throws after a commit is reported, never
 * answered (Rsx_DatabaseTransactionsManager) - the password change stands. A handler writing a
 * site-scoped row takes its own lock: it may run under another site's lock, or none.
 *
 * See: php artisan rsx:man session (PASSWORD CHANGES), rsx:man event_hooks
 */
class Password_Change
{
    /** The identity changed its own password, proving the old one (a Change Password screen). */
    public const CAUSE_SELF = 'self';

    /** A self-service reset completed from an emailed link. */
    public const CAUSE_RESET_LINK = 'reset_link';

    /** Somebody else - an administrator - set the identity's password. */
    public const CAUSE_ADMIN = 'admin';

    /** The identity chose a new one at sign-in after a forced reset (Forced_Password_Change). */
    public const CAUSE_FORCED_CHANGE = 'forced_change';

    /** An operator set it from the command line (rsx:users:password:set). */
    public const CAUSE_CLI = 'cli';

    /** The save that changed the password stated no cause. */
    public const CAUSE_UNKNOWN = 'unknown';

    /** Every cause, in the order above. */
    public const CAUSES = [
        self::CAUSE_SELF,
        self::CAUSE_RESET_LINK,
        self::CAUSE_ADMIN,
        self::CAUSE_FORCED_CHANGE,
        self::CAUSE_CLI,
        self::CAUSE_UNKNOWN,
    ];

    /**
     * The cause a set_password() call states, checked: null stays null (the save reports
     * CAUSE_UNKNOWN), anything outside CAUSES throws.
     *
     * FRAMEWORK INTERNAL - called by the identity models' set_password().
     *
     * @param string|null $cause
     * @return string|null
     */
    public static function _checked_cause(?string $cause): ?string
    {
        if ($cause !== null && !in_array($cause, self::CAUSES, true)) {
            throw new \InvalidArgumentException(
                "Unknown password change cause '{$cause}'. Use a Password_Change::CAUSE_* constant: "
                . implode(', ', self::CAUSES) . '.'
            );
        }

        return $cause;
    }

    /**
     * Arrange the change event for an identity whose password a save just changed. Called from
     * the identity model's `updated` hook: the acting identity and the address are read NOW,
     * at the change; the audit session is resolved when the event fires, after the commit, so
     * a rolled-back change never mints one.
     *
     * FRAMEWORK INTERNAL.
     *
     * @param Rsx_Model_Abstract $identity Login_User_Model or Portal_User_Model
     * @param string $event login_user.password.changed | portal_user.password.changed
     * @param string|null $cause The cause set_password() recorded, or null
     * @return void
     */
    public static function _after_change(Rsx_Model_Abstract $identity, string $event, ?string $cause): void
    {
        $payload = [
            'identity' => $identity,
            'cause' => $cause ?? self::CAUSE_UNKNOWN,
            'actor' => static::__acting_identity(),
            'audit_session_id' => null,
            'ip_address' => Session::get_client_ip(),
        ];

        $identity->getConnection()->afterCommit(function () use ($event, $payload) {
            $payload['audit_session_id'] = Session::get_audit_session_id();

            Rsx::trigger_action($event, $payload);
        });
    }

    /**
     * Who is acting on this request: on a portal request the signed-in portal user; otherwise
     * the staff identity signed in on the browser - the IMPERSONATOR while one is impersonating,
     * since that is the person who acted. Null with nobody signed in (a reset link, the forced
     * change before its sign-in, a command).
     *
     * @return Login_User_Model|Portal_User_Model|null
     */
    private static function __acting_identity(): ?Rsx_Model_Abstract
    {
        if (!Session::is_api_request() && Rsx_Portal::is_portal_request()) {
            $portal_user_id = Portal_Session::get_portal_user_id();

            return $portal_user_id ? Portal_User_Model::find((int) $portal_user_id) : null;
        }

        $login_user_id = Session::get_impersonator_login_user_id() ?: Session::get_login_user_id();

        return $login_user_id ? Login_User_Model::find((int) $login_user_id) : null;
    }
}
