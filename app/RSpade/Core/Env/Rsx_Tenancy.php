<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Env;

use RuntimeException;

/**
 * Rsx_Tenancy - whether this install serves one organisation or many.
 *
 * config('rsx.tenancy') is 'single' or 'multi'. It is APPLICATION configuration with no
 * .env key: the framework default is 'single' (system/config/rsx.php), and a multi-tenant
 * application overrides it in rsx/resource/config/rsx.php. Every reader goes through
 * mode(), which refuses a value outside the allowed set by throwing - a typo is never read
 * as one of the valid answers. A blank value is the default.
 *
 * A single-tenant install's tenant is ALWAYS site 1 (SINGLE_TENANT_SITE_ID) - a fixed fact,
 * not a setting. It is also the site the initial user belongs to in either tenancy.
 *
 * Its effect today is the client portal's tenancy: single serves one portal at PORTAL_URL
 * as written, multi requires the tenant placeholder in PORTAL_URL that addresses a portal
 * per site (Rsx_Portal_Url). See rsx:man portal.
 *
 * enforce_settings() is the boot check (Rsx_Framework_Provider::boot()): three config
 * reads and comparisons, every boot, web and CLI.
 */
class Rsx_Tenancy
{
    public const SINGLE = 'single';

    public const MULTI = 'multi';

    /**
     * The tenant of a single-tenant install, the site the initial user belongs to, and the
     * site a sign-in with no other membership lands on. Site 0 is the sessionless
     * infrastructure site and is never a tenant.
     */
    public const SINGLE_TENANT_SITE_ID = 1;

    /**
     * The install's tenancy, 'single' or 'multi'. Blank is the default ('single').
     *
     * @throws RuntimeException naming rsx.tenancy when the value is anything else
     */
    public static function mode(): string
    {
        $value = config('rsx.tenancy');

        if ($value === null || $value === '') {
            return self::SINGLE;
        }

        if ($value !== self::SINGLE && $value !== self::MULTI) {
            throw new RuntimeException(
                'The rsx.tenancy config value must be "single" or "multi" (set in rsx/resource/config/rsx.php).'
                . ' Current value: "' . static::__printable($value) . '". See rsx:man portal.'
            );
        }

        return $value;
    }

    /**
     * True when the install serves many tenants.
     */
    public static function is_multi(): bool
    {
        return static::mode() === self::MULTI;
    }

    /**
     * The boot check: throw on the first tenancy or portal switch whose value is outside
     * its allowed set. PORTAL_URL itself is judged at manifest build
     * (Portal_Url_ManifestSupport), not here.
     *
     * @throws RuntimeException naming the setting
     */
    public static function enforce_settings(): void
    {
        static::mode();
        \App\RSpade\Core\Portal\Rsx_Portal_Url::is_enabled();
        \App\RSpade\Core\Portal\Rsx_Portal_Url::custom_domains();
    }

    /**
     * A configured value as it reads in an error message.
     */
    private static function __printable(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
