<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * rsx:api:key:create - mint an external API key for a user.
 *
 * The plaintext is printed ONCE and is unrecoverable afterwards: only its SHA-256 reaches
 * the database. That is the whole reason this command prints so loudly - a key scrolled past
 * in a terminal is a key that has to be revoked and reissued.
 *
 * --expires is OPTIONAL and there is NO default. A key with no expiry lives until it is
 * revoked, which is the right shape for a server-to-server integration; inventing a lifetime
 * for one would silently break an integration at a time nobody chose.
 */
class Api_Key_Command extends Command
{
    protected $signature = 'rsx:api:key:create
                            {user : User id, or email address}
                            {--name= : Human-readable key name (default: "CLI key")}
                            {--expires= : Expiry as an ISO datetime or a relative span like "30 days". Omit for no expiry}
                            {--environment=live : Key environment: live or test}';

    protected $description = 'Create an external API key for a user (the plaintext is shown once)';

    public function handle()
    {
        $user = Api_Key_Cli_Support::resolve_user($this->argument('user'));

        if (!$user) {
            $this->error("No user matches '{$this->argument('user')}' (try a users.id or an email address).");

            return 1;
        }

        $environment = (string) $this->option("environment");

        if (!in_array($environment, ['live', 'test'], true)) {
            $this->error("--environment must be 'live' or 'test', got '{$environment}'.");

            return 1;
        }

        $expires_at = null;
        $expires_option = $this->option('expires');

        if ($expires_option !== null && trim((string) $expires_option) !== '') {
            try {
                $expires_at = Api_Key_Cli_Support::parse_expiry((string) $expires_option);
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return 1;
            }

            if ($expires_at->isPast()) {
                $this->error('--expires is in the past; the key would be unusable the moment it was created.');

                return 1;
            }
        }

        $name = (string) ($this->option('name') ?: 'CLI key');

        $result = Api_Key_Model::generate($user->id, $name, $environment, null, $expires_at);

        $this->newLine();
        $this->info('[OK] API key created');
        $this->line('  User:    ' . $user->id . ' (' . $user->email . ')');
        $this->line('  Name:    ' . $result['model']->name);
        $this->line('  Id:      ' . $result['model']->id);
        $this->line('  Expires: ' . ($expires_at ? Rsx_Time::format_datetime($expires_at->toIso8601String()) : 'never'));
        $this->newLine();
        $this->line('  KEY (shown once, store it now):');
        $this->line('  ' . $result['key']);
        $this->newLine();
        $this->warn('  This value cannot be recovered. Only its hash is stored.');
        $this->newLine();

        return 0;
    }
}
