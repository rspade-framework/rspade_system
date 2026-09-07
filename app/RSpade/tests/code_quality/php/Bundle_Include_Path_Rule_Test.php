<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Convention\BundleIncludePath_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\CodeQuality\Php\Bundle_Include_Clean_Bundle;
use App\RSpade\Tests\CodeQuality\Php\Bundle_Include_Panel_Class_Bundle;
use App\RSpade\Tests\CodeQuality\Php\Bundle_Include_Panel_Path_Bundle;
use App\RSpade\Tests\CodeQuality\Php\Bundle_Include_Panel_Routes_Bundle;

/**
 * CONV-BUNDLE-02, critical half: an rsx/ bundle may not include the framework application
 * tree (app/RSpade/Sys) - the system control panel.
 *
 * THE TREE IS NOT SHARED IN EITHER DIRECTION. CONV-BUNDLE-04 stops the panel borrowing from
 * rsx/; this stops rsx/ borrowing from the panel. Pulling the panel into an application
 * bundle ships framework-internal screens, components and theme tokens into the app's asset
 * graph, where the app's variables and Bootstrap build then apply to them and the next
 * framework update changes all of it underneath.
 *
 * An application does not need the include: the panel's entry route is published into every
 * bundle (config rsx.always_published_routes), so Rsx.Route('_Sys_Dashboard_Action') and
 * Permission.can_access('_Sys_Dashboard_Action') answer from any application page.
 *
 * The fixtures are REAL bundle classes, because the rule reads define() rather than parsing
 * the array literal - the same seam CONV-BUNDLE-04 uses. The file path handed to check() is
 * synthetic (an rsx/ path), which is exactly what the rule scopes on.
 */
class Bundle_Include_Path_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'CONV-BUNDLE-02';

    /**
     * Run the rule over one fixture bundle, as if its class had been declared in rsx/.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $fqcn): array
    {
        $source_file = (new \ReflectionClass($fqcn))->getFileName();
        $contents = file_get_contents($source_file);

        // The rule scopes on the path, which is the whole point of the rule: it judges
        // APPLICATION bundles. base_path() is <project>/system, so rsx/ hangs off it.
        $file_path = base_path('rsx/app/probe/probe_bundle.php');

        $metadata = [
            'class' => class_basename($fqcn),
            'fqcn' => $fqcn,
            'extends' => 'Rsx_Module_Bundle_Abstract',
        ];

        $collector = new ViolationCollector();
        $rule = new BundleIncludePath_CodeQualityRule($collector);
        $rule->check($file_path, $contents, $metadata);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    /**
     * Only the critical (framework-tree) findings - the low-severity "include your own
     * directory" convention is the other half of this rule and is not what these prove.
     *
     * @param array<int,\App\RSpade\CodeQuality\CodeQuality_Violation> $violations
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __critical(array $violations): array
    {
        return array_values(array_filter(
            $violations,
            fn ($violation) => $violation->severity === 'critical'
        ));
    }

    /**
     * CB2-SYS-PATH - a path under the framework application tree.
     */
    public static function test_a_framework_tree_path_is_critical()
    {
        $violations = static::__critical(static::__run(Bundle_Include_Panel_Path_Bundle::class));

        static::__assert_count(1, $violations, 'exactly the one offending entry is reported');
        static::__assert_contains('app/RSpade/Sys/theme', $violations[0]->message);
        static::__assert_contains('_Sys_Dashboard_Action', $violations[0]->suggestion);
    }

    /**
     * CB2-SYS-CLASS - the same dependency spelled as a bundle CLASS. The reserved
     * single-underscore prefix is framework property by construction (NAME-RESERVED-01), so
     * the name alone settles it.
     */
    public static function test_a_reserved_bundle_class_is_critical()
    {
        $violations = static::__critical(static::__run(Bundle_Include_Panel_Class_Bundle::class));

        static::__assert_count(1, $violations, 'the alias and __DIR__ entries are not offenders');
        static::__assert_contains('_Sys_Theme_Bundle', $violations[0]->message);
    }

    /**
     * CB2-SYS-ROUTES - include_routes is judged identically. Route extraction bundles no
     * assets, but it is still the application reaching into the framework's own application.
     */
    public static function test_include_routes_is_judged_too()
    {
        $violations = static::__critical(static::__run(Bundle_Include_Panel_Routes_Bundle::class));

        static::__assert_count(1, $violations);
        static::__assert_contains('app/RSpade/Sys/app/sys', $violations[0]->message);
        static::__assert_contains("'include_routes' list", $violations[0]->message);
    }

    /**
     * CB2-SYS-CLEAN - the shape a real application bundle has. rsx/ paths, an application
     * bundle class, npm-backed aliases and its own directory are all correct, and a rule
     * that flagged any of them would be worse than no rule.
     */
    public static function test_an_ordinary_application_bundle_is_clean()
    {
        $violations = static::__critical(static::__run(Bundle_Include_Clean_Bundle::class));

        static::__assert_count(0, $violations, 'an ordinary application include list is not this rule\'s business');
    }
}
