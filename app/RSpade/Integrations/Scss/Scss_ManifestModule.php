<?php

namespace App\RSpade\Integrations\Scss;

use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Manifest\ManifestModule_Abstract;

/**
 * Module for processing SCSS/CSS files in the manifest
 */
class Scss_ManifestModule extends ManifestModule_Abstract
{
    /**
     * Get file extensions this module handles
     */
    public function handles(): array
    {
        return ['scss', 'sass', 'css'];
    }
    
    /**
     * Get processing priority
     */
    public function priority(): int
    {
        return 1000; // Lowest priority, process after all other modules including Jqhtml
    }
    
    /**
     * Process a SCSS/CSS file and extract metadata
     *
     * For SCSS files, also detects if the file has a single top-level class selector
     * that matches a Blade view ID, JavaScript class extending Component,
     * or jqhtml template ID in the manifest.
     */
    public function process(string $file_path, array $metadata): array
    {
        $content = file_get_contents($file_path);
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        
        $metadata['type'] = 'stylesheet';
        $metadata['format'] = $extension;
        
        // Extract imports
        $imports = [];
        
        // SCSS/Sass @import
        if (preg_match_all('/@import\s+[\'"]([^\'"]+)[\'"];/', $content, $matches)) {
            $imports = array_merge($imports, $matches[1]);
        }
        
        // CSS @import
        if (preg_match_all('/@import\s+url\s*\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/', $content, $matches)) {
            $imports = array_merge($imports, $matches[1]);
        }
        
        // SCSS/Sass @use
        if (preg_match_all('/@use\s+[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $imports = array_merge($imports, $matches[1]);
        }
        
        if (!empty($imports)) {
            $metadata['imports'] = array_unique($imports);
        }
        
        // Extract variables (SCSS/Sass)
        if ($extension === 'scss' || $extension === 'sass') {
            $variables = [];
            if (preg_match_all('/\$([a-zA-Z_][\w-]*)\s*:/', $content, $matches)) {
                $variables = $matches[1];
            }
            
            if (!empty($variables)) {
                $metadata['variables'] = array_unique($variables);
            }
        }
        
        // Extract mixins (SCSS/Sass)
        if ($extension === 'scss' || $extension === 'sass') {
            $mixins = [];
            if (preg_match_all('/@mixin\s+([a-zA-Z_][\w-]*)/', $content, $matches)) {
                $mixins = $matches[1];
            }
            
            if (!empty($mixins)) {
                $metadata['mixins'] = array_unique($mixins);
            }
        }
        
        // Extract functions (SCSS/Sass)
        if ($extension === 'scss' || $extension === 'sass') {
            $functions = [];
            if (preg_match_all('/@function\s+([a-zA-Z_][\w-]*)/', $content, $matches)) {
                $functions = $matches[1];
            }
            
            if (!empty($functions)) {
                $metadata['functions'] = array_unique($functions);
            }
        }
        
        // Extract extends/placeholders (SCSS/Sass)
        if ($extension === 'scss' || $extension === 'sass') {
            $placeholders = [];
            if (preg_match_all('/%([a-zA-Z_][\w-]*)/', $content, $matches)) {
                $placeholders = $matches[1];
            }
            
            if (!empty($placeholders)) {
                $metadata['placeholders'] = array_unique($placeholders);
            }
        }
        
        // Extract main selectors (top-level classes/IDs)
        $selectors = [];

        // Remove comments to avoid false positives. One implementation, line-preserving,
        // and url()-aware - a `//` inside url(https://...) is not a comment.
        $clean_content = FileSanitizer::blank_scss_comments($content);

        // Extract class selectors
        if (preg_match_all('/^\.([a-zA-Z_][\w-]*)/m', $clean_content, $matches)) {
            foreach ($matches[1] as $class) {
                $selectors[] = '.' . $class;
            }
        }

        // Extract ID selectors
        if (preg_match_all('/^#([a-zA-Z_][\w-]*)/m', $clean_content, $matches)) {
            foreach ($matches[1] as $id) {
                $selectors[] = '#' . $id;
            }
        }

        if (!empty($selectors)) {
            $metadata['selectors'] = array_unique($selectors);
        }

        // Check for single top-level class selector pattern for SCSS files
        if ($extension === 'scss') {
            $this->detect_scss_id($clean_content, $metadata, $file_path);
        }
        
        // Check if it's a partial (starts with underscore)
        $filename = basename($file_path);
        if (str_starts_with($filename, '_')) {
            $metadata['is_partial'] = true;
        }
        
        // Determine scope based on path
        $relative_path = str_replace(base_path() . '/', '', $file_path);
        $metadata['relative_path'] = $relative_path;
        
        if (str_contains($relative_path, '/pages/')) {
            $metadata['scope'] = 'page';
        } elseif (str_contains($relative_path, '/components/')) {
            $metadata['scope'] = 'component';
        } elseif (str_contains($relative_path, '/layouts/')) {
            $metadata['scope'] = 'layout';
        } elseif (str_contains($relative_path, '/utilities/') || str_contains($relative_path, '/utils/')) {
            $metadata['scope'] = 'utility';
        } elseif (str_contains($relative_path, '/base/') || str_contains($relative_path, '/foundation/')) {
            $metadata['scope'] = 'base';
        } else {
            $metadata['scope'] = 'general';
        }
        
        // Check for media queries
        if (preg_match_all('/@media\s+([^{]+)/', $content, $matches)) {
            $media_queries = [];
            foreach ($matches[1] as $query) {
                $query = trim($query);
                if (str_contains($query, 'min-width')) {
                    $media_queries[] = 'responsive';
                }
                if (str_contains($query, 'print')) {
                    $media_queries[] = 'print';
                }
                if (str_contains($query, 'prefers-color-scheme')) {
                    $media_queries[] = 'dark-mode';
                }
            }
            
            if (!empty($media_queries)) {
                $metadata['media_features'] = array_unique($media_queries);
            }
        }
        
        // Check for CSS custom properties (CSS variables)
        $css_vars = [];
        if (preg_match_all('/--([a-zA-Z][\w-]*)/', $content, $matches)) {
            $css_vars = $matches[1];
        }
        
        if (!empty($css_vars)) {
            $metadata['css_variables'] = array_unique($css_vars);
        }
        
        // Check for Bootstrap usage
        if (preg_match('/\.(btn|col-|row|container|navbar|modal|card|form-control)/', $content)) {
            $metadata['uses_bootstrap'] = true;
        }
        
        // Check for Font Awesome usage
        if (preg_match('/\.(fa-|fas|far|fab|fal|fad)/', $content)) {
            $metadata['uses_fontawesome'] = true;
        }
        
        return $metadata;
    }

    /**
     * Detect if SCSS file has a single top-level class wrapper
     *
     * Sets scss_wrapper_class when every rule in the file is contained within one top-level
     * class selector (e.g., .Frontend_Dashboard { ... }): the file's first top-level selector
     * is `.Name` (a leading `_` allowed, then a capital), and the brace opened after it closes
     * at the end of the file, with nothing but whitespace and comments after it. The closing
     * brace is found by depth, with comments and quoted strings ignored, so a file of several
     * top-level rules (`.A { } .B { }`) has no wrapper class whatever its brace count.
     *
     * SCSS variable declarations ($var: value;) are allowed outside the wrapper
     * and are stripped before checking. Files containing only variables/comments
     * are considered valid (scss_variables_only = true).
     *
     * Additionally sets 'id' if the wrapper class matches a Blade view ID,
     * JS class extending Component, or jqhtml template in the manifest.
     *
     * @param string $clean_content the file with its comments blanked (FileSanitizer::blank_scss_comments)
     */
    protected function detect_scss_id(string $clean_content, array &$metadata, string $file_path = ''): void
    {
        // Remove SCSS variable declarations ($var: value;) - these are allowed outside wrapper
        // This regex matches: $variable-name: any value until semicolon;
        $content_without_vars = trim(preg_replace('/\$[a-zA-Z_][\w-]*\s*:[^;]+;/', '', $clean_content));

        // If content is empty after removing variables (only variables and comments),
        // mark as variables-only file - no wrapper needed
        if ($content_without_vars === '') {
            $metadata['scss_variables_only'] = true;
            return;
        }

        $class_name = static::wrapper_class_of($clean_content);

        if ($class_name === null) {
            return;
        }

        // Always set scss_wrapper_class when file is fully enclosed in a single class
        $metadata['scss_wrapper_class'] = $class_name;

        // Now check if this class name matches something in the manifest for setting 'id'
        // During build, we need to access the in-memory manifest data
        // The get_all() method returns the cached data, not the in-progress build
        // We need a different approach - access the static data directly

        // Get access to the Manifest class's internal data using reflection
        $reflection = new \ReflectionClass(\App\RSpade\Core\Manifest\Manifest::class);
        $data_property = $reflection->getProperty('data');
        $data_property->setAccessible(true);
        $manifest_state = $data_property->getValue();

        if (!isset($manifest_state['data']['files'])) {
            return;
        }

        $manifest_data = $manifest_state['data']['files'];

        $found_match = false;
        $scss_id_already_exists = false;

        // THIS FILE'S OWN PREVIOUS RECORD IS NOT "ANOTHER SCSS FILE".
        //
        // A rebuild carries the last build's file map forward, so on the SECOND build this
        // stylesheet's own entry - carrying the id this method assigned last time - was
        // matching the "another SCSS already claims this id" test and the id was silently
        // dropped. The result was an index whose stylesheet records depended on how many
        // times the tree had been rebuilt, which is exactly what
        // Manifest_Incremental_Modules_Test refuses to allow.
        $own_key = $file_path === '' ? null : str_replace(base_path() . '/', '', $file_path);

        foreach ($manifest_data as $manifest_key => $file_data) {
            if ($own_key !== null && $manifest_key === $own_key) {
                continue;
            }

            // Check if another SCSS file already has this ID
            if (isset($file_data['id']) && $file_data['id'] === $class_name &&
                isset($file_data['extension']) && $file_data['extension'] === 'scss') {
                $scss_id_already_exists = true;
                break;
            }

            // Check for matching ID in Blade view or jqhtml template
            if (isset($file_data['id']) && $file_data['id'] === $class_name) {
                $found_match = true;
            }

            // Check for JavaScript class extending Component
            if (isset($file_data['extension']) && $file_data['extension'] === 'js' &&
                isset($file_data['class']) && $file_data['class'] === $class_name &&
                \App\RSpade\Core\Manifest\Manifest::js_is_subclass_of($class_name, 'Component')) {
                $found_match = true;
            }
        }

        // Only set ID if we found a match and no other SCSS has this ID
        if ($found_match && !$scss_id_already_exists) {
            $metadata['id'] = $class_name;
        }
    }

    /**
     * The class a stylesheet is wrapped in, or null.
     *
     * Comments are blanked and variable declarations ($var: value;) removed first. The file
     * is wrapped when it then opens with `.Name {` (the `_?[A-Z]...` name shape lives in
     * App\RSpade\Core\Naming\Rsx_Identifier; a leading `_` is the framework prefix) and the
     * brace opened there is closed - matched by depth, quoted strings and unquoted url(...)
     * tokens stepped over - by the last non-whitespace character of the file.
     */
    public static function wrapper_class_of(string $scss): ?string
    {
        $content = trim(preg_replace('/\$[a-zA-Z_][\w-]*\s*:[^;]+;/', '', FileSanitizer::blank_scss_comments($scss)));

        if (!preg_match('/^\.(_?[A-Z][A-Za-z0-9_]+)\s*\{/', $content, $matches)) {
            return null;
        }

        $length = strlen($content);
        $depth = 0;
        $quote = null;

        for ($i = strlen($matches[0]) - 1; $i < $length; $i++) {
            $char = $content[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if (($char === 'u' || $char === 'U') && strncasecmp(substr($content, $i, 4), 'url(', 4) === 0) {
                $close = strpos($content, ')', $i);

                if ($close === false) {
                    return null;
                }

                $i = $close;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i === $length - 1 ? $matches[1] : null;
                }
            }
        }

        return null;
    }
}
