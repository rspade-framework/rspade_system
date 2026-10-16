<?php

namespace App\RSpade\Core\Logging;

use Illuminate\Log\Logger;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * System_Timezone_Log_Tap
 *
 * Stamps log records in the OPERATING SYSTEM's timezone (Rsx_Time::get_system_timezone())
 * instead of the process's UTC, so laravel.log reads in the same clock as the host's other
 * logs and `date`. Every channel in config/logging.php names it as a tap.
 *
 * Only the record's timestamp moves. The process itself still runs in UTC, and so does
 * every value the application stores or sends.
 *
 * Instantiatable because Laravel's tap contract is an invokable instance.
 */
#[Instantiatable]
class System_Timezone_Log_Tap
{
    /**
     * @param Logger $logger
     * @return void
     */
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->setTimezone(new \DateTimeZone(Rsx_Time::get_system_timezone()));
    }
}
