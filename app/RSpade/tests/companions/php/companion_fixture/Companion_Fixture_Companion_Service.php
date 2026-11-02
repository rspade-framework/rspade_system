<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php\CompanionFixture;

use App\RSpade\Core\Companion\Companion_Service_Abstract;

/**
 * Test fixture: the companion the realm tests sign as. Its endpoints are on
 * Companion_Fixture_Endpoints. ready_to_run() answers whatever the test put in $not_ready.
 */
class Companion_Fixture_Companion_Service extends Companion_Service_Abstract
{
    public static ?string $not_ready = null;

    public static bool $throw_when_asked = false;

    public static function name(): string
    {
        return 'companion_fixture';
    }

    public static function description(): string
    {
        return 'Test fixture for the companion realm';
    }

    public static function command(): array
    {
        return ['node', 'resource/main.js'];
    }

    public static function ready_to_run(): ?string
    {
        if (self::$throw_when_asked) {
            throw new \RuntimeException('Fixture readiness failure');
        }

        return self::$not_ready;
    }
}
