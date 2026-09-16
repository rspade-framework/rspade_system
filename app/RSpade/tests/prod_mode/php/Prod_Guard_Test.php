<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Prod\Rsx_Build_Context;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The build-tree write guard (Rsx_Project_Paths::assert_build_writable).
 *
 * The guard keys on the MODE and the build context, and a seal is not required for it
 * to refuse: an unsealed production box is a broken deployment, and an arbitrary
 * command writing into it is how it stays broken. A seal ON DISK refuses too, whatever
 * mode the process memoized at boot - a request that outlives rsx:mode:set prod must
 * not compile into the sealed tree. These tests pin exactly that - an unsealed
 * production mode is guarded, a sealed tree guards a development process, the build
 * context passes, development with no seal is a no-op, and a path outside the build
 * tree is none of the guard's business.
 *
 * The build root is redirected with Rsx_Project_Paths::_override(['build' => ...]) so no
 * real artifact is named, and the mode rides the Rsx::_testing_set_mode() seam (RSX_MODE
 * in .env is never touched). Every seam is restored in a finally block.
 *
 * Pure logic, no DB.
 */
class Prod_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Run $fn with a throwaway build root and the process mode forced, then restore.
     */
    private static function _with(string $mode, callable $fn): void
    {
        $root = sys_get_temp_dir() . '/rsx_guard_' . bin2hex(random_bytes(8));

        Rsx_Project_Paths::_override(['build' => $root]);
        Rsx::_testing_set_mode($mode);
        Rsx_Build_Context::_testing_reset();

        try {
            $fn($root);
        } finally {
            Rsx_Build_Context::_testing_reset();
            Rsx::clear_mode_cache();
            Rsx_Project_Paths::_clear_overrides();
        }
    }

    // -------------------------------------------------------------------------
    // Development -> always writable (the JIT mode rebuilds on demand)
    // -------------------------------------------------------------------------

    public static function test_development_is_never_guarded()
    {
        self::_with(Rsx::MODE_DEVELOPMENT, function ($root) {
            Rsx_Project_Paths::assert_build_writable($root . '/bundles/x.js', 'test');
            static::__pass();
        });
    }

    // -------------------------------------------------------------------------
    // Production without a seal -> STILL guarded (the whole point of the rework)
    // -------------------------------------------------------------------------

    public static function test_an_unsealed_production_box_is_guarded()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            static::__assert_false(
                is_file($root . '/prod_seal.json'),
                'this box has no seal at all - and is guarded anyway'
            );

            $exception = static::__assert_throws(
                \RuntimeException::class,
                fn () => Rsx_Project_Paths::assert_build_writable($root . '/bundles/x.js', 'test-write'),
                'is a build artifact'
            );

            static::__assert_contains('rsx:build --force', $exception->getMessage(), 'the refusal names the remedy');
        });
    }

    public static function test_debug_mode_is_guarded_too()
    {
        self::_with(Rsx::MODE_DEBUG, function ($root) {
            static::__assert_throws(
                \RuntimeException::class,
                fn () => Rsx_Project_Paths::assert_build_writable($root . '/manifest_index.php', 'test-write'),
                'is a build artifact'
            );
        });
    }

    // -------------------------------------------------------------------------
    // A seal on disk guards a process that memoized development at boot
    // -------------------------------------------------------------------------

    public static function test_a_seal_on_disk_guards_a_development_process()
    {
        self::_with(Rsx::MODE_DEVELOPMENT, function ($root) {
            mkdir($root, 0755, true);
            file_put_contents($root . '/prod_seal.json', '{}');

            try {
                $exception = static::__assert_throws(
                    \RuntimeException::class,
                    fn () => Rsx_Project_Paths::assert_build_writable($root . '/bundles/x.js', 'test-write'),
                    'is a build artifact'
                );

                static::__assert_contains('the build tree is sealed', $exception->getMessage(), 'the refusal says why a development process was refused');
                static::__assert_contains('rsx:build --force', $exception->getMessage(), 'the refusal names the remedy');
            } finally {
                unlink($root . '/prod_seal.json');
                rmdir($root);
            }
        });
    }

    // -------------------------------------------------------------------------
    // The build context is the ONE key
    // -------------------------------------------------------------------------

    public static function test_the_build_context_may_write()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            Rsx_Build_Context::begin();

            Rsx_Project_Paths::assert_build_writable($root . '/bundles/x.js', 'build-write');
            static::__pass();
        });
    }

    // -------------------------------------------------------------------------
    // Scope: the build tree and nothing else
    // -------------------------------------------------------------------------

    public static function test_a_path_outside_the_build_tree_is_not_the_guards_business()
    {
        self::_with(Rsx::MODE_PRODUCTION, function () {
            Rsx_Project_Paths::assert_build_writable('/var/log/somewhere.txt', 'unrelated-write');
            static::__pass();
        });
    }

    // -------------------------------------------------------------------------
    // rsx:clean's own refusal asks the same two questions
    // -------------------------------------------------------------------------

    public static function test_clean_refuses_in_a_production_mode_without_force()
    {
        self::_with(Rsx::MODE_PRODUCTION, function () {
            static::__assert_true(
                Rsx::is_production() && !Rsx_Build_Context::is_active(),
                'rsx:clean would demand --force'
            );

            Rsx_Build_Context::begin();

            static::__assert_true(
                Rsx_Build_Context::is_active(),
                'a build cleans without asking - it is the command that rebuilds what it discards'
            );
        });
    }
}
