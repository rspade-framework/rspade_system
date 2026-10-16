<?php
/**
 * JavaScript and CSS Minifier
 *
 * Runs over the `minify` subsystem of the node service (Rsx_Node_Service), so terser and
 * cssnano stay loaded between files.
 * Terser for JavaScript, cssnano for CSS.
 * Only used in production mode - debug mode retains readable code.
 */

namespace App\RSpade\Core\Bundle;

use RuntimeException;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;

class Minifier
{
    /**
     * Minify JavaScript content
     *
     * @param string $content JavaScript content to minify
     * @param string $filename Filename for error reporting
     * @param bool $strip_console_debug When true, Terser drops console_debug() call
     *                                   sites via pure_funcs (strict production only;
     *                                   driven by Manifest::_should_strip_console_debug()).
     * @return string Minified content
     */
    public static function minify_js(string $content, string $filename = 'bundle.js', bool $strip_console_debug = false): string
    {
        return static::_minify_via_rpc($content, 'js', $filename, $strip_console_debug);
    }

    /**
     * Obfuscate the strings of the eligible files of a bundle's APP JavaScript.
     *
     * The strict-production pass BundleCompiler runs on the app files just before they are
     * concatenated, when config('rsx.production.obfuscate_strings') is on. The CALLER chooses
     * the files; the whole transform - scope, the qualification rules, the bundle-wide
     * seeded dictionary, the rewrite and the generated `_$$SC` / `_$$` helper file - lives in
     * the `string_obfuscation` node subsystem (resource/string-obfuscation-service.js), which
     * writes its output into $output_dir. This method only carries the request.
     *
     * @param array $eligible Paths of the files to transform, in output order (the files
     *                        actually concatenated - a babel copy where there is one)
     * @param array $others Every other app file of the bundle (scanned for collisions only)
     * @param string $vendor_path The bundle's vendor output, scanned for collisions only
     *                            (absent = nothing to scan)
     * @param string $bundle_name Named in a failure
     * @param string $output_dir Scratch directory for the helper and the rewritten copies
     *                           (cleared first)
     * @return array {helper_path: ?string, files: [path => rewritten copy], eligible,
     *               rewritten, converted, words}; helper_path is null and files empty when
     *               nothing converted
     * @throws RuntimeException On a parse failure or when `_$$SC` / `_$$` is already in use
     */
    public static function obfuscate_strings(array $eligible, array $others, string $vendor_path, string $bundle_name, string $output_dir): array
    {
        $result = Rsx_Node_Service::request('string_obfuscation.obfuscate', [
            'eligible' => array_values($eligible),
            'others' => array_values($others),
            'vendor_path' => $vendor_path,
            'bundle' => $bundle_name,
            'output_dir' => $output_dir,
        ]);

        if (($result['status'] ?? null) === 'error') {
            throw new RuntimeException("String obfuscation failed for {$bundle_name}: {$result['error']['message']}");
        }

        if (($result['status'] ?? null) !== 'success' || !array_key_exists('helper_path', $result)) {
            throw new RuntimeException("Invalid response from the string_obfuscation RPC: " . json_encode($result));
        }

        $result['files'] = (array) ($result['files'] ?? []);
        unset($result['status']);

        return $result;
    }

    /**
     * Minify CSS content
     *
     * @param string $content CSS content to minify
     * @param string $filename Filename for error reporting
     * @return string Minified content
     */
    public static function minify_css(string $content, string $filename = 'bundle.css'): string
    {
        return static::_minify_via_rpc($content, 'css', $filename);
    }

    /**
     * Minify content via RPC server
     */
    protected static function _minify_via_rpc(string $content, string $type, string $filename, bool $strip_console_debug = false): string
    {
        // NO TIMEOUT anywhere on this path - Rsx_Node_Service::request() connects with none.
        // This call site once carried a 30s connect budget whose own comment gave the reason
        // to remove it: "a minify request can queue behind a large one already in flight."
        // Queueing behind real work is exactly the slowness that is normal.
        $result = Rsx_Node_Service::request('minify.minify', [
            'files' => [
                [
                    'type' => $type,
                    'content' => $content,
                    'filename' => $filename,
                    'strip_console_debug' => $strip_console_debug
                ]
            ]
        ]);

        if (!isset($result['results'][$filename])) {
            throw new RuntimeException("Invalid response from the minify RPC: " . json_encode($result));
        }

        $file_result = $result['results'][$filename];

        if ($file_result['status'] === 'error') {
            $error = $file_result['error'];
            throw new RuntimeException(
                "Minification failed for {$filename}: {$error['message']}"
            );
        }

        return $file_result['result'];
    }

    /**
     * Force a fresh node service.
     *
     * Kept as its own name because the production build calls it explicitly
     * (the build pipeline). Since consolidation there is ONE service per process, so this
     * restarts the whole thing, not a minify-only daemon. A daemon is always spawned from
     * current disk by its own parent, so this is never needed for CHANGED code; it remains
     * the way to demand a brand new process regardless.
     */
    public static function force_restart(): void
    {
        Rsx_Node_Service::force_restart();
    }
}
