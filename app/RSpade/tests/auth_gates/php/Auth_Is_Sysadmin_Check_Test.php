<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Auth\Auth_BundleIntegration;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * is_sysadmin - the framework's control-panel gate.
 *
 * Declared on Permission_Abstract, so it is a STAFF check and only a staff check:
 * the panel is a staff surface, and a portal identity must not be able to name it.
 * Its body is is_logged_in() today; what this class pins is the DECLARATION - the
 * realm it lives in, the class it resolves to, and its presence in the generated
 * JS mirror the sidebar's can_access() reads.
 */
class Auth_Is_Sysadmin_Check_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * AG-ROOT-01 - is_sysadmin is in the staff registry, resolving to the framework base.
     */
    public static function test_is_sysadmin_is_a_staff_check()
    {
        $checks = Auth_Gates::get_checks(Auth_Gates::REALM_STAFF);

        static::__assert_array_has_key('is_sysadmin', $checks);
        static::__assert_equals(
            'App\\RSpade\\Core\\Permission\\Permission_Abstract',
            $checks['is_sysadmin']['class']
        );
        static::__assert_equals('is_sysadmin', $checks['is_sysadmin']['method']);
    }

    /**
     * AG-ROOT-02 - The persisted manifest agrees: staff has it, portal does not.
     */
    public static function test_is_sysadmin_is_absent_from_the_portal_realm()
    {
        $manifest = Manifest::get_full_manifest();
        $checks = $manifest['data']['auth']['checks'] ?? [];

        static::__assert_array_has_key('is_sysadmin', $checks['staff'] ?? []);

        static::__assert_false(
            array_key_exists('is_sysadmin', $checks['portal'] ?? []),
            'is_sysadmin reached the portal realm - the control panel is a staff surface'
        );
    }

    /**
     * AG-ROOT-03 - The check evaluates in the staff realm, and naming it from the
     * portal realm is the ordinary unknown-name failure.
     */
    public static function test_is_sysadmin_evaluates_staff_side_only()
    {
        static::__reset_session();

        static::__assert_false(Auth_Gates::evaluate('is_sysadmin', Auth_Gates::REALM_STAFF));

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Auth_Gates::evaluate('is_sysadmin', Auth_Gates::REALM_PORTAL)
        );
    }

    /**
     * AG-ROOT-04 - The generated staff Permission mirror exports it, which is what
     * makes Permission.can_access() answer for a panel link in the browser.
     */
    public static function test_the_staff_js_mirror_exports_is_root()
    {
        $mirror = null;

        foreach (Auth_BundleIntegration::get_mirror_stub_paths() as $path) {
            if (str_contains($path, 'Portal_')) {
                continue;
            }

            $mirror = $path;
        }

        static::__assert_not_empty($mirror, 'no staff Permission mirror was generated');

        $absolute = str_starts_with($mirror, '/') ? $mirror : rsx_project_file_path($mirror);

        static::__assert_true(file_exists($absolute), "mirror stub missing at {$absolute}");
        static::__assert_contains('Permission.is_sysadmin', file_get_contents($absolute));
    }
}
