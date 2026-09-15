<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Prod\Rsx_Build_Context;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Build_Context - the single answer to "is this process producing the build tree?"
 *
 * Three things are worth pinning. A WEB REQUEST CAN NEVER BE ONE: the SAPI check is
 * first and unconditional, so nothing a browser sends makes a served request eligible to
 * rewrite what it is serving. The INTERNAL FLAG is honoured, because that is how a
 * subprocess of the build inherits the context (argv-stripped pre-boot, so it is read
 * through Rsx_Internal_Flags and never through $this->option()). And the CDN download
 * policy that rides on the same distinction - in a production mode only the build phase
 * may populate the mirror, while development populates it on demand.
 *
 * Pure logic, no DB.
 */
class Build_Context_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function teardown()
    {
        Rsx_Build_Context::_testing_reset();
        Rsx_Internal_Flags::clear(Rsx_Build_Context::FLAG);
    }

    // -------------------------------------------------------------------------
    // The SAPI floor
    // -------------------------------------------------------------------------

    public static function test_a_web_request_can_never_be_a_build_context()
    {
        $source = file_get_contents(
            base_path('app/RSpade/Core/Prod/Rsx_Build_Context.php')
        );

        $position_of_sapi_check = strpos($source, "PHP_SAPI !== 'cli'");
        $position_of_in_process = strpos($source, 'if (self::$active)');
        $position_of_flag = strpos($source, 'Rsx_Internal_Flags::has(self::FLAG)');

        static::__assert_true($position_of_sapi_check !== false, 'the SAPI check exists');
        static::__assert_true(
            $position_of_sapi_check < $position_of_in_process && $position_of_sapi_check < $position_of_flag,
            'it comes before every other way of granting the context, so no flag and no '
            . 'in-process call can make a served request a build'
        );
    }

    // -------------------------------------------------------------------------
    // The three grants
    // -------------------------------------------------------------------------

    public static function test_the_context_is_off_until_something_declares_it()
    {
        Rsx_Build_Context::_testing_reset();
        Rsx_Internal_Flags::clear(Rsx_Build_Context::FLAG);

        static::__assert_false(
            Rsx_Build_Context::is_active(),
            'an ordinary command is not a build'
        );
        static::__assert_equals(
            [],
            Rsx_Build_Context::child_flags(),
            'and it hands nothing to its children'
        );
    }

    public static function test_begin_declares_this_process()
    {
        Rsx_Build_Context::_testing_reset();

        Rsx_Build_Context::begin();

        static::__assert_true(Rsx_Build_Context::is_active(), 'begin() is the in-process declaration');
        static::__assert_equals(
            [Rsx_Build_Context::FLAG],
            Rsx_Build_Context::child_flags(),
            'an active context travels to every subprocess it spawns'
        );
    }

    public static function test_the_internal_flag_is_honoured()
    {
        Rsx_Build_Context::_testing_reset();

        Rsx_Internal_Flags::set(Rsx_Build_Context::FLAG);

        try {
            static::__assert_true(
                Rsx_Build_Context::is_active(),
                'a subprocess of the build carries the context on its argv'
            );
        } finally {
            Rsx_Internal_Flags::clear(Rsx_Build_Context::FLAG);
        }
    }

    public static function test_the_flag_follows_the_internal_convention()
    {
        static::__assert_true(
            str_starts_with(Rsx_Build_Context::FLAG, '--_'),
            'framework-internal flags are --_ prefixed, so they render in no help output '
            . 'and can never raise an unknown-option error'
        );
    }

    // -------------------------------------------------------------------------
    // What the context buys: the CDN download policy
    // -------------------------------------------------------------------------

    public static function test_the_cdn_policy_per_mode()
    {
        static::__assert_true(
            Cdn_Cache::_download_is_permitted(false, false),
            'development downloads on demand - it compiles on demand'
        );

        static::__assert_false(
            Cdn_Cache::_download_is_permitted(true, false),
            'a production mode outside the build never downloads - the store is a source '
            . 'artifact and a miss means the build did not mirror it'
        );

        static::__assert_true(
            Cdn_Cache::_download_is_permitted(true, true),
            'the build phase is what populates the mirror'
        );
    }
}
