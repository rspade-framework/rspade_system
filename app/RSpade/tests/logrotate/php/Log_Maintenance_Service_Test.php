<?php

namespace App\RSpade\Tests\Logrotate\Php;

use App\RSpade\Core\Logging\Log_Maintenance_Service;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Log_Maintenance_Service - the scheduled sweep's config gate.
 *
 * Only the DISABLED path is exercised here, deliberately: the enabled path rotates
 * the real storage/logs, and a test that mutates the developer's live logs to prove
 * a point is not a test worth having. The rotation itself is covered against fixture
 * directories in Rsx_Logrotate_Test.
 */
class Log_Maintenance_Service_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * With rotation disabled the task returns silently and touches nothing.
     */
    public static function test_disabled_config_is_a_no_op()
    {
        $original = config('rsx.logging.rotation.enabled');
        config(['rsx.logging.rotation.enabled' => false]);

        $before = self::__log_directory_listing();

        $task = new Task_Instance(Log_Maintenance_Service::class, 'rotate');
        $result = Log_Maintenance_Service::rotate($task);

        $after = self::__log_directory_listing();

        config(['rsx.logging.rotation.enabled' => $original]);

        static::__assert_equals(['skipped' => 'disabled'], $result);
        static::__assert_equals($before, $after, 'the log directory must be untouched when rotation is disabled');
    }

    /**
     * Names and sizes of everything in storage/logs, as a comparable snapshot.
     *
     * @return array filename => size
     */
    private static function __log_directory_listing(): array
    {
        $listing = [];

        foreach (scandir(storage_path('logs')) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $listing[$entry] = filesize(storage_path('logs/' . $entry));
        }

        ksort($listing);

        return $listing;
    }
}
