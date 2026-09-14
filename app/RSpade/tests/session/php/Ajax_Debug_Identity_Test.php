<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * `rsx:ajax --user=` establishes the WHOLE identity - site, login identity AND site user.
 *
 * The command used to set only the login identity. In CLI mode Session::get_user_id()
 * answers from a static that nothing derives, so every user-scoped endpoint ran as nobody
 * while the actor stamp resolved correctly - confident, wrong answers with no warning (a
 * downstream field report, 2026-09-04). The command now goes through
 * Session::impersonate() with the users row resolved for the login identity on the site,
 * resolves the site from that identity's single users row when --site is omitted, and
 * refuses when the site is not unique instead of running under no site at all.
 *
 * Each test runs the command in-process (Artisan::call), against the fixture endpoint
 * beside this file, and reads the identity the endpoint reported.
 */
class Ajax_Debug_Identity_Test extends Rsx_Test_Abstract
{
    private const CONTROLLER = 'Ajax_Debug_Identity_Fixture_Controller';

    public static function setup()
    {
        Session::reset_impersonation();
    }

    public static function teardown()
    {
        Session::reset_impersonation();
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    private static function __run(array $options): array
    {
        $exit = Artisan::call('rsx:ajax', array_merge([
            'controller' => self::CONTROLLER,
            'action' => 'identity',
        ], $options));

        $output = Artisan::output();
        $json_start = strpos($output, '{');
        $payload = $json_start === false ? null : json_decode(substr($output, $json_start), true);

        return ['exit' => $exit, 'output' => $output, 'json' => $payload];
    }

    private static function __baseline_site_id(): int
    {
        return (int) Site_Model::where('id', '>', 0)->orderBy('id')->value('id');
    }

    private static function __login_user_without_site_row(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'ajax-debug-' . uniqid() . '@rspade.test';
        $login_user->password = Hash::make('irrelevant-' . uniqid());
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    private static function __make_site(string $tag): int
    {
        $site = new Site_Model();
        $site->slug = 'ajax-debug-' . $tag . '-' . uniqid();
        $site->name = 'Ajax Debug ' . $tag;
        $site->is_enabled = true;
        $site->save();

        return (int) $site->id;
    }

    private static function __user_row(int $login_user_id, int $site_id): int
    {
        return User_Model::without_site_scope(function () use ($login_user_id, $site_id) {
            $user = new User_Model();
            $user->site_id = $site_id;
            $user->login_user_id = $login_user_id;
            $user->first_name = 'Ajax';
            $user->last_name = 'Debug';
            $user->save();

            return (int) $user->id;
        });
    }

    // -------------------------------------------------------------------------
    // the defect: the site user is resolved, not left null
    // -------------------------------------------------------------------------

    public static function test_user_and_site_flags_establish_all_three_identity_parts()
    {
        $site_id = static::__baseline_site_id();
        $run = static::__run(['--user' => '1', '--site' => (string) $site_id]);

        static::__assert_equals(0, $run['exit'], 'command succeeds: ' . $run['output']);
        static::__assert_equals(1, $run['json']['login_user_id'], 'login identity set');
        static::__assert_equals($site_id, $run['json']['site_id'], 'site set');
        static::__assert_equals(1, $run['json']['user_id'], 'the SITE user is resolved (users.id 1 is login user 1 on the baseline site)');
    }

    public static function test_email_form_of_user_resolves_the_same_identity()
    {
        $email = Login_User_Model::find(1)->email;
        $run = static::__run(['--user' => $email, '--site' => (string) static::__baseline_site_id()]);

        static::__assert_equals(0, $run['exit'], 'command succeeds: ' . $run['output']);
        static::__assert_equals(1, $run['json']['user_id'], 'site user resolved from an email');
    }

    public static function test_site_is_resolved_from_the_login_users_single_row_when_omitted()
    {
        $run = static::__run(['--user' => '1']);

        static::__assert_equals(0, $run['exit'], 'command succeeds: ' . $run['output']);
        static::__assert_equals(static::__baseline_site_id(), $run['json']['site_id'], 'the one site the login user has a row on');
        static::__assert_equals(1, $run['json']['user_id'], 'and the site user follows');
    }

    // -------------------------------------------------------------------------
    // the real states that used to be silent, now named
    // -------------------------------------------------------------------------

    public static function test_login_user_with_no_row_on_the_site_runs_with_null_user_and_says_so()
    {
        $login_user = static::__login_user_without_site_row();
        $site_id = static::__baseline_site_id();

        $run = static::__run(['--user' => (string) $login_user->id, '--site' => (string) $site_id, '--show-context' => true]);

        static::__assert_equals(0, $run['exit'], 'not an error - a cross-site login with no presence here is a real state: ' . $run['output']);
        static::__assert_equals((int) $login_user->id, $run['json']['login_user_id'], 'login identity set');
        static::__assert_equals($site_id, $run['json']['site_id'], 'site set');
        static::__assert_null($run['json']['user_id'], 'no users row, so no site user');
        static::__assert_true(
            str_contains($run['output'], "Set user_id to NULL: login user {$login_user->id} has no users row on site {$site_id}"),
            '--show-context names the null and why: ' . $run['output']
        );
    }

    public static function test_show_context_prints_the_resolved_site_user()
    {
        $site_id = static::__baseline_site_id();
        $run = static::__run(['--user' => '1', '--site' => (string) $site_id, '--show-context' => true]);

        static::__assert_true(
            str_contains($run['output'], "Set user_id to 1 (the users row of login user 1 on site {$site_id})"),
            'the resolved site user is printed: ' . $run['output']
        );
    }

    // -------------------------------------------------------------------------
    // refusals: no site at all is not a state worth reproducing
    // -------------------------------------------------------------------------

    public static function test_login_user_on_no_site_with_no_site_flag_is_refused()
    {
        $login_user = static::__login_user_without_site_row();

        $run = static::__run(['--user' => (string) $login_user->id]);

        static::__assert_equals(1, $run['exit'], 'refused: ' . $run['output']);
        static::__assert_equals('site_required', $run['json']['error_type'], 'names the remedy');
        static::__assert_true(str_contains($run['json']['error'], '--site='), 'tells the caller to pass --site');
    }

    public static function test_login_user_on_several_sites_with_no_site_flag_is_refused()
    {
        $login_user = static::__login_user_without_site_row();
        $site_a = static::__make_site('a');
        $site_b = static::__make_site('b');
        static::__user_row((int) $login_user->id, $site_a);
        static::__user_row((int) $login_user->id, $site_b);

        $run = static::__run(['--user' => (string) $login_user->id]);

        static::__assert_equals(1, $run['exit'], 'refused: ' . $run['output']);
        static::__assert_equals('site_required', $run['json']['error_type'], 'names the remedy');
        static::__assert_true(str_contains($run['json']['error'], (string) $site_a) && str_contains($run['json']['error'], (string) $site_b), 'lists the candidate sites: ' . $run['json']['error']);
    }

    public static function test_login_user_on_several_sites_with_site_flag_resolves_that_sites_user()
    {
        $login_user = static::__login_user_without_site_row();
        $site_a = static::__make_site('a');
        $site_b = static::__make_site('b');
        static::__user_row((int) $login_user->id, $site_a);
        $user_b = static::__user_row((int) $login_user->id, $site_b);

        $run = static::__run(['--user' => (string) $login_user->id, '--site' => (string) $site_b]);

        static::__assert_equals(0, $run['exit'], 'command succeeds: ' . $run['output']);
        static::__assert_equals($site_b, $run['json']['site_id'], 'the chosen site');
        static::__assert_equals($user_b, $run['json']['user_id'], 'and that site\'s users row, not the other site\'s');
    }
}
