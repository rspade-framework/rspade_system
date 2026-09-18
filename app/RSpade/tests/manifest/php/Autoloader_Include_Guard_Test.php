<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Autoloader;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The framework never includes an indexed PHP file twice - whoever loaded it first, and
 * under whatever spelling of its path.
 *
 * Composer's autoloader runs ahead of the manifest autoloader. On a case-insensitive mount
 * a PSR-4 lookup can answer with a real file under a differently-cased path, and PHP's
 * include table is keyed on the path string, so the framework's later require of the
 * canonical spelling redeclared the class and fataled (a downstream field report,
 * 2026-09-18). Autoloader::include_declaration() is the one seam every framework include
 * goes through: a declaration already present is never loaded again, and a file that
 * declares nothing is matched against the include table without case.
 *
 * Pure logic, no DB.
 */
class Autoloader_Include_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __scratch_file(string $name, string $php): string
    {
        $dir = Rsx_Project_Paths::tmp_path('include-guard-test');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $name;
        file_put_contents($path, $php);

        return $path;
    }

    /** A class somebody else already declared is not loaded again, however its file is spelled. */
    public static function test_a_declared_class_is_never_included_again()
    {
        $fqcn = 'Include_Guard_Probe_' . bin2hex(random_bytes(4));
        $path = static::__scratch_file($fqcn . '.php', "<?php\nclass {$fqcn} {}\n");

        // The first load, as composer would do it - under a spelling of the path that is
        // not the manifest's.
        require $path;
        static::__assert_true(Autoloader::_is_declared($fqcn), 'the probe class is declared');

        // The framework's load of the canonical path: without the seam this is a fatal
        // "Cannot redeclare class". The path handed in is the SAME file spelled differently
        // (an upper-cased directory segment), which does not exist on a case-sensitive box,
        // so reaching require_once here would fail loudly either way.
        $other_spelling = str_replace('include-guard-test', 'INCLUDE-GUARD-TEST', $path);
        Autoloader::include_declaration($other_spelling, $fqcn);

        static::__assert_true(Autoloader::_is_declared($fqcn), 'still declared exactly once, no fatal');

        unlink($path);
    }

    /** A file that declares nothing is matched against the include table without case. */
    public static function test_a_helper_file_is_matched_without_case()
    {
        $marker = 'include_guard_helper_' . bin2hex(random_bytes(4));
        $path = static::__scratch_file($marker . '.php', "<?php\nfunction {$marker}() { return 1; }\n");

        require $path;
        static::__assert_true(function_exists($marker), 'the helper was loaded once');

        $other_spelling = str_replace('include-guard-test', 'INCLUDE-GUARD-TEST', $path);
        Autoloader::include_declaration($other_spelling);

        static::__assert_true(function_exists($marker), 'and was not loaded a second time (a redeclare would have been fatal)');

        unlink($path);
    }

    /** The case-insensitive match is exact on everything but case. */
    public static function test_the_include_table_match_ignores_case_only()
    {
        $included = ['/var/www/html/rsx/models/user_model.php', '/var/www/html/system/app/helpers.php'];

        static::__assert_true(
            Autoloader::_included_under_any_case('/var/www/html/rsx/Models/User_Model.php', $included),
            'a differently-cased spelling of an included path matches'
        );
        static::__assert_true(
            Autoloader::_included_under_any_case('/var/www/html/rsx/models/user_model.php', $included),
            'the identical path matches'
        );
        static::__assert_false(
            Autoloader::_included_under_any_case('/var/www/html/rsx/models/user_model2.php', $included),
            'a different path does not'
        );
        static::__assert_false(
            Autoloader::_included_under_any_case('/var/www/html/rsx/models/user_model.php', []),
            'an empty include table matches nothing'
        );
    }

    /** A file nobody loaded is loaded, exactly as before. */
    public static function test_an_unloaded_file_is_included()
    {
        $fqcn = 'Include_Guard_Fresh_' . bin2hex(random_bytes(4));
        $path = static::__scratch_file($fqcn . '.php', "<?php\nclass {$fqcn} {}\n");

        static::__assert_false(Autoloader::_is_declared($fqcn), 'not declared before');
        Autoloader::include_declaration($path, $fqcn);
        static::__assert_true(Autoloader::_is_declared($fqcn), 'declared after');

        unlink($path);
    }
}
