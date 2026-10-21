<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Cli;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * `rsx:task:run <Service> <method> --user=<id|email> --site=<id>`, driven for real.
 *
 * The pair says who the run acts as, resolved exactly as rsx:ajax resolves it
 * (Rsx_Cli_Actor): the login identity, the site (required when the identity has users rows on
 * several sites), and its users row there. A #[Run_As_Dispatcher] task needs it - without
 * --user it is refused naming the flags, with it the task runs inline as that person - and
 * for any other task it is accepted and is never a task parameter. A #[Command] alias does
 * not take the pair: its options are all the task's own.
 *
 * Each child is pointed at the TEST database (DB_DATABASE, an environment fact) and commits
 * its run, so the class provisions a clean baseline and reads the rows the children wrote.
 * Output is captured merged (Rsx_Artisan::run): which stream a line takes is
 * Task_Command_Cli_Test's subject, not this one's.
 */
class Task_Run_As_Dispatcher_Cli_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    private const SERVICE = 'Task_Run_As_Fixture_Service';

    /**
     * @param array<int, string> $args argv after the command name
     * @return array{0: int, 1: string} [exit code, merged output]
     */
    private static function __run(string $command, array $args): array
    {
        $output = [];
        $exit = Rsx_Artisan::run($command, $args, $output, [
            'DB_DATABASE' => (string) config('database.connections.test.database'),
        ]);

        return [$exit, implode("\n", $output)];
    }

    /** The newest run of $method, or null when there is none. */
    private static function __last_run(string $method): ?Task_Run_Model
    {
        $id = DB::table('_tasks')->where('method', $method)->max('id');

        return $id === null ? null : Task_Run_Model::find((int) $id);
    }

    private static function __last_run_id(): int
    {
        return (int) DB::table('_tasks')->max('id');
    }

    /** The {"echo": {...}} line echo_params writes to stdout, decoded. */
    private static function __echoed(string $output): ?array
    {
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '{"echo"')) {
                return json_decode($line, true)['echo'];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // A #[Run_As_Dispatcher] task
    // -------------------------------------------------------------------------

    public static function test_without_user_a_dispatcher_task_is_refused_naming_the_flags()
    {
        $before = static::__last_run_id();

        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher']);

        static::__assert_equals(1, $exit);
        static::__assert_contains(
            '[ERROR] Task_Run_As_Fixture_Service::as_dispatcher runs as the user who dispatched it (#[Run_As_Dispatcher]). '
            . 'From the command line, say who it acts as: --user=<id|email> --site=<id>',
            $output
        );
        static::__assert_equals($before, static::__last_run_id(), 'nothing was recorded');
    }

    public static function test_a_site_alone_is_not_somebody_to_act_as()
    {
        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher', '--site=1']);

        static::__assert_equals(1, $exit);
        static::__assert_contains('say who it acts as: --user=<id|email> --site=<id>', $output);
    }

    /**
     * With --user (by email) and --site, the task runs inline as that person: identity,
     * membership, site, attribution - and no browser, sign-in or audit session, because a
     * command line has none.
     */
    public static function test_with_user_and_site_it_runs_inline_as_that_user()
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        $email = Login_User_Model::find($user->login_user_id)->email;

        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher', '--user=' . $email, '--site=' . $user->site_id]);

        static::__assert_equals(0, $exit, 'the run succeeded: ' . $output);

        $run = static::__last_run('as_dispatcher');
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_equals(Task_Run_Model::ORIGIN_INLINE, (int) $run->origin_id);

        $who = $run->state();
        static::__assert_equals((int) $user->login_user_id, (int) $who['login_user_id']);
        static::__assert_equals((int) $user->id, (int) $who['user_id'], 'the identity\'s users row on that site');
        static::__assert_equals((int) $user->site_id, (int) $who['site_id']);
        static::__assert_equals(['type' => 'User_Model', 'id' => (int) $user->id], $who['actor']);
        static::__assert_null($who['session_id']);
        static::__assert_null($who['user_session_id']);
        static::__assert_null($who['audit_session_id']);

        static::__assert_equals((int) $user->id, (int) $run->dispatched_by_id, 'the row names who it acted as');
        static::__assert_null($run->user_session_id);
        static::__assert_equals([], $run->params ?? [], '--user and --site are not task parameters');
    }

    /** --site may be left out when the identity has users rows on exactly one site. */
    public static function test_the_one_site_is_resolved_when_site_is_omitted()
    {
        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher', '--user=1']);

        static::__assert_equals(0, $exit, 'the run succeeded: ' . $output);
        static::__assert_equals(1, (int) static::__last_run('as_dispatcher')->state()['user_id']);
    }

    // -------------------------------------------------------------------------
    // The resolution is rsx:ajax's
    // -------------------------------------------------------------------------

    public static function test_an_unknown_user_or_site_is_refused_with_rsx_ajax_s_message()
    {
        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher', '--user=nobody@nowhere.invalid']);
        static::__assert_equals(1, $exit);
        static::__assert_contains('[ERROR] User not found: nobody@nowhere.invalid', $output);

        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher', '--user=1', '--site=987654']);
        static::__assert_equals(1, $exit);
        static::__assert_contains('[ERROR] Site ID not found: 987654', $output);
    }

    public static function test_site_is_required_when_the_identity_is_on_several_sites()
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'run-as-cli-' . uniqid() . '@rspade.test';
        $login_user->password = Hash::make('irrelevant-' . uniqid());
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $sites = [];
        $users = [];
        foreach (['a', 'b'] as $tag) {
            $site = new Site_Model();
            $site->slug = 'run-as-cli-' . $tag . '-' . uniqid();
            $site->name = 'Run As CLI ' . $tag;
            $site->is_enabled = true;
            $site->save();
            $sites[$tag] = (int) $site->id;

            $users[$tag] = User_Model::without_site_scope(function () use ($login_user, $site) {
                $user = new User_Model();
                $user->site_id = (int) $site->id;
                $user->login_user_id = (int) $login_user->id;
                $user->first_name = 'Run';
                $user->last_name = 'As';
                $user->is_enabled = true;
                $user->save();

                return (int) $user->id;
            });
        }

        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher', '--user=' . $login_user->id]);
        static::__assert_equals(1, $exit);
        static::__assert_contains("Login user {$login_user->id} has users rows on sites {$sites['a']}, {$sites['b']}; pass --site=<id> to choose one", $output);

        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher', '--user=' . $login_user->id, '--site=' . $sites['b']]);
        static::__assert_equals(0, $exit, 'the run succeeded: ' . $output);
        $who = static::__last_run('as_dispatcher')->state();
        static::__assert_equals($sites['b'], (int) $who['site_id'], 'the chosen site');
        static::__assert_equals($users['b'], (int) $who['user_id'], 'and the identity\'s users row there');
    }

    /** A disabled membership, and a disabled site, are refused before anything runs. */
    public static function test_a_disabled_membership_or_site_is_refused()
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'run-as-cli-disabled-' . uniqid() . '@rspade.test';
        $login_user->password = Hash::make('irrelevant-' . uniqid());
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $site = new Site_Model();
        $site->slug = 'run-as-cli-disabled-' . uniqid();
        $site->name = 'Run As CLI disabled';
        $site->is_enabled = true;
        $site->save();

        $user = User_Model::without_site_scope(function () use ($login_user, $site) {
            $user = new User_Model();
            $user->site_id = (int) $site->id;
            $user->login_user_id = (int) $login_user->id;
            $user->first_name = 'Run';
            $user->last_name = 'As';
            $user->is_enabled = false;
            $user->save();

            return $user;
        });

        $before = static::__last_run_id();
        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher', '--user=' . $login_user->id, '--site=' . $site->id]);
        static::__assert_equals(1, $exit);
        static::__assert_contains("membership on site {$site->id} is disabled", $output);
        static::__assert_equals($before, static::__last_run_id(), 'nothing was recorded');

        User_Model::without_site_scope(function () use ($user) {
            $user->is_enabled = true;
            $user->save();
        });
        $site->is_enabled = false;
        $site->save();

        [$exit, $output] = static::__run('rsx:task:run', [self::SERVICE, 'as_dispatcher', '--user=' . $login_user->id, '--site=' . $site->id]);
        static::__assert_equals(1, $exit);
        static::__assert_contains("Site {$site->id} is disabled", $output);
        static::__assert_equals($before, static::__last_run_id(), 'nothing was recorded');
    }

    // -------------------------------------------------------------------------
    // Any task; never an alias
    // -------------------------------------------------------------------------

    /** The flags work for an ordinary task too, and never reach its parameters. */
    public static function test_an_ordinary_task_runs_as_the_user_and_the_flags_are_not_parameters()
    {
        [$exit, $output] = static::__run('rsx:task:run', ['Test_Echo_Service', 'echo_params', '--user=1', '--site=1', '--a=1']);

        static::__assert_equals(0, $exit, 'the run succeeded: ' . $output);
        static::__assert_equals(['a' => '1'], static::__echoed($output));
        static::__assert_equals(1, (int) static::__last_run('echo_params')->dispatched_by_id, 'attributed to the user');
    }

    /** A #[Command] alias's options are all the task's own: --user is a parameter there. */
    public static function test_an_alias_passes_user_and_site_to_the_task()
    {
        [$exit, $output] = static::__run('rsx_test:echo', ['--user=someone', '--site=2']);

        static::__assert_equals(0, $exit, 'the run succeeded: ' . $output);
        $echoed = static::__echoed($output) ?? [];
        ksort($echoed);
        static::__assert_equals(['site' => '2', 'user' => 'someone'], $echoed);
        static::__assert_null(static::__last_run('echo_params')->dispatched_by_id, 'and the run acts as nobody');
    }
}
