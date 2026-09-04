<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Sso\Sso_Identity_Model;

/**
 * Shared argument handling and output shaping for the rsx:users:sso:* commands.
 *
 * The sibling of Two_Factor_Cli_Support, for the same reason and with the same shape: two
 * commands that both name an identity and both print a connection must not drift into two
 * dialects of either. The envelope helpers DELEGATE to Api_Key_Cli_Support rather than
 * restating it - one JSON shape across every framework CLI command is the property worth
 * having, and a script that has already learned {ok, command, data} must not have to learn a
 * second spelling of it here.
 *
 * THE OWNER IS A LOGIN IDENTITY, exactly as a second factor's is. A connected Google account
 * proves who is holding the browser, which is what login_users is; a users row is an
 * authorization scope inside one tenant and has nothing to say about it. So --user resolves
 * to a Login_User_Model and there is no --site to disambiguate with. login_users.email is
 * UNIQUE, so an email names exactly one identity or none. That resolution lives in
 * Login_User_Cli_Support, which rsx:users:2fa:* shares.
 *
 * WHAT IS DIFFERENT FROM THE BROWSER'S VIEW: Rsx_Sso::identities_list() lets the provider KEY
 * stand in for a label when a provider has since been switched off, because a settings screen
 * needs something to put in a row. A terminal is a different audience: an operator reading a
 * connection to a provider this install no longer configures needs to be TOLD that, or they
 * will spend the afternoon wondering why the button is missing. So identity_data() reports the
 * label as null in that case and the commands print '(no longer configured)'.
 */
class Sso_Cli_Support
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
     * name?" has exactly one answer, and rsx:users:2fa:* asks it too. Login_User_Cli_Support
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

    /**
     * key => label for every provider this install has live right now.
     *
     * Resolved ONCE per command rather than per row: enabled_providers() reads the brand mark
     * off disk for each provider, and a dump of ten connections has no business reading the
     * same SVG ten times.
     *
     * IT CAN THROW, and that is correct. A provider switched on with a credential missing is
     * an operator error that the login page would meet as a 500, and a CLI command that
     * quietly listed connections around it would be hiding the one thing worth saying.
     */
    public static function provider_labels(): array
    {
        $labels = [];

        foreach (Rsx_Sso::enabled_providers() as $provider) {
            $labels[$provider['key']] = $provider['label'];
        }

        return $labels;
    }

    /**
     * One _sso_identities row as both commands report it.
     *
     * Datetimes are the raw ISO strings the model already holds - never reformatted. A
     * terminal dump is evidence, and evidence that has been through a display timezone is
     * evidence an operator has to convert back before comparing it with a log line.
     *
     * @param Sso_Identity_Model $identity
     * @param array $provider_labels From provider_labels(); a key absent from it is a
     *                               connection whose provider is no longer configured.
     * @return array
     */
    public static function identity_data(Sso_Identity_Model $identity, array $provider_labels): array
    {
        $key = (string) $identity->provider_key;

        return [
            'id' => (int) $identity->id,
            'provider_key' => $key,
            // null, never the key standing in for it: a script must be able to tell a
            // connection this install still serves from one it has stopped serving.
            'provider_label' => $provider_labels[$key] ?? null,
            'email' => $identity->email,
            'name' => $identity->name,
            'last_login_at' => $identity->last_login_at,
            'created_at' => $identity->created_at,
        ];
    }

    /**
     * Every connection this identity holds, oldest first.
     *
     * @return array<Sso_Identity_Model>
     */
    public static function identity_rows(Login_User_Model $login_user): array
    {
        $rows = [];

        foreach (Sso_Identity_Model::where('login_user_id', $login_user->id)->orderBy('id')->result_set() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One connection, printed for a human. Shared so dump and unlink name a row identically -
     * an operator reading "[5] Google" from one command must recognise it in the other.
     */
    public static function print_identity_row(Command $command, array $data, string $prefix = ''): void
    {
        $label = $data['provider_label'] ?? '(no longer configured)';

        $command->line('  ' . $prefix . '[' . $data['id'] . '] ' . $data['provider_key'] . '  ' . $label);
        $command->line('      Email:     ' . ($data['email'] ?? '(none asserted)'));
        $command->line('      Name:      ' . ($data['name'] ?? '(none asserted)'));
        $command->line('      Last used: ' . ($data['last_login_at'] ?? 'never'));
        $command->line('      Connected: ' . ($data['created_at'] ?? 'unknown'));
    }
}
