<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;

/**
 * rsx:users:2fa:remove - strip a login identity's second factors.
 *
 * THE ADMINISTRATIVE UNLOCK: a user has lost their phone, and the account has to become
 * reachable with a password alone again. Also the way to clear a stale credential before
 * rsx:users:2fa:setup will mint a new one.
 *
 * WITHOUT --id IT REMOVES EVERYTHING, recovery codes included. That is the common case and
 * the one an operator means when they say "take 2FA off this account". WITH --id it removes
 * exactly one factor, and the recovery codes go with it only when it was the last one - the
 * facade's cascade, which exists because a set of recovery codes with no factor left to
 * recover is just bearer tokens that log somebody in with no second step at all.
 *
 * --id IS CHECKED FOR OWNERSHIP HERE, BEFORE THE FACADE. Rsx_Two_Factor::remove_credential()
 * treats a credential that is not this identity's as a NO-OP, which is right for the
 * settings screen (a stale page naming a row that has already gone is a race, not an attack)
 * and wrong for an operator: "removed" printed over an id that belongs to somebody else is
 * an operator who believes an account is unlocked when it is not. So the id is resolved
 * against this identity's own credentials first, and a miss is an error.
 *
 * It prompts before destroying anything, exactly as rsx:api:key:delete does, and --force is
 * the way to say the intent explicitly in a script. --json cannot prompt, so it requires
 * --force.
 */
class Two_Factor_Remove_Command extends Command
{
    protected $signature = 'rsx:users:2fa:remove
                            {--user= : Login identity id or email address (required)}
                            {--id= : Remove only this credential id (see rsx:users:2fa:dump). Omit to remove every factor}
                            {--force : Skip the confirmation prompt}
                            {--json : Output as JSON (requires --force)}';

    protected $description = 'Remove a login identity\'s second factors (all of them, or one named by --id)';

    public function handle()
    {
        $as_json = (bool) $this->option('json');
        $forced = (bool) $this->option('force');

        try {
            $login_user = Two_Factor_Cli_Support::resolve_login_user($this->option('user'));
            $credential_id = $this->__parse_id($this->option('id'));
        } catch (Api_Cli_Error $e) {
            return Two_Factor_Cli_Support::report_error($this, $e, $as_json);
        }

        // A prompt under --json would emit a question onto the stream a script is parsing and
        // then block forever waiting for an answer nobody is there to give.
        if ($as_json && !$forced) {
            return Two_Factor_Cli_Support::json_error(
                $this,
                'confirmation_required',
                '--json cannot prompt for confirmation. Add --force to state the intent explicitly.'
            );
        }

        $credentials = Rsx_Two_Factor::cli_dump_credentials($login_user);
        $recovery_before = Rsx_Two_Factor::recovery_codes_remaining($login_user);

        if ($credential_id !== null) {
            $targets = array_values(array_filter(
                $credentials,
                static fn ($credential) => $credential['id'] === $credential_id
            ));

            if (count($targets) === 0) {
                $message = 'Login identity ' . $login_user->id . ' holds no credential with id '
                    . $credential_id . '. Run rsx:users:2fa:dump --user=' . $login_user->id . ' to see the ids.';

                if ($as_json) {
                    return Two_Factor_Cli_Support::json_error($this, 'credential_not_found', $message);
                }

                $this->error('[ERROR] ' . $message);

                return 1;
            }
        } else {
            $targets = $credentials;
        }

        if (count($targets) === 0 && $recovery_before === 0) {
            if ($as_json) {
                return Two_Factor_Cli_Support::json_ok($this, [
                    'action' => 'none',
                    'user' => Two_Factor_Cli_Support::login_user_data($login_user),
                    'removed' => [],
                    'recovery_codes_removed' => 0,
                ]);
            }

            $this->newLine();
            $this->info('[OK] No two-factor credentials; nothing to do');
            Two_Factor_Cli_Support::print_identity($this, $login_user);
            $this->newLine();

            return 0;
        }

        if (!$as_json) {
            $this->newLine();
            Two_Factor_Cli_Support::print_identity($this, $login_user);
            $this->__print_targets($targets, $credential_id === null ? $recovery_before : null);
        }

        if (!$forced) {
            $question = $credential_id === null
                ? 'Remove every second factor from this identity? It will sign in with a password alone.'
                : 'Remove this credential?';

            if (!$this->confirm($question, false)) {
                $this->line('  Cancelled.');
                $this->newLine();

                return 0;
            }
        }

        if ($credential_id === null) {
            Rsx_Two_Factor::remove_all($login_user);
        } else {
            Rsx_Two_Factor::remove_credential($login_user, $credential_id);
        }

        $recovery_removed = $recovery_before - Rsx_Two_Factor::recovery_codes_remaining($login_user);

        if ($as_json) {
            return Two_Factor_Cli_Support::json_ok($this, [
                'action' => $credential_id === null ? 'removed_all' : 'removed',
                'user' => Two_Factor_Cli_Support::login_user_data($login_user),
                'removed' => array_map(static fn ($credential) => [
                    'id' => $credential['id'],
                    'type_id' => $credential['type_id'],
                    'type_id__label' => $credential['type_id__label'],
                    'label' => $credential['label'],
                ], $targets),
                'recovery_codes_removed' => $recovery_removed,
                'is_enabled' => Rsx_Two_Factor::is_enabled($login_user),
            ]);
        }

        $this->info('[OK] Removed ' . count($targets) . ' credential' . (count($targets) === 1 ? '' : 's')
            . ' and ' . $recovery_removed . ' recovery code' . ($recovery_removed === 1 ? '' : 's'));

        if (!Rsx_Two_Factor::is_enabled($login_user)) {
            $this->line('  This identity now signs in with a password alone.');
        }

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
            throw new Api_Cli_Error('id_invalid', "--id must be a positive credential id, got '{$value}'.");
        }

        return (int) $value;
    }

    /**
     * What is about to be destroyed, printed before the operator answers the prompt.
     */
    private function __print_targets(array $targets, ?int $recovery_count): void
    {
        foreach ($targets as $credential) {
            $this->line('  Remove:  ' . $credential['id'] . '  ' . $credential['type_id__label']
                . '  (' . ($credential['label'] ?? '') . ')');
        }

        if ($recovery_count !== null && $recovery_count > 0) {
            $this->line('  Remove:  ' . $recovery_count . ' recovery code' . ($recovery_count === 1 ? '' : 's'));
        }

        $this->newLine();
    }
}
