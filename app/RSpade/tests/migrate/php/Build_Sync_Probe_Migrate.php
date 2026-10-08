<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Commands\Migrate\Maint_Migrate;

/**
 * Maint_Migrate::sync_build_with_schema() with its three facts supplied and its one action
 * recorded: what the database had applied before the run, what it has now, what the build
 * was made against - and the rebuild, which is noted rather than performed.
 */
#[Instantiatable]
class Build_Sync_Probe_Migrate extends Maint_Migrate
{
    public string $applied_now = 'after';

    public ?string $build_hash = 'before';

    public bool $no_rebuild = false;

    public int $rebuild_exit = 0;

    /** One entry per rebuild: 'sealed' or 'development'. */
    public array $rebuilds = [];

    public array $lines = [];

    public function sync(?string $applied_before): int
    {
        $this->applied_before = $applied_before;

        return $this->sync_build_with_schema();
    }

    public function output(): string
    {
        return implode("\n", $this->lines);
    }

    protected function applied_migrations_hash(): string
    {
        return $this->applied_now;
    }

    protected function build_applied_migrations_hash(): ?string
    {
        return $this->build_hash;
    }

    protected function rebuild_suppressed(): bool
    {
        return $this->no_rebuild;
    }

    protected function rebuild_the_build(bool $sealed): int
    {
        $this->rebuilds[] = $sealed ? 'sealed' : 'development';

        return $this->rebuild_exit;
    }

    public function line($string, $style = null, $verbosity = null)
    {
        $this->lines[] = (string) $string;
    }

    public function info($string, $verbosity = null)
    {
        $this->lines[] = (string) $string;
    }

    public function warn($string, $verbosity = null)
    {
        $this->lines[] = (string) $string;
    }

    public function error($string, $verbosity = null)
    {
        $this->lines[] = (string) $string;
    }
}
