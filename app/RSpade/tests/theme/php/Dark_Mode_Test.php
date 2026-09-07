<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Theme\Php;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Theme\Rsx_Dark_Mode;

/**
 * The theme preference: resolution, the body contract, and the app-declared seam.
 *
 * THE PROPERTY THAT MATTERS is that an EXPLICIT preference is answerable on the server -
 * that is what lets the theme be painted into the HTML instead of corrected a frame later
 * by script. AUTO deliberately is not: prefers-color-scheme lives in the browser, so the
 * server must say "I do not know" rather than guess, and these tests pin that null.
 */
class Dark_Mode_Test extends Rsx_Test_Abstract
{
    public static function setup(): void
    {
        static::__acting_as_user(1);
    }

    public static function teardown(): void
    {
        Rsx_Dark_Mode::_clear_cache();
        static::__reset_session();
    }

    /**
     * Write through the real API. Assigning to a freshly-found model would update the row
     * while Session kept its own instance, so the reader would go on seeing the old value
     * - which is exactly the trap this helper existed to avoid.
     */
    private static function __set_mode(int $mode): void
    {
        Rsx_Dark_Mode::set_mode($mode);
    }

    // --- resolution ------------------------------------------------------------------------------

    public static function test_explicit_dark_is_resolved_server_side()
    {
        static::__set_mode(Login_User_Model::DARK_MODE_DARK);

        static::__assert_equals(Login_User_Model::DARK_MODE_DARK, Rsx_Dark_Mode::get_mode());
        static::__assert_true(Rsx_Dark_Mode::is_dark(), 'dark is knowable on the server');
    }

    public static function test_explicit_light_is_resolved_server_side()
    {
        static::__set_mode(Login_User_Model::DARK_MODE_LIGHT);

        static::__assert_false(Rsx_Dark_Mode::is_dark(), 'light is knowable on the server');
    }

    /**
     * The load-bearing null. The server cannot read the OS, and answering false here would
     * paint every auto user's page light and then correct it - the exact flash the whole
     * design exists to remove.
     */
    public static function test_auto_is_unknowable_server_side()
    {
        static::__set_mode(Login_User_Model::DARK_MODE_AUTO);

        static::__assert_equals(Login_User_Model::DARK_MODE_AUTO, Rsx_Dark_Mode::get_mode());
        static::__assert_null(Rsx_Dark_Mode::is_dark(), 'auto resolves to null, never to a guess');
    }

    // --- the body contract -----------------------------------------------------------------------

    /**
     * The mode class is always present and names the CHOICE; the dark class appears only
     * when dark is actually showing. A rule written against the mode class would be wrong
     * for half of auto's users, which is why they are two different classes.
     */
    public static function test_body_classes_name_mode_and_active_theme()
    {
        static::__set_mode(Login_User_Model::DARK_MODE_DARK);
        $classes = Rsx_Dark_Mode::body_classes();
        static::__assert_true(in_array('rsx-theme-dark', $classes, true), 'names the mode');
        static::__assert_true(in_array('rsx-dark', $classes, true), 'dark is active');

        static::__set_mode(Login_User_Model::DARK_MODE_LIGHT);
        $classes = Rsx_Dark_Mode::body_classes();
        static::__assert_true(in_array('rsx-theme-light', $classes, true), 'names the mode');
        static::__assert_false(in_array('rsx-dark', $classes, true), 'dark is not active');
    }

    public static function test_auto_carries_the_mode_but_no_theme()
    {
        static::__set_mode(Login_User_Model::DARK_MODE_AUTO);

        $classes = Rsx_Dark_Mode::body_classes();

        static::__assert_true(in_array('rsx-theme-auto', $classes, true), 'the mode is stated');
        static::__assert_false(
            in_array('rsx-dark', $classes, true),
            'the server never asserts a theme it cannot know - the client resolves auto'
        );
    }

    // --- the app-declared seam -------------------------------------------------------------------

    /**
     * The framework ships no UI toolkit, so it declares no attribute of its own. An app
     * names its vocabulary in config and the framework renders it verbatim.
     */
    public static function test_app_declared_attributes_are_rendered_for_the_active_theme()
    {
        config(['rsx.theme.dark_mode.attributes' => [
            'dark' => ['data-test-theme' => 'dark'],
            'light' => ['data-test-theme' => 'light'],
        ]]);

        static::__set_mode(Login_User_Model::DARK_MODE_DARK);
        static::__assert_equals(['data-test-theme' => 'dark'], Rsx_Dark_Mode::body_attributes());

        static::__set_mode(Login_User_Model::DARK_MODE_LIGHT);
        static::__assert_equals(['data-test-theme' => 'light'], Rsx_Dark_Mode::body_attributes());
    }

    /**
     * Under auto there is nothing honest to render, so nothing is. Rsx_Dark_Mode.js applies
     * the right set at boot instead.
     */
    public static function test_auto_renders_no_attributes()
    {
        config(['rsx.theme.dark_mode.attributes' => [
            'dark' => ['data-test-theme' => 'dark'],
            'light' => ['data-test-theme' => 'light'],
        ]]);

        static::__set_mode(Login_User_Model::DARK_MODE_AUTO);

        static::__assert_empty(Rsx_Dark_Mode::body_attributes(), 'auto is resolved client-side');
    }

    /**
     * An app that themes purely off rsx-dark declares nothing, and that must be fine.
     */
    public static function test_no_declared_vocabulary_is_valid()
    {
        config(['rsx.theme.dark_mode.attributes' => ['dark' => [], 'light' => []]]);
        static::__set_mode(Login_User_Model::DARK_MODE_DARK);

        static::__assert_empty(Rsx_Dark_Mode::body_attributes());
        static::__assert_true(in_array('rsx-dark', Rsx_Dark_Mode::body_classes(), true), 'the class still lands');
    }

    // --- writing ---------------------------------------------------------------------------------

    /**
     * 'changed' is what tells the client the page it is looking at is now painted wrong.
     */
    public static function test_set_mode_reports_whether_the_answer_moved()
    {
        static::__set_mode(Login_User_Model::DARK_MODE_LIGHT);

        static::__assert_true(
            Rsx_Dark_Mode::set_mode(Login_User_Model::DARK_MODE_DARK),
            'light -> dark moved'
        );
        static::__assert_false(
            Rsx_Dark_Mode::set_mode(Login_User_Model::DARK_MODE_DARK),
            'dark -> dark did not'
        );
    }

    public static function test_set_mode_rejects_an_unknown_mode()
    {
        static::__assert_throws(\InvalidArgumentException::class, function () {
            Rsx_Dark_Mode::set_mode(99);
        });
    }

    /**
     * A preference column is not a place to take the application down: an unrecognised
     * stored value reads as auto rather than throwing on every page render.
     */
    public static function test_an_unrecognised_stored_value_reads_as_auto()
    {
        static::__assert_equals(Login_User_Model::DARK_MODE_AUTO, Rsx_Dark_Mode::normalize_mode(42));
        static::__assert_equals(Login_User_Model::DARK_MODE_AUTO, Rsx_Dark_Mode::normalize_mode(null));
        static::__assert_equals(Login_User_Model::DARK_MODE_DARK, Rsx_Dark_Mode::normalize_mode('1'));
    }

    public static function test_mode_options_come_from_the_model_enum()
    {
        $options = Rsx_Dark_Mode::mode_options();

        static::__assert_count(3, $options, 'light, dark, auto');
        static::__assert_equals(Login_User_Model::DARK_MODE_LIGHT, $options[0]['value'], 'light first');
    }
}
