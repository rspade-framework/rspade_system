<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit coverage for Rsx_Prod_Seal: write/read/verify + drift detection + the
 * is_sealed() gate. A throwaway build root is injected via the class seam so no
 * test ever touches the real storage/rsx-build, and every seam is restored in a
 * finally block.
 *
 * Pure logic, no DB.
 */
class Prod_Seal_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Stage a fake build root (manifest_data.php, build_key, one bundle file) and
     * point Rsx_Prod_Seal at it. Returns the root path.
     */
    private static function _stage_build_root(): string
    {
        $root = sys_get_temp_dir() . '/rsx_seal_' . bin2hex(random_bytes(8));
        mkdir($root . '/bundles', 0777, true);
        file_put_contents($root . '/manifest_data.php', "<?php\nreturn ['ok' => true];\n");
        file_put_contents($root . '/build_key', "deadbeefcafef00d\n");
        file_put_contents($root . '/bundles/Foo__app.abc123.js', "console.log(1);\n");

        Rsx_Prod_Seal::_testing_set_root($root);

        return $root;
    }

    private static function _rmtree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // write / read
    // -------------------------------------------------------------------------

    public static function test_write_records_all_assets_and_metadata()
    {
        $root = self::_stage_build_root();
        try {
            $seal = Rsx_Prod_Seal::write('production');

            static::__assert_equals('production', $seal['rsx_mode']);
            static::__assert_equals('deadbeefcafef00d', $seal['build_key'], 'build_key comes from the on-disk file');
            // manifest_data.php + build_key + one bundle file = 3 assets.
            static::__assert_count(3, $seal['assets'], 'seal records every build asset');
            static::__assert_not_empty($seal['created_at']);

            // Read back independently.
            $read = Rsx_Prod_Seal::read();
            static::__assert_equals($seal['build_key'], $read['build_key'], 'read() round-trips write()');
            static::__assert_true(Rsx_Prod_Seal::exists(), 'seal file exists after write');
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            self::_rmtree($root);
        }
    }

    // -------------------------------------------------------------------------
    // verify - happy + drift
    // -------------------------------------------------------------------------

    public static function test_verify_clean_when_untampered()
    {
        $root = self::_stage_build_root();
        try {
            Rsx_Prod_Seal::write('production');
            $drift = Rsx_Prod_Seal::verify();
            // Mode drift is expected under the test process (RSX_MODE=development vs
            // sealed 'production'); assets + build_key must be clean.
            foreach ($drift as $finding) {
                static::__assert_true(
                    str_contains($finding, 'Mode mismatch'),
                    "unexpected drift finding: {$finding}"
                );
            }
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            self::_rmtree($root);
        }
    }

    public static function test_verify_detects_missing_asset()
    {
        $root = self::_stage_build_root();
        try {
            Rsx_Prod_Seal::write('production');
            unlink($root . '/bundles/Foo__app.abc123.js');

            $drift = Rsx_Prod_Seal::verify();
            $has_missing = false;
            foreach ($drift as $finding) {
                if (str_contains($finding, 'Missing asset')) {
                    $has_missing = true;
                }
            }
            static::__assert_true($has_missing, 'verify() must flag a deleted asset');
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            self::_rmtree($root);
        }
    }

    public static function test_verify_detects_hash_mismatch()
    {
        $root = self::_stage_build_root();
        try {
            Rsx_Prod_Seal::write('production');
            file_put_contents($root . '/bundles/Foo__app.abc123.js', "console.log(999);\n");

            $drift = Rsx_Prod_Seal::verify();
            $has_mismatch = false;
            foreach ($drift as $finding) {
                if (str_contains($finding, 'Hash mismatch')) {
                    $has_mismatch = true;
                }
            }
            static::__assert_true($has_mismatch, 'verify() must flag a tampered asset');
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            self::_rmtree($root);
        }
    }

    public static function test_verify_detects_build_key_mismatch()
    {
        $root = self::_stage_build_root();
        try {
            Rsx_Prod_Seal::write('production');
            file_put_contents($root . '/build_key', "0000000000000000\n");

            $drift = Rsx_Prod_Seal::verify();
            $has_key_drift = false;
            foreach ($drift as $finding) {
                if (str_contains($finding, 'Build key mismatch')) {
                    $has_key_drift = true;
                }
            }
            static::__assert_true($has_key_drift, 'verify() must flag a build_key change');
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            self::_rmtree($root);
        }
    }

    public static function test_verify_reports_when_no_seal()
    {
        $root = self::_stage_build_root();
        try {
            // No write() call - no seal file present.
            $drift = Rsx_Prod_Seal::verify();
            static::__assert_count(1, $drift);
            static::__assert_true(str_contains($drift[0], 'No seal present'));
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            self::_rmtree($root);
        }
    }

    // -------------------------------------------------------------------------
    // is_sealed gate: BOTH a seal file AND a production-like mode are required
    // -------------------------------------------------------------------------

    public static function test_is_sealed_reflects_mode_when_seal_present()
    {
        $root = self::_stage_build_root();
        try {
            Rsx_Prod_Seal::write('production');
            // The seal file exists...
            static::__assert_true(Rsx_Prod_Seal::exists(), 'seal file is present');
            // ...so is_sealed() must mirror the MODE half of the AND gate exactly:
            // sealed iff a production-like RSX_MODE is active. Written mode-agnostic
            // so it holds whether the suite runs in development or production.
            static::__assert_equals(
                \App\RSpade\Core\Rsx::is_production(),
                Rsx_Prod_Seal::is_sealed(),
                'with a seal file present, is_sealed() must equal Rsx::is_production()'
            );
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            self::_rmtree($root);
        }
    }

    public static function test_is_sealed_false_without_seal_file()
    {
        $root = self::_stage_build_root();
        try {
            static::__assert_false(Rsx_Prod_Seal::is_sealed(), 'no seal file -> not sealed');
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            self::_rmtree($root);
        }
    }
}
