<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Tests\Migrate\Php\Fake_Migration_Repository;

/**
 * The narrow slice of Laravel's Migrator that run_migrations_with_normalization() uses.
 * Nothing here touches a database: the migrations table is empty by construction (every
 * staged file is pending) and running one is a no-op.
 */
#[Instantiatable]
class Fake_Migrator
{
    public function getRepository(): Fake_Migration_Repository
    {
        return new Fake_Migration_Repository();
    }

    public function getMigrationName(string $path): string
    {
        return str_replace('.php', '', basename($path));
    }

    public function runPending(array $files, array $options = []): void
    {
    }
}
