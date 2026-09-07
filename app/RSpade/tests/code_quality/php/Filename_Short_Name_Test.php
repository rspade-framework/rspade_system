<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\PHP\Filename_ShortName;
use App\RSpade\Core\PHP\Filename_Suggester;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for Filename_ShortName - the single source of truth for the RSX
 * "short filename" algorithm (B4.9 consolidation of four duplicate copies).
 *
 * Behavior pinned here:
 * - ANYWHERE-IN-PATH prefix match (the property that distinguishes the canonical
 *   algorithm from the old tail-anchored copy in Filename_Suggester).
 * - The app/RSpade guard: framework code never gets a short name.
 * - Segment-count rules: 1- and 2-segment names have no valid short name.
 * - count_matched_prefix_parts() and extract_short_name() never disagree.
 * - Filename_Suggester now shares the guarded, anywhere-in-path algorithm (the
 *   intentional behavior change: the auto-fixer aligns with the enforcer).
 */
class Filename_Short_Name_Test extends Rsx_Test_Abstract
{
    // Pure string logic - no database access.
    protected static $use_database_transactions = false;

    // =====================================================================
    // Anywhere-in-path match: the prefix sits mid-path, not at the tail.
    // A tail-anchored matcher would return null here; the canonical one matches.
    // =====================================================================

    public static function test_extract_matches_prefix_anywhere_in_path()
    {
        // "frontend" appears at dir index 2 of 6 (NOT the tail); the old
        // tail-anchored suggester compared only the trailing dir segments and
        // would have found no match.
        $short = Filename_ShortName::extract_short_name(
            'frontend_calendar_widget',
            'rsx/app/frontend/calendar/event/list'
        );

        static::__assert_equals(
            'calendar_widget',
            $short,
            'a prefix segment matched mid-path yields the shortened name (anywhere-in-path, not tail-anchored)'
        );
    }

    // =====================================================================
    // app/RSpade guard: framework files get NO short name.
    // =====================================================================

    public static function test_extract_guards_framework_path()
    {
        // Without the guard, prefix "Core_Foo" is tail-present in the dir and
        // would yield "Bar_Widget"; the guard forces null for framework code.
        $short = Filename_ShortName::extract_short_name(
            'Core_Foo_Bar_Widget',
            'app/RSpade/Core/Foo'
        );

        static::__assert_null(
            $short,
            'a directory under app/RSpade (relative, no leading slash) never yields a short name'
        );
    }

    public static function test_extract_guards_framework_path_absolute()
    {
        $short = Filename_ShortName::extract_short_name(
            'Core_Foo_Bar_Widget',
            '/var/www/html/system/app/RSpade/Core/Foo'
        );

        static::__assert_null(
            $short,
            'an absolute directory under app/RSpade also never yields a short name'
        );
    }

    // =====================================================================
    // Segment-count rules: 1- and 2-segment names have no valid short name.
    // =====================================================================

    public static function test_extract_single_segment_name_is_null()
    {
        $short = Filename_ShortName::extract_short_name('widget', 'rsx/app/frontend/widget');

        static::__assert_null($short, 'a 1-segment name has no droppable prefix');
    }

    public static function test_extract_two_segment_name_is_null()
    {
        // Even though "frontend" matches the directory, a 2-segment name must
        // keep its full form (dropping a part would leave a single segment).
        $short = Filename_ShortName::extract_short_name('frontend_widget', 'rsx/app/frontend');

        static::__assert_null($short, 'a 2-segment name is never shortened');
    }

    // =====================================================================
    // count and extract never disagree on a known case.
    // =====================================================================

    public static function test_count_agrees_with_extract()
    {
        $name = 'frontend_calendar_widget';
        $dir = 'rsx/app/frontend/calendar/event/list';

        $count = Filename_ShortName::count_matched_prefix_parts($name, $dir);
        $short = Filename_ShortName::extract_short_name($name, $dir);

        static::__assert_equals(1, $count, 'exactly one prefix segment ("frontend") is directory-redundant');

        // Dropping $count leading segments from the name must reproduce the short name.
        $reconstructed = implode('_', array_slice(explode('_', $name), $count));
        static::__assert_equals(
            $short,
            $reconstructed,
            'count_matched_prefix_parts and extract_short_name describe the same cut'
        );
    }

    public static function test_count_guards_framework_path()
    {
        $count = Filename_ShortName::count_matched_prefix_parts('Core_Foo_Bar_Widget', 'app/RSpade/Core/Foo');

        static::__assert_equals(0, $count, 'framework paths yield zero removable prefix parts');
    }

    // =====================================================================
    // Intentional behavior change: Filename_Suggester now shares the guarded,
    // anywhere-in-path algorithm. It previously (tail-anchored, unguarded)
    // returned "Bar_Widget" for this framework path.
    // =====================================================================

    public static function test_suggester_returns_null_for_framework_path()
    {
        $short = Filename_Suggester::extract_short_name('Core_Foo_Bar_Widget', 'app/RSpade/Core/Foo');

        static::__assert_null(
            $short,
            'the suggester now honors the app/RSpade guard (was "Bar_Widget" under the old tail-anchored copy)'
        );
    }

    public static function test_suggester_full_filename_for_framework_path()
    {
        $filename = Filename_Suggester::get_suggested_class_filename(
            'app/RSpade/Core/Foo/Core_Foo_Bar_Widget.php',
            'Core_Foo_Bar_Widget',
            'php',
            true
        );

        static::__assert_equals(
            'Core_Foo_Bar_Widget.php',
            $filename,
            'a framework class keeps its full filename (no short-name suggestion)'
        );
    }
}
