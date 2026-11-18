<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\UrlFetch\Php;

use App\RSpade\Lib\Support\UrlFetch\Rsx_Url_Fetch;

/**
 * Rsx_Url_Fetch with 127.0.0.1 counted as a PUBLIC address and every other address
 * classified as shipped.
 *
 * The test box has no public server to start a redirect chain from, so this stands the
 * loopback fixture server in for one: a fetch through this class with the policy ON reaches
 * the fixture, and a redirect from it to a genuinely private address must still be refused
 * by the real per-hop check.
 */
class Url_Fetch_Loopback_Public_Fixture extends Rsx_Url_Fetch
{
    public static function address_class(string $ip): ?string
    {
        if ($ip === '127.0.0.1') {
            return null;
        }

        return parent::address_class($ip);
    }
}
