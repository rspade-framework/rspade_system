<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;

class FilenameCase_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'FILE-CASE-01';
    }
    
    public function get_name(): string
    {
        return 'Filename Case Check';
    }
    
    public function get_description(): string
    {
        return 'All files in rsx/ should be lowercase';
    }
    
    public function get_file_patterns(): array
    {
        return ['*.*']; // All files
    }
    
    public function get_default_severity(): string
    {
        return 'low';
    }
    
    /**
     * Check if a filename contains uppercase characters (for RSX files) - from line 1706
     * Excludes vendor and resource directories, and .md files
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Skip vendor and resource directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/resource/')) {
            return;
        }
        
        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }
        
        // Only check files in rsx/ directory
        if (!Rsx_Paths::is_application($file_path)) {
            return;
        }
        
        // Get just the filename without the directory path
        $filename = basename($file_path);
        
        // Skip .md files
        if (str_ends_with($filename, '.md')) {
            return;
        }

        // A file that CARRIES A CLASS NAME may be spelled like the class: a PHP class file,
        // a JS class file, a jqhtml component (Frontend_Spa_Controller.php,
        // Frontend_Spa_Layout.js, Notification_Dropdown.jqhtml) - the spelling
        // MANIFEST-FILENAME-01 accepts under rsx/ - and so may the COMPANIONS of such a
        // file (the .scss / .blade.php sharing its stem: files sharing a prefix are one
        // related set). Lowercase is the rule for everything else under rsx/.
        $stem = preg_replace('/\.(blade\.php|php|js|jqhtml|scss)$/', '', $filename);
        if ($stem !== $filename && preg_match('/[A-Z]/', $stem)) {
            $declared = $metadata['class'] ?? $metadata['id'] ?? null;
            if ($declared === $stem) {
                return;
            }
            // The name indexes answer "does a class or component of this name exist" without
            // throwing (php_find_class() fails loud for an unknown name, correctly, for its callers).
            $index = Manifest::get_full_manifest()['data'] ?? [];
            if (isset($index['php_classes'][$stem])
                || isset($index['js_classes'][$stem])
                || isset($index['jqhtml']['components'][$stem])) {
                return;
            }
        }

        // Check if filename contains uppercase characters
        if (preg_match('/[A-Z]/', $filename)) {
            // Convert to lowercase for suggestion
            $suggested = strtolower($filename);
            
            $this->add_violation(
                $file_path,
                0,
                "Filename '{$filename}' contains uppercase characters. All files in rsx/ should be lowercase.",
                $filename,
                "Rename file to '{$suggested}'. Remember: class names should still use First_Letter_Uppercase format.",
                'low'
            );
        }
    }
}