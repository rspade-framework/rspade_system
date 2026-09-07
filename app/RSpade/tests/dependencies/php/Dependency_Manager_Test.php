<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dependencies\Php;

use App\RSpade\Core\Dependencies\Dependency_Manager;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Framework test for Dependency_Manager - pure logic, no network.
 *
 * Reads the real framework installed set (system/vendor/composer/installed.json) and
 * the real exposure config - both deterministic. Recording tests mutate the real root
 * composer.json, so the file's raw bytes are snapshotted in setup() and restored in
 * teardown() (and by each mutating test) - a run never leaves the repo dirty.
 */
class Dependency_Manager_Test extends Rsx_Test_Abstract
{
    // Pure logic - no database access.
    protected static $use_database_transactions = false;

    // Raw bytes of the root composer.json, captured before any test mutates it.
    private static ?string $composer_snapshot = null;

    // Raw bytes of the root package.json, captured before any test mutates it.
    private static ?string $package_snapshot = null;

    public static function setup()
    {
        static::$composer_snapshot = file_get_contents(Dependency_Manager::root_composer_json_path());
        static::$package_snapshot = file_get_contents(Dependency_Manager::root_package_json_path());
    }

    public static function teardown()
    {
        static::_restore_composer_json();
        static::_restore_package_json();
    }

    private static function _restore_composer_json()
    {
        if (static::$composer_snapshot !== null) {
            file_put_contents(Dependency_Manager::root_composer_json_path(), static::$composer_snapshot);
        }
    }

