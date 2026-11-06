<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Email_Blocked_Address_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Test-only task bodies for Site_Lock_Test and Site_Lock_Hold_Discipline_Test: a site-scoped
 * write made without the site's write lock, the same write inside a write section and under a
 * write exception, a body that leaves a database transaction open, and one that leaves a
 * write exception open. Indexed only under a test run (Manifest::scan_directories()).
 *
 * Every method writes an _email_blocked_addresses row (a framework site-scoped table) for
 * the declared site, with the address passed in $params['email'].
 */
class Site_Lock_Fixture_Service extends Rsx_Service_Abstract
{
    #[Task('Write a site-scoped row with no site lock (test-only)')]
    public static function write_unlocked(Task_Instance $task, array $params = [])
    {
        static::__block((string) $params['email']);

        return null;
    }

    #[Task('Write a site-scoped row inside a write section (test-only)')]
    public static function write_in_section(Task_Instance $task, array $params = [])
    {
        Site_Lock::write((int) $params['site_id'], fn () => static::__block((string) $params['email']));

        return null;
    }

    #[Task('Leave a database transaction open (test-only)')]
    public static function leak_transaction(Task_Instance $task, array $params = [])
    {
        DB::beginTransaction();

        return null;
    }

    #[Task('Write a site-scoped row under a write exception (test-only)')]
    public static function write_under_exception(Task_Instance $task, array $params = [])
    {
        Site_Lock::write_exception('site lock test: record from a task', fn () => static::__block((string) $params['email']));

        return null;
    }

    #[Task('Leave a write exception open (test-only)')]
    public static function leak_exception(Task_Instance $task, array $params = [])
    {
        Site_Lock::acquire_write_exception('site lock test: left open by a task');

        return null;
    }

    private static function __block(string $email): void
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'site lock test fixture';
        $row->save();
    }
}
