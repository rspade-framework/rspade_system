<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;

// @REALTIME-AUTH-01-EXCEPTION - test fixture, intentionally public, no real data

/**
 * Test fixture: a public topic ($requires_auth = false). Subscribable by
 * anyone, authenticated or not.
 */
class Realtime_Test_Public_Topic extends Realtime_Topic_Abstract
{
    public static bool $requires_auth = false;

    public static function can_subscribe(array $filter = []): bool
    {
        return true;
    }
}
