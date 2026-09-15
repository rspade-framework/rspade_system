<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Build;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Prod\Rsx_Build_Context;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The seal gate: what a production-like box does when it has no build to serve.
 *
 * Manifest::init() refuses in a production mode when either half of the deployment
 * artifact is absent - the manifest index, or the seal that says a build completed -
 * unless the process IS the build. Manifest::__production_build_is_unusable() is that
 * same predicate, asked by code that runs before any command boots, so it is the honest
 * unit-level subject: init() itself is a once-per-process side effect that would rebuild
 * this box's real index.
 *
 * The end-to-end half - a real unsealed box answering 500 to a web request and exit 1 to
 * rsx:health - is tests/prod_mode/cli/prod_lifecycle.sh, which is where removing a real
 * seal file belongs.
 *
 * Every seam here is scratch: a throwaway build root through
 * Rsx_Project_Paths::_override(['build' => ...]) plus a Manifest_Build pointed at the same
 * root (the memoized one is the developer's), and the mode through
 * Rsx::_testing_set_mode(). RSX_MODE and the real build tree are never touched.
 *
 * Pure logic, no DB.
 */
class Seal_Gate_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Run $fn against an empty scratch build root with the process mode forced.
     *
     * $fn receives the root. Everything the closure leaves behind is removed.
     */
    private static function _with(string $mode, callable $fn): void
    {
        $root = sys_get_temp_dir() . '/rsx_seal_gate_' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);

        $saved_build = Manifest::$_build;

        Rsx_Project_Paths::_override(['build' => $root]);
        Manifest::$_build = new Manifest_Build([], $root, $mode);
        Rsx::_testing_set_mode($mode);
        Rsx_Build_Context::_testing_reset();
        Rsx_Prod_Seal::_reset_cache();

        try {
            $fn($root);
        } finally {
            Rsx_Build_Context::_testing_reset();
            Rsx_Prod_Seal::_testing_reset();
            Rsx::clear_mode_cache();
            Manifest::$_build = $saved_build;
            Rsx_Project_Paths::_clear_overrides();
            self::__remove_tree($root);
        }
    }

    /** Remove a scratch tree without going through the guarded helper. */
    private static function __remove_tree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::__remove_tree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /** Put a manifest index in the scratch root (content is irrelevant - presence is the question). */
    private static function _stage_index(string $root): void
    {
        file_put_contents($root . '/' . Manifest::CACHE_FILE, "<?php return [];\n");
    }

    /** Put a seal file in the scratch root. */
    private static function _stage_seal(string $root): void
    {
        file_put_contents($root . '/' . Rsx_Prod_Seal::SEAL_FILENAME, json_encode(['mode' => 'production']));
        Rsx_Prod_Seal::_reset_cache();
    }

    // -------------------------------------------------------------------------
    // The message
    // -------------------------------------------------------------------------

    public static function test_the_refusal_states_the_condition_and_the_remedy()
    {
        static::__assert_contains('unsealed', Manifest::UNSEALED_BUILD_MESSAGE, 'the refusal names the condition');
        static::__assert_contains('rsx:build --force', Manifest::UNSEALED_BUILD_MESSAGE, 'and the command that repairs it');
        static::__assert_contains('rsx:man prod', Manifest::UNSEALED_BUILD_MESSAGE, 'and where the whole procedure is written down');
    }

    // -------------------------------------------------------------------------
    // Both halves are required
    // -------------------------------------------------------------------------

    public static function test_a_production_box_with_no_build_at_all_is_unusable()
    {
        self::_with(Rsx::MODE_PRODUCTION, function () {
            static::__assert_true(
                Manifest::__production_build_is_unusable(),
                'no index and no seal is a broken deployment, not a box that builds one for itself'
            );
        });
    }

    public static function test_an_index_without_a_seal_is_unusable()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            self::_stage_index($root);

            static::__assert_true(
                Manifest::__production_build_is_unusable(),
                'an index left behind by an interrupted build is not a completed build'
            );
        });
    }

    public static function test_a_seal_without_an_index_is_unusable()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            self::_stage_seal($root);

            static::__assert_true(
                Manifest::__production_build_is_unusable(),
                'a seal describing assets that are gone is not a build either'
            );
        });
    }

    public static function test_both_halves_present_is_a_box_that_serves()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            self::_stage_index($root);
            self::_stage_seal($root);

            static::__assert_false(
                Manifest::__production_build_is_unusable(),
                'index plus seal is exactly what rsx:build leaves behind'
            );
        });
    }

    // -------------------------------------------------------------------------
    // Who is exempt
    // -------------------------------------------------------------------------

    public static function test_the_build_is_never_refused_its_own_empty_tree()
    {
        self::_with(Rsx::MODE_PRODUCTION, function () {
            Rsx_Build_Context::begin();

            static::__assert_false(
                Manifest::__production_build_is_unusable(),
                'the process producing the artifact cannot be gated on the artifact existing'
            );
        });
    }

    public static function test_development_never_refuses()
    {
        self::_with(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_false(
                Manifest::__production_build_is_unusable(),
                'development builds what it finds missing, every request'
            );
        });
    }

    public static function test_debug_is_gated_exactly_like_production()
    {
        self::_with(Rsx::MODE_DEBUG, function () {
            static::__assert_true(
                Manifest::__production_build_is_unusable(),
                'debug is a sealed build too - the only difference is what is stripped'
            );
        });
    }

    // -------------------------------------------------------------------------
    // The escape hatches: recovery must work ON the broken box
    // -------------------------------------------------------------------------

    public static function test_the_mode_and_introspection_commands_skip_the_manifest()
    {
        $exempt = ['rsx:prod:enable', 'rsx:prod:disable', 'rsx:mode:set', 'rsx:clean', 'rsx:man', 'list', 'help'];

        foreach ($exempt as $command) {
            static::__assert_true(
                self::_skips_manifest_boot($command),
                $command . ' must run on a box that has no build - it is how the box gets one'
            );
        }
    }

    public static function test_every_other_command_faces_the_gate()
    {
        foreach (['migrate', 'rsx:health', 'rsx:test', 'rsx:prod:verify'] as $command) {
            static::__assert_false(
                self::_skips_manifest_boot($command),
                $command . ' reads manifest data, so it must not run against a build that is not there'
            );
        }
    }

    public static function test_a_bare_artisan_invocation_lists_commands_rather_than_refusing()
    {
        $saved = $_SERVER['argv'];
        $_SERVER['argv'] = ['artisan'];

        try {
            static::__assert_true(Manifest::__cli_skips_manifest_boot(), 'the command list is how an operator finds the remedy');
        } finally {
            $_SERVER['argv'] = $saved;
        }
    }

    /** Ask __cli_skips_manifest_boot() about one command name. */
    private static function _skips_manifest_boot(string $command): bool
    {
        $saved = $_SERVER['argv'];
        $_SERVER['argv'] = ['artisan', $command];

        try {
            return Manifest::__cli_skips_manifest_boot();
        } finally {
            $_SERVER['argv'] = $saved;
        }
    }
}
