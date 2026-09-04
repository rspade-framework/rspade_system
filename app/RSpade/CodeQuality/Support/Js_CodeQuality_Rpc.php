<?php

namespace App\RSpade\CodeQuality\Support;

use App\RSpade\Core\JsParsers\Rsx_Node_Service;

/**
 * JavaScript Code Quality client
 *
 * Runs over the `quality` subsystem of the node service (Rsx_Node_Service), so the babel
 * parser and acorn stay loaded across the thousands of files a check walks.
 *
 * RPC Methods:
 *   - quality.lint: Check JavaScript syntax using Babel parser
 *   - quality.analyze_this: Analyze 'this' usage patterns using Acorn
 */
class Js_CodeQuality_Rpc
{
    /**
     * Lint a JavaScript file for syntax errors
     *
     * @param string $file_path Path to the JavaScript file
     * @return array|null Error info array or null if no errors
     */
    public static function lint(string $file_path): ?array
    {
        // Outside the marshaling try/catch below, so a service that will not start fails
        // LOUD rather than being reported as "no violations".
        Rsx_Node_Service::ensure();

        return static::_lint_via_rpc($file_path);
    }

    /**
     * Analyze a JavaScript file for 'this' usage violations
     *
     * @param string $file_path Path to the JavaScript file
     * @return array Violations array (may be empty)
     */
    public static function analyze_this(string $file_path): array
    {
        // Outside the marshaling try/catch below, so a service that will not start fails
        // LOUD rather than being reported as "no violations". This matters MORE here than
        // anywhere else: _analyze_this_via_rpc() swallows its own failures and returns no
        // violations, so a start failure inside it would read as a clean file.
        Rsx_Node_Service::ensure();

        return static::_analyze_this_via_rpc($file_path);
    }

    /**
     * Lint via the node service
     */
    protected static function _lint_via_rpc(string $file_path): ?array
    {
        try {
            $data = Rsx_Node_Service::request('quality.lint', [
                'files' => [$file_path],
            ]);

            if (isset($data['error'])) {
                throw new \RuntimeException("RPC error: " . $data['error']);
            }

            if (!isset($data['results'][$file_path])) {
                throw new \RuntimeException("No result for file in RPC response");
            }

            $result = $data['results'][$file_path];

            if ($result['status'] === 'success') {
                // Return the error info if present, null if no errors
                return $result['error'];
            }

            // Handle error response
            if ($result['status'] === 'error' && isset($result['error'])) {
                throw new \RuntimeException("Lint error: " . ($result['error']['message'] ?? 'Unknown error'));
            }

            return null;

        } catch (\Exception $e) {
            throw new \RuntimeException(
                "JavaScript lint RPC error for {$file_path}: " . $e->getMessage()
            );
        }
    }

    /**
     * Analyze this-usage via the node service
     */
    protected static function _analyze_this_via_rpc(string $file_path): array
    {
        try {
            $data = Rsx_Node_Service::request('quality.analyze_this', [
                'files' => [$file_path],
            ]);

            if (isset($data['error'])) {
                throw new \RuntimeException("RPC error: " . $data['error']);
            }

            if (!isset($data['results'][$file_path])) {
                throw new \RuntimeException("No result for file in RPC response");
            }

            $result = $data['results'][$file_path];

            if ($result['status'] === 'success') {
                return $result['violations'] ?? [];
            }

            // Handle error response - return empty violations, don't fail
            return [];

        } catch (\Exception $e) {
            // Log error but don't fail the check
            console_debug('JS_CODE_QUALITY', "RPC error for {$file_path}: " . $e->getMessage());
            return [];
        }
    }
}
