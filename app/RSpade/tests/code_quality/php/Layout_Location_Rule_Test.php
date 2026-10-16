<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Convention\LayoutLocation_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for CONV-LAYOUT-01 (LayoutLocation_CodeQualityRule).
 *
 * A layout belongs to a module of one of the two application realms - the staff
 * application under rsx/app/ and the client portal under rsx/portal/ - and never sits
 * directly in a realm root. The rule reads only the path, so each case hands it a path
 * under the application tree and no file is written.
 */
class Layout_Location_Rule_Test extends Rsx_Test_Abstract
{
    // Path inspection only - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'CONV-LAYOUT-01';

    /** The rule's findings for a layout at $relative (relative to the application tree). */
    private static function __findings(string $relative): array
    {
        $collector = new ViolationCollector();
        $rule = new LayoutLocation_CodeQualityRule($collector);
        $rule->check(base_path('rsx/' . $relative), '');

        // A convention finding is kept apart from the ordinary violations.
        return array_values(array_filter(
            $collector->get_convention_violations(),
            fn ($violation) => $violation->rule_id === self::RULE_ID
        ));
    }

    public static function test_a_layout_in_a_staff_module_is_clean()
    {
        static::__assert_count(0, static::__findings('app/backend/backend_layout.blade.php'));
        static::__assert_count(0, static::__findings('app/frontend/billing/invoices/print/invoice_print_layout.blade.php'));
    }

    public static function test_a_layout_in_a_portal_module_is_clean()
    {
        static::__assert_count(0, static::__findings('portal/auth/portal_auth_layout.blade.php'));
        static::__assert_count(0, static::__findings('portal/public_pages/portal_public_layout.blade.php'));
    }

    public static function test_a_layout_directly_in_a_realm_root_is_flagged()
    {
        $staff = static::__findings('app/stray_layout.blade.php');
        static::__assert_count(1, $staff);
        static::__assert_contains('not directly in ./rsx/app/', $staff[0]->message);

        $portal = static::__findings('portal/stray_layout.blade.php');
        static::__assert_count(1, $portal);
        static::__assert_contains('not directly in ./rsx/portal/', $portal[0]->message);
    }

    public static function test_a_layout_outside_both_realms_is_flagged()
    {
        $findings = static::__findings('lib/widgets/widget_layout.blade.php');
        static::__assert_count(1, $findings);
        static::__assert_contains('./rsx/app/ or ./rsx/portal/', $findings[0]->message);
    }

    public static function test_a_view_that_is_not_a_layout_is_never_examined()
    {
        static::__assert_count(0, static::__findings('lib/widgets/widget_panel.blade.php'));
    }
}
