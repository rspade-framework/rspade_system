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
 * rsx:users:2fa:unlock - lift a login identity's second-factor lock.
 *
 * An identity that has given rsx.two_factor.identity_max_failures wrong answers inside the
 * failure window is refused verification - a correct code included - until the window
 * closes. This is the operator's release for a real user who locked themselves out: it
 * clears the identity's failure count (Rsx_Two_Factor::clear_failures()) and touches
 * nothing else. The factors stay as they are; rsx:users:2fa:remove is the command that
 * takes them away.
 */
class Two_Factor_Unlock_Command extends Command
{
    protected $signature = 'rsx:users:2fa:unlock
                            {--user= : Login identity id or email address (required)}
                            {--json : Output as JSON}';

    protected $description = 'Clear a login identity\'s second-factor failure count, lifting a lock';

    public function handle()
    {
        $as_json = (bool) $this->option('json');

        try {
            $login_user = Two_Factor_Cli_Support::resolve_login_user($this->option('user'));
        } catch (Api_Cli_Error $e) {
            return Two_Factor_Cli_Support::report_error($this, $e, $as_json);
        }

        $was_locked = Rsx_Two_Factor::is_locked($login_user);

        Rsx_Two_Factor::clear_failures($login_user);

        if ($as_json) {
            return Two_Factor_Cli_Support::json_ok($this, [
                'user' => Two_Factor_Cli_Support::login_user_data($login_user),
                'was_locked' => $was_locked,
            ]);
        }

        $this->newLine();
        $this->info($was_locked
            ? '[OK] Second-factor lock lifted'
            : '[OK] Failure count cleared (the identity was not locked)');
        Two_Factor_Cli_Support::print_identity($this, $login_user);
        $this->newLine();

        return 0;
    }
}
