<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use App\RSpade\Core\Health\Environment_Health_Checks;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The project-tree rows of rsx:health: storage/, tmp/ and build/ writability, the
 * production read-only posture, and the debug-log-level warning.
 *
 * WHY THE ROWS ARE SHAPED THIS WAY. storage/ holds user data and tmp/ holds derived
 * caches, so a box that cannot write them cannot accept an upload or compile a template -
 * both are FAIL in every mode. build/ holds build OUTPUTS, which a production mode writes
 * once and then only reads, so it is required writable in development and merely reported
 * in a production mode. All three EXIST-OR-CREATE, because a missing one is a state the
 * next framework command repairs by itself and a FAIL on it would be noise.
 *
 * The three trees are not all redirectable per-process (storage_root() deliberately is
 * not), so the branches are driven through _tree_row() against sandbox paths. The prod
 * rows are driven through the mode seam.
 *
 * No database access - skip the per-test transaction.
 */
class Project_Tree_Health_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @var string[] Sandbox paths to remove after the current test. */
    private static $sandboxes = [];

    private static function __sandbox(): string
    {
        $path = sys_get_temp_dir() . '/rsx-tree-health-' . random_hash(12);
        self::$sandboxes[] = $path;

        return $path;
    }

    private static function __cleanup(): void
    {
        foreach (self::$sandboxes as $path) {
            exec_safe('chmod -R u+rwx ' . escapeshellarg($path) . ' 2>/dev/null');
            exec_safe('rm -rf ' . escapeshellarg($path));
        }

        self::$sandboxes = [];
        Rsx::clear_mode_cache();
    }

    // -------------------------------------------------------------------------
    // _tree_row(): exist-or-create, then writable
    // -------------------------------------------------------------------------

    public static function test_an_existing_writable_tree_is_ok()
    {
        try {
            $path = self::__sandbox();
            ensure_directory($path);

            $row = Environment_Health_Checks::_tree_row('sandbox/', $path);

            static::__assert_equals('OK', $row['status'], 'a writable tree is OK');
            static::__assert_equals('sandbox/', $row['label'], 'the row carries the label it was given');
        } finally {
            self::__cleanup();
        }
    }

    public static function test_a_missing_tree_is_created_rather_than_reported()
    {
        try {
            $path = self::__sandbox();

            static::__assert_false(is_dir($path), 'the sandbox does not exist yet');

            $row = Environment_Health_Checks::_tree_row('sandbox/', $path);

            static::__assert_equals('OK', $row['status'], 'a creatable tree is created, not reported');
            static::__assert_true(is_dir($path), 'and it now exists');
        } finally {
            self::__cleanup();
        }
    }

    /**
     * A tree that cannot be created is a FAIL.
     *
     * The blocker is a FILE sitting on the path, not a permission bit: mkdir refuses to
     * create a directory under a regular file for every user, root included, so this
     * branch is reachable whoever the suite runs as.
     */
    public static function test_a_tree_that_cannot_be_created_fails()
    {
        try {
            $blocker = self::__sandbox();
            file_put_contents_safe($blocker, "not a directory\n");

            $row = Environment_Health_Checks::_tree_row('sandbox/', $blocker . '/child');

            static::__assert_equals('FAIL', $row['status'], 'an uncreatable tree is a FAIL');
            static::__assert_contains('could not be created', $row['detail'], 'the detail names the condition');
            static::__assert_not_null($row['remediation'] ?? null, 'and carries a remediation');
        } finally {
            self::__cleanup();
        }
    }

    /**
     * A tree that exists and cannot be written is a FAIL.
     *
     * The only way to make a directory unwritable is a permission bit, and root ignores
     * permission bits - so on a root suite this case is unreachable and says so rather
     * than asserting something it did not prove.
     */
    public static function test_an_unwritable_tree_fails()
    {
        try {
            $path = self::__sandbox();
            ensure_directory($path);
            exec_safe('chmod 500 ' . escapeshellarg($path));

            if (is_writable($path)) {
                static::__skip('this process is root, which writes a 0500 directory regardless');

                return;
            }

            $row = Environment_Health_Checks::_tree_row('sandbox/', $path);

            static::__assert_equals('FAIL', $row['status'], 'an unwritable tree is a FAIL');
            static::__assert_contains('not writable', $row['detail'], 'the detail names the condition');
        } finally {
            self::__cleanup();
        }
    }

    // -------------------------------------------------------------------------
    // Which trees are reported, per mode
    // -------------------------------------------------------------------------

    private static function __labels(array $rows): array
    {
        return array_map(static fn ($row) => $row['label'] ?? '', $rows);
    }

    public static function test_development_reports_all_three_trees()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

            $labels = self::__labels(Environment_Health_Checks::storage_writability());

            foreach (['storage/', 'tmp/', 'build/'] as $label) {
                static::__assert_true(in_array($label, $labels, true), "development reports {$label}");
            }
        } finally {
            self::__cleanup();
        }
    }

    public static function test_a_production_mode_does_not_require_a_writable_build_tree()
    {
        try {
            foreach ([Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
                Rsx::_testing_set_mode($mode);

                $labels = self::__labels(Environment_Health_Checks::storage_writability());

                static::__assert_true(in_array('storage/', $labels, true), 'user data must be writable in every mode');
                static::__assert_true(in_array('tmp/', $labels, true), 'derived caches must be writable in every mode');
                static::__assert_true(
                    !in_array('build/', $labels, true),
                    "build/ is not a writability requirement in {$mode} mode - only the build writes it"
                );
            }
        } finally {
            self::__cleanup();
        }
    }

    // -------------------------------------------------------------------------
    // The tmp links and the PHP temp directory
    // -------------------------------------------------------------------------

    /**
     * Laravel's storage path is the tmp tree, so tmp/logs and tmp/app are what keep the
     * two persistent names persistent. A broken link is a data-loss shape rather than an
     * error, which is why both are reported in every mode.
     */
    public static function test_the_two_storage_links_are_reported_and_healthy()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

            $rows = Environment_Health_Checks::storage_writability();
            $by_label = [];
            foreach ($rows as $row) {
                $by_label[$row['label'] ?? ''] = $row;
            }

            foreach (['tmp/logs link', 'tmp/app link'] as $label) {
                static::__assert_array_has_key($label, $by_label, "{$label} is reported");
                static::__assert_equals('OK', $by_label[$label]['status'], "{$label} resolves onto persistent storage");
            }
        } finally {
            self::__cleanup();
        }
    }

    /**
     * Where a plain library's scratch file lands. The framework exports TMPDIR in both
     * entrypoints, so this row says whether something upstream answered first.
     */
    public static function test_the_php_temp_directory_row_reports_the_tmp_root()
    {
        try {
            $rows = Environment_Health_Checks::storage_writability();
            $by_label = [];
            foreach ($rows as $row) {
                $by_label[$row['label'] ?? ''] = $row;
            }

            static::__assert_array_has_key('PHP temp dir', $by_label, 'the row exists');
            static::__assert_equals(
                str_starts_with(rtrim(sys_get_temp_dir(), '/') . '/', \App\RSpade\Core\Paths\Rsx_Project_Paths::tmp_root() . '/') ? 'OK' : 'WARN',
                $by_label['PHP temp dir']['status'],
                'OK exactly when sys_get_temp_dir() resolves inside the tmp tree'
            );
        } finally {
            self::__cleanup();
        }
    }

    /**
     * The two regenerable caches are reported under their tmp/ names: a thumbnail is
     * derived from a blob that is still in the store, so it is a cache and not user data.
     */
    public static function test_the_cache_subdirectory_rows_name_the_tmp_tree()
    {
        try {
            $labels = self::__labels(Environment_Health_Checks::storage_writability());

            foreach (['tmp/thumbnails', 'tmp/renditions', 'storage/logs'] as $label) {
                static::__assert_true(in_array($label, $labels, true), "{$label} is reported");
            }
        } finally {
            self::__cleanup();
        }
    }

    // -------------------------------------------------------------------------
    // The read-only posture rows
    // -------------------------------------------------------------------------

    public static function test_the_posture_rows_are_production_only()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

            $labels = self::__labels(Environment_Health_Checks::mode_sanity());

            foreach (['system/', 'rsx/', 'build/'] as $label) {
                static::__assert_true(
                    !in_array($label, $labels, true),
                    "development says nothing about {$label} - those trees are written constantly"
                );
            }
        } finally {
            self::__cleanup();
        }
    }

    public static function test_a_production_mode_reports_every_deployed_tree_as_info()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_PRODUCTION);

            $rows = Environment_Health_Checks::mode_sanity();
            $by_label = [];
            foreach ($rows as $row) {
                $by_label[$row['label'] ?? ''] = $row;
            }

            foreach (['system/', 'rsx/', 'build/'] as $label) {
                static::__assert_array_has_key($label, $by_label, "a production mode reports {$label}");
                static::__assert_equals(
                    'INFO',
                    $by_label[$label]['status'],
                    'the posture is reported, never enforced - a box that has not adopted it works'
                );
                static::__assert_contains(
                    'production posture',
                    $by_label[$label]['detail'],
                    'the detail states the expected answer beside the real one'
                );
            }
        } finally {
            self::__cleanup();
        }
    }

    // -------------------------------------------------------------------------
    // The log-level warning
    // -------------------------------------------------------------------------

    public static function test_a_stack_channel_resolves_to_its_loudest_member()
    {
        $restore = config('logging.channels');

        try {
            config([
                'logging.channels.rsx_test_stack' => [
                    'driver' => 'stack',
                    'channels' => ['rsx_test_quiet', 'rsx_test_loud'],
                ],
                'logging.channels.rsx_test_quiet' => ['driver' => 'single', 'level' => 'error'],
                'logging.channels.rsx_test_loud' => ['driver' => 'single', 'level' => 'debug'],
            ]);

            static::__assert_equals(
                'debug',
                Environment_Health_Checks::_effective_log_level('rsx_test_stack'),
                'a stack writes at the loudest level any member accepts'
            );
            static::__assert_equals(
                'error',
                Environment_Health_Checks::_effective_log_level('rsx_test_quiet'),
                'a plain channel answers its own level'
            );
        } finally {
            config(['logging.channels' => $restore]);
        }
    }

    public static function test_an_unknown_channel_has_no_level()
    {
        static::__assert_null(
            Environment_Health_Checks::_effective_log_level('rsx_test_no_such_channel'),
            'a channel nothing declares has no level to report'
        );
    }

    public static function test_debug_logging_warns_in_a_production_mode_only()
    {
        $restore_channels = config('logging.channels');
        $restore_default = config('logging.default');

        try {
            config([
                'logging.default' => 'rsx_test_loud',
                'logging.channels.rsx_test_loud' => ['driver' => 'single', 'level' => 'debug'],
            ]);

            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);
            static::__assert_true(
                !in_array('Log Level', self::__labels(Environment_Health_Checks::mode_sanity()), true),
                'debug logging is what development is for'
            );

            Rsx::_testing_set_mode(Rsx::MODE_PRODUCTION);
            $rows = Environment_Health_Checks::mode_sanity();
            $log_rows = array_values(array_filter($rows, static fn ($row) => ($row['label'] ?? '') === 'Log Level'));

            static::__assert_count(1, $log_rows, 'a production mode warns about it');
            static::__assert_equals('WARN', $log_rows[0]['status'], 'it is a warning, not a failure');
            static::__assert_contains('LOG_LEVEL=info', $log_rows[0]['remediation'], 'the remediation names the key');
        } finally {
            config(['logging.channels' => $restore_channels, 'logging.default' => $restore_default]);
            self::__cleanup();
        }
    }

    public static function test_info_logging_does_not_warn_in_a_production_mode()
    {
        $restore_channels = config('logging.channels');
        $restore_default = config('logging.default');

        try {
            config([
                'logging.default' => 'rsx_test_quiet',
                'logging.channels.rsx_test_quiet' => ['driver' => 'single', 'level' => 'info'],
            ]);

            Rsx::_testing_set_mode(Rsx::MODE_PRODUCTION);

            static::__assert_true(
                !in_array('Log Level', self::__labels(Environment_Health_Checks::mode_sanity()), true),
                'info is the level the remediation asks for, so it warns about nothing'
            );
        } finally {
            config(['logging.channels' => $restore_channels, 'logging.default' => $restore_default]);
            self::__cleanup();
        }
    }
}
