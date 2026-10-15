<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Manifest\Class_Override_Drift;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * rsx:overrides:list - every class override in force, read off the manifest's override map.
 *
 * An override leaves nothing on disk to look at: the framework file stays where it is, under
 * its own name, and the manifest records that the application file stands in for it
 * (Manifest::overrides(), rsx:man class_override). This is the listing an operator reads
 * instead - one row per shadowed framework file: the kind, the declared name, the application
 * file that overrides it and the framework file it shadows.
 *
 * DRIFT, FOR PHP. A copied class is frozen the moment it is taken while the framework file
 * keeps moving, so each PHP row carries Class_Override_Drift's verdict: "current" (the copy
 * declares every public/protected member the framework file declares), "N missing" (and the
 * members are named below the table), or "extends base" for a split class whose override
 * extends its `_Abstract` base and so inherits everything - there is no copy to measure. The
 * three asset kinds are not analysed.
 *
 * Read-only, and it works in every mode: it reads the index, sealed builds included.
 */
class Overrides_List_Command extends Command
{
    protected $signature = 'rsx:overrides:list
                            {--json : Output as JSON}';

    protected $description = 'List every class override in force: kind, name, the application file, the framework file it shadows, and PHP drift';

    /** Drift verdicts, as they appear in --json. */
    public const DRIFT_CURRENT = 'current';
    public const DRIFT_MISSING = 'missing';
    public const DRIFT_EXTENDS_BASE = 'extends_base';

    public function handle(): int
    {
        $rows = static::rows();

        if ($this->option('json')) {
            $this->line(json_encode(['overrides' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($rows === []) {
            $this->line('No class overrides.');

            return 0;
        }

        $this->newLine();
        $this->table(
            ['Kind', 'Name', 'Override', 'Shadows framework file', 'Drift'],
            array_map(static fn (array $row) => [
                $row['kind'],
                $row['name'],
                $row['override_file'],
                $row['framework_file'],
                static::drift_label($row['drift']),
            ], $rows)
        );

        $drifting = array_filter($rows, static fn (array $row) => ($row['drift']['status'] ?? null) === self::DRIFT_MISSING);

        foreach ($drifting as $row) {
            $this->line('  ' . $row['name'] . ' lacks: ' . implode(', ', $row['drift']['missing']));
        }

        if ($drifting !== []) {
            $this->line('  php artisan rsx:check names each member (CLASS-OVERRIDE-DRIFT-01); see rsx:man class_override.');
        }

        $this->newLine();

        return 0;
    }

    /**
     * One row per override, ordered by kind then name. Paths are relative to the project root.
     *
     * @return array<int, array{
     *     kind: string, name: string, override_file: string, framework_file: string,
     *     fqcn: ?string, by_fqcn: ?string,
     *     drift: ?array{status: string, missing: array<int, string>}
     * }>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (Manifest::overrides() as $framework_file => $override) {
            $rows[] = [
                'kind' => $override['kind'],
                'name' => $override['name'],
                'override_file' => static::project_relative($override['by']),
                'framework_file' => static::project_relative($framework_file),
                'fqcn' => $override['fqcn'] ?? null,
                'by_fqcn' => $override['by_fqcn'] ?? null,
                'drift' => $override['kind'] === 'php'
                    ? static::drift($framework_file, $override['by'], $override['name'])
                    : null,
            ];
        }

        usort($rows, static fn (array $a, array $b) => [$a['kind'], $a['name'], $a['framework_file']] <=> [$b['kind'], $b['name'], $b['framework_file']]);

        return $rows;
    }

    /**
     * The drift verdict for one PHP override. Both arguments are manifest keys.
     *
     * @return array{status: string, missing: array<int, string>}
     */
    private static function drift(string $framework_file, string $override_file, string $class_name): array
    {
        $framework_path = Rsx_Paths::absolute($framework_file);
        $override_path = Rsx_Paths::absolute($override_file);

        // A split class: the framework file is `class X extends X_Abstract` and the override
        // extends the same base, so every member is inherited and nothing can be missing.
        $framework_source = @file_get_contents($framework_path);
        $override_source = @file_get_contents($override_path);

        if ($framework_source !== false && $override_source !== false) {
            $base = Class_Override_Drift::declared_parent($framework_source, $class_name);

            if ($base === $class_name . '_Abstract' && Class_Override_Drift::declared_parent($override_source, $class_name) === $base) {
                return ['status' => self::DRIFT_EXTENDS_BASE, 'missing' => []];
            }
        }

        $analysis = Class_Override_Drift::analyze_pair($framework_path, $override_path, $class_name);

        if ($analysis['missing'] === []) {
            return ['status' => self::DRIFT_CURRENT, 'missing' => []];
        }

        return [
            'status' => self::DRIFT_MISSING,
            'missing' => array_map([Class_Override_Drift::class, 'describe'], $analysis['missing']),
        ];
    }

    private static function drift_label(?array $drift): string
    {
        return match ($drift['status'] ?? null) {
            null => '-',
            self::DRIFT_CURRENT => 'current',
            self::DRIFT_EXTENDS_BASE => 'extends base',
            self::DRIFT_MISSING => count($drift['missing']) . ' missing',
        };
    }

    /**
     * A manifest key as the operator types it from the project root: application files are
     * already `rsx/...`, framework files live under `system/`.
     */
    private static function project_relative(string $manifest_key): string
    {
        return Rsx_Paths::is_application($manifest_key) ? $manifest_key : 'system/' . $manifest_key;
    }
}
