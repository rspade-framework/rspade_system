<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\FrameworkUpdate\Php;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Manifest\Manifest_Store;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Guards for the "fresh manifest cache + missing Phase-6 stub output" field bug.
 *
 * After a framework-update recovery the js-model-stubs directory was ABSENT while
 * the manifest cache was present and validated as fresh, so manifest build was a
 * cache hit (stub generation never ran) and bundle compile emitted ZERO Base_*
 * model stubs while reporting success - the client-side model layer dead app-wide.
 *
 * Two independent fail-loud guards close it, each unit tested here with synthetic
 * inputs + a real temp file (no full manifest/bundle build required):
 *   Fix 1: Manifest_Store::_first_missing_stub_output() - the dev cache
 *          validation seam that marks the cache STALE when a recorded stub output
 *          is gone.
 *   Fix 2: BundleCompiler::_assert_stub_output_present() - the compile choke point
 *          that throws instead of silently omitting a missing manifest-referenced
 *          stub.
 */
class Framework_Stub_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // A path (relative to base_path()) that is guaranteed NOT to exist on disk.
    private const MISSING_MODEL_STUB = 'storage/rsx-build/js-model-stubs/base-does-not-exist-xyzzy-model.js';
    private const MISSING_CONTROLLER_STUB = 'storage/rsx-build/js-stubs/does_not_exist_xyzzy_controller.js';
    // A stub path under a directory that itself does not exist (whole-dir-absent case).
    private const MISSING_STUB_DIR = 'storage/rsx-build/js-model-stubs-gone-xyzzy/base-user-model.js';

    // =====================================================================
    // Fix 1: _first_missing_stub_output
    // =====================================================================

    // All recorded stub outputs present -> null (cache stays valid).
    public static function test_all_stub_outputs_present_returns_null()
    {
        $present_rel = static::__make_temp_stub();
        try {
            $files = [
                'rsx/models/user_model.php' => ['extension' => 'php', 'class' => 'User_Model'],
                $present_rel => ['extension' => 'js', 'class' => 'Base_User_Model', 'is_model_stub' => true],
            ];

            static::__assert_null(
                Manifest_Store::_first_missing_stub_output($files),
                'A files map whose stub outputs all exist must report no missing stub'
            );
        } finally {
            static::__unlink_temp_stub($present_rel);
        }
    }

    // Empty / stub-less map -> null (nothing to prove).
    public static function test_no_stub_entries_returns_null()
    {
        $files = [
            'rsx/models/user_model.php' => ['extension' => 'php', 'class' => 'User_Model'],
            'rsx/app/frontend/x.js'     => ['extension' => 'js', 'class' => 'X'],
        ];

        static::__assert_null(Manifest_Store::_first_missing_stub_output($files));
    }

    // A recorded model stub missing from disk -> its path is returned (cache STALE).
    public static function test_missing_model_stub_is_detected()
    {
        $files = [
            self::MISSING_MODEL_STUB => ['extension' => 'js', 'class' => 'Base_Does_Not_Exist_Xyzzy_Model', 'is_model_stub' => true],
        ];

        static::__assert_equals(
            self::MISSING_MODEL_STUB,
            Manifest_Store::_first_missing_stub_output($files),
            'A recorded model stub absent from disk must be reported'
        );
    }

    // A recorded controller stub (is_stub) missing from disk -> reported too.
    public static function test_missing_controller_stub_is_detected()
    {
        $files = [
            self::MISSING_CONTROLLER_STUB => ['extension' => 'js', 'class' => 'Does_Not_Exist_Xyzzy_Controller', 'is_stub' => true],
        ];

        static::__assert_equals(
            self::MISSING_CONTROLLER_STUB,
            Manifest_Store::_first_missing_stub_output($files)
        );
    }

    // Whole js-model-stubs dir absent -> the entry resolves independently and is reported.
    public static function test_absent_stub_directory_is_detected()
    {
        $files = [
            self::MISSING_STUB_DIR => ['extension' => 'js', 'class' => 'Base_User_Model', 'is_model_stub' => true],
        ];

        static::__assert_equals(
            self::MISSING_STUB_DIR,
            Manifest_Store::_first_missing_stub_output($files),
            'A stub under a missing directory must be reported (catches whole-dir absence)'
        );
    }

    // A NON-stub file missing from disk must NOT be reported by this guard (it is the
    // deletion sweep's job, and storage/ entries are intentionally exempt there).
    public static function test_missing_non_stub_file_is_ignored()
    {
        $files = [
            'storage/rsx-build/js-model-stubs/some-plain-file.js' => ['extension' => 'js', 'class' => 'Plain'],
        ];

        static::__assert_null(
            Manifest_Store::_first_missing_stub_output($files),
            'Entries without is_stub/is_model_stub must be ignored by the stub-output guard'
        );
    }

    // First missing stub short-circuits (present entry before, missing entry after).
    public static function test_returns_first_missing_when_mixed()
    {
        $present_rel = static::__make_temp_stub();
        try {
            $files = [
                $present_rel             => ['extension' => 'js', 'class' => 'Base_Present', 'is_model_stub' => true],
                self::MISSING_MODEL_STUB => ['extension' => 'js', 'class' => 'Base_Gone', 'is_model_stub' => true],
            ];

            static::__assert_equals(
                self::MISSING_MODEL_STUB,
                Manifest_Store::_first_missing_stub_output($files)
            );
        } finally {
            static::__unlink_temp_stub($present_rel);
        }
    }

    // =====================================================================
    // Fix 2: BundleCompiler::_assert_stub_output_present
    // =====================================================================

    // A present stub passes silently (no throw).
    public static function test_compiler_guard_passes_for_present_stub()
    {
        $present_rel = static::__make_temp_stub();
        try {
            BundleCompiler::_assert_stub_output_present($present_rel, 'rsx/models/user_model.php');
            static::__pass('Present stub must not throw');
        } finally {
            static::__unlink_temp_stub($present_rel);
        }
    }

    // A missing manifest-referenced stub throws (the silent-omission bite point).
    public static function test_compiler_guard_throws_for_missing_stub()
    {
        static::__assert_throws(\RuntimeException::class, function () {
            BundleCompiler::_assert_stub_output_present(self::MISSING_MODEL_STUB, 'rsx/models/does_not_exist_model.php');
        }, 'missing from disk');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    // Create a real temp file under storage/rsx-tmp and return its project-logical
    // path (so rsx_project_file_path($rel) resolves to it - storage lives at the
    // project root, NOT under base_path()).
    private static function __make_temp_stub(): string
    {
        $rel = 'storage/rsx-tmp/stub_guard_test_' . uniqid() . '.js';
        file_put_contents(rsx_project_file_path($rel), "// temp stub for stub-guard test\n");

        return $rel;
    }

    private static function __unlink_temp_stub(string $rel): void
    {
        $abs = rsx_project_file_path($rel);
        if (file_exists($abs)) {
            unlink($abs);
        }
    }
}
