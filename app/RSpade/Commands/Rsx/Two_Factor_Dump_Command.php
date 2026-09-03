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
 * rsx:users:2fa:dump - everything an identity's second factors are, seeds included.
 *
 * IT PRINTS TOTP SECRETS, and that is the point of it rather than an oversight. An operator
 * whose user has lost the phone the QR code was scanned into has exactly two options: mint a
 * new seed and have them re-enroll, or read the existing one back so it can be entered into
 * a replacement app. The second is often the one that keeps a person working, and it is
 * available to anybody with shell access on this box regardless - the seed is encrypted with
 * the application key, which is in .env, which the shell can read. Withholding it here would
 * protect nothing and remove the escape hatch.
 *
 * It is the deliberate opposite of Rsx_Two_Factor::list_credentials(), which is metadata
 * only because its output reaches a browser. Nothing on this table has any business reaching
 * a browser; a terminal is a different audience.
 *
 * Recovery codes are a COUNT and never a list: they are bcrypt hashed and there is nothing
 * to print. A user who has lost them needs a new set, which is rsx:users:2fa:setup after a
 * remove, or the regenerate button in their own settings.
 */
class Two_Factor_Dump_Command extends Command
{
    protected $signature = 'rsx:users:2fa:dump
                            {--user= : Login identity id or email address (required)}
                            {--json : Output as JSON}';

    protected $description = 'Show a login identity\'s second factors, including its TOTP secrets and provisioning URIs';

    public function handle()
    {
        $as_json = (bool) $this->option('json');

        try {
            $login_user = Two_Factor_Cli_Support::resolve_login_user($this->option('user'));
        } catch (Api_Cli_Error $e) {
            return Two_Factor_Cli_Support::report_error($this, $e, $as_json);
        }

        $credentials = Rsx_Two_Factor::cli_dump_credentials($login_user);
        $recovery_remaining = Rsx_Two_Factor::recovery_codes_remaining($login_user);

        if ($as_json) {
            return Two_Factor_Cli_Support::json_ok($this, [
                'user' => Two_Factor_Cli_Support::login_user_data($login_user),
                'credentials' => $credentials,
                'recovery_codes_remaining' => $recovery_remaining,
                'is_enabled' => Rsx_Two_Factor::is_enabled($login_user),
            ]);
        }

        $this->newLine();

        if (count($credentials) === 0 && $recovery_remaining === 0) {
            $this->info('[OK] No two-factor credentials');
            Two_Factor_Cli_Support::print_identity($this, $login_user);
            $this->newLine();

            return 0;
        }

        $this->info('[OK] ' . count($credentials) . ' credential' . (count($credentials) === 1 ? '' : 's'));
        Two_Factor_Cli_Support::print_identity($this, $login_user);
        $this->line('  Enabled: ' . (Rsx_Two_Factor::is_enabled($login_user) ? 'yes' : 'no'));
        $this->line('  Codes:   ' . $recovery_remaining . ' recovery code'
            . ($recovery_remaining === 1 ? '' : 's') . ' remaining');

        foreach ($credentials as $credential) {
            $this->newLine();
            $this->line('  [' . $credential['id'] . '] ' . $credential['type_id__label']
                . '  (' . ($credential['label'] ?? '') . ')');
            $this->line('      Confirmed: ' . ($credential['confirmed_at'] ?? 'NOT CONFIRMED'));
            $this->line('      Last used: ' . ($credential['last_used_at'] ?? 'never'));
            $this->line('      Counter:   ' . $credential['counter']);

            if ($credential['secret'] !== null) {
                $this->line('      Secret:    ' . $credential['secret']);
                $this->line('      URI:       ' . $credential['otpauth_uri']);
            }
        }

        $this->newLine();
        $this->warn('  This output contains live TOTP secrets. Anyone holding one can generate this identity\'s codes.');
        $this->newLine();

        return 0;
    }
}