    private static function _restore_package_json()
    {
        if (static::$package_snapshot !== null) {
            file_put_contents(Dependency_Manager::root_package_json_path(), static::$package_snapshot);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Replace map generation
    |--------------------------------------------------------------------------
    */

    public static function test_replace_map_is_non_empty()
    {
        $map = Dependency_Manager::generate_composer_replace_map();
        static::__assert_not_empty($map, 'Replace map should list the framework installed set');
    }

    public static function test_replace_map_contains_laravel_framework_v_prefixed()
    {
        $map = Dependency_Manager::generate_composer_replace_map();

        static::__assert_true(
            array_key_exists('laravel/framework', $map),
            'Replace map must include laravel/framework'
        );

        // Versions are kept verbatim from installed.json - laravel ships a "v" prefix
        // and composer accepts it in replace, so we must NOT strip it.
        static::__assert_true(
            str_starts_with($map['laravel/framework'], 'v'),
            'laravel/framework version must keep its leading "v" (got: ' . $map['laravel/framework'] . ')'
        );
    }

    public static function test_replace_map_is_sorted_by_key()
    {
        $map = Dependency_Manager::generate_composer_replace_map();

        $keys = array_keys($map);
        $sorted = $keys;
        sort($sorted);

        static::__assert_equals($sorted, $keys, 'Replace map must be sorted by package name');
    }

    public static function test_replace_map_excludes_root_meta_package()
    {
        $map = Dependency_Manager::generate_composer_replace_map();

        // The framework's own root package (laravel/laravel) is the project, not a dep.
        static::__assert_false(
            array_key_exists('laravel/laravel', $map),
            'Replace map must exclude the framework root meta-package'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Exposure checks
    |--------------------------------------------------------------------------
    */

    public static function test_exposed_package_is_recognized()
    {
        static::__assert_true(
            Dependency_Manager::is_exposed_composer('guzzlehttp/guzzle'),
            'guzzlehttp/guzzle is on the exposed surface'
        );
    }

    public static function test_installed_but_not_exposed_is_internal()
    {
        // symfony/console ships with the framework but is NOT exposed.
        static::__assert_true(
            Dependency_Manager::is_framework_installed_composer('symfony/console'),
            'symfony/console must be present in the framework installed set'
        );
        static::__assert_false(
            Dependency_Manager::is_exposed_composer('symfony/console'),
            'symfony/console must NOT be exposed'
        );
    }

    /**
     * The sentinel is a name no package registry can ever answer for, deliberately: a real
     * package used as the "not installed" fixture stops being one the day somebody installs
     * it, and this test previously named one that later joined the standard library.
     */
    private const UNKNOWN_PACKAGE = 'rspade-test/no-such-package';

    public static function test_unknown_package_is_neither()
    {
        static::__assert_false(
            Dependency_Manager::is_exposed_composer(self::UNKNOWN_PACKAGE),
            self::UNKNOWN_PACKAGE . ' is not exposed'
        );
        static::__assert_false(
            Dependency_Manager::is_framework_installed_composer(self::UNKNOWN_PACKAGE),
            self::UNKNOWN_PACKAGE . ' is not framework-installed'
        );
    }

    public static function test_framework_version_lookup()
    {
        $version = Dependency_Manager::framework_composer_version('guzzlehttp/guzzle');
        static::__assert_not_empty($version, 'guzzlehttp/guzzle must have an installed version');

        static::__assert_null(
            Dependency_Manager::framework_composer_version(self::UNKNOWN_PACKAGE),
            'Uninstalled package version lookup must be null'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Recording round-trip
    |--------------------------------------------------------------------------
    */

    public static function test_record_and_unrecord_round_trip()
    {
        try {
            $version = Dependency_Manager::framework_composer_version('guzzlehttp/guzzle');

            Dependency_Manager::record_provided_composer('guzzlehttp/guzzle', $version);

            $recorded = Dependency_Manager::get_recorded_provided_composer();
            static::__assert_true(
                array_key_exists('guzzlehttp/guzzle', $recorded),
                'Recording must be readable back'
            );
            static::__assert_equals(
                $version,
                $recorded['guzzlehttp/guzzle'],
                'Recorded version must match the version at record time'
            );

            $removed = Dependency_Manager::unrecord_provided_composer('guzzlehttp/guzzle');
            static::__assert_true($removed, 'unrecord must report a record was present');

            $after = Dependency_Manager::get_recorded_provided_composer();
            static::__assert_false(
                array_key_exists('guzzlehttp/guzzle', $after),
                'Recording must be gone after unrecord'
            );

            static::__assert_false(
                Dependency_Manager::unrecord_provided_composer('guzzlehttp/guzzle'),
                'unrecord of an absent record must report false'
            );
        } finally {
            static::_restore_composer_json();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reconcile checks
    |--------------------------------------------------------------------------
    */

    public static function test_reconcile_clean_recording_has_no_problem()
    {
        try {
            $version = Dependency_Manager::framework_composer_version('guzzlehttp/guzzle');
            Dependency_Manager::record_provided_composer('guzzlehttp/guzzle', $version);

            $problems = Dependency_Manager::check_recorded_composer();

            foreach ($problems as $problem) {
                static::__assert_false(
                    $problem['package'] === 'guzzlehttp/guzzle',
                    'A freshly-recorded, still-installed, same-major package must not be a problem'
                );
            }
        } finally {
            static::_restore_composer_json();
        }
    }

    public static function test_reconcile_removed_package_is_flagged()
    {
        try {
            // A package that is not in the framework installed set at all.
            Dependency_Manager::record_provided_composer('acme/does-not-exist', '1.2.3');

            $problems = Dependency_Manager::check_recorded_composer();

            $found = null;
            foreach ($problems as $problem) {
                if ($problem['package'] === 'acme/does-not-exist') {
                    $found = $problem;
                    break;
                }
            }

            static::__assert_not_null($found, 'A recording for a missing package must be flagged');
            static::__assert_equals('removed', $found['problem'], 'Missing package problem must be "removed"');
            static::__assert_null($found['installed'], 'Removed package installed version must be null');
        } finally {
            static::_restore_composer_json();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reconcile checks (npm)
    |--------------------------------------------------------------------------
    */

    public static function test_reconcile_npm_clean_recording_has_no_problem()
    {
        try {
            $version = Dependency_Manager::framework_npm_version('dompurify');
            static::__assert_not_empty($version, 'dompurify must be declared in the framework npm set');

            Dependency_Manager::record_provided_npm('dompurify', $version);

            $problems = Dependency_Manager::check_recorded_npm();

            foreach ($problems as $problem) {
                static::__assert_false(
                    $problem['package'] === 'dompurify',
                    'A freshly-recorded, still-declared, same-major npm package must not be a problem'
                );
            }
        } finally {
            static::_restore_package_json();
        }
    }

    public static function test_reconcile_npm_removed_package_is_flagged()
    {
        try {
            // A package that is not in the framework npm set at all.
            Dependency_Manager::record_provided_npm('acme-does-not-exist', '1.2.3');

            $problems = Dependency_Manager::check_recorded_npm();

            $found = null;
            foreach ($problems as $problem) {
                if ($problem['package'] === 'acme-does-not-exist') {
                    $found = $problem;
                    break;
                }
            }

            static::__assert_not_null($found, 'A recording for a missing npm package must be flagged');
            static::__assert_equals('removed', $found['problem'], 'Missing npm package problem must be "removed"');
            static::__assert_null($found['installed'], 'Removed npm package installed version must be null');
        } finally {
            static::_restore_package_json();
        }
    }

    public static function test_reconcile_npm_major_change_is_flagged()
    {
        try {
            // Record dompurify at a deliberately lower major than the framework declares.
            Dependency_Manager::record_provided_npm('dompurify', '1.0.0');

            $problems = Dependency_Manager::check_recorded_npm();

            $found = null;
            foreach ($problems as $problem) {
                if ($problem['package'] === 'dompurify') {
                    $found = $problem;
                    break;
                }
            }

            static::__assert_not_null($found, 'A recorded major mismatch must be flagged');
            static::__assert_equals('major_change', $found['problem'], 'Major mismatch problem must be "major_change"');
        } finally {
            static::_restore_package_json();
        }
    }
}
