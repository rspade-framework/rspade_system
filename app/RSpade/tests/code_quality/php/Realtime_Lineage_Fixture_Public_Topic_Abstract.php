<?php

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Realtime\Realtime_Topic_Abstract;

/**
 * Fixture for Realtime_Topic_Lineage_Rule_Test: an intermediate topic base declared PUBLIC.
 */
abstract class Realtime_Lineage_Fixture_Public_Topic_Abstract extends Realtime_Topic_Abstract
{
    public static bool $requires_auth = false;
}
