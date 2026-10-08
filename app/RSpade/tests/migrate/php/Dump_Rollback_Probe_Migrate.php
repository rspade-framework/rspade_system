<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Core\Database\Migrate_Dump_Rollback;

/**
 * Maint_Migrate with its outside world replaced, so the --dump-rollback FLOW in handle() runs
 * for real against a scratch database: the mechanism is a Migrate_Dump_Rollback over the
 * scratch connection and a sandbox directory; the migrations are a callback; the maintenance
 * window, the datadir snapshot and the bare run are recorded rather than performed; output is
 * collected.
 */
#[Instantiatable]
class Dump_Rollback_Probe_Migrate extends Maint_Migrate
{
    public array $options = [];

    public int $pending = 1;

    /** fn(): int - what "running the migrations" does to the scratch database. */
    public $migrations;

    public bool $maintenance_up = false;

    public array $events = [];

    public array $lines = [];

    /** When set, the client-program check fails with this message. */
    public ?string $binaries_unusable = null;

    public function __construct(private string $scratch_connection, private string $scratch_dir)
    {
        parent::__construct();
    }

    protected function make_dump_rollback(): Migrate_Dump_Rollback
    {
        return new Migrate_Dump_Rollback($this->scratch_connection, $this->scratch_dir, function (string $level, string $line): void {
            $this->lines[] = $line;
        });
    }

    protected function dump_rollback_option(string $name): bool
    {
        return (bool) ($this->options[$name] ?? false);
    }

    protected function is_framework_only_run(): bool
    {
        return (bool) ($this->options['framework-only'] ?? false);
    }

    // The build always describes the database here: what migrate does about a build that
    // does not is Migrate_Build_Sync_Test's subject.
    protected function applied_migrations_hash(): string
    {
        return 'same';
    }

    protected function build_applied_migrations_hash(): ?string
    {
        return 'same';
    }

    protected function probe_is_rspade_container(): bool
    {
        return true;
    }

    protected function snapshot_protection_engaged(): bool
    {
        return false;
    }

    protected function count_pending_migrations(): int
    {
        return $this->pending;
    }

    protected function execute_migrations(): int
    {
        $this->events[] = 'migrations';

        return ($this->migrations)();
    }

    protected function run_without_snapshot(): int
    {
        $this->events[] = 'bare_run';

        return 0;
    }

    protected function require_dump_rollback_binaries(): void
    {
        if ($this->binaries_unusable !== null) {
            throw new \RuntimeException($this->binaries_unusable);
        }
    }

    protected function maintenance_is_up(): bool
    {
        return $this->maintenance_up;
    }

    protected function maintenance_enable(): int
    {
        $this->events[] = 'maintenance_enable';
        $this->maintenance_up = true;

        return 0;
    }

    protected function maintenance_disable(): void
    {
        $this->events[] = 'maintenance_disable';
        $this->maintenance_up = false;
    }

    public function call($command, array $arguments = []): int
    {
        $this->events[] = 'call:' . $command;

        return 0;
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
