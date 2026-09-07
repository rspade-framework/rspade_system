<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The Manifest build-mode policy helpers are the SINGLE source of truth for every
 * mode-gated decision in the build pipeline (auto-rebuild, minify, sourcemaps,
 * console_debug strip, debug-info injection). This test pins each helper's
 * boolean for all three modes so a future edit to a call site cannot silently drift
 * the policy.
 *
 * The mode is driven through the Rsx::_testing_set_mode() seam - RSX_MODE in .env is
 * never touched - and restored via clear_mode_cache() in a finally block.
 *
 * Pure logic, no DB.
 */
class Policy_Helpers_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Run $fn with the process mode forced to $mode, then restore.
     */
    private static function _with_mode(string $mode, callable $fn): void
    {
        Rsx::_testing_set_mode($mode);
        try {
            $fn();
        } finally {
            Rsx::clear_mode_cache();
        }
    }

    // -------------------------------------------------------------------------
    // _should_auto_rebuild - development only (non-dev ships pre-built bundles)
    // -------------------------------------------------------------------------

    public static function test_auto_rebuild_matrix()
    {
        self::_with_mode(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_true(Manifest::_should_auto_rebuild(), 'dev auto-rebuilds');
        });
        self::_with_mode(Rsx::MODE_DEBUG, function () {
            static::__assert_false(Manifest::_should_auto_rebuild(), 'debug does not auto-rebuild');
        });
        self::_with_mode(Rsx::MODE_PRODUCTION, function () {
            static::__assert_false(Manifest::_should_auto_rebuild(), 'production does not auto-rebuild');
        });
    }

    // -------------------------------------------------------------------------
    // _should_minify - strict production only
    // -------------------------------------------------------------------------

    public static function test_minify_matrix()
    {
        self::_with_mode(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_false(Manifest::_should_minify(), 'dev does not minify');
        });
        self::_with_mode(Rsx::MODE_DEBUG, function () {
            static::__assert_false(Manifest::_should_minify(), 'debug keeps readable code');
        });
        self::_with_mode(Rsx::MODE_PRODUCTION, function () {
            static::__assert_true(Manifest::_should_minify(), 'strict production minifies');
        });
    }

    // -------------------------------------------------------------------------
    // _should_inline_sourcemaps - the logical inverse of _should_minify()
    // -------------------------------------------------------------------------

    public static function test_inline_sourcemaps_matrix()
    {
        self::_with_mode(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_true(Manifest::_should_inline_sourcemaps(), 'dev keeps sourcemaps');
        });
        self::_with_mode(Rsx::MODE_DEBUG, function () {
            static::__assert_true(Manifest::_should_inline_sourcemaps(), 'debug keeps sourcemaps');
        });
        self::_with_mode(Rsx::MODE_PRODUCTION, function () {
            static::__assert_false(Manifest::_should_inline_sourcemaps(), 'strict production strips maps via minify');
        });
    }

    public static function test_inline_sourcemaps_is_inverse_of_minify()
    {
        foreach ([Rsx::MODE_DEVELOPMENT, Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
            self::_with_mode($mode, function () use ($mode) {
                static::__assert_equals(
                    !Manifest::_should_minify(),
                    Manifest::_should_inline_sourcemaps(),
                    "sourcemaps survive exactly when minify does not run ({$mode})"
                );
            });
        }
    }

    // -------------------------------------------------------------------------
    // _should_cache_cdn was DELETED. Mirroring is no longer mode-gated: every mode
    // serves external assets from the same /_vendor/ mirror, so there is no decision
    // left to make and the few remaining sealed-vs-development branches ask
    // Rsx::is_production() directly.
    // -------------------------------------------------------------------------

    public static function test_cache_cdn_helper_is_gone()
    {
        static::__assert_false(
            method_exists(Manifest::class, '_should_cache_cdn'),
            '_should_cache_cdn() must not exist - every mode mirrors, so nothing decides'
        );
    }

    // -------------------------------------------------------------------------
    // _should_strip_console_debug - strict production only
    // -------------------------------------------------------------------------

    public static function test_strip_console_debug_matrix()
    {
        self::_with_mode(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_false(Manifest::_should_strip_console_debug(), 'dev keeps console_debug');
        });
        self::_with_mode(Rsx::MODE_DEBUG, function () {
            static::__assert_false(Manifest::_should_strip_console_debug(), 'debug keeps console_debug');
        });
        self::_with_mode(Rsx::MODE_PRODUCTION, function () {
            static::__assert_true(Manifest::_should_strip_console_debug(), 'strict production strips console_debug');
        });
    }

    // -------------------------------------------------------------------------
    // _should_include_debug_info - development AND debug (NOT strict production)
    // -------------------------------------------------------------------------

    public static function test_include_debug_info_matrix()
    {
        self::_with_mode(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_true(Manifest::_should_include_debug_info(), 'dev injects console_debug config');
        });
        self::_with_mode(Rsx::MODE_DEBUG, function () {
            static::__assert_true(Manifest::_should_include_debug_info(), 'debug injects console_debug config');
        });
        self::_with_mode(Rsx::MODE_PRODUCTION, function () {
            static::__assert_false(Manifest::_should_include_debug_info(), 'strict production omits console_debug config');
        });
    }

    // -------------------------------------------------------------------------
    // _should_merge_bundles was DELETED (merging is BACKLOG, not implemented).
    // -------------------------------------------------------------------------

    public static function test_merge_bundles_helper_is_gone()
    {
        static::__assert_false(
            method_exists(Manifest::class, '_should_merge_bundles'),
            '_should_merge_bundles() must not exist - merging is a backlog item, no dead promise'
        );
    }
}
