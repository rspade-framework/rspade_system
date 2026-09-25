<?php

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;
use App\RSpade\Core\Session\Session;

/**
 * Fixture for Realtime_Topic_Lineage_Rule_Test: an intermediate topic base that carries the
 * real auth check, so the topics beneath it inherit a checked can_subscribe(). Replaceable,
 * so the override fixture may replace it outright.
 */
abstract class Realtime_Lineage_Fixture_Checked_Topic_Abstract extends Realtime_Topic_Abstract
{
    #[Replaceable]
    public static function can_subscribe(array $filter = []): bool
    {
        return Session::is_logged_in();
    }
}
