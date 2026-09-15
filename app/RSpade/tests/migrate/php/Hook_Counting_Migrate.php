<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Tests\Migrate\Php\Fake_Migrator;

/**
 * Maint_Migrate with its two outside dependencies replaced: the normalize subcommand and
 * console output. Everything about the migration loop itself - discovery, ordering, the
 * BETWEEN-migrations condition and the hook dispatch - is the real code.
 */
#[Instantiatable]
class Hook_Counting_Migrate extends Maint_Migrate
{
    /** What the stubbed migrate:normalize_schema call reports. */
    public int $normalize_exit_code = 0;

    /** Every command name the loop asked for, in order. */
    public array $calls = [];

    /**
     * Run the real loop over $paths with a fake migrator.
     */
    public function drive_migration_loop(array $paths): void
    {
        $this->run_migrations_with_normalization(new Fake_Migrator(), $paths, false, []);
    }

    /**
     * The loop's only subcommand is migrate:normalize_schema, which issues DDL. Stubbed:
     * this test is about the hook, not about normalization.
     */
    public function call($command, array $arguments = []): int
    {
        $this->calls[] = $command;

        return $this->normalize_exit_code;
    }

    // Console output - the command was constructed without an output interface, and the
    // narration is not what is under test.
    public function info($string, $verbosity = null): void
    {
    }

    public function line($string, $style = null, $verbosity = null): void
    {
    }

    public function warn($string, $verbosity = null): void
    {
    }

    public function error($string, $verbosity = null): void
    {
    }

    public function newLine($count = 1): static
    {
        return $this;
    }
}
