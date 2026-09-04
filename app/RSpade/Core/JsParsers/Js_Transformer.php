<?php
/**
 * JavaScript Transformer using Babel
 *
 * Transpiles modern JavaScript features (decorators) to compatible code.
 * Private fields (#private) are NOT transpiled - native browser support is used.
 *
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\JsParsers;

use Illuminate\Support\Facades\File;
use RuntimeException;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;

/**
 * Babel transformation over the `babel` subsystem of the node service (Rsx_Node_Service).
 * This class owns the disk cache, its toolchain fingerprint and the error vocabulary; the
 * daemon's lifecycle belongs entirely to the service.
 */
class Js_Transformer
{
    /**
     * Node service module carrying the transform (participates in the cache fingerprint)
     */
    protected const BABEL_SERVICE_MODULE = 'app/RSpade/Core/JsParsers/resource/babel-service.js';

    /**
     * Cache directory for transformed JavaScript files
     */
    protected const CACHE_DIR = 'storage/rsx-tmp/babel_cache';

    /**
     * Vendored decorator fork bundle (participates in the cache fingerprint)
     */
    protected const DECORATOR_FORK_BUNDLE = 'app/RSpade/Core/JsParsers/resource/babel-plugin-decorators/index.js';

    /**
     * Cached toolchain fingerprint (computed once per process)
     */
    protected static ?string $toolchain_fingerprint = null;

    /**
     * Transform a JavaScript file using Babel
     *
     * @param string $file_path Path to JavaScript file
     * @param string $target Target environment (modern, es6, es5)
     * @return string Transformed JavaScript code
     */
    public static function transform(string $file_path, string $target = 'modern'): string
    {
        // Generate cache key using file hash, target, and toolchain fingerprint.
        // The fingerprint folds in the transformer script, the vendored decorator fork,
        // and the @babel/core version so that swapping any of them invalidates every
        // cache entry automatically (old entries orphan harmlessly).
        $cache_key = _rsx_file_hash_for_build($file_path) . '_' . $target . '_' . static::_toolchain_fingerprint();
        $cache_file = rsx_project_file_path(self::CACHE_DIR . '/' . $cache_key . '.js');

        // Check if cached result exists
        if (file_exists($cache_file)) {
            $mtime_cache = filemtime($cache_file);
            $mtime_source = filemtime($file_path);

            if ($mtime_cache >= $mtime_source) {
                return file_get_contents($cache_file);
            }
        }

        // Transform the file
        $result = static::_transform_without_cache($file_path, $target);

        // Cache the result
        static::_cache_result($cache_key, $result);

        return $result;
    }

    /**
     * Transform a JavaScript string using Babel
     *
     * @param string $js_code JavaScript code to transform
     * @param string $file_path Original file path (for hash generation)
     * @param string $target Target environment (modern, es6, es5)
     * @return string Transformed JavaScript code
     */
    public static function transform_string(string $js_code, string $file_path, string $target = 'modern'): string
    {
        // Create temporary file
        $temp_file = tempnam(sys_get_temp_dir(), 'babel_');
        file_put_contents_safe($temp_file, $js_code);

        try {
            // Transform using temporary file
            $result = static::_transform_without_cache($temp_file, $target, $file_path);
            return $result;
        } finally {
            // Clean up temporary file
            @unlink($temp_file);
        }
    }

    /**
     * Transform without using cache
     *
     * @param string $file_path Path to file to transform
     * @param string $target Target environment
     * @param string|null $original_path Original file path for hash (if using temp file)
     * @return string Transformed code
     */
    protected static function _transform_without_cache(string $file_path, string $target, ?string $original_path = null): string
    {
        // Use RPC server for transformation
        return static::_transform_via_rpc($file_path, $target, $original_path);
    }

    /**
     * Cache the transformer result
     *
     * @param string $cache_key Cache key
     * @param string $result Transformed code
     */
    protected static function _cache_result(string $cache_key, string $result): void
    {
        $cache_dir = rsx_project_file_path(self::CACHE_DIR);

        // Ensure cache directory exists
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        $cache_file = $cache_dir . '/' . $cache_key . '.js';
        file_put_contents_safe($cache_file, $result);
    }

