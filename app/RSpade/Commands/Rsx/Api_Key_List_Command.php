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
 * rsx:api:key:list - the API keys belonging to a user.
 *
 * Shows the state an operator actually needs to answer "is this integration working, and is
 * this key safe to remove": whether the key is usable right now, when it expires, and when it
 * was last used. LAST USED is the one that matters most - a key with no usage in months is a
 * key that can probably go, and one used minutes ago is a key that will break something.
 *
 * Active keys only by default. --all includes revoked and expired ones, because "why did this
 * stop working" is a question that needs the dead keys visible.
 */
class Api_Key_List_Command extends Command
{
    protected $signature = 'rsx:api:key:list
                            {user : User id, or email address}
                            {--all : Include revoked and expired keys}';

    protected $description = 'List a user\'s external API keys with expiry and last-used state';

    public function handle()
    {
        $user = Api_Key_Cli_Support::resolve_user($this->argument('user'));

        if (!$user) {
            $this->error("No user matches '{$this->argument('user')}' (try a users.id or an email address).");

            return 1;
        }

        $show_all = (bool) $this->option('all');

        // get_for_user() returns an Rsx_Result_Set: every key, iterated a page at a time, so
        // a user with a long history is listed in full rather than truncated at some limit.
        $rows = [];
        $active = 0;

        foreach (Api_Key_Model::get_for_user((int) $user->id) as $key) {
            $is_valid = $key->is_valid();

            if ($is_valid) {
                $active++;
            }

            if (!$is_valid && !$show_all) {
                continue;
            }

            $rows[] = [
                $key->id,
                $key->name,
                $key->key_prefix,
                static::__state($key),
                $key->expires_at ? Rsx_Time::format_datetime($key->expires_at) : 'never',
                $key->last_used_at ? Rsx_Time::relative($key->last_used_at) : 'never used',
                Rsx_Time::format_datetime($key->created_at),
            ];
        }

        $this->newLine();
        $this->line('API keys for user ' . $user->id . ' (' . $user->email . ')');

        if (empty($rows)) {
            $this->newLine();
            $this->line($show_all ? '  No keys.' : '  No active keys. Re-run with --all to include revoked and expired ones.');
            $this->newLine();

            return 0;
        }

        $this->newLine();
        $this->table(
            ['Id', 'Name', 'Prefix', 'State', 'Expires', 'Last used', 'Created'],
            $rows
        );
        $this->line('  ' . $active . ' active');
        $this->newLine();

        return 0;
    }

    /**
     * Why a key is unusable, not merely that it is - revoked and expired call for different
     * responses from whoever is reading.
     */
    private static function __state(Api_Key_Model $key): string
    {
        if ($key->is_revoked) {
            return 'revoked';
        }

        if ($key->expires_at && Rsx_Time::is_past($key->expires_at)) {
            return 'expired';
        }

        return 'active';
    }
}
