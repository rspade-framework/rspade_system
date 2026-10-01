<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;

/**
 * rsx:users:password:set - set a login identity's password from the command line.
 *
 * THE OPERATOR'S RESET: somebody is locked out, or an account has to be taken back, and there
 * is nobody signed in to do it through a screen. The identity is named by --user (a
 * login_users.id or an email address, resolved by Login_User_Cli_Support like every other
 * rsx:users:* command).
 *
 * WHERE THE PASSWORD COMES FROM, in the order a caller should prefer them:
 *   - no flag:            a hidden prompt, typed twice. Nothing is echoed or recorded.
 *   - --password-stdin:   the first line of stdin, for a script (printf '%s' "$pw" | ...).
 *   - --password=<value>: on the command line, where it lands in shell history and is
 *                         readable by any local user from the process list while it runs.
 * Two sources at once is an error rather than a precedence rule, so a script never sets a
 * password it did not mean to. --json cannot prompt, so it needs one of the two flags.
 *
 * NO LENGTH OR COMPLEXITY RULE IS ENFORCED HERE beyond "not empty". A staff password policy
 * is application vocabulary (the template's signup applies its own), and an operator setting
 * a password by hand is the one caller trusted to choose it.
 *
 * EVERY SIGNED-IN SESSION OF THE IDENTITY ENDS by default, because an operator changing a
 * password almost always means the old one is lost or compromised, and a session that
 * outlives the password it was opened with defeats the reset. Session::_deactivate_sessions_for_user()
 * is the primitive for a caller with no signed-in operator; each ended session gets the
 * realtime refresh push and a 'session.terminated' event with scope 'internal'.
 * --keep-sessions leaves them alone.
 */
class Login_User_Password_Set_Command extends Command
{
    protected $signature = 'rsx:users:password:set
                            {--user= : Login identity id or email address (required)}
                            {--password= : The new password (visible in shell history and the process list - prefer the prompt or --password-stdin)}
                            {--password-stdin : Read the new password from the first line of stdin}
                            {--keep-sessions : Leave the identity\'s signed-in sessions active}
                            {--json : Output as JSON (requires --password or --password-stdin)}';

    protected $description = 'Set a login identity\'s password (prompts unless --password or --password-stdin is given)';

    public function handle()
    {
        $as_json = (bool) $this->option('json');

        try {
            $login_user = Login_User_Cli_Support::resolve_login_user($this->option('user'));
            $password = $this->__read_password($as_json);
        } catch (Api_Cli_Error $e) {
            return Api_Key_Cli_Support::report_error($this, $e, $as_json);
        }

        $login_user->password = Login_User_Model::hash_password($password);
        $login_user->save();

        $sessions_ended = $this->option('keep-sessions')
            ? 0
            : Session::_deactivate_sessions_for_user((int) $login_user->id);

        if ($as_json) {
            return Api_Key_Cli_Support::json_ok($this, [
                'action' => 'password_set',
                'user' => Login_User_Cli_Support::login_user_data($login_user),
                'sessions_ended' => $sessions_ended,
            ]);
        }

        $this->newLine();
        $this->info('[OK] Password set');
        Login_User_Cli_Support::print_identity($this, $login_user);

        if ($this->option('keep-sessions')) {
            $this->line('  Signed-in sessions were left active (--keep-sessions).');
        } else {
            $this->line('  Ended ' . $sessions_ended . ' signed-in session' . ($sessions_ended === 1 ? '' : 's') . '.');
        }

        $this->newLine();

        return 0;
    }

    /**
     * The new password, from exactly one source.
     *
     * @throws Api_Cli_Error when no usable source was given, two were, or the value is empty
     */
    private function __read_password(bool $as_json): string
    {
        $from_option = $this->option('password');
        $from_stdin = (bool) $this->option('password-stdin');

        if ($from_option !== null && $from_stdin) {
            throw new Api_Cli_Error(
                'password_source_conflict',
                'Pass the password one way: --password or --password-stdin, not both.'
            );
        }

        if ($from_option !== null) {
            $password = (string) $from_option;
        } elseif ($from_stdin) {
            $password = $this->__read_stdin_line();
        } else {
            if ($as_json || !$this->input->isInteractive()) {
                throw new Api_Cli_Error(
                    'password_required',
                    'No terminal to prompt on: pass --password-stdin (or --password).'
                );
            }

            $password = (string) $this->secret('New password');
            $confirm = (string) $this->secret('Confirm new password');

            if ($password !== $confirm) {
                throw new Api_Cli_Error('password_mismatch', 'The two passwords did not match. Nothing was changed.');
            }
        }

        if ($password === '') {
            throw new Api_Cli_Error('password_empty', 'The new password is empty. Nothing was changed.');
        }

        return $password;
    }

    /**
     * The first line of stdin, without its line ending. Only the ending is stripped: a
     * password may legitimately begin or end with a space.
     */
    private function __read_stdin_line(): string
    {
        $line = fgets(STDIN);

        if ($line === false) {
            return '';
        }

        return rtrim($line, "\r\n");
    }
}
