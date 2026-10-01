<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\LoginUsers\Cli;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:users:password:set / rsx:users:email:set - the operator's credential repairs, end to end.
 *
 * BOTH COMMANDS CHANGE HOW SOMEBODY SIGNS IN, and the operator has nothing to check the
 * result against until that person tries. So what is pinned is the claim each one makes:
 * the password command's new hash verifies and its old one does not, and every session the
 * identity held is gone unless --keep-sessions said otherwise; the email command moves the
 * sign-in address, moves the memberships that showed the old address on EVERY site (trashed
 * ones too), leaves a membership an administrator had re-addressed alone, and refuses an
 * address another identity - a deleted one included - still holds.
 *
 * The interactive prompt and --password-stdin cannot be driven through Artisan::call(),
 * which has no terminal and no stdin to give; what IS pinned is that a call with no terminal
 * refuses instead of setting an empty password, and that two sources at once refuse.
 *
 * Run in-process: both commands write ordinary rows on this connection, so the per-test
 * transaction rolls them back.
 */
class Login_User_Cli_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const OLD_PASSWORD = 'the-old-password';

    private static function __make_login_user(string $tag): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'login_user_cli_' . $tag . '_' . uniqid() . '@example.com';
        $login_user->password = Hash::make(self::OLD_PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * A membership of $login_user on $site_id showing $email.
     */
    private static function __make_membership(Login_User_Model $login_user, int $site_id, string $email): User_Model
    {
        static::__acting_as_site($site_id);

        $user = new User_Model();
        $user->site_id = $site_id;
        $user->login_user_id = $login_user->id;
        $user->first_name = 'Cli';
        $user->last_name = 'Fixture';
        $user->email = $email;
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    private static function __make_site(string $tag): int
    {
        $site = new Site_Model();
        $site->slug = 'login-user-cli-' . $tag . '-' . uniqid();
        $site->name = 'Login User Cli ' . $tag;
        $site->is_enabled = true;
        $site->save();

        return (int) $site->id;
    }

    private static function __insert_session(int $login_user_id): int
    {
        return (int) DB::table('_sessions')->insertGetId([
            'session_token' => bin2hex(random_bytes(16)),
            'csrf_token' => bin2hex(random_bytes(16)),
            'active' => 1,
            'site_id' => self::SITE_ID,
            'version' => 1,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'login-user-cli-test',
            'login_user_id' => $login_user_id,
            'last_active' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function __is_active(int $session_id): bool
    {
        return (bool) DB::table('_sessions')->where('id', $session_id)->value('active');
    }

    /**
     * The stored values, read past every model cache.
     */
    private static function __row(int $table_id, string $table = 'login_users'): object
    {
        return DB::table($table)->where('id', $table_id)->first();
    }

    /**
     * Run a command with --json, returning [exit_code, decoded_envelope].
     */
    private static function __json_call(string $command, array $args): array
    {
        $code = Artisan::call($command, $args + ['--json' => true]);

        return [$code, json_decode(Artisan::output(), true)];
    }

    // -------------------------------------------------------------------------
    // rsx:users:password:set
    // -------------------------------------------------------------------------

    /**
     * luc-01: the new password verifies, the old one does not, and an email names the same
     * identity an id does.
     */
    public static function test_password_set_replaces_the_hash()
    {
        $login_user = static::__make_login_user('pw');

        [$code, $envelope] = static::__json_call('rsx:users:password:set', [
            '--user' => $login_user->email,
            '--password' => 'the-new-password',
        ]);

        static::__assert_equals(0, $code);
        static::__assert_true($envelope['ok']);
        static::__assert_equals('password_set', $envelope['data']['action']);
        static::__assert_equals((int) $login_user->id, $envelope['data']['user']['id'], 'resolved by email');

        $hash = static::__row((int) $login_user->id)->password;

        static::__assert_true(Hash::check('the-new-password', $hash), 'the new password verifies');
        static::__assert_false(Hash::check(self::OLD_PASSWORD, $hash), 'the old one no longer does');
    }

    /**
     * luc-02: every signed-in session of the identity ends, and nobody else's does.
     */
    public static function test_password_set_ends_the_identitys_sessions()
    {
        $login_user = static::__make_login_user('sessions');
        $bystander = static::__make_login_user('bystander');

        $first = static::__insert_session((int) $login_user->id);
        $second = static::__insert_session((int) $login_user->id);
        $other = static::__insert_session((int) $bystander->id);

        [$code, $envelope] = static::__json_call('rsx:users:password:set', [
            '--user' => (string) $login_user->id,
            '--password' => 'reset-by-operator',
        ]);

        static::__assert_equals(0, $code);
        static::__assert_equals(2, $envelope['data']['sessions_ended']);
        static::__assert_false(static::__is_active($first));
        static::__assert_false(static::__is_active($second));
        static::__assert_true(static::__is_active($other), 'another identity keeps its session');
    }

    /**
     * luc-03: --keep-sessions changes the password and ends nothing.
     */
    public static function test_password_set_keep_sessions_leaves_them_active()
    {
        $login_user = static::__make_login_user('keep');
        $session = static::__insert_session((int) $login_user->id);

        [$code, $envelope] = static::__json_call('rsx:users:password:set', [
            '--user' => (string) $login_user->id,
            '--password' => 'kept-sessions',
            '--keep-sessions' => true,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_equals(0, $envelope['data']['sessions_ended']);
        static::__assert_true(static::__is_active($session));
        static::__assert_true(Hash::check('kept-sessions', static::__row((int) $login_user->id)->password));
    }

    /**
     * luc-04: every refusal changes nothing - no source with no terminal, two sources, an
     * empty value, and an identity that does not exist.
     */
    public static function test_password_set_refusals_change_nothing()
    {
        $login_user = static::__make_login_user('refuse');
        $session = static::__insert_session((int) $login_user->id);

        $cases = [
            'password_required' => ['--user' => (string) $login_user->id],
            'password_source_conflict' => [
                '--user' => (string) $login_user->id,
                '--password' => 'x',
                '--password-stdin' => true,
            ],
            'password_empty' => ['--user' => (string) $login_user->id, '--password' => ''],
            'user_required' => ['--password' => 'x'],
            'user_not_found' => ['--user' => 'nobody_' . uniqid() . '@example.com', '--password' => 'x'],
        ];

        foreach ($cases as $error_code => $args) {
            [$code, $envelope] = static::__json_call('rsx:users:password:set', $args);

            static::__assert_equals(1, $code, $error_code);
            static::__assert_false($envelope['ok'], $error_code);
            static::__assert_equals($error_code, $envelope['error']['code']);
        }

        static::__assert_true(Hash::check(self::OLD_PASSWORD, static::__row((int) $login_user->id)->password));
        static::__assert_true(static::__is_active($session), 'a refusal ends no session');
    }

    // -------------------------------------------------------------------------
    // rsx:users:email:set
    // -------------------------------------------------------------------------

    /**
     * luc-05: the sign-in address moves, and so does every membership that showed the old
     * address - on every site, trashed ones included. A membership an administrator had
     * re-addressed is left alone, and no session ends.
     */
    public static function test_email_set_moves_the_identity_and_its_memberships()
    {
        $login_user = static::__make_login_user('email');
        $old_email = $login_user->email;
        $new_email = 'moved_' . uniqid() . '@example.com';
        $session = static::__insert_session((int) $login_user->id);

        $second_site = static::__make_site('second');
        $third_site = static::__make_site('third');

        $home = static::__make_membership($login_user, self::SITE_ID, $old_email);
        $away = static::__make_membership($login_user, $second_site, $old_email);
        $readdressed = static::__make_membership($login_user, $third_site, 'billing_' . uniqid() . '@example.com');
        $readdressed_email = $readdressed->email;

        static::__acting_as_site($second_site);
        $away->delete();

        [$code, $envelope] = static::__json_call('rsx:users:email:set', [
            '--user' => (string) $login_user->id,
            '--email' => '  ' . $new_email . '  ',
        ]);

        static::__assert_equals(0, $code);
        static::__assert_equals('email_set', $envelope['data']['action']);
        static::__assert_equals($old_email, $envelope['data']['old_email']);
        static::__assert_equals($new_email, $envelope['data']['user']['email'], 'trimmed');
        static::__assert_equals($new_email, static::__row((int) $login_user->id)->email);

        static::__assert_equals($new_email, static::__row((int) $home->id, 'users')->email);
        static::__assert_equals($new_email, static::__row((int) $away->id, 'users')->email, 'another site, trashed');
        static::__assert_equals($readdressed_email, static::__row((int) $readdressed->id, 'users')->email, 'left alone');

        $updated_ids = array_column($envelope['data']['memberships_updated'], 'id');
        sort($updated_ids);
        $expected_ids = [(int) $home->id, (int) $away->id];
        sort($expected_ids);

        static::__assert_equals($expected_ids, $updated_ids);
        static::__assert_true(static::__is_active($session), 'an email change ends no session');

        [$by_new_code, $by_new] = static::__json_call('rsx:users:email:set', [
            '--user' => $new_email,
            '--email' => $new_email,
        ]);

        static::__assert_equals(0, $by_new_code, 'the new address now names the identity');
        static::__assert_equals('none', $by_new['data']['action'], 'setting the current address is a no-op');
    }

    /**
     * luc-06: an address another identity holds is refused, a SOFT-DELETED holder included
     * (the UNIQUE index does not know about soft deletes), and the refusal changes nothing.
     */
    public static function test_email_set_refuses_an_address_already_held()
    {
        $login_user = static::__make_login_user('taken');
        $old_email = $login_user->email;
        $live_holder = static::__make_login_user('live_holder');
        $deleted_holder = static::__make_login_user('deleted_holder');
        $deleted_holder->delete();

        [$code, $envelope] = static::__json_call('rsx:users:email:set', [
            '--user' => (string) $login_user->id,
            '--email' => $live_holder->email,
        ]);

        static::__assert_equals(1, $code);
        static::__assert_equals('email_in_use', $envelope['error']['code']);

        [$deleted_code, $deleted] = static::__json_call('rsx:users:email:set', [
            '--user' => (string) $login_user->id,
            '--email' => $deleted_holder->email,
        ]);

        static::__assert_equals(1, $deleted_code);
        static::__assert_equals('email_in_use', $deleted['error']['code']);
        static::__assert_contains('deleted identity', $deleted['error']['message']);

        static::__assert_equals($old_email, static::__row((int) $login_user->id)->email);
    }

    /**
     * luc-07: a missing or malformed --email is refused before anything is written.
     */
    public static function test_email_set_refuses_a_missing_or_malformed_address()
    {
        $login_user = static::__make_login_user('malformed');
        $old_email = $login_user->email;

        foreach (['email_required' => '', 'email_invalid' => 'not-an-address'] as $error_code => $value) {
            [$code, $envelope] = static::__json_call('rsx:users:email:set', [
                '--user' => (string) $login_user->id,
                '--email' => $value,
            ]);

            static::__assert_equals(1, $code, $error_code);
            static::__assert_equals($error_code, $envelope['error']['code']);
        }

        static::__assert_equals($old_email, static::__row((int) $login_user->id)->email);
    }
}
