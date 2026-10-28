<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\UnboundedResultSet_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// @DB-UNBOUNDED-01-EXCEPTION - this file's fixtures are SOURCE STRINGS describing the flagged
// reads so the rule can be run against them. It reads nothing itself.

/**
 * Unit tests for DB-UNBOUNDED-01 (UnboundedResultSet_CodeQualityRule): a whole-set ->get() /
 * ->pluck() on a model declaring $unbounded, in framework code, is flagged unless the chain
 * bounds it. Session declares $unbounded.
 *
 * The rule judges only framework paths (`/app/RSpade/`, outside tests/), so each fixture is
 * written under a scratch directory whose path carries that segment, and the rule is driven
 * directly.
 */
class Unbounded_Result_Set_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'DB-UNBOUNDED-01';

    /**
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $statement): array
    {
        $source = "<?php\n\nnamespace App\\RSpade\\Core\\Probe;\n\nuse App\\RSpade\\Core\\Session\\Session;\n\n"
            . "class Unbounded_Probe\n{\n    public static function go()\n    {\n        return {$statement};\n    }\n}\n";

        $dir = Rsx_Project_Paths::tmp_path() . '/unbounded_fixture_' . uniqid() . '/app/RSpade/Core/Probe';
        ensure_directory($dir);
        $path = $dir . '/Unbounded_Probe.php';
        file_put_contents($path, $source);

        try {
            $collector = new ViolationCollector();
            (new UnboundedResultSet_CodeQualityRule($collector))->check($path, $source);

            return $collector->get_by_rule(self::RULE_ID);
        } finally {
            @unlink($path);
        }
    }

    public static function test_a_bare_whole_set_read_is_flagged()
    {
        static::__assert_count(1, static::__run("Session::where('active', true)->get()"), 'a builder-rooted ->get() is flagged');
        static::__assert_count(1, static::__run("Session::query()->pluck('id')"), 'a builder-rooted ->pluck() is flagged');
    }

    public static function test_a_read_through_the_portal_seam_is_flagged()
    {
        static::__assert_count(1, static::__run("Session::_portal_query()->where('active', true)->get()"), 'Session::_portal_query() roots a Session read');
    }

    public static function test_a_bounded_read_is_not_flagged()
    {
        static::__assert_empty(static::__run("Session::_portal_query()->where('session_token', 'x')->first()"), 'first() is bounded');
        static::__assert_empty(static::__run("Session::where('active', true)->limit(20)->get()"), 'limit() is bounded');
        static::__assert_empty(static::__run("Session::_portal_query()->result_set()"), 'result_set() iterates');
    }
}
