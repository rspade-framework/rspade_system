<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use App\RSpade\Core\Files\Rsx_Document_Renderer_Abstract;

/**
 * Fixture document renderer that claims the 'html' rendition format, which is reserved to the
 * framework spreadsheet renderer: Document_Renderer_Registry_Test proves resolving it is refused.
 * It never renders.
 */
class Document_Renderer_Html_Fixture_Renderer extends Rsx_Document_Renderer_Abstract
{
    public static function render(string $source_path, string $target_path, ?string $extension): void
    {
        shouldnt_happen('Document_Renderer_Html_Fixture_Renderer is never resolved, so it never renders');
    }

    public static function rendition_format(): string
    {
        return 'html';
    }
}
