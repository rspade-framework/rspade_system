<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\RSpade\Commands\Database\Seed_Command;
use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Commands\Rsx\Rsx_Test_Command;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Locks\Site_Lock_Cli;
use App\RSpade\Core\Models\Email_Blocked_Address_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Locks\Php\Site_Lock_Cli_Exempt_Fixture_Command;
use App\RSpade\Tests\Locks\Php\Site_Lock_Cli_Fixture_Command;

/**
 * Site_Lock_Cli - the enforcement window of command-line code.
 *
 * The commands run in-process through Artisan::call(), which dispatches the same Symfony
 * command events as a command started from the shell, so the window opened here is the one
 * every artisan command gets. The fixtures (Site_Lock_Cli_Fixture_Command and its exempt
 * subclass) are registered into the running Artisan application by setup(). The test runner
 * itself is exempt, so the test body runs outside every window and only the command's run
 * is checked.
 */
class Site_Lock_Cli_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);

        Artisan::registerCommand(new Site_Lock_Cli_Fixture_Command());
        Artisan::registerCommand(new Site_Lock_Cli_Exempt_Fixture_Command());
    }

    public static function test_a_command_writing_a_site_scoped_model_without_a_section_fatals()
    {
        $email = static::__email();

        static::__assert_false(Site_Lock::_is_enforcing(), 'the test runner is exempt: no window around the test');

        $message = static::__refusal(fn () => Artisan::call('rsx_test:site_lock_cli_fixture', ['mode' => 'bare', 'email' => $email]));

        static::__assert_contains('Site lock enforcement', $message);
        static::__assert_contains('command rsx_test:site_lock_cli_fixture', $message, 'the refusal names the command');
        static::__assert_false(static::__exists($email), 'the refused write wrote nothing');
        static::__assert_false(Site_Lock::_is_enforcing(), 'the window closed with the command that threw');
    }

    public static function test_the_same_write_inside_a_write_section_passes()
    {
        $email = static::__email();

        static::__assert_equals(0, Artisan::call('rsx_test:site_lock_cli_fixture', ['mode' => 'section', 'email' => $email]));

        static::__assert_true(static::__exists($email), 'the sectioned write landed');
        static::__assert_null(Site_Lock::_held(), 'the section released its lock');
        static::__assert_false(Site_Lock::_is_enforcing(), 'the window closed with the command');
    }

    public static function test_the_window_takes_no_lock_and_permits_a_site_change()
    {
        Site_Lock_Cli_Fixture_Command::$probe = [];

        static::__assert_equals(0, Artisan::call('rsx_test:site_lock_cli_fixture', ['mode' => 'probe', 'email' => static::__email()]));

        static::__assert_equals(
            ['enforcing' => true, 'request_window' => false, 'site_changed' => true],
            Site_Lock_Cli_Fixture_Command::$probe,
            'enforced, not a request window, and the site may change between sections'
        );
    }

    public static function test_an_exempt_command_is_unchecked()
    {
        $email = static::__email();

        static::__assert_equals(0, Artisan::call('rsx_test:site_lock_cli_exempt_fixture', ['mode' => 'bare', 'email' => $email]));

        static::__assert_true(static::__exists($email), 'the exempt command wrote without a lock');

        Site_Lock_Cli_Fixture_Command::$probe = [];
        Artisan::call('rsx_test:site_lock_cli_exempt_fixture', ['mode' => 'probe', 'email' => static::__email()]);
        static::__assert_false(Site_Lock_Cli_Fixture_Command::$probe['enforcing'], 'an exempt command opens no window');
    }

    public static function test_exemption_is_declared_by_constant_or_vendor_family_and_needs_a_reason()
    {
        static::__assert_equals('site lock cli test fixture', Site_Lock_Cli::exemption_reason(Site_Lock_Cli_Exempt_Fixture_Command::class));
        static::__assert_null(Site_Lock_Cli::exemption_reason(Site_Lock_Cli_Fixture_Command::class), 'an undeclared command is enforced');

        foreach ([Maint_Migrate::class, Seed_Command::class, Rsx_Test_Command::class] as $class) {
            static::__assert_not_null(Site_Lock_Cli::exemption_reason($class), "{$class} declares its exemption");
        }

        static::__assert_not_null(
            Site_Lock_Cli::exemption_reason(\Illuminate\Database\Console\Migrations\RollbackCommand::class),
            "Laravel's own migration commands are exempt by their family"
        );

        $unreasoned = new class extends Command {
            public const SITE_LOCK_EXEMPT = '';

            protected $signature = 'rsx_test:site_lock_cli_unreasoned';
        };

        static::__assert_contains('with no reason', static::__refusal(fn () => Site_Lock_Cli::exemption_reason($unreasoned)));
    }

    public static function test_a_window_closed_out_of_order_is_refused()
    {
        Site_Lock_Cli::_open_script_window('site_lock_cli_test.php');
        Site_Lock::_enter_enforcement('inner window');

        try {
            static::__assert_contains(
                "the innermost enforcement window is 'inner window'",
                static::__refusal(fn () => Site_Lock::_leave_enforcement_labelled('script site_lock_cli_test.php'))
            );
        } finally {
            Site_Lock::_leave_enforcement();
        }

        Site_Lock::_leave_enforcement_labelled('script site_lock_cli_test.php');
        static::__assert_false(Site_Lock::_is_enforcing(), 'both windows closed');
    }

    private static function __email(): string
    {
        return 'site-lock-cli-' . bin2hex(random_bytes(6)) . '@test.rspade.test';
    }

    private static function __exists(string $email): bool
    {
        return Email_Blocked_Address_Model::where('email', $email)->exists();
    }

    /** The message of the exception $work throws (the test fails if it throws none). */
    private static function __refusal(callable $work): string
    {
        try {
            $work();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        static::__assert_true(false, 'expected a refusal');

        return '';
    }
}
