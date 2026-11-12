<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Console\Command;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;

/**
 * A console command for Site_Lock_Cli_Test, registered into the running Artisan application
 * by the test (commands are not discovered from the test tree). It writes the site lock
 * tests' site-scoped model, Email_Blocked_Address_Model, on the acting site (the test's site 1):
 *
 *   bare     - the write with no lock (refused inside the command's window)
 *   section  - the write inside Site_Lock::write(1)
 *   exception - the write under Site_Lock::write_exception()
 *   leak_exception - the write under a write exception it never releases
 *   probe    - no write; records what the command saw (window open, site change allowed)
 */
#[Instantiatable]
class Site_Lock_Cli_Fixture_Command extends Command
{
    protected $signature = 'rsx_test:site_lock_cli_fixture {mode} {email}';

    protected $description = 'Site_Lock_Cli_Test fixture';

    /** What the last 'probe' run saw. */
    public static array $probe = [];

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        switch ((string) $this->argument('mode')) {
            case 'bare':
                static::__block($email);
                break;

            case 'section':
                Site_Lock::write(1, fn () => static::__block($email));
                break;

            case 'exception':
                Site_Lock::write_exception('site lock cli test: record from a command', fn () => static::__block($email));
                break;

            case 'leak_exception':
                Site_Lock::acquire_write_exception('site lock cli test: left open by a command');
                static::__block($email);
                break;

            case 'probe':
                Session::set_site_id(1);
                static::$probe = ['enforcing' => Site_Lock::_is_enforcing(), 'request_window' => Site_Lock::_in_request_window(), 'site_changed' => true];
                break;

            default:
                shouldnt_happen('Site_Lock_Cli_Fixture_Command: unknown mode');
        }

        return 0;
    }

    private static function __block(string $email): void
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'site lock cli test';
        $row->save();
    }
}
