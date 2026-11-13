<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use RuntimeException;
use App\RSpade\Core\Database\Migration_Guard;
use App\RSpade\Tests\Migrate\Php\Fake_Migrator;

/**
 * A Fake_Migrator whose runPending() stands in for a migration's up(): it records whether
 * the migration window was open while it ran, and optionally throws the way a failing up()
 * does.
 */
#[Instantiatable]
class Guard_Probing_Migrator extends Fake_Migrator
{
    /** Migration_Guard::is_active() as seen inside each runPending() call. */
    public array $seen_active = [];

    /** When set, runPending() throws this message. */
    public ?string $throw_message = null;

    public function runPending(array $files, array $options = []): void
    {
        parent::runPending($files, $options);

        $this->seen_active[] = Migration_Guard::is_active();

        if ($this->throw_message !== null) {
            throw new RuntimeException($this->throw_message);
        }
    }
}
