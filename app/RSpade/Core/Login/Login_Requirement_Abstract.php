<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Login;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Login_Requirement_Abstract - one thing a signed-in user must do before the site is theirs.
 *
 * An application declares a requirement as a class extending this one - "enroll a second
 * factor", "accept the terms", "upload a profile picture", "connect your storage account".
 * The manifest finds it; nothing registers it. While any requirement applies to a signed-in
 * identity and is unsatisfied, that identity reads as NOT LOGGED IN everywhere except the
 * surfaces the requirement lists - so it can do the requirement and nothing else.
 * Login_Requirements is the engine; rsx:man login_requirements is the contract.
 *
 * ```php
 * class Terms_Requirement extends Login_Requirement_Abstract
 * {
 *     const REALM = 'staff';
 *
 *     public static function is_satisfied(Rsx_Model_Abstract $user): bool
 *     {
 *         return $user->terms_accepted_at !== null;
 *     }
 *
 *     public static function screen(): string { return 'Terms_Controller::index'; }
 *
 *     public static function surfaces(): array { return ['Terms_Controller::accept']; }
 * }
 * ```
 *
 * WHY `const REALM` IS NOT DECLARED HERE: a requirement for staff users read against a
 * portal user (or the reverse) is a requirement nobody can satisfy, and there is no safe
 * default. Login_Requirements throws on a concrete class that omits it.
 */
#[Instantiatable]
abstract class Login_Requirement_Abstract
{
    /**
     * Where this requirement comes in the sequence: the lowest outstanding ORDER is the
     * screen a user is sent to first. Ties go by class name.
     */
    const ORDER = 100;

    /**
     * Has this user met the requirement?
     *
     * THE TRUTH, and the only completion signal: there is no "mark it done" call, so a
     * requirement cannot be completed without actually being satisfied. Evaluated when the
     * identity signs in, when the staff site changes, on Login_Requirements::recheck(), and
     * whenever a user with requirements outstanding asks for anything their requirements do
     * not list - so the moment they have done what was asked, the next request lets them in.
     *
     * Read the user's state from $user, not from Session: the session is what this answer is
     * deciding about.
     *
     * @param Rsx_Model_Abstract $user The site user (User_Model) for 'staff', the
     *                                 Portal_User_Model for 'portal'.
     * @return bool
     */
    abstract public static function is_satisfied(Rsx_Model_Abstract $user): bool;

    /**
     * The page that does the requirement: a route target ('Controller::method') in the
     * requirement's realm, which a user with this requirement outstanding is sent to. It is
     * reachable while the requirement is outstanding without being listed in surfaces().
     *
     * @return string
     */
    abstract public static function screen(): string;

    /**
     * Everything else reachable while this requirement is outstanding: route and Ajax
     * endpoint targets ('Controller::method'), framework surfaces included (the upload
     * endpoint, a two-factor enrollment endpoint). Listed here, in one place, so the whole of
     * what a not-yet-admitted user can reach is readable in one file.
     *
     * A listed surface sees the identity as signed in - its #[Auth] gates are evaluated as
     * they would be for a fully signed-in user, and they still apply.
     *
     * @return array
     */
    #[Replaceable]
    public static function surfaces(): array
    {
        return [];
    }

    /**
     * Does this requirement apply while someone is impersonating the user? Default no:
     * the person viewing the account has signed in as themselves, and cannot do most
     * requirements for the account's owner anyway (a second factor cannot be enrolled while
     * impersonating).
     *
     * @return bool
     */
    #[Replaceable]
    public static function applies_while_impersonating(): bool
    {
        return false;
    }
}
