<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;

/**
 * Test fixture: an auth-required topic (the default). Mirrors the real
 * Portal_Notification_Topic pattern - only the owning portal user may
 * subscribe to their own filter.
 */
class Realtime_Test_Private_Topic extends Realtime_Topic_Abstract
{
    public static function can_subscribe(array $filter = []): bool
    {
        if (!Portal_Session::is_logged_in()) {
            return false;
        }

        $requested = isset($filter['portal_user_id']) ? (int) $filter['portal_user_id'] : 0;

        return $requested > 0 && $requested === (int) Portal_Session::get_portal_user_id();
    }
}
