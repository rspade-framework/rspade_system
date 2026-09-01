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
 * rsx:api:key:create - mint an external API key for a user.
 *
 * This is how a script grants ITSELF access to the application API: an importer or an
 * exporter mints a key here, then calls /api/vN with Authorization: Bearer <key> like any
 * other client. There is no second, privileged CLI channel into the API - the CLI mints a
 * credential and then speaks the same HTTP the outside world speaks, so a script exercises
 * exactly the surface it will be gated on.
 *
 * The plaintext is printed ONCE and is unrecoverable afterwards: only its SHA-256 reaches
 * the database. That is the whole reason this command prints so loudly - a key scrolled past
 * in a terminal is a key that has to be revoked and reissued.
 *
 * --expires is OPTIONAL and there is NO default. A key with no expiry lives until it is
 * revoked, which is the right shape for a server-to-server integration; inventing a lifetime
 * for one would silently break an integration at a time nobody chose. A script that wants a
 * key for the duration of one run wants rsx:api:key:temp instead.
 */
class Api_Key_Command extends Command
{
    protected $signature = 'rsx:api:key:create
                            {--user= : User id or email address (required)}
                            {--site= : Site id, to disambiguate an email held in more than one site}
                            {--name= : Human-readable key name (default: "CLI key")}
                            {--expires= : Expiry as an ISO datetime or a relative span like "30 days". Omit for no expiry}
                            {--environment=live : Key environment: live or test}
                            {--scope=* : Scope path pattern ("/api/v1/contacts/*"), repeatable. Omit for an unrestricted key}
                            {--read-only : Mint a key that may execute GET requests only; any other verb is refused 403 read_only_key}
                            {--json : Output as JSON}';

    protected $description = 'Create an external API key for a user (the plaintext is shown once)';

    public function handle()
    {
        $as_json = (bool) $this->option('json');

        try {
            $user = Api_Key_Cli_Support::resolve_user($this->option('user'), $this->option('site'));
            $environment = Api_Key_Cli_Support::parse_environment($this->option('environment'));

            $expires_at = null;
            $expires_option = $this->option('expires');

            if ($expires_option !== null && trim((string) $expires_option) !== '') {
                $expires_at = Api_Key_Cli_Support::parse_expiry((string) $expires_option);
            }

            // Validated before anything is minted: a scope set that cannot be read must not
            // become a credential somebody believes is narrow.
            $scopes = Api_Key_Cli_Support::parse_scopes($this->option('scope'));
        } catch (Api_Cli_Error $e) {
            return Api_Key_Cli_Support::report_error($this, $e, $as_json);
        }

        $name = (string) ($this->option('name') ?: 'CLI key');

        $result = Api_Key_Model::generate(
            $user->id,
            $name,
            $environment,
            null,
            $expires_at,
            $scopes,
            (bool) $this->option('read-only')
        );
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
        $this->info('[OK] API key created');
        $this->line('  User:    ' . $user->id . ' (' . $user->email . ')  site ' . $user->site_id);
        $this->line('  Name:    ' . $result['model']->name);
        $this->line('  Id:      ' . $result['model']->id);
        $this->line('  Expires: ' . ($expires_at ? Rsx_Time::format_datetime($expires_at->toIso8601String()) : 'never'));
        $this->line('  Access:  ' . Api_Key_Cli_Support::key_access_summary($result['model']));
        $this->line('  Scope:   ' . Api_Key_Cli_Support::key_scope_summary($result['model']));

        // The scopes in full, echoed back normalized, because this is the one moment the
        // operator can still see they wrote the scope they meant to write.
        foreach (preg_split('/\n/', (string) $result['model']->scopes, -1, PREG_SPLIT_NO_EMPTY) as $scope) {
            $this->line('           ' . $scope);
        }

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
