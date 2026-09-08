<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Naming\Rsx_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE ONE ANSWER TO "WHERE IS THIS FILE", checked in both spellings.
 *
 * `Rsx_Paths` exists because forty call sites open-coded a path test as
 * `str_starts_with($p, 'rsx/')` or `str_contains($p, '/app/RSpade/')` - two tests that
 * disagree the moment the caller holds the other spelling. The predicates therefore have
 * exactly one property worth asserting: RELATIVE and ABSOLUTE forms of the same file must
 * answer the same, and a SEGMENT must not match a longer word that starts with it.
 *
 * The subtree pair (`under_*`) and the subpath pair are asserted here because they were
 * added for the rule conversion - a rule asking "is this under rsx/app/" now asks the
 * vocabulary rather than restating the prefix.
 */
class Rsx_Paths_Predicates_Test extends Rsx_Test_Abstract
{
    // Pure string work - no database access.
    protected static $use_database_transactions = false;

    /**
     * Framework and application, in the relative spelling and the absolute one.
     */
    public static function test_tree_predicates_agree_across_spellings()
    {
        $framework_relative = 'app/RSpade/Core/Rsx.php';
        $framework_absolute = base_path($framework_relative);
        $application_relative = 'rsx/models/client_model.php';
        $application_absolute = base_path($application_relative);

        foreach ([$framework_relative, $framework_absolute] as $path) {
            static::__assert_true(Rsx_Paths::is_framework($path), "framework: {$path}");
            static::__assert_false(Rsx_Paths::is_application($path), "not application: {$path}");
        }

        foreach ([$application_relative, $application_absolute] as $path) {
            static::__assert_true(Rsx_Paths::is_application($path), "application: {$path}");
            static::__assert_false(Rsx_Paths::is_framework($path), "not framework: {$path}");
        }
    }

    /**
     * A prefix is matched as WHOLE SEGMENTS. `rsx_legacy/` is not `rsx/`, and a directory
     * named `my_app/RSpade` is not the framework.
     */
    public static function test_prefixes_match_whole_segments_only()
    {
        static::__assert_false(Rsx_Paths::is_application('rsx_legacy/models/x.php'));
        static::__assert_false(Rsx_Paths::is_framework('my_app/RSpade/Core/Rsx.php'));
        static::__assert_false(Rsx_Paths::under_application('rsx/apple/x.php', 'app/'));
        static::__assert_false(Rsx_Paths::under_framework('app/RSpade/Systems/x.php', 'Sys/'));
    }

    /**
     * The subtree forms narrow the tree test, in both spellings.
     */
    public static function test_subtree_predicates()
    {
        static::__assert_true(Rsx_Paths::under_application('rsx/app/frontend/x.php', 'app/'));
        static::__assert_true(Rsx_Paths::under_application(base_path('rsx/app/frontend/x.php'), 'app'));
        static::__assert_false(Rsx_Paths::under_application('rsx/theme/x.scss', 'app/'));
        static::__assert_true(Rsx_Paths::under_application('rsx/theme/components/x.scss', 'theme/components/'));

        static::__assert_true(Rsx_Paths::under_framework('app/RSpade/Sys/app/sys/x.php', 'Sys/'));
        static::__assert_true(Rsx_Paths::under_framework(base_path('app/RSpade/Sys/x.php'), 'Sys'));
        static::__assert_false(Rsx_Paths::under_framework('app/RSpade/Core/Rsx.php', 'Sys/'));

        // No subtree given is the plain tree test.
        static::__assert_true(Rsx_Paths::under_application('rsx/theme/x.scss'));
        static::__assert_true(Rsx_Paths::under_framework('app/RSpade/Core/Rsx.php'));
    }

    /**
     * The subpath forms return what is INSIDE the tree, and null for a path outside it.
     */
    public static function test_subpath_extraction()
    {
        static::__assert_equals(
            'app/frontend/x.php',
            Rsx_Paths::application_subpath('rsx/app/frontend/x.php')
        );
        static::__assert_equals(
            'app/frontend/x.php',
            Rsx_Paths::application_subpath(base_path('rsx/app/frontend/x.php'))
        );
        static::__assert_null(Rsx_Paths::application_subpath('app/RSpade/Core/Rsx.php'));

        static::__assert_equals(
            'Core/Rsx.php',
            Rsx_Paths::framework_subpath('app/RSpade/Core/Rsx.php')
        );
        static::__assert_equals(
            'Core/Rsx.php',
            Rsx_Paths::framework_subpath(base_path('app/RSpade/Core/Rsx.php'))
        );
        static::__assert_null(Rsx_Paths::framework_subpath('rsx/models/client_model.php'));
    }

    /**
     * The three test trees answer as test trees, and nothing else does.
     */
    public static function test_test_tree_predicate()
    {
        static::__assert_true(Rsx_Paths::is_test_tree('app/RSpade/tests/manifest/php/X_Test.php'));
        static::__assert_true(Rsx_Paths::is_test_tree('app/RSpade/temp/scratch.php'));
        static::__assert_true(Rsx_Paths::is_test_tree('rsx/tests/php/X_Test.php'));
        static::__assert_false(Rsx_Paths::is_test_tree('rsx/models/client_model.php'));
        static::__assert_false(Rsx_Paths::is_test_tree('app/RSpade/Core/Rsx.php'));
    }
}
