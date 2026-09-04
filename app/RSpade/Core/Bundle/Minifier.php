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
     * (Prod_Build_Command). Since consolidation there is ONE service per process, so this
     * restarts the whole thing, not a minify-only daemon. A daemon is always spawned from
     * current disk by its own parent, so this is never needed for CHANGED code; it remains
     * the way to demand a brand new process regardless.
     */
    public static function force_restart(): void
    {
        Rsx_Node_Service::force_restart();
    }
}
