<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Models\Portal_User_Model;

/**
 * rsx:users:force_password_reset - require (or, with --clear, stop requiring) that an identity
 * reset its password before it may sign in with one.
 *
 * Sets password_reset_required on a staff login identity (--user=<id|email>) or a client
 * portal user (--portal-user=<id>, or an email with --site=<id>). While it is set the
 * credential verifier refuses that identity's correct password with
 * Auth_Password_Reset_Required_Exception; the framework clears it when the password changes.
 * --clear is the recovery path for a wrongly-flagged account, so nobody edits the column by
 * hand.
 *
 * WHAT IT DOES NOT DO: send an email, end a session, or change the password. The reset
 * workflow (a forgot-password link, a support call) is the application's; a session already
 * signed in stays signed in (rsx:users:password:set ends sessions when the password must go
 * now); and a passkey or federated sign-in is not refused, because it never presents the
 * password.
 */
class Force_Password_Reset_Command extends Command
{
    protected $signature = 'rsx:users:force_password_reset
                            {--user= : Staff login identity id or email address}
                            {--portal-user= : Portal user id, or email address with --site}
                            {--site= : The portal user\'s site id (required with a portal user email)}
                            {--clear : Stop requiring a password reset}
                            {--json : Output as JSON}';

    protected $description = 'Require a password reset before an identity\'s next password sign-in (--clear to lift it; sends no email)';

    public function handle()
    {
        $as_json = (bool) $this->option('json');
        $required = !$this->option('clear');

        try {
            $identity = $this->__resolve_identity();
        } catch (Api_Cli_Error $e) {
            return Api_Key_Cli_Support::report_error($this, $e, $as_json);
        }

        $is_portal = $identity instanceof Portal_User_Model;
        $was_required = $identity->is_password_reset_required();

        if ($was_required !== $required) {
            $identity->password_reset_required = $required ? 1 : 0;

            // A portal user belongs to a site and a command serves none: the write is made
            // across sites, like the lookup.
            if ($is_portal) {
                Portal_User_Model::without_site_scope(fn () => $identity->save());
            } else {
                $identity->save();
            }
        }

        if ($as_json) {
            return Api_Key_Cli_Support::json_ok($this, [
                'action' => $required ? 'password_reset_required' : 'password_reset_cleared',
                'realm' => $is_portal ? 'portal' : 'staff',
                $is_portal ? 'portal_user' : 'user' => $is_portal
                    ? Portal_User_Cli_Support::portal_user_data($identity)
                    : Login_User_Cli_Support::login_user_data($identity),
                'was_required' => $was_required,
                'is_required' => $required,
            ]);
        }

        $this->newLine();

        if ($was_required === $required) {
            $this->info($required
                ? '[OK] A password reset was already required; nothing changed'
                : '[OK] No password reset was required; nothing changed');
        } else {
            $this->info($required
                ? '[OK] Password reset required before the next password sign-in'
                : '[OK] Password reset requirement cleared');
        }

        if ($is_portal) {
            Portal_User_Cli_Support::print_identity($this, $identity);
        } else {
            Login_User_Cli_Support::print_identity($this, $identity);
        }

        if ($required) {
            $this->line('  No email was sent and no session was ended; the reset workflow is the application\'s.');
        }

        $this->newLine();

        return 0;
    }

    /**
     * The one identity the options name.
     *
     * @return \App\RSpade\Core\Models\Login_User_Model|Portal_User_Model
     * @throws Api_Cli_Error
     */
    private function __resolve_identity()
    {
        $user = $this->option('user');
        $portal_user = $this->option('portal-user');

        if ($user !== null && $portal_user !== null) {
            throw new Api_Cli_Error(
                'identity_conflict',
                'Name one identity: --user (staff) or --portal-user (client portal), not both.'
            );
        }

        if ($portal_user !== null) {
            return Portal_User_Cli_Support::resolve_portal_user($portal_user, $this->option('site'));
        }

        if ($this->option('site') !== null) {
            throw new Api_Cli_Error(
                'site_not_applicable',
                '--site applies to --portal-user only: a staff login identity spans every site.'
            );
        }

        return Login_User_Cli_Support::resolve_login_user($user);
    }
}
