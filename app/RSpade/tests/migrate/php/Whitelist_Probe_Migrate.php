<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Commands\Migrate\Maint_Migrate;

/**
 * Maint_Migrate pointed at a SANDBOX whitelist, for Whitelist_Prod_Mode_Test.
 *
 * whitelist_locations() is the command's own seam, so the whitelist logic under test -
 * the mode gate, the absent-file branch, the stray-file tripwire - is the real code. The
 * sandbox is a temp directory that never existed before the test and is removed after it,
 * so no .migration_whitelist on this box is read or written.
 *
 * Console output is captured rather than printed: the command is constructed without an
 * output interface, and the refusal text is one of the things being asserted.
 */
#[Instantiatable]
class Whitelist_Probe_Migrate extends Maint_Migrate
{
    /** Basenames the sandbox whitelist file lists, when one is written by the test. */
    public array $whitelisted = [];

    /** Every console line this run produced. */
    public array $console = [];

    /** @var string|null The sandbox root, created on first use. */
    private $sandbox = null;

    /**
     * Create a throwaway migration directory holding $basenames as empty migration files.
     * The caller removes it.
     */
    public static function stage_migrations(array $basenames): string
    {
        $dir = sys_get_temp_dir() . '/rsx-whitelist-migrations-' . random_hash(12);
        ensure_directory($dir);

        foreach ($basenames as $name) {
            file_put_contents_safe($dir . '/' . $name, "<?php\n// Never executed - see Whitelist_Prod_Mode_Test.\n");
        }

        return $dir;
    }

    /**
     * Whether a whitelist file now exists in the sandbox.
     *
     * The sandbox starts without one unless the test staged it, so this answers "did the
     * run write it" for the creation cases and "is the staged one still there" for the
     * rest.
     */
    public function wrote_whitelist(): bool
    {
        return $this->sandbox !== null && is_file($this->sandbox . '/.migration_whitelist');
    }

    /** Everything the command printed, as one string. */
    public function console_text(): string
    {
        return implode("\n", $this->console);
    }

    /** Drive the real whitelist check. */
    public function check_whitelist(array $paths): bool
    {
        return $this->checkMigrationWhitelist($paths);
    }

    /** Drive the real whitelist creation. */
    public function create_initial_whitelist(): void
    {
        $this->createInitialWhitelist();
    }

    /**
     * The basenames the sandbox whitelist file currently lists, or [] when there is none.
     *
     * @return string[]
     */
    public function whitelisted_basenames_on_disk(): array
    {
        if (!$this->wrote_whitelist()) {
            return [];
        }

        $decoded = json_decode(file_get_contents($this->sandbox . '/.migration_whitelist'), true);

        return array_keys($decoded['migrations'] ?? []);
    }

    /** Remove the sandbox. Every test calls this in a finally. */
    public function cleanup(): void
    {
        if ($this->sandbox !== null && is_dir($this->sandbox)) {
            exec_safe('rm -rf ' . escapeshellarg($this->sandbox));
        }

        $this->sandbox = null;
    }

    /**
     * ONE sandbox location, holding whatever the test staged. The sandbox directory is
     * created lazily and holds the migration files the whitelist would be built from.
     */
    protected function whitelist_locations(): array
    {
        if ($this->sandbox === null) {
            $this->sandbox = sys_get_temp_dir() . '/rsx-whitelist-sandbox-' . random_hash(12);
            ensure_directory($this->sandbox);

            // One migration file, so createInitialWhitelist() has something to list and a
            // written file is distinguishable from a refusal.
            file_put_contents_safe(
                $this->sandbox . '/2026_09_15_000001_listed.php',
                "<?php\n// Never executed - see Whitelist_Prod_Mode_Test.\n"
            );

            if (!empty($this->whitelisted)) {
                $migrations = [];
                foreach ($this->whitelisted as $basename) {
                    $migrations[$basename] = ['created_at' => 'test', 'created_by' => 'test', 'command' => 'test'];
                }

                file_put_contents_safe(
                    $this->sandbox . '/.migration_whitelist',
                    json_encode(['migrations' => $migrations], JSON_PRETTY_PRINT)
                );
            }
        }

        return [$this->sandbox . '/.migration_whitelist' => $this->sandbox];
    }

    public function info($string, $verbosity = null): void
    {
        $this->console[] = (string) $string;
    }

    public function line($string, $style = null, $verbosity = null): void
    {
        $this->console[] = (string) $string;
    }

    public function warn($string, $verbosity = null): void
    {
        $this->console[] = (string) $string;
    }

    public function error($string, $verbosity = null): void
    {
        $this->console[] = (string) $string;
    }

    public function newLine($count = 1): static
    {
        return $this;
    }
}
