<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use App\RSpade\Core\Files\Rsx_Document_Renderer_Abstract;

/**
 * Fixture document renderer standing in for an APPLICATION's renderer: registered for a type
 * through a runtime config('rsx.documents.renderers') override by Document_Renderer_Registry_Test,
 * and resolved like any other renderer. It writes FIXTURE_BYTES to the target, so a test can tell
 * its rendition from the framework's. The test tree is in the manifest only while rsx:test runs.
 */
class Document_Renderer_Fixture_Renderer extends Rsx_Document_Renderer_Abstract
{
    public const FIXTURE_BYTES = "%PDF-1.4\n% Document_Renderer_Fixture_Renderer\n";

    public static function render(string $source_path, string $target_path, ?string $extension): void
    {
        $dir = dirname($target_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($target_path, self::FIXTURE_BYTES);
    }
}