    /**
     * Toolchain fingerprint folded into the transform cache key.
     *
     * A short md5 over the pieces of the transform toolchain whose change must invalidate
     * every cached transform:
     *   - the vendored decorator fork bundle (its content hash)
     *   - the @babel/core version string (from its package.json)
     *   - the node service's babel module (its content hash)
     *   - the one-shot transformer script (its content hash)
     *
     * BOTH transform implementations are folded in, and they must stay that way: they
     * carry the SAME babel options, so an edit to either changes what a transform
     * produces. Hashing only one of them means editing the other silently serves
     * every file from a cache built by the old options.
     *
     * This is the CACHE's fingerprint: it invalidates cached BYTES ON DISK. The service
     * itself needs no such check - each PHP process spawns its own daemon from current disk
     * and nobody ever inherits one.
     *
     * Computed once per process (static cache). Files are small / read once, so this is
     * cheap. Missing pieces contribute a marker rather than throwing here -- the transform
     * path itself fails loud downstream if the toolchain is genuinely absent.
     *
     * @return string 12-char hex fingerprint
     */
    protected static function _toolchain_fingerprint(): string
    {
        if (static::$toolchain_fingerprint !== null) {
            return static::$toolchain_fingerprint;
        }

        $fork_bundle = base_path(self::DECORATOR_FORK_BUNDLE);
        $server_script = base_path(self::BABEL_SERVICE_MODULE);
        $babel_core_pkg = base_path('node_modules/@babel/core/package.json');

        $fork_hash = is_file($fork_bundle) ? md5_file($fork_bundle) : 'no-fork';
        $server_hash = is_file($server_script) ? md5_file($server_script) : 'no-server';

        $babel_core_version = 'no-babel-core';
        if (is_file($babel_core_pkg)) {
            $pkg = json_decode(file_get_contents($babel_core_pkg), true);
            $babel_core_version = $pkg['version'] ?? 'unknown';
        }

        static::$toolchain_fingerprint = substr(
            md5($fork_hash . '.' . $babel_core_version . '.' . $server_hash),
            0,
            12
        );

        return static::$toolchain_fingerprint;
    }

    /**
     * Clear the transformation cache
     */
    public static function clear_cache(): void
    {
        $cache_dir = rsx_project_file_path(self::CACHE_DIR);

        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '/*.js');
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }


    /**
     * Get list of required npm packages
     *
     * @return array
     */
    public static function get_required_packages(): array
    {
        return [
            '@babel/core' => '^7.24.0',
            '@babel/preset-env' => '^7.24.0',
            '@babel/plugin-proposal-decorators' => '^7.24.0'
        ];
    }

    /**
     * Transform file via the node service.
     *
     * Lazy by construction: only a genuine cache MISS reaches request(), and request() is
     * what starts the service - a fully cached bundle compile never spawns node at all.
     *
     * @param string $file_path Path to file to transform
     * @param string $target Target environment
     * @param string|null $original_path Original file path for hash (if using temp file)
     * @return string Transformed code
     */
    protected static function _transform_via_rpc(string $file_path, string $target, ?string $original_path = null): string
    {
        // Use original path for hash generation if provided (for temp files).
        // Reduce it to a checkout-RELATIVE path: the transformer derives a private-
        // function scoping prefix from md5(hash_path). An absolute path would embed
        // the checkout location into every bundle's bytes, so two byte-identical
        // checkouts would emit different bundle CONTENT under the same filename. The
        // relative path is still unique per file (no collisions) but identical across
        // checkouts.
        $hash_path = _rsx_relative_build_path($original_path ?: $file_path);

        $result = Rsx_Node_Service::request('babel.transform', [
            'files' => [
                [
                    'path' => $file_path,
                    'target' => $target,
                    'hash_path' => $hash_path
                ]
            ]
        ]);

        if (!isset($result['results'])) {
            throw new RuntimeException(
                "JS Transformer RPC returned invalid response for {$file_path}:\n" . json_encode($result)
            );
        }

        $file_result = $result['results'][$file_path] ?? null;

        if (!$file_result) {
            throw new RuntimeException("JS Transformer RPC did not return a result for {$file_path}");
        }

        // Handle error response
        if ($file_result['status'] === 'error' && isset($file_result['error'])) {
            $error = $file_result['error'];
            $message = $error['message'] ?? 'Unknown error';
            $line = $error['line'] ?? null;
            $column = $error['column'] ?? null;
            $suggestion = $error['suggestion'] ?? null;

            // Build error message
            $error_msg = "JavaScript transformation failed";
            if ($line && $column) {
                $error_msg .= " at line {$line}, column {$column}";
            }
            $error_msg .= " in {$file_path}:\n{$message}";

            if ($suggestion) {
                $error_msg .= "\n\n{$suggestion}";
            }

            // Check for specific error types
            if (str_contains($message, 'Cannot find module')) {
                throw new RuntimeException(
                    "Babel packages not installed.\n" .
                    "Run: npm install\n" .
                    "Error: {$message}"
                );
            }

            throw new RuntimeException($error_msg);
        }

        // Success - return transformed code
        if ($file_result['status'] === 'success' && isset($file_result['result'])) {
            return $file_result['result'];
        }

        // Unknown response format
        throw new RuntimeException(
            "JS Transformer RPC returned an unexpected response for {$file_path}:\n" .
            json_encode($file_result, JSON_PRETTY_PRINT)
        );
    }
}