<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Sso\Rsx_Sso;

/**
 * rsx:users:sso:unlink - disconnect a provider account from a login identity.
 *
 * THE OPERATOR ESCAPE HATCH, and the reason it exists is that the user cannot always reach
 * the settings screen that would do it: an account whose connected mailbox has been taken
 * over, a person who has left an organisation and whose Google account is about to be
 * deleted underneath the connection, an identity connected to the wrong account by a
 * mistake at sign-up. Every one of those is somebody who cannot sign in to fix it themselves.
 *
 * EXACTLY ONE OF --id AND --all IS REQUIRED. Neither is refused rather than defaulted to
 * either meaning: guessing --all would disconnect every provider on an account where the
 * operator meant one, and guessing nothing would print a success over an account nothing
 * happened to. Both together is refused for the same reason - it names two different
 * intentions and the command has no basis for choosing.
 *
 * --id IS CHECKED FOR OWNERSHIP HERE, BEFORE THE FACADE, exactly as rsx:users:2fa:remove
 * checks its credential id. Rsx_Sso::unlink() treats a row that is not this identity's as a
 * NO-OP, which is right for the settings screen (a stale page naming a connection that has
 * already gone is a race, not an attack) and wrong for an operator: "disconnected" printed
 * over an id that belongs to somebody else is an operator who believes an account is secured
 * when it is not.
 *
 * IT DOES NOT PROMPT, and that is the deliberate difference from rsx:users:2fa:remove.
 * Removing a second factor destroys a seed that cannot be recovered; disconnecting a provider
 * destroys a row the user re-creates by pressing "Continue with Google" once. There is
 * nothing here worth stopping an operator - or a script - to confirm.
 *
 * WHAT IT DOES NOT CHECK is whether the account keeps any way to sign in at all. That is
 * application vocabulary - some products require a password, some are SSO-only - and the
 * framework has no grounds for an opinion (Rsx_Sso::unlink()'s docblock carries the full
 * reasoning). An operator who leaves an account with no password and no connection has done
 * exactly what they asked to do.
 */
class Sso_Unlink_Command extends Command
{
    protected $signature = 'rsx:users:sso:unlink
                            {--user= : Login identity id or email address (required)}
                            {--id= : Disconnect only this connection id (see rsx:users:sso:dump)}
                            {--all : Disconnect every provider account this identity holds}
                            {--json : Output as JSON}';

    protected $description = 'Disconnect a login identity\'s provider accounts (one named by --id, or every one with --all)';

    public function handle()
    {
        $as_json = (bool) $this->option('json');
        $all = (bool) $this->option('all');

        try {
            $login_user = Sso_Cli_Support::resolve_login_user($this->option('user'));
            $identity_id = $this->__parse_id($this->option('id'));

            if ($identity_id === null && !$all) {
                throw new Api_Cli_Error(
                    'invalid_options',
                    'Nothing to disconnect: pass --id=<connection id> to remove one connection, '
                    . 'or --all to remove every one. Run rsx:users:sso:dump --user='
                    . $login_user->id . ' to see the ids.'
                );
            }

            if ($identity_id !== null && $all) {
                throw new Api_Cli_Error(
                    'invalid_options',
                    '--id and --all name two different intentions; pass exactly one of them.'
                );
            }
        } catch (Api_Cli_Error $e) {
            return Sso_Cli_Support::report_error($this, $e, $as_json);
        }

        $provider_labels = Sso_Cli_Support::provider_labels();
        $identities = [];

        foreach (Sso_Cli_Support::identity_rows($login_user) as $row) {
            $identities[] = Sso_Cli_Support::identity_data($row, $provider_labels);
        }

        if ($identity_id !== null) {
            $targets = array_values(array_filter(
                $identities,
                static fn ($identity) => $identity['id'] === $identity_id
            ));

            if (count($targets) === 0) {
                $message = 'Login identity ' . $login_user->id . ' holds no connection with id '
                    . $identity_id . '. Run rsx:users:sso:dump --user=' . $login_user->id
                    . ' to see the ids.';

                if ($as_json) {
                    return Sso_Cli_Support::json_error($this, 'identity_not_found', $message);
                }

                $this->error('[ERROR] ' . $message);

                return 1;
            }
        } else {
            $targets = $identities;
        }

        if (count($targets) === 0) {
            if ($as_json) {
                return Sso_Cli_Support::json_ok($this, [
                    'action' => 'none',
                    'user' => Sso_Cli_Support::login_user_data($login_user),
                    'removed' => [],
                ]);
            }

            $this->newLine();
            $this->info('[OK] No connected accounts; nothing to do');
            Sso_Cli_Support::print_identity($this, $login_user);
            $this->newLine();

            return 0;
        }

        if ($identity_id === null) {
            Rsx_Sso::unlink_all($login_user);
        } else {
            Rsx_Sso::unlink($login_user, $identity_id);
        }

        if ($as_json) {
            return Sso_Cli_Support::json_ok($this, [
                'action' => $identity_id === null ? 'removed_all' : 'removed',
                'user' => Sso_Cli_Support::login_user_data($login_user),
                'removed' => $targets,
            ]);
        }

        $this->newLine();
        $this->info('[OK] Disconnected ' . count($targets) . ' connected account'
            . (count($targets) === 1 ? '' : 's'));
        Sso_Cli_Support::print_identity($this, $login_user);

        foreach ($targets as $identity) {
            $this->newLine();
            Sso_Cli_Support::print_identity_row($this, $identity);
        }

        $remaining = count($identities) - count($targets);

        $this->newLine();
        $this->line('  ' . $remaining . ' connected account' . ($remaining === 1 ? '' : 's') . ' remaining.');
        $this->newLine();

        return 0;
    }

    /**
     * --id as an integer, or null when it was not given.
     *
     * @throws Api_Cli_Error when the value is not a positive integer
     */
    private function __parse_id($id_option): ?int
    {
        $value = trim((string) ($id_option ?? ''));

        if ($value === '') {
            return null;
        }

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new Api_Cli_Error('id_invalid', "--id must be a positive connection id, got '{$value}'.");
        }

        return (int) $value;
    }
}
