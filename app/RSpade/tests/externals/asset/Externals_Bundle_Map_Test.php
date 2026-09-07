<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Externals\Asset;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The BAKED half of the registry: BundleCompiler::_create_javascript_externals() writes the
 * realm-resolved identifier map into every bundle's tail as Rsx_External_Resources._define(),
 * which is the only thing that makes Rsx.load_external('turnstile') resolvable in the browser.
 *
 * Two properties are asserted against the COMPILED bundles in storage/rsx-build/bundles (dev
 * JIT keeps them current on this box, per the prod_mode emission-order precedent):
 *
 * - the define call is present, carries the framework's own turnstile declaration, and the
 *   entry is shaped the way the loader indexes it (integrity is an OBJECT keyed by URL, never
 *   an empty JSON array);
 * - the realm baked into a portal bundle is 'portal' and into a staff bundle 'staff' - a
 *   bundle's realm is DERIVED from whether it includes #[Portal_Route] controllers, so a
 *   regression there would silently hand one realm the other's declarations.
 *
 * The tests skip when the bundle has never been compiled: the property under test is what the
 * compiler emits, not how fresh the artifact is.
 */
class Externals_Bundle_Map_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Newest compiled app JS for a bundle, or null when it has never been compiled.
     */
    protected static function __newest_app_bundle(string $bundle_name): ?string
    {
        $files = glob(storage_path("rsx-build/bundles/{$bundle_name}__app.*.js"));

        if (empty($files)) {
            return null;
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    public static function test_baked_map_reaches_the_compiled_bundle()
    {
        $bundle = static::__newest_app_bundle('Login_Bundle');

        if ($bundle === null) {
            static::__skip('No compiled Login_Bundle app JS present');

            return;
        }

        $content = file_get_contents($bundle);

        static::__assert_contains(
            'Rsx_External_Resources._define(',
            $content,
            'the compiled bundle bakes the external-resource map'
        );

        static::__assert_contains(
            '"turnstile":',
            $content,
            "the framework's own declared identifier is in the baked map"
        );

        static::__assert_contains(
            'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=__rsx_turnstile_onload',
            $content,
            'the declared URL is baked verbatim - the query string carries the readiness global'
        );

        static::__assert_contains(
            '"callback_param": "onload"',
            $content,
            'the readiness contract is baked, so the loader knows which global to define'
        );

        static::__assert_false(
            str_contains($content, '"integrity": []'),
            'integrity is emitted as an object keyed by URL, never as an empty JSON array'
        );
    }

    public static function test_bundle_realm_is_baked_per_bundle()
    {
        $portal = static::__newest_app_bundle('Portal_Bundle');
        $staff = static::__newest_app_bundle('Login_Bundle');

        if ($portal === null || $staff === null) {
            static::__skip('Portal_Bundle and Login_Bundle must both be compiled');

            return;
        }

        static::__assert_contains(
            '// Realm: portal',
            file_get_contents($portal),
            'a bundle including #[Portal_Route] controllers bakes the portal realm'
        );

        static::__assert_contains(
            '// Realm: staff',
            file_get_contents($staff),
            'a bundle with no portal controllers bakes the staff realm'
        );
    }
}
