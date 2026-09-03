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
 * Shared argument handling and output shaping for the rsx:users:2fa:* commands.
 *
 * The sibling of Api_Key_Cli_Support, and the same reasoning: "how a user is named" and
 * "what an envelope looks like" are answered ONCE, so three commands cannot drift into three
 * subtly different dialects. The envelope helpers DELEGATE to Api_Key_Cli_Support rather
 * than restating it - one JSON shape across every framework CLI command is the property
 * worth having, and a script that has already learned {ok, command, data} must not have to
 * learn a second spelling of it here.
 *
 * WHAT IS DIFFERENT FROM THE API COMMANDS: a second factor hangs off a LOGIN IDENTITY, not
 * off a site user. login_users is the cross-site identity that actually signs in, and a
 * phone enrolled once per tenant would be wrong - so --user resolves to a Login_User_Model
 * and there is no --site to disambiguate with. login_users.email is UNIQUE, so an email
 * names exactly one identity or none; there is no ambiguity case to answer.
 */
class Two_Factor_Cli_Support
{
    /**
     * The JSON envelope, success form: {"ok": true, "command": "...", "data": { ... }}.
     *
     * @return int the process exit code, always 0
     */
    public static function json_ok(Command $command, array $data): int
    {
        return Api_Key_Cli_Support::json_ok($command, $data);
    }

    /**
     * The JSON envelope, failure form. Still JSON, still a non-zero exit.
     *
     * @return int the process exit code, always 1
     */
    public static function json_error(Command $command, string $error_code, string $message): int
    {
        return Api_Key_Cli_Support::json_error($command, $error_code, $message);
    }

    /**
     * Report an expected-input failure in whichever form the caller asked for.
     */
    public static function report_error(Command $command, Api_Cli_Error $error, bool $as_json): int
    {
        return Api_Key_Cli_Support::report_error($command, $error, $as_json);
    }

    /**
     * Resolve the login identity named by --user.
     *
     * WHY --user IS AN OPTION AND STILL REQUIRED, restated because it is the trap: every
     * framework command spells this --user=, so a positional user here would be a
     * cross-command inconsistency; and an option is optional by nature, so the requirement
     * is enforced HERE, loudly, naming the flag. Never by defaulting to identity 1, which
     * would arm - or strip - the wrong account and say nothing about it.
     *
     * A numeric value is a login_users.id; anything else is an email address. Soft-deleted
     * identities are excluded by the model's own scope, which is the answer that should be
     * given: a deleted identity is not one an operator may arm a second factor for.
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
