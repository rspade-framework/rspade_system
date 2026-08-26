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
 */
class Api_Key_Delete_Command extends Command
{
    protected $signature = 'rsx:api:key:delete
                            {id : The key id (see rsx:api:key:list)}
                            {--purge : Delete the row outright instead of revoking it}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Revoke an external API key (or --purge to remove the row)';

    public function handle()
    {
        $key = Api_Key_Model::find((int) $this->argument('id'));

        if (!$key) {
            $this->error('No API key with id ' . $this->argument('id') . '. Run rsx:api:key:list <user> to see the ids.');

            return 1;
        }

        $purge = (bool) $this->option('purge');

        $this->newLine();
        $this->line('  Key:       ' . $key->id . '  ' . $key->key_prefix . '  (' . $key->name . ')');
        $this->line('  User:      ' . $key->user_id);
        $this->line('  Last used: ' . ($key->last_used_at ? Rsx_Time::relative($key->last_used_at) : 'never used'));
        $this->newLine();

        if ($key->is_revoked && !$purge) {
            $this->line('  Already revoked; nothing to do.');
            $this->newLine();

            return 0;
        }

        if (!$this->option('force')) {
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
            $key->delete();
            $this->info('[OK] API key ' . $this->argument('id') . ' deleted');
        } else {
            $key->revoke();
            $this->info('[OK] API key ' . $this->argument('id') . ' revoked');
        }

        $this->newLine();

        return 0;
    }
}
