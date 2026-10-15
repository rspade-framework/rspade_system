<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Cli;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:overrides:list - the operator's listing of the class overrides in force.
 *
 * An override leaves nothing on disk to look at (the framework file stays in place, under its
 * own name), so this command is where an operator sees them: one row per shadowed framework
 * PHP file, read off the manifest's override map, with its drift verdict. Only PHP classes
 * are overridable, so there is no kind column.
 *
 * Driven in-process over a SYNTHETIC override map, swapped into Manifest::$data and restored
 * in a finally - the same seam the drift health-row tests use. The drifting pair's files live
 * under rsx/resource/, which the manifest never indexes; the pair whose framework file sits
 * in the framework tree needs no file at all, since only its path spelling is asserted.
 *
 * Pure filesystem + static state, no DB.
 */
class Overrides_List_Command_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const PROBE_CLASS = 'Overrides_List_Probe_Temp';

    private const FRAMEWORK_FILE = 'rsx/resource/overrides_list_probe_temp_framework.php';
    private const OVERRIDE_FILE = 'rsx/resource/overrides_list_probe_temp_override.php';

    private const FRAMEWORK_TREE_FILE = 'app/RSpade/temp/Overrides_List_Probe_Service.php';
    private const FRAMEWORK_TREE_OVERRIDE_FILE = 'rsx/resource/overrides_list_probe_temp_service.php';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    private static function __write(string $relative, array $body_lines, ?string $extends = null): void
    {
        $path = base_path($relative);
        ensure_directory(dirname($path));

        $declaration = 'class ' . self::PROBE_CLASS . ($extends === null ? '' : ' extends ' . $extends);

        file_put_contents($path, implode("\n", array_merge(['<?php', '', $declaration, '{'], $body_lines, ['}', ''])));
    }

    private static function __remove_fixtures(): void
    {
        foreach ([self::FRAMEWORK_FILE, self::OVERRIDE_FILE] as $relative) {
            if (file_exists(base_path($relative))) {
                unlink(base_path($relative));
            }
        }
    }

    private static function __php_entry(): array
    {
        return [
            self::FRAMEWORK_FILE => [
                'name' => self::PROBE_CLASS,
                'by' => self::OVERRIDE_FILE,
                'fqcn' => 'App\\RSpade\\Temp\\' . self::PROBE_CLASS,
                'by_fqcn' => 'Rsx\\Resource\\' . self::PROBE_CLASS,
            ],
        ];
    }

    /** An override of a class in the framework tree - neither file exists on disk. */
    private static function __framework_tree_entry(): array
    {
        return [
            self::FRAMEWORK_TREE_FILE => [
                'name' => 'Overrides_List_Probe_Service',
                'by' => self::FRAMEWORK_TREE_OVERRIDE_FILE,
                'fqcn' => 'App\\RSpade\\Temp\\Overrides_List_Probe_Service',
                'by_fqcn' => 'Rsx\\Resource\\Overrides_List_Probe_Service',
            ],
        ];
    }

    /**
     * Run the command over a synthetic override map.
     *
     * @return array{exit: int, output: string}
     */
    private static function __run(array $overrides, bool $json = false): array
    {
        $saved_data = Manifest::$data;
        Manifest::$data = ['data' => ['files' => [], 'overrides' => $overrides]];

        try {
            $exit = Artisan::call('rsx:overrides:list', $json ? ['--json' => true] : []);

            return ['exit' => $exit, 'output' => Artisan::output()];
        } finally {
            Manifest::$data = $saved_data;
        }
    }

    // =====================================================================
    // Tests
    // =====================================================================

    /** No override in force: one plain line, exit 0 - an empty listing is a result, not an error. */
    public static function test_no_overrides_says_so()
    {
        $run = static::__run([]);

        static::__assert_equals(0, $run['exit'], 'an empty listing is not a failure');
        static::__assert_contains('No class overrides.', $run['output'], 'and it says so in so many words');

        $json = static::__run([], true);
        static::__assert_equals(['overrides' => []], json_decode($json['output'], true), '--json is an empty list');
    }

    /** A PHP override that lacks a framework member is listed with the count, and the member is named. */
    public static function test_a_drifting_php_override_is_listed_with_its_missing_members()
    {
        static::__write(self::FRAMEWORK_FILE, [
            '    public function framework_only() {}',
            '    public function shared() {}',
        ]);
        static::__write(self::OVERRIDE_FILE, ['    public function shared() {}']);

        try {
            $run = static::__run(static::__php_entry());

            static::__assert_equals(0, $run['exit'], 'drift is reported, never an error exit');
            static::__assert_contains(self::PROBE_CLASS, $run['output'], 'the row names the class');
            static::__assert_contains(self::OVERRIDE_FILE, $run['output'], 'and the application file');
            static::__assert_contains('1 missing', $run['output'], 'the drift column counts what the copy lacks');
            static::__assert_contains('function framework_only()', $run['output'], 'and the member is named under the table');
            static::__assert_contains('rsx:check', $run['output'], 'with a pointer at the full finding');
        } finally {
            static::__remove_fixtures();
        }
    }

    /** A copy that carries everything is "current"; a split override that extends the base is "extends base". */
    public static function test_current_and_extends_base_verdicts()
    {
        static::__write(self::FRAMEWORK_FILE, ['    public function shared() {}']);
        static::__write(self::OVERRIDE_FILE, ['    public function shared() {}', '    public function app_only() {}']);

        try {
            $rows = json_decode(static::__run(static::__php_entry(), true)['output'], true)['overrides'];
            static::__assert_equals('current', $rows[0]['drift']['status'], 'its own additions are not drift');
        } finally {
            static::__remove_fixtures();
        }

        static::__write(self::FRAMEWORK_FILE, [], self::PROBE_CLASS . '_Abstract');
        static::__write(self::OVERRIDE_FILE, [], self::PROBE_CLASS . '_Abstract');

        try {
            $run = static::__run(static::__php_entry());
            static::__assert_contains('extends base', $run['output'], 'a split override inherits every member; there is no copy to measure');
        } finally {
            static::__remove_fixtures();
        }
    }

    /** A framework path is spelled from the project root; the table has no kind column. */
    public static function test_a_framework_path_is_spelled_from_the_project_root()
    {
        $run = static::__run(static::__framework_tree_entry());

        static::__assert_contains('Overrides_List_Probe_Service', $run['output'], 'the class name');
        static::__assert_contains('system/' . self::FRAMEWORK_TREE_FILE, $run['output'], 'the framework file, as typed from the project root');
        static::__assert_contains(self::FRAMEWORK_TREE_OVERRIDE_FILE, $run['output'], 'the override file');
        static::__assert_false(str_contains($run['output'], 'Kind'), 'only PHP is overridable, so there is no kind column');
    }

    /** --json: one object per override, carrying the fields a script reads. */
    public static function test_json_shape()
    {
        static::__write(self::FRAMEWORK_FILE, ['    public function framework_only() {}']);
        static::__write(self::OVERRIDE_FILE, []);

        try {
            $run = static::__run(static::__php_entry() + static::__framework_tree_entry(), true);
        } finally {
            static::__remove_fixtures();
        }

        static::__assert_equals(0, $run['exit'], '--json exits 0');

        $decoded = json_decode($run['output'], true);
        static::__assert_true(is_array($decoded) && isset($decoded['overrides']), 'the document is {"overrides": [...]}');
        static::__assert_count(2, $decoded['overrides'], 'one entry per override');

        $by_name = array_column($decoded['overrides'], null, 'name');
        $drifting = $by_name[self::PROBE_CLASS];

        static::__assert_equals(
            ['name', 'override_file', 'framework_file', 'fqcn', 'by_fqcn', 'drift'],
            array_keys($drifting),
            'every entry carries the same fields, and no kind'
        );
        static::__assert_equals('missing', $drifting['drift']['status'], 'the drift verdict');
        static::__assert_equals(['function framework_only()'], $drifting['drift']['missing'], 'with the missing members named');
        static::__assert_equals('App\\RSpade\\Temp\\' . self::PROBE_CLASS, $drifting['fqcn'], 'the framework class');
        static::__assert_equals('Rsx\\Resource\\' . self::PROBE_CLASS, $drifting['by_fqcn'], 'and the class that answers for it');

        static::__assert_equals('system/' . self::FRAMEWORK_TREE_FILE, $by_name['Overrides_List_Probe_Service']['framework_file'], 'project-root-relative framework path');
        static::__assert_equals('current', $by_name['Overrides_List_Probe_Service']['drift']['status'], 'every row carries a verdict');
    }
}
