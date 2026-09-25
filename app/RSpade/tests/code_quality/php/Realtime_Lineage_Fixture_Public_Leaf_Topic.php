<?php

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Tests\CodeQuality\Php\Realtime_Lineage_Fixture_Public_Topic_Abstract;

/**
 * Fixture for Realtime_Topic_Lineage_Rule_Test: a topic under a public base, whose own
 * can_subscribe() has no session check - public by inheritance, not missing auth.
 */
class Realtime_Lineage_Fixture_Public_Leaf_Topic extends Realtime_Lineage_Fixture_Public_Topic_Abstract
{
    public static function can_subscribe(array $filter = []): bool
    {
        return true;
    }
}
