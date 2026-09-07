<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Search\Php;

/**
 * Fixture handler proving an app #[OnEvent] can intercept the document.extract_text filter chain.
 *
 * CRITICAL - MARKER GUARD: this handler is manifest-discovered and therefore LIVE in dev. It is
 * registered on the REAL extraction chain that Search_Index_Service consults for every blob. To
 * avoid hijacking production extraction, it INTERCEPTS ONLY paths containing the marker
 * 'rsx_test_intercept' and DECLINES (returns null) for everything else - and real blob paths are
 * content hashes that can never contain the marker. Which contract branch it returns is selected
 * by a sub-marker in the path so the tests can exercise every return shape.
 */
class Search_Extract_Text_Fixture_Handler
{
    #[OnEvent('document.extract_text', priority: 5)]
    public static function intercept($data)
    {
        $path = $data['path'] ?? '';

        // Decline for every real file (their paths are content hashes, never carry the marker).
        if (strpos($path, 'rsx_test_intercept') === false) {
            return null;
        }

        if (strpos($path, 'rsx_test_intercept_unsupported') !== false) {
            return ['status' => 'unsupported'];
        }

        if (strpos($path, 'rsx_test_intercept_failed') !== false) {
            return ['status' => 'failed', 'error' => 'fixture forced failure'];
        }

        return 'FIXTURE_EXTRACTED_TEXT:' . basename($path);
    }
}
