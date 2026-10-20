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
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:users:force_password_reset - setting and clearing password_reset_required from the
 * command line, for a staff login identity (--user) or a portal user (--portal-user, with
 * --site for an email), with the shared --json envelope.
 *
 * What is pinned is the column the command claims to have written, read past every model
 * cache, the was/is pair it reports, and the refusals for an ambiguous or contradictory
 * naming. The password itself is never touched.
 *
 * Run in-process: the command writes ordinary rows on this connection, so the per-test
 * transaction rolls them back.
 */
class Force_Password_Reset_Cli_Test extends Rsx_Test_Abstract
{
    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'force_reset_' . uniqid() . '@example.com';
        $login_user->password = Hash::make('the-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    private static function __make_portal_user(): Portal_User_Model
    {
        $site = new Site_Model();
        $site->slug = 'force-reset-' . uniqid();
        $site->name = 'Force Reset';
        $site->is_enabled = true;
        $site->save();

        static::__acting_as_site((int) $site->id);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = (int) $site->id;
        $portal_user->email = 'force_reset_portal_' . uniqid() . '@example.com';
        $portal_user->set_password('the-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        // The command serves no tenant: run it from another site's scope.
        static::__acting_as_site(1);

        return $portal_user;
    }

    private static function __json_call(array $args): array
    {
        $code = Artisan::call('rsx:users:force_password_reset', $args + ['--json' => true]);

        return [$code, json_decode(Artisan::output(), true)];
    }

    private static function __flag(string $table, int $id): int
    {
        return (int) DB::table($table)->where('id', $id)->value('password_reset_required');
    }

    /**
     * fpr-01: a staff identity is flagged by email and cleared by id; the password is untouched.
     */
    public static function test_staff_set_and_clear()
    {
        $login_user = static::__make_login_user();
        $hash = (string) DB::table('login_users')->where('id', $login_user->id)->value('password');

        [$code, $envelope] = static::__json_call(['--user' => $login_user->email]);

        static::__assert_equals(0, $code);
        static::__assert_true($envelope['ok']);
        static::__assert_equals('password_reset_required', $envelope['data']['action']);
        static::__assert_equals('staff', $envelope['data']['realm']);
        static::__assert_equals((int) $login_user->id, $envelope['data']['user']['id']);
        static::__assert_false($envelope['data']['was_required']);
        static::__assert_true($envelope['data']['is_required']);
        static::__assert_equals(1, static::__flag('login_users', (int) $login_user->id));
        static::__assert_equals($hash, (string) DB::table('login_users')->where('id', $login_user->id)->value('password'), 'the password is untouched');

        [$code, $envelope] = static::__json_call(['--user' => (string) $login_user->id, '--clear' => true]);

        static::__assert_equals(0, $code);
        static::__assert_equals('password_reset_cleared', $envelope['data']['action']);
        static::__assert_true($envelope['data']['was_required']);
        static::__assert_false($envelope['data']['is_required']);
        static::__assert_equals(0, static::__flag('login_users', (int) $login_user->id));
    }

    /**
     * fpr-02: a portal user is flagged by id from another tenant's scope, and by email with
     * --site; an email without --site is refused.
     */
    public static function test_portal_set_and_clear()
    {
        $portal_user = static::__make_portal_user();

        [$code, $envelope] = static::__json_call(['--portal-user' => (string) $portal_user->id]);

        static::__assert_equals(0, $code);
        static::__assert_equals('portal', $envelope['data']['realm']);
        static::__assert_equals((int) $portal_user->id, $envelope['data']['portal_user']['id']);
        static::__assert_equals((int) $portal_user->site_id, $envelope['data']['portal_user']['site_id']);
        static::__assert_equals(1, static::__flag('portal_users', (int) $portal_user->id));

        [$code, $envelope] = static::__json_call(['--portal-user' => $portal_user->email]);

        static::__assert_equals(1, $code);
        static::__assert_equals('site_required', $envelope['error']['code']);

        [$code, $envelope] = static::__json_call([
            '--portal-user' => $portal_user->email,
            '--site' => (string) $portal_user->site_id,
            '--clear' => true,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_true($envelope['data']['was_required']);
        static::__assert_equals(0, static::__flag('portal_users', (int) $portal_user->id));
    }

    /**
     * fpr-03: the refusals - no identity, both realms at once, an unknown id, a --site that
     * contradicts the portal user's own.
     */
    public static function test_refusals()
    {
        $portal_user = static::__make_portal_user();

        [$code, $envelope] = static::__json_call([]);
        static::__assert_equals(1, $code);
        static::__assert_equals('user_required', $envelope['error']['code']);

        [, $envelope] = static::__json_call(['--user' => '1', '--portal-user' => '1']);
        static::__assert_equals('identity_conflict', $envelope['error']['code']);

        [, $envelope] = static::__json_call(['--portal-user' => '999999999']);
        static::__assert_equals('portal_user_not_found', $envelope['error']['code']);

        [, $envelope] = static::__json_call(['--portal-user' => (string) $portal_user->id, '--site' => '999999999']);
        static::__assert_equals('site_mismatch', $envelope['error']['code']);
        static::__assert_equals(0, static::__flag('portal_users', (int) $portal_user->id), 'a refusal writes nothing');
    }

    /**
     * fpr-04: the human output says what changed, and that nothing was emailed.
     */
    public static function test_human_output()
    {
        $login_user = static::__make_login_user();

        $code = Artisan::call('rsx:users:force_password_reset', ['--user' => $login_user->email]);
        $output = Artisan::output();

        static::__assert_equals(0, $code);
        static::__assert_contains('[OK] Password reset required', $output);
        static::__assert_contains('No email was sent', $output);

        Artisan::call('rsx:users:force_password_reset', ['--user' => $login_user->email]);
        static::__assert_contains('already required; nothing changed', Artisan::output());
    }
}
