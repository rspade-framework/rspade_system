<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Exception;
use App\RSpade\Core\Files\Rsx_Document_Renderer_Abstract;

/**
 * Fixture document renderer that fails every render and declares NO soft-failure checks - the
 * shape of an application renderer that only knows how to throw. Registered for a type through a
 * runtime config('rsx.documents.renderers') override by Document_Soft_Failure_Test, which shows
 * that such a renderer's failures are always HARD: they are charged, and counted toward the
 * attempt limit. The test tree is in the manifest only while rsx:test runs.
 */
class Document_Throwing_Fixture_Renderer extends Rsx_Document_Renderer_Abstract
{
    public const FAILURE_MESSAGE = 'Document_Throwing_Fixture_Renderer always fails';

    public static function render(string $source_path, string $target_path, ?string $extension): void
    {
        throw new Exception(self::FAILURE_MESSAGE);
    }
}
