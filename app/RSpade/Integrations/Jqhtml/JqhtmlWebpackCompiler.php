<?php

namespace App\RSpade\Integrations\Jqhtml;

use App\RSpade\Core\Cache\File_Content_Cache;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;
use App\RSpade\Integrations\Jqhtml\Jqhtml_Exception_ViewException;

/**
 * JqhtmlWebpackCompiler - Compiles JQHTML templates to JavaScript during bundle build
 *
 * This service handles the compilation of .jqhtml template files into JavaScript
 * during the bundle build process. It uses the @jqhtml/parser NPM package - through the
 * `jqhtml` subsystem of the node service (Rsx_Node_Service) - to perform the compilation,
 * and caches the results based on file modification times.
 *
 * Features:
 * - Uses @jqhtml/parser for template compilation
 * - Caches compiled templates based on file mtime
 * - Throws fatal errors on compilation failures (fail loud)
 * - Integrates with the bundle compilation pipeline
 */
#[Instantiatable]
class JqhtmlWebpackCompiler
{
    /**
     * The ONE derived-cache namespace for jqhtml, shared with Jqhtml_BundleProcessor. The
     * two LAYERS - the parser's raw output and the bundle-ready wrap of it - are told apart
     * by the VARIANT, not by a namespace each: they key on the same template file, expire
     * together, and are swept together. See App\RSpade\Core\Cache\File_Content_Cache.
     *
     * NOT named NAMESPACE: `namespace` is a PHP keyword, so `self::NAMESPACE` lexes as
     * T_NAMESPACE and the token-based import fixer reads every use of it as the top of a
     * new file.
     */
    public const CACHE_NAMESPACE = 'jqhtml';

    /** Variant prefix for the parser's RAW output. */
    public const PARSED_VARIANT = '_parsed';

    /**
     * Path to jqhtml-compile binary for package validation (RPC server used for actual compilation)
     */
    protected string $compiler_path;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Use official jqhtml CLI compiler from npm package
        $this->compiler_path = base_path('node_modules/@jqhtml/parser/bin/jqhtml-compile');

