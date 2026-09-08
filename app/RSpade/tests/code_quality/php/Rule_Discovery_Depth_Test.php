<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Support\RuleDiscovery;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A RULE IS FOUND WHEREVER IT IS FILED.
 *
 * Rule discovery used to be `glob(base_path('app/RSpade/CodeQuality/Rules') . '/**' . '/*.php')`,
 * and PHP's `glob()` HAS NO `**`: the pattern matched exactly one directory level, by
 * accident, and every rule happens to live exactly one level down. A rule filed two levels
 * deep would simply never have been discovered - no error, no warning, a rule that silently
 * never ran. The walk is a RecursiveIteratorIterator now, and this is the test that says so.
 *
 * The probe rule is written to disk, discovered, and removed in a finally. It is deliberately
 * inert - not a manifest-scan rule, matching no file pattern - so that even if a process
 * discovers it in the window it exists, it has nothing to say about anything.
 */
class Rule_Discovery_Depth_Test extends Rsx_Test_Abstract
{
    // A filesystem scan; no database.
    protected static $use_database_transactions = false;

    /** Two levels below Rules/, which is one level deeper than any real rule. */
    private const PROBE_SUBPATH = 'Manifest/Depth_Probe_Nest/Depth_Probe_CodeQualityRule.php';

    private const PROBE_FQCN =
        'App\\RSpade\\CodeQuality\\Rules\\Manifest\\Depth_Probe_Nest\\Depth_Probe_CodeQualityRule';

    private static function __probe_path(): string
    {
        return base_path('app/RSpade/CodeQuality/Rules/' . self::PROBE_SUBPATH);
    }

    private static function __probe_source(): string
    {
        return "<?php\n\n"
            . "namespace App\\RSpade\\CodeQuality\\Rules\\Manifest\\Depth_Probe_Nest;\n\n"
            . "use App\\RSpade\\CodeQuality\\Rules\\CodeQualityRule_Abstract;\n\n"
            . "class Depth_Probe_CodeQualityRule extends CodeQualityRule_Abstract\n"
            . "{\n"
            . "    public function get_id(): string\n    {\n        return 'DEPTH-PROBE-01';\n    }\n\n"
            . "    public function get_name(): string\n    {\n        return 'Depth Probe';\n    }\n\n"
            . "    public function get_description(): string\n    {\n"
            . "        return 'A test fixture proving rule discovery recurses. It matches nothing.';\n    }\n\n"
            . "    public function get_file_patterns(): array\n    {\n        return [];\n    }\n\n"
            . "    public function check(string \$file_path, string \$contents, array \$metadata = []): void\n"
            . "    {\n    }\n"
            . "}\n";
    }

    /**
     * A rule two directory levels below Rules/ is discovered.
     */
    public static function test_a_rule_two_levels_deep_is_discovered()
    {
        $path = static::__probe_path();

        ensure_directory(dirname($path));
        file_put_contents($path, static::__probe_source());

        try {
            // The file list is memoized for the process, and this process already looked.
            RuleDiscovery::_forget_for_tests();

            $files = RuleDiscovery::rule_files();

            static::__assert_greater_than(
                50,
                count($files),
                'the discovery found the rules directory (a scan that finds nothing proves nothing)'
            );

            static::__assert_true(
                isset($files[self::PROBE_FQCN]),
                'a rule filed two levels below Rules/ was discovered. Discovery must recurse:'
                . " PHP's glob() has no ** and silently matched one level only."
            );

            static::__assert_equals(
                $path,
                $files[self::PROBE_FQCN],
                'discovery reports the file the rule actually lives in (which is what the'
                . ' driver fingerprints a rule by)'
            );
        } finally {
            @unlink($path);
            @rmdir(dirname($path));

            // Leave the process's memo describing the tree as it now is.
            RuleDiscovery::_forget_for_tests();
        }
    }
}
