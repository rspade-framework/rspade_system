<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use App\RSpade\Core\Auth\Auth_BundleIntegration;
use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Emit the JS Permission mirrors for #[Auth_Check] - one attachment per realm check, so a
 * check is spelled identically in both languages and the composite logic has exactly ONE
 * implementation (the PHP body). Auth_BundleIntegration documents the output shape, the
 * shadowing rule and the bundle ordering; this class is the generator.
 *
 * AN ORDINARY SUPPORT MODULE, LAST IN THE LIST. It reads $manifest_data['data']['auth'],
 * which Auth_ManifestSupport wrote earlier in the same ordered pass, so the check registry
 * is complete by the time it runs. The generated paths are recorded back into that index
 * under 'mirror_stubs'.
 *
 * IT HAS ALWAYS CONTENT-COMPARED BEFORE WRITING, and it is the pattern the other two stub
 * generators now follow.
 */
class Auth_Stub_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * realm => [JS class the mirrors attach to, output filename].
     */
    private const REALM_TARGETS = [
        Auth_ManifestSupport::REALM_STAFF => ['Permission', 'Permission_Auth_Mirror.js'],
        Auth_ManifestSupport::REALM_PORTAL => ['Portal_Permission', 'Portal_Permission_Auth_Mirror.js'],
    ];

    public static function get_name(): string
    {
        return 'Auth Mirror Stubs';
    }

    /**
     * Emit one mirror file per realm that has checks, and record them in the index.
     */
    public static function process(array &$manifest_data, array $changed_files, array $removed_files): void
    {
        $stub_dir = rsx_project_file_path(Auth_BundleIntegration::STUB_DIR);

        if (!is_dir($stub_dir)) {
            mkdir($stub_dir, 0755, true);
        }

        $generated_filenames = [];
        $generated_relative_paths = [];

        foreach (self::REALM_TARGETS as $realm => [$js_class, $filename]) {
            $checks = array_keys($manifest_data['data']['auth']['checks'][$realm] ?? []);
            sort($checks);

            $hand_written = static::_hand_written_statics($manifest_data, $js_class);

            $mirrored = array_values(array_diff($checks, $hand_written));
            $skipped = array_values(array_intersect($checks, $hand_written));

            if (empty($mirrored) && empty($skipped)) {
                // The realm defines no checks at all - emit nothing, and let the
                // orphan sweep below remove a file left by a previous build.
                continue;
            }

            $content = static::_generate_mirror_content($realm, $js_class, $mirrored, $skipped);

            $full_path = $stub_dir . '/' . $filename;

            // The desired CONTENT is the fingerprint: it changes when the check set
            // changes, when the skip set changes, and when this generator's template
            // changes. Comparing it directly is both cheaper and stricter than a
            // stored hash, and it survives the auth index being rebuilt every build.
            if (!file_exists($full_path) || file_get_contents($full_path) !== $content) {
                file_put_contents_safe($full_path, $content);
            }

            $generated_filenames[] = $filename;
            $generated_relative_paths[] = Auth_BundleIntegration::STUB_DIR . '/' . $filename;
        }

        // Clean up orphaned mirrors (a realm that lost its last check, or a rename).
        foreach (glob($stub_dir . '/*.js') as $existing) {
            if (!in_array(basename($existing), $generated_filenames, true)) {
                unlink($existing);
            }
        }

        // Register with the auth index the support module built in Phase 5. This is
        // the manifest record BundleCompiler reads; the files deliberately do NOT
        // enter data.files, because they declare no class and are not scannable
        // source - they are build output owned by this integration.
        $manifest_data['data']['auth']['mirror_stubs'] = $generated_relative_paths;
    }

    /**
     * Static method names already declared BY HAND on a JS class.
     *
     * Derived from the manifest's own JS metadata, so a new hand-written method
     * automatically starts winning over a same-named generated attachment with no
     * list to maintain here.
     *
     * @return array<int, string>
     */
    private static function _hand_written_statics(array $manifest_data, string $js_class): array
    {
        $file = $manifest_data['data']['js_classes'][$js_class]['file'] ?? null;

        if ($file === null) {
            shouldnt_happen(
                "Auth mirror generation could not find the JS class '{$js_class}' in the manifest. " .
                'It is framework core (app/RSpade/Core/Js) and must always be indexed.'
            );
        }

        $metadata = $manifest_data['data']['files'][$file] ?? [];

        return array_keys($metadata['public_static_methods'] ?? []);
    }

    /**
     * Render one realm's mirror file.
     *
     * @param array<int, string> $mirrored Check names getting an attachment
     * @param array<int, string> $skipped  Check names a hand-written method owns
     */
    private static function _generate_mirror_content(string $realm, string $js_class, array $mirrored, array $skipped): string
    {
        $content = "/**\n";
        $content .= " * Auto-generated auth check mirrors for the {$realm} realm ({$js_class})\n";
        $content .= " * DO NOT EDIT - This file is automatically regenerated\n";
        $content .= " *\n";
        $content .= " * One static per #[Auth_Check]-marked method on the realm's PHP Permission class,\n";
        $content .= " * so a check is spelled identically in both languages. Each reads the render-time\n";
        $content .= " * grants map (window.rsxapp.auth) - never a network call.\n";
        $content .= " *\n";
        $content .= " * See: php artisan rsx:man auth_gates\n";

        if (!empty($skipped)) {
            $content .= " *\n";
            $content .= " * Not mirrored ({$js_class} declares these by hand; the hand-written body wins):\n";
            foreach ($skipped as $check_name) {
                $content .= " *   {$check_name}\n";
            }
        }

        $content .= " */\n";

        foreach ($mirrored as $check_name) {
            $content .= "\n";
            $content .= "{$js_class}.{$check_name} = function () {\n";
            $content .= "    return window.rsxapp.auth ? window.rsxapp.auth.{$check_name} === 1 : false;\n";
            $content .= "};\n";
        }

        return $content;
    }
}
