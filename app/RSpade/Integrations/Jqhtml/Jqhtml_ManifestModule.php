<?php

namespace App\RSpade\Integrations\Jqhtml;

use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Manifest\ManifestModule_Abstract;

/**
 * JqhtmlManifestModule - Manifest module for JQHTML template files
 * 
 * This module handles discovery and metadata extraction for .jqhtml template files.
 * It extracts:
 * - Template name from <Define:ComponentName> syntax
 * - Component references (PascalCase tags)
 * - Dependencies (@import directives)
 * - Template metadata for component binding
 */
class Jqhtml_ManifestModule extends ManifestModule_Abstract
{
    /**
     * Get file extensions this module handles
     * 
     * @return array
     */
    public function handles(): array
    {
        return ['jqhtml', 'jqtpl'];
    }
    
    /**
     * Get processing priority (lower = earlier)
     * 
     * @return int
     */
    public function priority(): int
    {
        return 500; // Process after core types but before CSS
    }
    
    /**
     * Process a file and extract metadata
     *
     * @param string $file_path Full path to the file
     * @param array $metadata Existing metadata
     * @return array Updated metadata
     */
    public function process(string $file_path, array $metadata): array
    {
        // Read template content
        $content = file_get_contents($file_path);

        // Check if file has meaningful content after comment removal
        $cleaned_content = $this->remove_comments($content);
        $trimmed_content = trim($cleaned_content);

        // If file is effectively empty after comments removed and trimmed, don't require a template name
        if (empty($trimmed_content)) {
            $metadata['type'] = 'jqhtml_template';
            $metadata['components'] = [];
            $metadata['dependencies'] = [];
            $metadata['slots'] = [];
            return $metadata;
        }

        // Extract template name
        $template_name = $this->extract_template_name($content);
        if (!$template_name) {
            // Non-empty file must have a template name - fail loud
            throw new \RuntimeException("JQHTML template in {$file_path} has content but no <Define:ComponentName> tag. Please add a template definition to the file.");
        }

        // Extract component references
        $components = $this->extract_components($content);

        // Extract dependencies
        $dependencies = $this->extract_dependencies($content);

        // Extract slots
        $slots = $this->extract_slots($content);

        // Extract tag name (defaults to 'div' if not specified)
        $tag_name = $this->extract_tag_name($content);

        // Add JQHTML-specific metadata
        $metadata['template_name'] = $template_name;
        $metadata['id'] = $template_name; // Set the ID to the component name
        $metadata['type'] = 'jqhtml_template';
        $metadata['tag_name'] = $tag_name;
        $metadata['components'] = $components;
        $metadata['dependencies'] = $dependencies;
        $metadata['slots'] = $slots;

        return $metadata;
    }
    
    /**
     * Blank the comments in JQHTML content.
     *
     * One implementation, in FileSanitizer, and line-preserving: the bodies become spaces,
     * so every offset this module then computes still addresses the original template.
     *
     * @param string $content Template content
     * @return string Content with comment bodies blanked
     */
    protected function remove_comments(string $content): string
    {
        return FileSanitizer::blank_template_comments($content);
    }

    /**
     * Extract template name from content
     *
     * @param string $content Template content
     * @return string|null Template name
     */
    protected function extract_template_name(string $content): ?string
    {
        // Remove comments before searching for Define:
        $content = $this->remove_comments($content);

        // Find the first instance of <Define: and capture alphanumeric+underscore characters
        // The component name continues until we hit a non-alphanumeric/non-underscore character
        if (preg_match('/<Define:(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract tag name from Define statement
     *
     * @param string $content Template content
     * @return string Tag name (defaults to 'div' if not specified)
     */
    protected function extract_tag_name(string $content): string
    {
        // Remove comments before searching
        $content = $this->remove_comments($content);

        // Look for tag attribute in Define statement
        // Matches: <Define:ComponentName tag="span"> or <Define:ComponentName tag='span'>
        if (preg_match('/<Define:\w+\s+[^>]*tag=["\']([^"\']+)["\']/', $content, $matches)) {
            return $matches[1];
        }

        return 'div'; // Default tag name
    }
    
    /**
     * Extract component references from template
     * 
     * @param string $content Template content
     * @return array Component names
     */
    protected function extract_components(string $content): array
    {
        $components = [];
        
        // Match component tags. The `_?[A-Z][A-Za-z0-9_]*` fragment is the name shape whose
        // home is App\RSpade\Core\Naming\Rsx_Identifier (a leading `_` is the framework prefix).
        preg_match_all('/<(_?[A-Z][A-Za-z0-9_]*)(?:\s+[^>]*)?\/?>|<\/(_?[A-Z][A-Za-z0-9_]*)>/', $content, $matches);
        
        foreach ($matches[1] as $tag) {
            if ($tag && !in_array($tag, $components)) {
                $components[] = $tag;
            }
        }
        
        foreach ($matches[2] as $tag) {
            if ($tag && !in_array($tag, $components)) {
                $components[] = $tag;
            }
        }
        
        // Remove Define tags as they're not component references
        $components = array_filter($components, function($c) {
            return !str_starts_with($c, 'Define');
        });
        
        return array_values($components);
    }
    
    /**
     * Extract dependencies from template
     * 
     * @param string $content Template content
     * @return array Dependency paths
     */
    protected function extract_dependencies(string $content): array
    {
        $dependencies = [];
        
        // Match @import directives
        preg_match_all('/@import\s+[\'"]([^\'"]+)[\'"]/', $content, $matches);
        
        foreach ($matches[1] as $dep) {
            if ($dep && !in_array($dep, $dependencies)) {
                $dependencies[] = $dep;
            }
        }
        
        return $dependencies;
    }
    
    /**
     * Extract slot definitions from template
     * 
     * @param string $content Template content
     * @return array Slot names
     */
    protected function extract_slots(string $content): array
    {
        $slots = [];
        
        // Match <Slot:slotname> syntax
        preg_match_all('/<Slot:(\w+)>/', $content, $matches);
        
        foreach ($matches[1] as $slot) {
            if ($slot && !in_array($slot, $slots)) {
                $slots[] = $slot;
            }
        }
        
        // Also match <slot name="..."> syntax
        preg_match_all('/<slot\s+name=[\'"](\w+)[\'"]/', $content, $matches);
        
        foreach ($matches[1] as $slot) {
            if ($slot && !in_array($slot, $slots)) {
                $slots[] = $slot;
            }
        }
        
        return $slots;
    }
    
}