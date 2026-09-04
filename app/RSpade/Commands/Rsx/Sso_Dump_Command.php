<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

/**
 * rsx:users:sso:dump - every provider account a login identity can sign in with.
 *
 * THE QUESTION IT ANSWERS IS "why can this person get in?", and it is asked at exactly the
 * moment nobody can look it up in a browser: an account that signs in without the password
 * anybody remembers setting, a user who has lost access to the mailbox their Google account
 * uses, a support call about an account somebody else appears to be reaching. The settings
 * screen shows the same list, but only to the person holding the account - which is no help
 * when the question is about them.
 *
 * IT PRINTS NO SECRET, and unlike rsx:users:2fa:dump that is not a decision, it is the table:
 * _sso_identities holds no token and no refresh token by design (Sso_Identity_Model's
 * docblock says why). Everything here is metadata, and the same metadata the user's own
 * settings screen shows them.
 *
 * ZERO CONNECTIONS EXITS 0. This is a state dump, not an assertion - "this identity has no
 * connected accounts" is a complete and successful answer, and a non-zero exit would make
 * every script that runs it treat a perfectly ordinary account as a failure.
 *
 * A CONNECTION CAN OUTLIVE ITS PROVIDER'S CONFIG. Switching SSO_GOOGLE_ENABLED off does not
 * delete anything, so a row naming a provider this install no longer configures is normal and
 * is reported as '(no longer configured)' rather than as an error. It is still there, it can
 * still be removed with rsx:users:sso:unlink, and it will start working again the moment the
 * credentials come back.
 */
class Sso_Dump_Command extends Command
{
    protected $signature = 'rsx:users:sso:dump
                            {--user= : Login identity id or email address (required)}
                            {--json : Output as JSON}';

    protected $description = 'Show the provider accounts a login identity can sign in with';

    public function handle()
    {
        $as_json = (bool) $this->option('json');

        try {
            $login_user = Sso_Cli_Support::resolve_login_user($this->option('user'));
        } catch (Api_Cli_Error $e) {
            return Sso_Cli_Support::report_error($this, $e, $as_json);
        }

        $provider_labels = Sso_Cli_Support::provider_labels();
        $identities = [];

        foreach (Sso_Cli_Support::identity_rows($login_user) as $row) {
            $identities[] = Sso_Cli_Support::identity_data($row, $provider_labels);
        }

        if ($as_json) {
            return Sso_Cli_Support::json_ok($this, [
                'user' => Sso_Cli_Support::login_user_data($login_user),
                'identities' => $identities,
            ]);
        }

        $this->newLine();

        if (count($identities) === 0) {
            $this->info('[OK] No connected accounts');
            Sso_Cli_Support::print_identity($this, $login_user);
            $this->newLine();

            return 0;
        }

        $this->info('[OK] ' . count($identities) . ' connected account'
            . (count($identities) === 1 ? '' : 's'));
        Sso_Cli_Support::print_identity($this, $login_user);

        foreach ($identities as $identity) {
            $this->newLine();
            Sso_Cli_Support::print_identity_row($this, $identity);
        }

        $this->newLine();

        return 0;
    }
}