        // Validate compiler exists - MUST exist
        if (!file_exists($this->compiler_path)) {
            throw new \RuntimeException(
                "Official JQHTML CLI compiler not found at: {$this->compiler_path}. " .
                "Run 'npm install @jqhtml/parser@^2.2.59' to install the official CLI compiler."
            );
        }
    }
    
    /**
     * The installed @jqhtml/parser version, read once per process.
     *
     * FATAL when unresolvable: no parser package means no working compiler, and a guessed
     * version is worse than a loud stop (the service module resolves the same package the
     * same way and refuses the same way).
     */
    public static function _parser_version(): string
    {
        static $version = null;

        if ($version !== null) {
            return $version;
        }

        $package = base_path('node_modules/@jqhtml/parser/package.json');

        if (!is_file($package)) {
            throw new \RuntimeException(
                '@jqhtml/parser is not installed at ' . $package
                . ' - the jqhtml compiler cannot run without it.'
            );
        }

        $version = json_decode(file_get_contents($package), true)['version'] ?? null;

        if (!is_string($version) || $version === '') {
            throw new \RuntimeException('@jqhtml/parser package.json carries no version.');
        }

        return $version;
    }

    /**
     * Compile a single JQHTML template file
     *
     * @param string $file_path Path to .jqhtml file
     * @return string Compiled JavaScript code
     * @throws \RuntimeException On compilation failure
     */
    public function compile_file(string $file_path): string
    {
        if (!file_exists($file_path)) {
            throw new \RuntimeException("JQHTML template not found: {$file_path}");
        }

        // Cache key = the template's identity (the shared derived-cache key, which folds in
        // mtime in development and the content in a sealed build) + the PARSER'S OWN VERSION
        // as the VARIANT. The version is in the key because a compiled template is the
        // parser's output: upgrade @jqhtml/parser and every cached compile is the OLD
        // parser's work, still keyed valid by a path and an mtime that never moved. That is
        // precisely how a wrong version (or a whole parser upgrade) kept being served for
        // months - the daemon was recycled, the cache was not. Same discipline as
        // Js_Transformer's toolchain fingerprint and the node service's .meta.
        $variant = self::PARSED_VARIANT . '_pv' . static::_parser_version();

        $cached = File_Content_Cache::get(self::CACHE_NAMESPACE, $file_path, $variant, 'js');

        if ($cached !== null) {
            console_debug("JQHTML", "Using cached JQHTML template: {$file_path}");

            return $cached;
        }

        console_debug("JQHTML", "Compiling JQHTML template: {$file_path}");

        // Compile via RPC server
        $compiled_js = static::_compile_via_rpc($file_path);

        // Extract template variable name and append registration call
        // CRITICAL: Do NOT add extra newlines - they break sourcemap line offsets
        // The registration is appended AFTER the sourcemap comment so it doesn't affect mappings
        if (preg_match('/var\s+(template_\w+)\s*=/', $compiled_js, $matches)) {
            $template_var = $matches[1];
            // Append registration on same line as end of sourcemap (no extra newlines)
            $compiled_js = rtrim($compiled_js) . "\njqhtml.register_template({$template_var});";
        }

        $wrapped_js = $compiled_js;

        // Ensure exactly one newline at end (no extra)
        $wrapped_js = rtrim($wrapped_js) . "\n";

        // Cache the compiled result. Entries for templates that no longer exist (or whose
        // parser version has moved on) are removed by File_Content_Cache::sweep_all() at the
        // end of the manifest build - there is no private per-template cleanup here.
        File_Content_Cache::put(self::CACHE_NAMESPACE, $file_path, $variant, 'js', $wrapped_js);

        return $wrapped_js;
    }
    
    /**
     * Compile multiple JQHTML template files
     * 
     * @param array $files Array of file paths
     * @return array Compiled JavaScript code keyed by file path
     */
    public function compile_files(array $files): array
    {
        $compiled = [];
        
        foreach ($files as $file) {
            try {
                $compiled[$file] = $this->compile_file($file);
            } catch (\Exception $e) {
                // FAIL LOUD - don't continue on error
                throw new \RuntimeException(
                    "Failed to compile JQHTML templates: " . $e->getMessage()
                );
            }
        }
        
        return $compiled;
    }
    
    /**
     * Extract component name from file path
     * 
     * @param string $file_path Path to .jqhtml file
     * @return string Component name
     */
    protected function extract_component_name(string $file_path): string
    {
        // Remove base path and extension
        $relative = str_replace(base_path() . '/', '', $file_path);
        $relative = preg_replace('/\.jqhtml$/i', '', $relative);
        
        // Convert path to component name (e.g., rsx/app/components/MyComponent)
        // to MyComponent or components/MyComponent
        $parts = explode('/', $relative);
        
        // Use the filename as the component name
        return basename($relative);
    }
    
    /**
     * Compile file via the node service.
     *
     * Lazy by construction: only a genuine cache MISS reaches request(), and request() is
     * what starts the service - a fully cached bundle compile never spawns node at all.
     *
     * @param string $file_path Path to file to compile
     * @return string Compiled code
     */
    protected static function _compile_via_rpc(string $file_path): string
    {
        $result = Rsx_Node_Service::request('jqhtml.compile', [
            'files' => [
                [
                    'path' => $file_path,
                    'format' => 'iife',
                    'sourcemap' => true
                ]
            ]
        ]);

        if (!isset($result['results'])) {
            throw new \RuntimeException(
                "JQHTML compile RPC returned an invalid response for {$file_path}:\n" . json_encode($result)
            );
        }

        $file_result = $result['results'][$file_path] ?? null;

        if (!$file_result) {
            throw new \RuntimeException("JQHTML compile RPC did not return a result for {$file_path}");
        }

        // Handle error response
        if ($file_result['status'] === 'error' && isset($file_result['error'])) {
            $error = $file_result['error'];
            $message = $error['message'] ?? 'Unknown error';
            $line = $error['line'] ?? null;
            $column = $error['column'] ?? null;

            // Throw appropriate exception type
            if ($line && $column) {
                throw new Jqhtml_Exception_ViewException(
                    "JQHTML compilation failed:\n{$message}",
                    $file_path,
                    $line,
                    $column
                );
            }

            throw new \RuntimeException(
                "JQHTML compilation failed for {$file_path}:\n{$message}"
            );
        }

        // Success - return compiled code
        if ($file_result['status'] === 'success' && isset($file_result['result'])) {
            return $file_result['result'];
        }

        // Unknown response format
        throw new \RuntimeException(
            "JQHTML compile RPC returned an unexpected response for {$file_path}:\n" .
            json_encode($file_result, JSON_PRETTY_PRINT)
        );
    }
}