<?php

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Tests\CodeQuality\Php\Realtime_Lineage_Fixture_Checked_Topic_Abstract;

/**
 * Fixture for Realtime_Topic_Lineage_Rule_Test: overrides its checked base with a
 * can_subscribe() that lets anyone in.
 */
class Realtime_Lineage_Fixture_Override_Topic extends Realtime_Lineage_Fixture_Checked_Topic_Abstract
{
    public static function can_subscribe(array $filter = []): bool
    {
        return true;
    }
}
