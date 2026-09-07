<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use App\RSpade\Core\Health\Heal_Runner;
use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Health\Submodule_Visibility_Health_Checks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The "Submodule Visibility" health row and the `submodule-ignore-dirty` heal target.
 *
 * inspect() is driven against sandbox git repositories through its project-root seam, so
 * every branch (not a submodule project, missing ignore, a wrong ignore, the repo-wide
 * diff.ignoreSubmodules) is reachable without touching this box's own configuration. No
 * actual submodule is cloned: both settings are pure configuration.
 *
 * The wiring script itself is covered by tests/environment_updates/cli t6, and the
 * conversion that sets it by tests/framework_update/cli t34.
 */
class Submodule_Visibility_Health_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The sandbox repository for the current test. */
    private static $root = null;

    /**
     * Build a sandbox git repository. Torn down by __teardown_root().
     *
     * @return string
     */
    private static function __make_root(): string
    {
        $root = sys_get_temp_dir() . '/rsx-submodule-vis-' . random_hash(12);
        ensure_directory($root);
        exec_safe('git -C ' . escapeshellarg($root) . ' init -q .');
        self::$root = $root;

        return $root;
    }

    private static function __teardown_root(): void
    {
        if (self::$root !== null && is_dir(self::$root)) {
            exec_safe('rm -rf ' . escapeshellarg(self::$root));
        }

        self::$root = null;
    }

    /**
     * Write a .gitmodules declaring the system submodule, optionally with an ignore value.
     */
    private static function __declare_system_submodule(string $root, ?string $ignore = null): void
    {
        $content = "[submodule \"system\"]\n\tpath = system\n"
            . "\turl = https://example.com/rspade_system.git\n\tbranch = master\n";

        if ($ignore !== null) {
            $content .= "\tignore = {$ignore}\n";
        }

        file_put_contents($root . '/.gitmodules', $content);
    }

    public static function test_inspect_reports_a_correctly_configured_project()
    {
        $root = self::__make_root();

        try {
            self::__declare_system_submodule($root, 'dirty');

            $state = Submodule_Visibility_Health_Checks::inspect($root);

            static::__assert_true($state['is_submodule_project']);
            static::__assert_equals('dirty', $state['gitmodules_ignore']);
            static::__assert_null($state['blanket_ignore_submodules']);
        } finally {
            self::__teardown_root();
        }
    }

    public static function test_inspect_reports_a_missing_and_a_wrong_ignore()
    {
        $root = self::__make_root();

        try {
            // Absent - the shape `git submodule add` leaves behind.
            self::__declare_system_submodule($root);
            $state = Submodule_Visibility_Health_Checks::inspect($root);
            static::__assert_true($state['is_submodule_project']);
            static::__assert_null($state['gitmodules_ignore']);

            // Present but wrong: `all` hides the pointer, which is the whole defect.
            self::__declare_system_submodule($root, 'all');
            $state = Submodule_Visibility_Health_Checks::inspect($root);
            static::__assert_equals('all', $state['gitmodules_ignore']);
        } finally {
            self::__teardown_root();
        }
    }

    public static function test_inspect_reports_the_repo_wide_setting()
    {
        $root = self::__make_root();

        try {
            self::__declare_system_submodule($root, 'dirty');
            exec_safe('git -C ' . escapeshellarg($root) . ' config --local diff.ignoreSubmodules all');

            $state = Submodule_Visibility_Health_Checks::inspect($root);

            static::__assert_equals('all', $state['blanket_ignore_submodules']);
        } finally {
            self::__teardown_root();
        }
    }

    public static function test_inspect_is_silent_on_a_project_with_no_system_submodule()
    {
        $root = self::__make_root();

        try {
            // No .gitmodules at all.
            $state = Submodule_Visibility_Health_Checks::inspect($root);
            static::__assert_false($state['is_submodule_project']);
            static::__assert_null($state['gitmodules_ignore']);

            // A .gitmodules declaring some OTHER submodule is equally not ours.
            file_put_contents(
                $root . '/.gitmodules',
                "[submodule \"rsx/resource/model-builder\"]\n\tpath = rsx/resource/model-builder\n"
                . "\turl = https://example.com/mb.git\n"
            );
            $state = Submodule_Visibility_Health_Checks::inspect($root);
            static::__assert_false($state['is_submodule_project']);
        } finally {
            self::__teardown_root();
        }
    }

    public static function test_health_row_is_declared_and_never_fails()
    {
        $rows = Health_Check_Runner::run_one(
            Submodule_Visibility_Health_Checks::class,
            'submodule_visibility',
            'Submodule Visibility'
        );

        static::__assert_greater_than(0, count($rows));

        foreach ($rows as $row) {
            static::__assert_equals('Submodule Visibility', $row['label']);
            static::__assert_true(
                in_array($row['status'], ['OK', 'INFO', 'WARN'], true),
                'the Submodule Visibility row is advisory only, never FAIL: '
                    . $row['status'] . ' - ' . $row['detail']
            );

            if ($row['status'] === 'WARN') {
                static::__assert_not_empty($row['remediation'], 'every WARN row names its remedy');
            }
        }
    }

    public static function test_the_monorepo_reports_info_and_never_warns()
    {
        // In the framework monorepo system/ is authored source: there is no gitlink, so
        // there is nothing that could hide one. This is the box the suite runs on.
        if (!config('rsx.code_quality.is_framework_developer', false)) {
            return;
        }

        $rows = Health_Check_Runner::run_one(
            Submodule_Visibility_Health_Checks::class,
            'submodule_visibility',
            'Submodule Visibility'
        );

        static::__assert_equals(1, count($rows));
        static::__assert_equals('INFO', $rows[0]['status']);
    }

    public static function test_heal_target_is_declared()
    {
        $targets = Heal_Runner::discover();

        static::__assert_true(
            isset($targets['submodule-ignore-dirty']),
            'rsx:heal must declare submodule-ignore-dirty'
        );
        static::__assert_equals(
            Submodule_Visibility_Health_Checks::class,
            $targets['submodule-ignore-dirty']['fqcn']
        );
    }

    public static function test_heal_runs_and_is_a_no_op_here()
    {
        // The monorepo has no system submodule and no blanket setting, so the script
        // exits having done nothing - the path that must not be reported as a repair.
        $result = Heal_Runner::run('submodule-ignore-dirty');

        static::__assert_true(
            in_array($result['status'], ['HEALED', 'ALREADY_OK'], true),
            'the healer must not refuse on a healthy tree: ' . $result['status'] . ' - ' . $result['detail']
        );
    }
}
