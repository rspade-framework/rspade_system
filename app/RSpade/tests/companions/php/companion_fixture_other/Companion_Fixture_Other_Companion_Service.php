<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php\CompanionFixtureOther;

use Illuminate\Http\Request;
use App\RSpade\Core\Companion\Companion_Service_Abstract;

/**
 * Test fixture: a second companion, whose endpoint the first may not reach.
 */
class Companion_Fixture_Other_Companion_Service extends Companion_Service_Abstract
{
    public static function name(): string
    {
        return 'companion_fixture_other';
    }

    public static function description(): string
    {
        return 'Test fixture: the other companion';
    }

    public static function command(): array
    {
        return ['node', 'resource/main.js'];
    }

    #[Companion_Endpoint('companion_fixture_other')]
    public static function only_mine(Request $request, array $params = [])
    {
        return ['whose' => 'other'];
    }
}
