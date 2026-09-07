<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit coverage for the immutability guard (Rsx_Prod_Seal::assert_mutable) and the
 * authorization mechanism. Uses the is_sealed()/root seams so no real .env or real
 * build root is touched; authorization is granted via authorize_process() (never an
 * env variable - authorization is an invocation parameter) and always cleared by
 * _testing_reset() in a finally block.
 *
 * The rsx:clean sealed-refusal is a direct is_sealed() check in the command;
 * is_sealed() gating is proven here and the full command refusal is exercised by
 * the batch E2E.
 *
 * Pure logic, no DB.
 */
class Prod_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // Not sealed -> always mutable (the common, cheap path)
    // -------------------------------------------------------------------------

    public static function test_assert_mutable_passes_when_not_sealed()
    {
        try {
            Rsx_Prod_Seal::_testing_set_sealed(false);
            // Any path is fine when not sealed - no exception.
            Rsx_Prod_Seal::assert_mutable('/anything/at/all.txt', 'test');
            static::__pass();
        } finally {
            Rsx_Prod_Seal::_testing_reset();
        }
    }

    // -------------------------------------------------------------------------
    // Sealed + unauthorized -> refuse writes under the build root
    // -------------------------------------------------------------------------

    public static function test_assert_mutable_throws_when_sealed_and_unauthorized()
    {
        $root = sys_get_temp_dir() . '/rsx_guard_' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        try {
            // Clean slate: no authorization carried over from a prior test.
            Rsx_Prod_Seal::_testing_reset();
            Rsx_Prod_Seal::_testing_set_root($root);
            Rsx_Prod_Seal::_testing_set_sealed(true);

            static::__assert_throws(
                \RuntimeException::class,
                fn () => Rsx_Prod_Seal::assert_mutable($root . '/bundles/x.js', 'test-write'),
                'immutable prod build asset'
            );
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            rmdir($root);
        }
    }

    // -------------------------------------------------------------------------
    // Sealed + authorized -> permitted (the rebuild path)
    // -------------------------------------------------------------------------

    public static function test_assert_mutable_passes_when_authorized()
    {
        $root = sys_get_temp_dir() . '/rsx_guard_' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        try {
            Rsx_Prod_Seal::_testing_set_root($root);
            Rsx_Prod_Seal::_testing_set_sealed(true);
            Rsx_Prod_Seal::authorize_process();

            // Authorized: no exception even for a path under the build root.
            Rsx_Prod_Seal::assert_mutable($root . '/bundles/x.js', 'authorized-write');
            static::__pass();
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            rmdir($root);
        }
    }

    // -------------------------------------------------------------------------
    // Sealed + unauthorized but path OUTSIDE the build root -> permitted
    // -------------------------------------------------------------------------

    public static function test_assert_mutable_ignores_paths_outside_build_root()
    {
        $root = sys_get_temp_dir() . '/rsx_guard_' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        try {
            Rsx_Prod_Seal::_testing_reset();
            Rsx_Prod_Seal::_testing_set_root($root);
            Rsx_Prod_Seal::_testing_set_sealed(true);

            // A path that is not under the build root is not a build asset.
            Rsx_Prod_Seal::assert_mutable('/var/log/somewhere.txt', 'unrelated-write');
            static::__pass();
        } finally {
            Rsx_Prod_Seal::_testing_reset();
            rmdir($root);
        }
    }

    // -------------------------------------------------------------------------
    // is_authorized requires an explicit authorization (CLI test SAPI already
    // satisfies the SAPI half; the test process argv carries no --authorized flag)
    // -------------------------------------------------------------------------

    public static function test_is_authorized_requires_authorization()
    {
        try {
            Rsx_Prod_Seal::_testing_reset();
            static::__assert_false(
                Rsx_Prod_Seal::is_authorized(),
                'no authorization -> not authorized'
            );

            Rsx_Prod_Seal::authorize_process();
            static::__assert_true(
                Rsx_Prod_Seal::is_authorized(),
                'authorize_process() -> authorized in CLI'
            );
        } finally {
            Rsx_Prod_Seal::_testing_reset();
        }
    }

    // -------------------------------------------------------------------------
    // The rsx:clean guard decision is is_sealed(): prove it flips with the seam.
    // -------------------------------------------------------------------------

    public static function test_clean_guard_decision_follows_is_sealed()
    {
        try {
            Rsx_Prod_Seal::_testing_set_sealed(true);
            static::__assert_true(Rsx_Prod_Seal::is_sealed(), 'rsx:clean would refuse when sealed');

            Rsx_Prod_Seal::_testing_set_sealed(false);
            static::__assert_false(Rsx_Prod_Seal::is_sealed(), 'rsx:clean would proceed when not sealed');
        } finally {
            Rsx_Prod_Seal::_testing_reset();
        }
    }
}
