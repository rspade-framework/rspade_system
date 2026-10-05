<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * is_framework_developer - the framework-harness gate.
 *
 * Declared on Permission_Abstract, so a STAFF check. Its answer is a property of the
 * INSTALL (config('rsx.code_quality.is_framework_developer'), fed by IS_FRAMEWORK_DEVELOPER),
 * never of the visitor: anonymous or signed in, every caller passes on a framework-development
 * box and every caller is refused anywhere else. It gates the harness pages the framework's own
 * http tests drive (/ssr-test).
 */
class Auth_Is_Framework_Developer_Check_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * AG-FWDEV-01 - in the staff registry, resolving to the framework base; absent from portal.
     */
    public static function test_is_framework_developer_is_a_staff_check()
    {
        $checks = Auth_Gates::get_checks(Auth_Gates::REALM_STAFF);

        static::__assert_array_has_key('is_framework_developer', $checks);
        static::__assert_equals(
            'App\\RSpade\\Core\\Permission\\Permission_Abstract',
            $checks['is_framework_developer']['class']
        );

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Auth_Gates::evaluate('is_framework_developer', Auth_Gates::REALM_PORTAL)
        );
    }

    /**
     * AG-FWDEV-02 - the answer follows the install flag, for an anonymous caller.
     */
    public static function test_is_framework_developer_follows_the_install_flag()
    {
        static::__reset_session();
        $saved = config('rsx.code_quality.is_framework_developer');

        try {
            config(['rsx.code_quality.is_framework_developer' => false]);
            static::__assert_false(Auth_Gates::evaluate('is_framework_developer', Auth_Gates::REALM_STAFF), 'an ordinary install refuses');

            config(['rsx.code_quality.is_framework_developer' => true]);
            static::__assert_true(Auth_Gates::evaluate('is_framework_developer', Auth_Gates::REALM_STAFF), 'a framework-development box admits');
        } finally {
            config(['rsx.code_quality.is_framework_developer' => $saved]);
        }
    }
}
