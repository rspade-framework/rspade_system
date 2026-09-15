<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

/**
 * An empty migrations table - every staged file counts as pending.
 */
#[Instantiatable]
class Fake_Migration_Repository
{
    public function getRan(): array
    {
        return [];
    }
}
