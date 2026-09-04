<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Models\Login_User_Model;

/**
 * "Which login identity does --user name?" - answered ONCE, for every framework CLI command
 * that operates on one.
 *
 * It is the sibling of Api_Key_Cli_Support, which answers the same shape of question for a
 * SITE USER, and the split between them is the point: an api key is minted for a users row,
 * which is an authorization scope inside one tenant, while a second factor and a connected
 * provider account both hang off login_users, the cross-site identity that actually signs in.
 * Two questions, two resolvers; but each question has exactly one answer, and a command that
 * spelled either of them a second time would be a place for the two to drift.
 *
 * WHAT DELEGATES TO IT: Two_Factor_Cli_Support (rsx:users:2fa:*) and Sso_Cli_Support
 * (rsx:users:sso:*), both as thin pass-throughs, exactly as both already delegate the JSON
 * envelope to Api_Key_Cli_Support. A command keeps calling its own concern's support class,
 * so nothing at a call site knows this file exists - which is what makes it free to grow a
 * third caller later.
 *
 * THE ERROR CODES ARE PART OF THE CONTRACT. 'user_required' and 'user_not_found' are reported
 * as error.code in --json output, and a script branching on either must get the same answer
 * whichever command it asked. They are never localized and never reworded.
 */
class Login_User_Cli_Support
{
    /**
     * Resolve the login identity named by --user.
     *
     * WHY --user IS AN OPTION AND STILL REQUIRED, restated because it is the trap: every
     * framework command spells this --user=, so a positional user here would be a
     * cross-command inconsistency; and an option is optional by nature, so the requirement
     * is enforced HERE, loudly, naming the flag. Never by defaulting to identity 1, which
     * would arm - or strip, or disconnect - the wrong account and say nothing about it.
     *
     * A numeric value is a login_users.id; anything else is an email address. login_users.email
     * is UNIQUE, so an email names exactly one identity or none; there is no ambiguity case to
     * answer, which is the other difference from the site-user resolver. Soft-deleted
     * identities are excluded by the model's own scope, which is the answer that should be
     * given: a deleted identity is not one an operator administers.
     *
     * @throws Api_Cli_Error when --user is missing or does not resolve
     */
    public static function resolve_login_user($user_option): Login_User_Model
    {
        $needle = trim((string) ($user_option ?? ''));

        if ($needle === '') {
            throw new Api_Cli_Error(
                'user_required',
                '--user is required: pass a login_users.id or an email address '
                . '(e.g. --user=1 or --user=ops@example.com).'
            );
        }

        if (ctype_digit($needle)) {
            $login_user = Login_User_Model::find((int) $needle);

            if (!$login_user) {
                throw new Api_Cli_Error('user_not_found', "No login identity with id {$needle}.");
            }

            return $login_user;
        }

        $login_user = Login_User_Model::where('email', $needle)->first();

        if (!$login_user) {
            throw new Api_Cli_Error(
                'user_not_found',
                "No login identity matches '{$needle}' (try a login_users.id or an email address)."
            );
        }

        return $login_user;
    }

    /**
     * The identity every envelope carries, so a script never has to re-resolve what it just
     * asked for.
     */
    public static function login_user_data(Login_User_Model $login_user): array
    {
        return [
            'id' => (int) $login_user->id,
            'email' => $login_user->email,
        ];
    }

    /**
     * The identifying line every command opens its human output with.
     */
    public static function print_identity(Command $command, Login_User_Model $login_user): void
    {
        $command->line('  User:    ' . $login_user->id . ' (' . $login_user->email . ')');
    }
}
