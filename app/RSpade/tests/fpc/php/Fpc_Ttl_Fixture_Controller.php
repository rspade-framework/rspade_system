<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Fpc\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Two REAL cacheable routes, one declaring a TTL and one not, so the TTL test reads the
 * same manifest rows the dispatcher reads at runtime rather than a hand-built fixture.
 *
 * The declaration is the whole subject: #[FPC(ttl: 5)] is a five-minute page and #[FPC]
 * is a page that lives until something clears it, and both facts have to survive the trip
 * through the attribute scanner onto the route row.
 *
 * Indexed only while the suite is running, like every other test-tree surface.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth]; the inline-check
 * heuristic this rule pattern-matches is retired by the auth-gates epic (W8).
 */
class Fpc_Ttl_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** The route declaring a five-minute lifetime. */
    public const TTL_PATH = '/test-fpc/ttl';

    /** The route declaring none. */
    public const FOREVER_PATH = '/test-fpc/forever';

    #[FPC(ttl: 5)]
    #[Route('/test-fpc/ttl')]
    #[Auth('public')]
    public static function ttl(Request $request, array $params = [])
    {
        return ['route' => 'ttl'];
    }

    #[FPC]
    #[Route('/test-fpc/forever')]
    #[Auth('public')]
    public static function forever(Request $request, array $params = [])
    {
        return ['route' => 'forever'];
    }
}
