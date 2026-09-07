<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The static get() misuse guards on Session and Portal_Session.
 *
 * Both classes are Eloquent models, so a bare Session::get('site_id') - which reads
 * like Laravel's session()->get() - previously fell through static magic to Eloquent's
 * Model::get(): a full-table SELECT hydrating every _sessions row (observed OOM at
 * ~54k rows in the wild - see docs.dev/external_requests/
 * 2026_07_13_upload_session_get_oom_session_cleanup.md). The guard converts that
 * time bomb into an instant, self-explaining exception, while leaving legitimate
 * query-builder reads (where(...)->get()) untouched.
 */
class Session_Misuse_Guard_Test extends Rsx_Test_Abstract
{
    public static function test_session_static_get_throws_with_accessor_guidance()
    {
        static::__assert_throws(
            Rsx_Caller_Exception::class,
            function () {
                Session::get('site_id');
            },
            'get_site_id()'
        );
    }

    public static function test_portal_session_static_get_throws_with_accessor_guidance()
    {
        static::__assert_throws(
            Rsx_Caller_Exception::class,
            function () {
                Portal_Session::get('site_id');
            },
            'get_site_id()'
        );
    }

    public static function test_query_builder_get_is_unaffected()
    {
        // The guard shadows ONLY the bare static call. where(...)->get() goes through
        // the Builder instance and must keep working (this is how framework code
        // legitimately queries the sessions table).
        $result = Session::where('id', -1)->get();
        static::__assert_count(0, $result, 'builder get() still executes a normal query');

        // Portal_Session is a facade, not a model - it has no query builder of its own.
        // The rows carrying a portal identity are queried through Session::_portal_query().
        $portal_result = Session::_portal_query()->where('id', -1)->get();
        static::__assert_count(0, $portal_result, 'portal realm builder get() still executes a normal query');
    }
}
