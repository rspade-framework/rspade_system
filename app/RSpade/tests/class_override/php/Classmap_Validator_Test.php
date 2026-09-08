<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\Core\Manifest\Manifest_Indexer;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Mechanism 2 - the composer-classmap validator + blocking dump that heals the
 * on-disk classmap after the class-override rename/restore pass leaves an entry
 * pointing at a renamed (.php.upstream) or removed file.
 *
 * Covers the pure staleness detector (_find_stale_classmap_entries) and the
 * orchestrator (_validate_composer_classmap). The actual composer dump-autoload is
 * exercised only through the testable seam ($_composer_dump_runner) so these unit
 * tests never shell out; the real dump is proven E2E in ticket verification.
 *
 * Pure logic, no DB.
 */
class Classmap_Validator_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Write a scratch classmap fixture (a PHP file returning FQCN => absolute path)
     * and return its path. Cleaned up by the caller.
     */
    private static function __write_classmap_fixture(array $map): string
    {
        $path = storage_path('rsx-tmp/classmap_fixture_' . uniqid() . '.php');
        $lines = ["<?php", "", "return ["];
        foreach ($map as $fqcn => $file) {
            $lines[] = '    ' . var_export($fqcn, true) . ' => ' . var_export($file, true) . ',';
        }
        $lines[] = '];';
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    // -------------------------------------------------------------------------
    // _find_stale_classmap_entries()
    // -------------------------------------------------------------------------

    // A classmap with one missing file surfaces exactly that entry.
    public static function test_detects_single_stale_entry()
    {
        $present = __FILE__; // a file guaranteed to exist
        $missing = storage_path('rsx-tmp/definitely_missing_' . uniqid() . '.php');

        $fixture = static::__write_classmap_fixture([
            'App\\Present_One' => $present,
            'App\\Gone_Model'  => $missing,
        ]);

        try {
            $stale = Manifest_Indexer::_find_stale_classmap_entries($fixture);

            static::__assert_count(1, $stale, 'Exactly one stale entry expected');
            static::__assert_true(isset($stale['App\\Gone_Model']), 'The missing-file FQCN must be reported');
            static::__assert_equals($missing, $stale['App\\Gone_Model']);
        } finally {
            @unlink($fixture);
        }
    }

    // An all-present classmap reports nothing stale.
    public static function test_all_present_reports_no_staleness()
    {
        $fixture = static::__write_classmap_fixture([
            'App\\Present_One' => __FILE__,
            'App\\Present_Two' => __DIR__ . '/Autoload_Warning_Tolerance_Test.php',
        ]);

        try {
            $stale = Manifest_Indexer::_find_stale_classmap_entries($fixture);
            static::__assert_empty($stale, 'No entries should be stale when every file exists');
        } finally {
            @unlink($fixture);
        }
    }

    // -------------------------------------------------------------------------
    // _validate_composer_classmap() - dump seam invocation
    // -------------------------------------------------------------------------

    // Stale classmap -> the dump runner IS invoked, with the stale entries.
    public static function test_validate_invokes_dump_when_stale()
    {
        $missing = storage_path('rsx-tmp/definitely_missing_' . uniqid() . '.php');
        $fixture = static::__write_classmap_fixture([
            'App\\Present_One' => __FILE__,
            'App\\Gone_Model'  => $missing,
        ]);

        $invoked_with = null;
        $saved = Manifest_Indexer::$_composer_dump_runner;
        Manifest_Indexer::$_composer_dump_runner = function ($stale) use (&$invoked_with) {
            $invoked_with = $stale;
        };

        try {
            Manifest_Indexer::_validate_composer_classmap($fixture);

            static::__assert_not_empty($invoked_with, 'Dump runner must be invoked when the classmap is stale');
            static::__assert_true(isset($invoked_with['App\\Gone_Model']), 'Runner must receive the stale entries');
        } finally {
            Manifest_Indexer::$_composer_dump_runner = $saved;
            @unlink($fixture);
        }
    }

    // Clean classmap -> the dump runner is NOT invoked.
    public static function test_validate_skips_dump_when_clean()
    {
        $fixture = static::__write_classmap_fixture([
            'App\\Present_One' => __FILE__,
        ]);

        $invoked = false;
        $saved = Manifest_Indexer::$_composer_dump_runner;
        Manifest_Indexer::$_composer_dump_runner = function ($stale) use (&$invoked) {
            $invoked = true;
        };

        try {
            Manifest_Indexer::_validate_composer_classmap($fixture);
            static::__assert_false($invoked, 'A clean classmap must not trigger a composer dump');
        } finally {
            Manifest_Indexer::$_composer_dump_runner = $saved;
            @unlink($fixture);
        }
    }

    // A missing classmap file -> no-op (no throw, no dump).
    public static function test_validate_noops_on_missing_classmap()
    {
        $invoked = false;
        $saved = Manifest_Indexer::$_composer_dump_runner;
        Manifest_Indexer::$_composer_dump_runner = function ($stale) use (&$invoked) {
            $invoked = true;
        };

        try {
            Manifest_Indexer::_validate_composer_classmap(storage_path('rsx-tmp/no_such_classmap_' . uniqid() . '.php'));
            static::__assert_false($invoked, 'A missing classmap must be a silent no-op');
        } finally {
            Manifest_Indexer::$_composer_dump_runner = $saved;
        }
    }

    /**
    * The classmap heal MUST pass --no-scripts. Composer's stock post-autoload-dump
    * runs `@php artisan package:discover`, a brand-new artisan process spawned
    * mid-pull on a tree being swapped underneath it, with the runtime services
    * stopped - and it cannot inherit the pull's --_framework-update-override (an argv
    * token), so it depends entirely on the maintenance gate's classification to run at
    * all. Any failure there is propagated by composer and the heal throws mid-pull.
    * Nothing else in the codebase can catch a regression here (the runner shells out),
    * so the invariant is asserted against the source.
    */
    public static function test_composer_dump_skips_scripts()
    {
        $reflection = new \ReflectionMethod(Manifest_Indexer::class, '_run_composer_dump');
        $file = file($reflection->getFileName());
        $body = implode('', array_slice(
            $file,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        static::__assert_true(
            str_contains($body, 'composer dump-autoload') && str_contains($body, '--no-scripts'),
            '_run_composer_dump() must invoke composer dump-autoload with --no-scripts (nested artisan is gate-blocked mid-update)'
        );
    }
}
