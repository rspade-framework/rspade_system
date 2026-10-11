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
 * The theme preference: resolution, storage, and the vocabulary shipped to the browser.
 *
 * The server never paints a theme - autotheme.js puts it on <html> before first paint
 * from window.rsxapp.theme - so what is pinned here is what that payload is built from:
 * the stored mode, the class names, and the app-declared attribute sets. AUTO resolves
 * to null server-side: prefers-color-scheme lives in the browser, so the server must say
 * "I do not know" rather than guess.
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

    // --- the vocabulary shipped to autotheme.js ---------------------------------------------------

    /**
     * The mode class names the CHOICE and the dark class names what is SHOWING. A rule
     * written against the mode class would be wrong for half of auto's users, which is
     * why they are two different names.
     */
    public static function test_mode_class_names_the_choice()
    {
        static::__assert_equals('rsx-theme-', Rsx_Dark_Mode::mode_class_prefix());
        static::__assert_equals('rsx-theme-light', Rsx_Dark_Mode::mode_class(Login_User_Model::DARK_MODE_LIGHT));
        static::__assert_equals('rsx-theme-dark', Rsx_Dark_Mode::mode_class(Login_User_Model::DARK_MODE_DARK));
        static::__assert_equals('rsx-theme-auto', Rsx_Dark_Mode::mode_class(Login_User_Model::DARK_MODE_AUTO));
        static::__assert_equals('rsx-dark', Rsx_Dark_Mode::dark_class());

        config(['rsx.theme.dark_mode.mode_class_prefix' => 'theme-', 'rsx.theme.dark_mode.dark_class' => 'is-dark']);
        static::__assert_equals('theme-auto', Rsx_Dark_Mode::mode_class(Login_User_Model::DARK_MODE_AUTO));
        static::__assert_equals('is-dark', Rsx_Dark_Mode::dark_class());
    }

    /**
     * The framework ships no UI toolkit, so it declares no attribute of its own. An app
     * names its vocabulary in config, per resolved theme, and both sets are answerable
     * whatever the mode - the browser picks between them.
     */
    public static function test_app_declared_attributes_are_answered_per_theme()
    {
        config(['rsx.theme.dark_mode.attributes' => [
            'dark' => ['data-test-theme' => 'dark'],
            'light' => ['data-test-theme' => 'light'],
        ]]);

        static::__set_mode(Login_User_Model::DARK_MODE_AUTO);

        static::__assert_equals(['data-test-theme' => 'dark'], Rsx_Dark_Mode::attributes_for(true));
        static::__assert_equals(['data-test-theme' => 'light'], Rsx_Dark_Mode::attributes_for(false));
    }

    /**
     * An app that themes purely off rsx-dark declares nothing, and that must be fine.
     */
    public static function test_no_declared_vocabulary_is_valid()
    {
        config(['rsx.theme.dark_mode.attributes' => ['dark' => [], 'light' => []]]);

        static::__assert_empty(Rsx_Dark_Mode::attributes_for(true));
        static::__assert_empty(Rsx_Dark_Mode::attributes_for(false));
    }

    /**
     * The view's body class carries the view id and nothing about the theme, which lives
     * on <html>.
     */
    public static function test_body_class_carries_no_theme()
    {
        static::__set_mode(Login_User_Model::DARK_MODE_DARK);

        static::__assert_false(str_contains(rsx_body_class(), 'rsx-dark'));
        static::__assert_false(str_contains(rsx_body_class(), 'rsx-theme-'));
    }

    // --- writing ---------------------------------------------------------------------------------

    /**
     * 'changed' reports whether the stored value moved.
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
