<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use RuntimeException;
use App\RSpade\Core\Files\Imagick_Policy;
use App\RSpade\Core\Files\Imagick_Thumbnail_Renderer;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Every framework Imagick call site refuses to touch an image under a permissive
 * ImageMagick policy.
 *
 * The verdict is injected through Imagick_Policy::$verdict_for_tests, so the test does
 * not depend on the policy installed on the box that runs it; rsx:health's "ImageMagick
 * Coder Policy" row is what checks the real one.
 */
class Imagick_Policy_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_a_permissive_policy_throws_naming_the_coders()
    {
        Imagick_Policy::$verdict_for_tests = ['SVG', 'TEXT'];

        try {
            Imagick_Policy::assert_safe();
            static::__fail('assert_safe() returned under a permissive policy');
        } catch (RuntimeException $e) {
            static::__assert_contains('SVG, TEXT', $e->getMessage());
            static::__assert_contains('policy.xml', $e->getMessage(), 'the message names the remedy');
        } finally {
            Imagick_Policy::$verdict_for_tests = null;
        }
    }

    public static function test_a_correct_policy_passes()
    {
        Imagick_Policy::$verdict_for_tests = [];

        try {
            Imagick_Policy::assert_safe();
            static::__assert_true(true, 'no exception under a correct policy');
        } finally {
            Imagick_Policy::$verdict_for_tests = null;
        }
    }

    public static function test_the_thumbnail_renderer_refuses_before_reading()
    {
        Imagick_Policy::$verdict_for_tests = ['MSVG'];

        try {
            Imagick_Thumbnail_Renderer::render('/nonexistent/source.png', 10, 10);
            static::__fail('the renderer rendered under a permissive policy');
        } catch (RuntimeException $e) {
            static::__assert_contains('Refusing to process an image', $e->getMessage(), 'the guard ran before the read');
        } finally {
            Imagick_Policy::$verdict_for_tests = null;
        }
    }
}
