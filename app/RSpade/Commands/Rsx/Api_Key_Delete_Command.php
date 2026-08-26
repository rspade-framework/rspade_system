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
 * rsx:api:key:delete - stop a key working.
 *
 * REVOKES by default rather than deleting the row. Revocation is immediate and total - the
 * key fails at Api_Key_Model::find_by_key(), before any endpoint runs - and it keeps the row,
 * which matters because _api_request_log rows reference api_key_id: destroying the key would
 * leave every logged call it ever made pointing at nothing, exactly when someone is trying to
 * work out what it did.
 *
 * --purge removes the row outright, for a key created in error that has no history worth
 * keeping.
 *
 * The key is named by ID, not by --user: an id already identifies exactly one key across
 * every user and site, and asking for the owner as well would only create a way to name the
 * wrong pair.
 */
class Api_Key_Delete_Command extends Command
{
    protected $signature = 'rsx:api:key:delete
                            {id : The key id (see rsx:api:key:list)}
                            {--purge : Delete the row outright instead of revoking it}
                            {--force : Skip the confirmation prompt}
                            {--json : Output as JSON (requires --force)}';

    protected $description = 'Revoke an external API key (or --purge to remove the row)';

    public function handle()
    {
        $as_json = (bool) $this->option('json');
        $purge = (bool) $this->option('purge');
        $forced = (bool) $this->option('force');
        $id = (string) $this->argument('id');

        // A prompt under --json would emit a question onto the stream a script is parsing and
        // then block forever waiting for an answer nobody is there to give. Refusing is the
        // only honest answer: it says what to add, and it never destroys anything unasked.
        if ($as_json && !$forced) {
            return Api_Key_Cli_Support::json_error(
                $this,
                'confirmation_required',
                '--json cannot prompt for confirmation. Add --force to state the intent explicitly.'
            );
        }

        $key = Api_Key_Model::find((int) $id);

        if (!$key) {
            $message = 'No API key with id ' . $id . '. Run rsx:api:key:list --user=<id|email> to see the ids.';

            if ($as_json) {
                return Api_Key_Cli_Support::json_error($this, 'key_not_found', $message);
            }

            $this->error('[ERROR] ' . $message);

            return 1;
        }

        if ($key->is_revoked && !$purge) {
            if ($as_json) {
                return Api_Key_Cli_Support::json_ok($this, [
                    'action' => 'none',
                    'api_key' => Api_Key_Cli_Support::key_data($key),
                ]);
            }

            $this->newLine();
            $this->__print_key($key);
            $this->line('  Already revoked; nothing to do.');
            $this->newLine();

            return 0;
        }

        if (!$as_json) {
            $this->newLine();
            $this->__print_key($key);
        }

        if (!$forced) {
            $question = $purge
                ? 'Permanently delete this key? Its request-log rows will reference a key that no longer exists.'
                : 'Revoke this key? Anything using it stops working immediately.';

            if (!$this->confirm($question, false)) {
                $this->line('  Cancelled.');
                $this->newLine();

                return 0;
            }
        }

        if ($purge) {
            $data = Api_Key_Cli_Support::key_data($key);
            $key->delete();
        } else {
            $key->revoke();
            $data = Api_Key_Cli_Support::key_data($key);
        }

        if ($as_json) {
            return Api_Key_Cli_Support::json_ok($this, [
                'action' => $purge ? 'purged' : 'revoked',
                'api_key' => $data,
            ]);
        }

        $this->info($purge
            ? '[OK] API key ' . $id . ' deleted'
            : '[OK] API key ' . $id . ' revoked');
        $this->newLine();

        return 0;
    }

    /**
     * The identifying block an operator reads before answering the confirmation prompt.
     */
    private function __print_key(Api_Key_Model $key): void
    {
        $this->line('  Key:       ' . $key->id . '  ' . $key->key_prefix . '  (' . $key->name . ')');
        $this->line('  User:      ' . $key->user_id);
        $this->line('  Last used: ' . ($key->last_used_at ? Rsx_Time::relative($key->last_used_at) : 'never used'));
        $this->newLine();
    }
}
