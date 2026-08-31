<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

class DomMethod_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-DOM-01';
    }
    
    public function get_name(): string
    {
        return 'JavaScript DOM Method Usage Check';
    }
    
    public function get_description(): string
    {
        return 'Enforces jQuery instead of native DOM methods';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.js'];
    }
    
    public function get_default_severity(): string
    {
        return 'medium';
    }
    
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Only check files in ./rsx/ directory
        if (!str_contains($file_path, '/rsx/') && !str_starts_with($file_path, 'rsx/')) {
            return;
        }
        
        // Skip vendor and node_modules directories, plus the mirrored CDN cache.
        // Third-party bundles (rsx/theme/vendor/bootstrap5/, rsx/resource/.cdn-cache/) are
        // not ours to rewrite, and their minified sources and test specs are full of literal
        // '<script>' strings this rule would otherwise read as our own code.
        if (str_contains($file_path, '/vendor/')
            || str_contains($file_path, '/node_modules/')
            || str_contains($file_path, '/.cdn-cache/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Get both original and sanitized content
        $original_content = file_get_contents($file_path);
        $original_lines = explode("\n", $original_content);
        
        // Get sanitized content with comments removed
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $sanitized_lines = $sanitized_data['lines'];
        
        foreach ($sanitized_lines as $line_num => $sanitized_line) {
            $line_number = $line_num + 1;
            
            // Skip if the line is empty in sanitized version
            if (trim($sanitized_line) === '') {
                continue;
            }
            
            $original_line = $original_lines[$line_num] ?? $sanitized_line;
            
            // Check for document.getElementById
            if (preg_match('/\bdocument\.getElementById\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.getElementById(id)' with '$('#' + id)' or use a jQuery selector directly like $('#myId'). " .
                    "jQuery provides a more consistent and powerful API for DOM manipulation that works across all browsers.",
                    'medium'
                );
            }
            
            // Check for script-tag creation, either quoting. A <script> is NOT an ordinary
            // element and the generic jQuery advice below is actively wrong for it - see
            // __script_remediation().
            //
            // The TAG NAME lives inside a string literal, and the sanitizer blanks literal
            // contents - so the structural half of each test runs against the sanitized line
            // (a comment can never spoof it) and the argument half against the original.
            if (preg_match('/\bdocument\.createElement\s*\(\s*[\'"]/', $sanitized_line)
                && preg_match('/\bdocument\.createElement\s*\(\s*([\'"])\s*script\s*\1/i', $original_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Do not hand-inject a <script> tag. Declare the resource and load it with Rsx.load_external().",
                    trim($original_line),
                    $this->__script_remediation(),
                    'high'
                );
            }
            // Check for jQuery script construction - $('<script ...>'), any spelling.
            elseif (preg_match('/\$\s*\(\s*[\'"]/', $sanitized_line)
                && preg_match('/\$\s*\(\s*[\'"]\s*<script\b/i', $original_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Do not build a <script> tag with jQuery. Declare the resource and load it with Rsx.load_external().",
                    trim($original_line),
                    $this->__script_remediation(),
                    'high'
                );
            }
            // Check for document.createElement (every other tag)
            elseif (preg_match('/\bdocument\.createElement\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.createElement(tagName)' with '$('<' + tagName + '>')' or use jQuery element creation like $('<div>'). " .
                    "jQuery provides a more fluent API for creating and manipulating DOM elements.",
                    'medium'
                );
            }
            
            // Check for document.getElementsByClassName
            if (preg_match('/\bdocument\.getElementsByClassName\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.getElementsByClassName(className)' with $('.' + className) or use a jQuery class selector directly like $('.myClass'). " .
                    "jQuery provides a more consistent API that returns a jQuery object with many useful methods.",
                    'medium'
                );
            }
            
            // Check for document.getElementsByTagName
            if (preg_match('/\bdocument\.getElementsByTagName\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.getElementsByTagName(tagName)' with $(tagName) or use a jQuery tag selector like $('div'). " .
                    "jQuery provides a unified API for element selection.",
                    'medium'
                );
            }
            
            // Check for document.querySelector
            if (preg_match('/\bdocument\.querySelector\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.querySelector(selector)' with $(selector). " .
                    "jQuery's selector engine is more powerful and consistent across browsers.",
                    'medium'
                );
            }
            
            // Check for document.querySelectorAll
            if (preg_match('/\bdocument\.querySelectorAll\s*\(/i', $sanitized_line)) {
                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Use jQuery instead of native DOM methods.",
                    trim($original_line),
                    "Replace 'document.querySelectorAll(selector)' with $(selector). " .
                    "jQuery automatically handles collections and provides chainable methods.",
                    'medium'
                );
            }
        }
    }

    /**
     * The remediation both <script> cases share.
     *
     * An external script is DECLARED, never injected: the Content-Security-Policy whitelist
     * is computed from the declarations, so a hand-injected tag is a blocked tag under an
     * enforcing policy. And jQuery is not the escape hatch - it never inserts a live script
     * node at all, which suppresses load/error events and bypasses Subresource Integrity.
     */
    protected function __script_remediation(): string
    {
        return "An external script is DECLARED, not injected. Put it in a *.externals.php file beside the feature "
            . "and load it by identifier:\n"
            . "    // rsx/app/frontend/reports/charts.externals.php\n"
            . "    return ['chartjs' => ['js' => ['https://cdn.example.com/chart.umd.min.js'], 'realm' => 'staff']];\n"
            . "    // in the component that needs it\n"
            . "    await Rsx.load_external('chartjs');\n"
            . "The CSP whitelist derives from that declaration, so an undeclared script is a BLOCKED script - "
            . "hand-injection cannot work under an enforcing policy. A sealed build also mirrors the declared URL "
            . "locally, and load_external() is memoized per page.\n"
            . "jQuery script insertion is NOT the answer either: jQuery's domManip special-cases scripts - it "
            . "disables the node, inserts it, then re-executes the source through _evalUrl(), a synchronous "
            . "async:false XHR plus globalEval. The browser never fetches the element, so its load/error events "
            . "never fire (a promise keyed on them hangs forever) and Subresource Integrity is silently bypassed: "
            . "integrity/crossorigin sit on a node nothing fetches.\n"
            . "If a genuinely local, non-external script must be constructed by hand, use the native DOM API and "
            . "mark the file with a rationale'd @JS-DOM-01-EXCEPTION comment.\n"
            . "See: php artisan rsx:man external_resources, php artisan rsx:man csp";
    }
}
