<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * rsx:api:key:temp - a key that expires on its own, for the duration of one script run.
 *
 * The shape a batch job wants: an importer mints a key, calls /api/vN with it for as long as
 * the import takes, and exits. Nothing has to remember to revoke it, because the expiry is
 * what revokes it - and a job that dies half way leaves a credential that stops working by
 * itself rather than one that lives until somebody notices.
 *
 * It is the same mint /apidocs performs for its live tester (Api_Docs_Controller::mint_temporary_key),
 * with the lifetime moved to the command line. The DEFAULT is one hour, matching that page.
 *
 * The name is deliberate and unmissable - it appears verbatim in rsx:api:key:list and in
 * Settings > API Keys, where the question being asked is always "what is this and can it go".
 * "Temporary (CLI)" answers both without anyone having to check the expiry column.
 */
class Api_Key_Temp_Command extends Command
{
    /**
     * The name every key minted here carries. A constant so the string in the docs, the
     * string in the UI and the string in the code cannot drift apart.
     */
    public const KEY_NAME = 'Temporary (CLI)';

    protected $signature = 'rsx:api:key:temp
                            {--user= : User id or email address (required)}
                            {--site= : Site id, to disambiguate an email held in more than one site}
                            {--expires=1 hour : Lifetime as a relative span ("30 minutes", "24 hours") or an ISO datetime}
                            {--environment=live : Key environment: live or test}
                            {--json : Output as JSON}';

    protected $description = 'Mint a short-lived external API key that expires on its own';

    public function handle()
    {
        $as_json = (bool) $this->option('json');

        try {
            $user = Api_Key_Cli_Support::resolve_user($this->option('user'), $this->option('site'));
            $environment = Api_Key_Cli_Support::parse_environment($this->option('environment'));
            $expires_at = Api_Key_Cli_Support::parse_expiry((string) $this->option('expires'));
        } catch (Api_Cli_Error $e) {
            return Api_Key_Cli_Support::report_error($this, $e, $as_json);
        }

        $result = Api_Key_Model::generate($user->id, static::KEY_NAME, $environment, null, $expires_at);
        $api_access = Api_Key_Cli_Support::api_access_enabled($user);

        if ($as_json) {
            return Api_Key_Cli_Support::json_ok($this, [
                'key' => $result['key'],
                'api_key' => Api_Key_Cli_Support::key_data($result['model']),
                'user' => Api_Key_Cli_Support::user_data($user),
                'api_access_enabled' => $api_access,
            ]);
        }

        $this->newLine();
        $this->info('[OK] Temporary API key created');
        $this->line('  User:    ' . $user->id . ' (' . $user->email . ')  site ' . $user->site_id);
        $this->line('  Name:    ' . $result['model']->name);
        $this->line('  Id:      ' . $result['model']->id);
        $this->line('  Expires: ' . Rsx_Time::format_datetime($expires_at->toIso8601String()));
        $this->newLine();
        $this->line('  KEY (shown once, store it now):');
        $this->line('  ' . $result['key']);
        $this->newLine();
        $this->warn('  This value cannot be recovered. Only its hash is stored.');

        if (!$api_access) {
            $this->newLine();
            $this->warn('  [WARNING] This user has API access disabled (users.is_api_access_enabled = 0).');
            $this->warn('  The key is valid, but every request with it is rejected until that is turned on.');
        }

        $this->newLine();

        return 0;
    }
}
