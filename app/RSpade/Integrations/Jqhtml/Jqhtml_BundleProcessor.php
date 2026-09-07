<?php

namespace App\RSpade\Integrations\Jqhtml;

use Exception;
use RuntimeException;
use App\RSpade\Core\Bundle\BundleProcessor_Abstract;
use App\RSpade\Core\Cache\File_Content_Cache;
use App\RSpade\Integrations\Jqhtml\JqhtmlWebpackCompiler;
use App\RSpade\Integrations\Jqhtml\Jqhtml_Exception_ViewException;

/**
 * JqhtmlProcessor - Processes JQHTML template files
 *
 * Compiles JQHTML templates into JavaScript code that can be
 * included in bundles. Uses webpack-based compilation with caching.
 */
class Jqhtml_BundleProcessor extends BundleProcessor_Abstract
{
    /**
     * Derived-cache namespace for the bundle-ready compiled template (the parser's output
     * wrapped in its provenance comment). Its sibling namespace `jqhtml-parsed` holds the
     * parser's RAW output, cached by JqhtmlWebpackCompiler one layer down. See
     * App\RSpade\Core\Cache\File_Content_Cache.
     */
    public const COMPILED_NAMESPACE = 'jqhtml-compiled';

    /**
     * Compiler instance
     */
    protected static ?JqhtmlWebpackCompiler $compiler = null;

    /**
     * Get processor name
     */
    public static function get_name(): string
    {
        return 'jqhtml';
    }

    /**
     * Get file extensions this processor handles
     */
    public static function get_extensions(): array
    {
        return ['jqhtml'];
    }

    /**
     * Process multiple files in batch
     * Compiles JQHTML templates and appends the JavaScript output to the bundle
     */
    public static function process_batch(array &$bundle_files): void
    {
        // Check for jqhtml files
        $jqhtml_files = array_filter($bundle_files, function ($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'jqhtml';
        });

        console_debug('JQHTML', 'process_batch called with ' . count($bundle_files) . ' files, ' . count($jqhtml_files) . ' jqhtml files');

        if (empty($jqhtml_files)) {
            console_debug('JQHTML', 'No jqhtml files to process');

            return;
        }

        // Initialize compiler if needed
        if (!static::$compiler) {
            static::$compiler = new JqhtmlWebpackCompiler();
        }

        // Process each JQHTML file
        foreach ($bundle_files as $path) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);

            // Only process JQHTML files
            if ($ext !== 'jqhtml') {
                continue;  // Skip non-JQHTML files
            }

            console_debug('JQHTML', "Processing file: {$path}");

            // The wrapped compile lives in the shared derived cache, whose key is
            // _rsx_file_hash_for_build() - the framework's ONE file-identity helper. That
            // name participates in the bundle's file ordering, and the determinism the
            // ordering needs is a SEALED-BUILD property: in production/debug the helper
            // hashes the checkout-RELATIVE path plus the content, so two byte-identical
            // checkouts produce identical names. In development it folds in mtime, which is
            // exactly the staleness test this loop used to perform by hand.
            // The parser's VERSION is the variant, for the same reason it is part of
            // compile_file()'s key: a cached compile is the PARSER'S output, and neither the
            // template's path nor its content moves when @jqhtml/parser is upgraded.
            $variant = '_pv' . JqhtmlWebpackCompiler::_parser_version();
            $temp_file = File_Content_Cache::path(self::COMPILED_NAMESPACE, $path, $variant, 'js');

            // Check if we need to compile
            $needs_compile = File_Content_Cache::get(self::COMPILED_NAMESPACE, $path, $variant, 'js', true) === null;

            if ($needs_compile) {
                console_debug('JQHTML', "Compiling: {$path}");

                try {
                    // Compile the template using webpack compiler
                    $js_code = static::$compiler->compile_file($path);

                    // Strip the compiler's comment line if present (single-line // comment)
                    if (preg_match('/^\/\/[^\n]*\n(.*)$/s', $js_code, $matches)) {
                        $js_code = $matches[1];
                    }

                    // Add our comment inline without any newlines to preserve sourcemap line offsets
                    $relative_path = str_replace(base_path() . '/', '', $path);
                    $wrapped_code = "/* Compiled from: {$relative_path} */ {$js_code}";

                    // Ensure proper newline at end
                    if (!str_ends_with($wrapped_code, "\n")) {
                        $wrapped_code .= "\n";
                    }

                    // Write to the derived cache (atomic; this is also the bundle input)
                    $temp_file = File_Content_Cache::put(
                        self::COMPILED_NAMESPACE,
                        $path,
                        $variant,
                        'js',
                        $wrapped_code
                    );

                    console_debug('JQHTML', "Compiled {$path} -> {$temp_file} (" . strlen($wrapped_code) . ' bytes)');
                } catch (Jqhtml_Exception_ViewException $e) {
                    // Let JQHTML ViewExceptions pass through for proper Ignition display
                    throw $e;
                } catch (\Illuminate\View\ViewException $e) {
                    // Let ViewExceptions pass through for proper display
                    throw $e;
                } catch (Exception $e) {
                    // FAIL LOUD - re-throw other exceptions
                    throw new RuntimeException(
                        "Failed to process JQHTML template {$path}: " . $e->getMessage()
                    );
                }
            } else {
                console_debug('JQHTML', "Using cached: {$temp_file}");
            }

            // ALWAYS append the compiled JS file to the bundle (whether freshly compiled or cached)
            $bundle_files[] = $temp_file;
        }

        console_debug('JQHTML', 'Final bundle_files count: ' . count($bundle_files));
    }

    /**
     * Post-processing hook - no longer generates manifest
     * Templates self-register via their compiled code
     */
    public static function after_processing(array $processed_files, array $options = []): array
    {
        // Templates now self-register when their compiled JS executes
        // No need for separate manifest generation
        return [];
    }

    /**
     * Pre-processing hook - reset compiled templates cache
     */
    public static function before_processing(array $all_files, array $options = []): void
    {
    }

    /**
     * Get processor priority (processes before JS)
     */
    public static function get_priority(): int
    {
        return 400;  // Process before JavaScript files
    }

    /**
     * Validate processor configuration
     */
    public static function validate(): void
    {
        // JqhtmlWebpackCompiler must exist - it's a required part of the jqhtml integration

        // Check if @jqhtml/parser is installed
        $package_path = base_path('node_modules/@jqhtml/parser/package.json');
        if (!file_exists($package_path)) {
            throw new RuntimeException(
                "@jqhtml/parser NPM package not found. Run 'npm install' to install @jqhtml packages."
            );
        }
    }

    /**
     * Check if processor should run in current environment
     *
     * @return bool True if processor should run
     */
    public static function is_enabled(): bool
    {
        // Always enabled - bundles control inclusion via module dependencies
        return true;
    }
}
