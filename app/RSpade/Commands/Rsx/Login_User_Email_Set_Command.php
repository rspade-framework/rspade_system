<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;

/**
 * rsx:users:email:set - change the email address a login identity signs in with.
 *
 * The identity is named by --user (a login_users.id or its CURRENT email address, resolved by
 * Login_User_Cli_Support like every other rsx:users:* command); --email is the new address.
 *
 * THE SITE MEMBERSHIPS FOLLOW. Each users row carries its own email - the site's contact and
 * invitation address, edited separately in user management - and a membership still showing
 * the old address after its sign-in address moved is a record that now names somebody's
 * former mailbox. So every users row of this identity whose email equals the OLD address is
 * rewritten too, across every site and including trashed memberships; a membership whose
 * address an administrator had already set to something else is left as it is, because that
 * was a deliberate choice this command has no reason to undo. Everything is written in ONE
 * transaction, through each model's own save(), so revisions and realtime fire per record.
 *
 * THE NEW ADDRESS MUST BE FREE ACROSS EVERY IDENTITY, TRASHED ONES INCLUDED. login_users.email
 * is UNIQUE at the database, and the index does not know about soft deletes, so an address
 * held by a deleted identity is refused here with a message saying so - rather than reaching
 * the index and failing as a duplicate-key exception.
 *
 * Verification state is left alone: an operator setting an address by hand is vouching for it.
 */
class Login_User_Email_Set_Command extends Command
{
    protected $signature = 'rsx:users:email:set
                            {--user= : Login identity id or current email address (required)}
                            {--email= : The new email address (required)}
                            {--json : Output as JSON}';

    protected $description = 'Change the email address a login identity signs in with (and the memberships that showed the old one)';

    public function handle()
    {
        $as_json = (bool) $this->option('json');

        try {
            $login_user = Login_User_Cli_Support::resolve_login_user($this->option('user'));
            $new_email = $this->__parse_email($this->option('email'));
            $this->__assert_email_free($login_user, $new_email);
        } catch (Api_Cli_Error $e) {
            return Api_Key_Cli_Support::report_error($this, $e, $as_json);
        }

        $old_email = (string) $login_user->email;

        if ($new_email === $old_email) {
            if ($as_json) {
                return Api_Key_Cli_Support::json_ok($this, [
                    'action' => 'none',
                    'user' => Login_User_Cli_Support::login_user_data($login_user),
                    'old_email' => $old_email,
                    'memberships_updated' => [],
                ]);
            }

            $this->newLine();
            $this->info('[OK] The identity already uses that address; nothing to do');
            Login_User_Cli_Support::print_identity($this, $login_user);
            $this->newLine();

            return 0;
        }

        $memberships_updated = DB::transaction(function () use ($login_user, $old_email, $new_email) {
            $login_user->email = $new_email;
            $login_user->save();

            return $this->__update_memberships((int) $login_user->id, $old_email, $new_email);
        });

        if ($as_json) {
            return Api_Key_Cli_Support::json_ok($this, [
                'action' => 'email_set',
                'user' => Login_User_Cli_Support::login_user_data($login_user),
                'old_email' => $old_email,
                'memberships_updated' => $memberships_updated,
            ]);
        }

        $this->newLine();
        $this->info('[OK] Email changed');
        $this->line('  User:    ' . $login_user->id . ' (' . $old_email . ' -> ' . $new_email . ')');

        foreach ($memberships_updated as $membership) {
            $this->line('  Membership ' . $membership['id'] . ' on site ' . $membership['site_id'] . ' updated to the new address.');
        }

        $this->newLine();

        return 0;
    }

    /**
     * --email, trimmed and checked for shape.
     *
     * @throws Api_Cli_Error when it is missing or is not an email address
     */
    private function __parse_email($email_option): string
    {
        $email = trim((string) ($email_option ?? ''));

        if ($email === '') {
            throw new Api_Cli_Error('email_required', '--email is required: pass the new address (e.g. --email=ops@example.com).');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new Api_Cli_Error('email_invalid', "'{$email}' is not an email address.");
        }

        if (mb_strlen($email) > Login_User_Model::field_length('email')) {
            throw new Api_Cli_Error(
                'email_invalid',
                'The address is longer than ' . Login_User_Model::field_length('email') . ' characters.'
            );
        }

        return $email;
    }

    /**
     * Refuse an address another identity holds, a soft-deleted one included.
     *
     * The comparison is the database's own (the column's collation), so it agrees with the
     * UNIQUE index about what counts as the same address.
     *
     * @throws Api_Cli_Error when the address is taken
     */
    private function __assert_email_free(Login_User_Model $login_user, string $new_email): void
    {
        $holder = Login_User_Model::withTrashed()
            ->where('email', $new_email)
            ->where('id', '!=', $login_user->id)
            ->first();

        if (!$holder) {
            return;
        }

        $message = "'{$new_email}' already belongs to login identity {$holder->id}";

        if ($holder->trashed()) {
            $message .= ' (a deleted identity, which still holds the address)';
        }

        throw new Api_Cli_Error('email_in_use', $message . '.');
    }

    /**
     * Rewrite every membership of this identity that showed the old address, on every site.
     *
     * @return array<int, array{id: int, site_id: int}>
     */
    private function __update_memberships(int $login_user_id, string $old_email, string $new_email): array
    {
        return User_Model::without_site_scope(function () use ($login_user_id, $old_email, $new_email) {
            $updated = [];

            $memberships = User_Model::withTrashed()
                ->where('login_user_id', $login_user_id)
                ->where('email', $old_email)
                ->orderBy('id')
                ->get();

            foreach ($memberships as $membership) {
                $membership->email = $new_email;
                $membership->save();

                $updated[] = ['id' => (int) $membership->id, 'site_id' => (int) $membership->site_id];
            }

            return $updated;
        });
    }
}
