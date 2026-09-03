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
 * rsx:users:2fa:setup - arm an identity with an authenticator-app credential from a shell.
 *
 * THE BOOTSTRAP AND RECOVERY TOOL. The web enrollment flow can only ever act on the
 * signed-in identity, which leaves two situations with no path at all: the first account on
 * a fresh box, before anybody can sign in to enroll anything; and a user who has lost both
 * their phone and their code sheet, whose account an administrator cannot enroll for them
 * through the UI by design. This is the answer to both, and it is deliberately not a
 * general-purpose enrollment channel - it runs as whoever holds shell access on the box,
 * which is already a higher privilege than any identity in the application.
 *
 * The seed and the recovery codes are printed ONCE and cannot be printed again: the seed is
 * encrypted on the row and the codes are bcrypt hashed. rsx:users:2fa:dump can still read a
 * TOTP seed back afterwards, but the recovery codes are gone the moment this scrolls off
 * the screen - regenerating is then the only option.
 *
 * A SECOND SEED IS REFUSED. Stacking one would leave the identity with two live
 * authenticator credentials and no way to tell from the phone which is which, and it is
 * never what an operator running a recovery meant to ask for. Remove the existing one first
 * with rsx:users:2fa:remove; adding a SECOND factor deliberately is what the settings screen
 * is for.
 */
class Two_Factor_Setup_Command extends Command
{
    protected $signature = 'rsx:users:2fa:setup
                            {--user= : Login identity id or email address (required)}
                            {--json : Output as JSON}';

    protected $description = 'Enroll an authenticator app for a login identity (prints the TOTP secret and recovery codes once)';

    public function handle()
    {
        $as_json = (bool) $this->option('json');

        try {
            $login_user = Two_Factor_Cli_Support::resolve_login_user($this->option('user'));
        } catch (Api_Cli_Error $e) {
            return Two_Factor_Cli_Support::report_error($this, $e, $as_json);
        }

        if (Rsx_Two_Factor::has_confirmed_totp($login_user)) {
            $message = 'Login identity ' . $login_user->id . ' already holds an authenticator-app credential. '
                . 'Remove it first with rsx:users:2fa:remove --user=' . $login_user->id . ', or add a second '
                . 'factor from the account settings screen.';

            if ($as_json) {
                return Two_Factor_Cli_Support::json_error($this, 'totp_already_enrolled', $message);
            }

            $this->error('[ERROR] ' . $message);

            return 1;
        }

        $result = Rsx_Two_Factor::cli_setup_totp($login_user, 'CLI setup');

        if ($as_json) {
            return Two_Factor_Cli_Support::json_ok($this, [
                'user' => Two_Factor_Cli_Support::login_user_data($login_user),
                'secret' => $result['secret'],
                'otpauth_uri' => $result['otpauth_uri'],
                'recovery_codes' => $result['recovery_codes'],
            ]);
        }

        $this->newLine();
        $this->info('[OK] Authenticator app enrolled');
        Two_Factor_Cli_Support::print_identity($this, $login_user);
        $this->line('  Label:   CLI setup');

        $this->newLine();
        $this->line('  SECRET (base32, type this into the authenticator app):');
        $this->line('  ' . $result['secret']);

        $this->newLine();
        $this->line('  PROVISIONING URI (encode as a QR code to scan it instead):');
        $this->line('  ' . $result['otpauth_uri']);

        $this->newLine();
        $this->line('  RECOVERY CODES (' . count($result['recovery_codes']) . ', each usable once):');

        foreach ($result['recovery_codes'] as $code) {
            $this->line('    ' . $code);
        }

        $this->newLine();
        $this->warn('  Shown once. The recovery codes cannot be recovered - only their hashes are stored.');
        $this->warn('  Any recovery codes this identity held before now have stopped working.');
        $this->newLine();

        return 0;
    }
}
