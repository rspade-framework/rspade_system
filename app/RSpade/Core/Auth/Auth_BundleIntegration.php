<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use App\RSpade\Core\Bundle\BundleIntegration_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Auth gates integration: generates the JS Permission mirrors for #[Auth_Check].
 *
 * For each check marked on a realm's Permission class, the build emits a same-named
 * static on the realm's JS Permission class, so a check is spelled identically in
 * both languages and composite logic has exactly ONE implementation (the PHP body):
 *
 *     Permission::can_view_billing()   // PHP - executes the method
 *     Permission.can_view_billing()    // JS  - reads the render-time grant map
 *
 * Hand-written JS twins of check logic are forbidden - dual implementations drift.
 *
 * OUTPUT SHAPE. The emitted files carry NO class declaration: the classes
 * (Permission, Portal_Permission) are framework core JS that already exist, so the
 * mirrors are trailing-statement attachments onto them.
 *
 *     Permission.can_view_billing = function () {
 *         return window.rsxapp.auth ? window.rsxapp.auth.can_view_billing === 1 : false;
 *     };
 *
 * SHADOWING. An attachment is an ASSIGNMENT: it would overwrite a hand-written
 * static of the same name. So a check whose name already exists as a method on the
 * target JS class is SKIPPED, and the hand-written body wins ('is_logged_in' is the
 * built-in case - the JS twin reads window.rsxapp.is_auth, which agrees with the
 * grant map in both realms). The skip set is DERIVED from the manifest's JS class
 * metadata rather than hard-coded, so it can never drift from the classes.
 *
 * ORDERING (BundleCompiler). The attachments must evaluate AFTER the class
 * declarations they attach to. The generic stub path (_get_js_stubs) cannot carry
 * them: files with no class are bucketed as "non-class files" and hoisted to the
 * FRONT of the bundle, which would run the attachments in the classes' temporal dead
 * zone. BundleCompiler therefore appends these files explicitly, after the
 * dependency-ordered class files. It also folds their content into the app bundle's
 * cache key, since they are inputs no bundle file list contains.
 *
 * GENERATION lives in Auth_Stub_ManifestSupport, an ordinary entry at the end of
 * config('rsx.manifest_support'); this class owns the DIRECTORY constant and the
 * compiler-facing lookup of what that module produced.
 *
 * See: php artisan rsx:man auth_gates
 */
class Auth_BundleIntegration extends BundleIntegration_Abstract
{
    /** Project-relative directory the mirror files are written to. */
    public const STUB_DIR = 'storage/rsx-build/js-auth-stubs';

    public static function get_name(): string
    {
        return 'auth';
    }

    /**
     * Auth gates are declared with PHP attributes and JS decorators - no file
     * extension of their own.
     */
    public static function get_file_extensions(): array
    {
        return [];
    }

    /**
     * The mirror files an app bundle must include, as absolute paths.
     *
     * Read from the manifest's auth index (written by generate_manifest_stubs), so
     * the compiler never has to guess filenames.
     *
     * @return array<int, string>
     */
    public static function get_mirror_stub_paths(): array
    {
        $manifest = Manifest::get_full_manifest();
        $relative_paths = $manifest['data']['auth']['mirror_stubs'] ?? [];

        $paths = [];

        foreach ($relative_paths as $relative_path) {
            $full_path = rsx_project_file_path($relative_path);

            if (!file_exists($full_path)) {
                throw new \RuntimeException(
                    "Bundle compile references a generated auth mirror that is missing from disk:\n" .
                    "  stub: {$relative_path}\n\n" .
                    "The manifest records this file but its Phase-6 output does not exist (the\n" .
                    "js-auth-stubs directory was pruned while the manifest cache stayed fresh).\n" .
                    "Compiling would silently omit every generated Permission check mirror.\n\n" .
                    'Remedy: php artisan rsx:manifest:build --force'
                );
            }

            $paths[] = $full_path;
        }

        return $paths;
    }



}
