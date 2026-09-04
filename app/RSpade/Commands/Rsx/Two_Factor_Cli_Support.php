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
 *
 * That resolution now lives in Login_User_Cli_Support, which rsx:users:sso:* shares.
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
     * DELEGATED, for the same reason the envelope above is: "which login identity does --user
     * name?" has exactly one answer, and rsx:users:sso:* asks it too. Login_User_Cli_Support
     * owns it - the error codes, the id-or-email rule and the refusal to default to identity 1
     * are all stated there.
     *
     * @throws Api_Cli_Error when --user is missing or does not resolve
     */
    public static function resolve_login_user($user_option): Login_User_Model
    {
        return Login_User_Cli_Support::resolve_login_user($user_option);
    }

    /**
     * The identity every envelope carries, so a script never has to re-resolve what it just
     * asked for.
     */
    public static function login_user_data(Login_User_Model $login_user): array
    {
        return Login_User_Cli_Support::login_user_data($login_user);
    }

    /**
     * The identifying line every command opens its human output with.
     */
    public static function print_identity(Command $command, Login_User_Model $login_user): void
    {
        Login_User_Cli_Support::print_identity($command, $login_user);
    }
}
