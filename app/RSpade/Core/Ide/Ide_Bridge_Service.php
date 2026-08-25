<?php

namespace App\RSpade\Core\Ide;

use App\RSpade\Core\Ide\Ide_Bridge_Token;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Ide_Bridge_Service
 *
 * Scheduled maintenance of the IDE bridge's local-file grants.
 *
 * WHAT ROTATION IS FOR. The grant is a long-lived credential sitting in storage/ - the
 * one thing an editor presents to reach /_ide/service/*. Minting a fresh one every 15
 * minutes bounds how long a copy of that file stays useful: a secret read off a backup,
 * a synced folder or a shared screen expires on its own instead of lasting until
 * somebody notices.
 *
 * WHAT IT IS NOT FOR. It is NOT what makes the bridge work, and nothing here is load
 * bearing for a developer who never enabled cron. Ide_Bridge_Token::ensure() mints one
 * grant on the first development web request and NOTHING expires it - that grant keeps
 * working forever on a box with no scheduler. Rotation is an improvement layered on top
 * of a system that is already correct without it, which is why this service can be
 * disabled, fail, or never run at all without breaking anybody.
 *
 * TWO GRANTS STAY VALID (Ide_Bridge_Token::ACTIVE_GRANTS). Rotation and use are not
 * synchronized: an editor holds whatever it last read until its own refresh comes
 * round, so at the instant a new grant appears the PREVIOUS one is still the only value
 * any client has. Keeping the last two means a rotation is never observable as a
 * failure - the client's next refresh picks the new one up on its own schedule.
 *
 * OUTSIDE DEVELOPMENT IT PURGES. A sealed build refuses the bridge at auth.php
 * regardless, so a grant file there opens no door - but it is still a secret on disk
 * with no reader, and the box that most needs it gone is the one that ran in
 * development first and was flipped to production later. The sweep is unconditional and
 * runs on the same schedule, so the cleanup happens whether or not anyone remembers.
 */
class Ide_Bridge_Service extends Rsx_Service_Abstract
{
    /**
     * Rotate the IDE bridge grants (development), or purge them (anywhere else).
     *
     * #[Exclusive] because two workers racing here would mint two grants in one
     * interval and immediately retire one of them - churn that could retire a grant a
     * client had just read. The work is a few file operations, so a run never overlaps
     * the next by enough for exclusion to cost anything.
     *
     * @param Task_Instance $task
     * @param array $params
     * @return array{minted: ?string, removed: int, mode: string}
     */
    #[Task('Rotate the IDE bridge grant tokens (purges them outside development)')]
    #[Exclusive]
    #[Schedule('*/15 * * * *')]
    public static function rotate_grants(Task_Instance $task, array $params = [])
    {
        $result = Ide_Bridge_Token::rotate();

        // Silent on the ordinary path. A rotation is routine and happens 96 times a day;
        // logging each one would bury the runs that actually said something.
        if ($result['mode'] === 'purged' && $result['removed'] > 0) {
            $task->log('info', sprintf(
                'Purged %d IDE bridge grant(s): the bridge is development-only and this box is %s.',
                $result['removed'],
                Rsx::get_mode()
            ));
        }

        return $result;
    }
}
