<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Portal\Php;

use App\RSpade\Core\Auth\Auth_Gates;

/**
 * A controllable stand-in for the staff realm's `can_impersonate` check.
 *
 * The framework declares can_impersonate DENYING (Permission_Abstract) and an application
 * redeclares it with its own rule, so a framework test cannot rely on any particular
 * application's answer. install() swaps this method in for that one name in the staff
 * registry (Auth_Gates::_set_index_for_testing), every other check unchanged; $grant is the
 * answer. Deliberately not an #[Auth_Check]: it never enters the real registry.
 */
class Portal_Impersonation_Grant_Fixture
{
    public static bool $grant = true;

    public static function can_impersonate(): bool
    {
        return static::$grant;
    }

    /**
     * Route can_impersonate here, answering $grant.
     */
    public static function install(bool $grant = true): void
    {
        static::$grant = $grant;

        $checks = Auth_Gates::get_index()['checks'] ?? [];
        $checks[Auth_Gates::REALM_STAFF]['can_impersonate'] = [
            'class' => static::class,
            'method' => 'can_impersonate',
            'file' => __FILE__,
        ];

        Auth_Gates::_set_index_for_testing($checks, null);
    }

    public static function uninstall(): void
    {
        Auth_Gates::_reset_for_testing();
        static::$grant = true;
    }
}
