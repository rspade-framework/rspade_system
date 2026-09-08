<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Externals\Asset;

use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Manifest\Manifest;
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
 * - every compiled bundle bakes exactly one realm, and the identifier map it bakes is that
 *   realm's map - a bundle's realm is DERIVED from whether it includes #[Portal_Route]
 *   controllers, so a regression there would silently hand one realm the other's
 *   declarations, and this is the assertion that would show it. Which bundle SHOULD be
 *   which realm is not asserted: that is the application's include list, not a framework
 *   property, and a bundle nobody has browsed has no compiled artifact to read.
 *
 * WHICH BUNDLES ARE READ COMES FROM THE MANIFEST. The application decides what bundles it
 * declares and what they are called, so the set is resolved from
 * php_get_extending('Rsx_Module_Bundle_Abstract') and filtered to the ones the application
 * itself declares. Turnstile is declared realm 'both', so it belongs in either realm's map
 * and any application bundle can carry the first assertion.
 *
 * The tests skip when no such bundle has ever been compiled: the property under test is what
 * the compiler emits, not how fresh the artifact is.
 */
class Externals_Bundle_Map_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The application's own module bundles, in name order. Bundles declared under
     * app/RSpade are excluded: the panel's own two are not application bundles, and a
     * bundle fixture in the test tree is never compiled.
     *
     * @return string[]
     */
    protected static function __application_bundle_names(): array
    {
        $names = [];

        foreach (Manifest::php_get_extending('Rsx_Module_Bundle_Abstract') as $name => $metadata) {
            $file = $metadata['file'] ?? '';

            if (str_starts_with($name, '_') || !str_starts_with($file, 'rsx/')) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }

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

    /**
     * [bundle_name => compiled app JS path] for every application bundle that has one.
     *
     * @return array<string, string>
     */
    protected static function __compiled_application_bundles(): array
    {
        $compiled = [];

        foreach (static::__application_bundle_names() as $name) {
            $path = static::__newest_app_bundle($name);

            if ($path !== null) {
                $compiled[$name] = $path;
            }
        }

        return $compiled;
    }

    public static function test_baked_map_reaches_the_compiled_bundle()
    {
        $compiled = static::__compiled_application_bundles();

        if (empty($compiled)) {
            static::__skip('No compiled application bundle app JS present');

            return;
        }

        $bundle_name = array_key_first($compiled);
        $content = file_get_contents($compiled[$bundle_name]);

        static::__assert_contains(
            'Rsx_External_Resources._define(',
            $content,
            "the compiled {$bundle_name} bakes the external-resource map"
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
        $compiled = static::__compiled_application_bundles();

        if (empty($compiled)) {
            static::__skip('No compiled application bundle app JS present');

            return;
        }

        foreach ($compiled as $bundle_name => $path) {
            $content = file_get_contents($path);

            preg_match_all('/^\/\/ Realm: (\w+)$/m', $content, $matches);

            static::__assert_count(
                1,
                $matches[1],
                "{$bundle_name} must bake exactly one realm line"
            );

            $realm = $matches[1][0];

            static::__assert_true(
                in_array($realm, ['staff', 'portal'], true),
                "{$bundle_name} baked an unknown realm '{$realm}'"
            );

            // The realm line and the map must agree: the identifiers baked are exactly the
            // ones the registry resolves FOR THAT REALM. A realm-detection regression hands
            // a bundle the other realm's declarations, and this is what would show it.
            foreach (array_keys(Rsx_Externals::resolved_map_for_realm($realm)) as $identifier) {
                static::__assert_contains(
                    '"' . $identifier . '":',
                    $content,
                    "{$bundle_name} bakes realm '{$realm}' but omits that realm's '{$identifier}'"
                );
            }
        }
    }
}
