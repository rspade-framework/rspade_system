<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Bundle emission ORDER CONTRACT regression tests.
 *
 * A bundle's define() 'include' array order IS the JS evaluation order (with
 * dependency hoisting for known extends/decorator edges). Model JS stubs are
 * emitted AT their owning PHP file's include position, and the concrete model
 * aliases (class X_Model extends Base_X_Model {}) are spliced in immediately
 * after the stubs - so 'rsx/models' declared before the app directory means an
 * app class may reference X_Model at EVAL time (static X = Some_Model.CONST at
 * module scope) without dying in the temporal dead zone.
 *
 * Regression: a global resort of the assembled file list (plus tail-appended
 * stubs/aliases) inverted declared order and TDZ-crashed downstream apps.
 * See docs.dev/external_requests/2026_07_15_bundle_emission_order_tdz.md.
 *
 * These tests read the newest COMPILED Frontend_Bundle app JS from
 * storage/rsx-build/bundles (dev JIT keeps it current on this box). If no
 * compiled bundle exists the tests skip - the property under test is emission
 * order, which is independent of how fresh the bundle contents are.
 */
class Bundle_Emission_Order_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Line number of the first match of $pattern in $content, or null.
     */
    protected static function __first_line(string $content, string $pattern): ?int
    {
        $offset = 0;
        $line = 1;
        foreach (explode("\n", $content) as $i => $text) {
            $found = preg_match($pattern, $text);
            if ($found) {
                return $i + 1;
            }
        }

        return null;
    }

    protected static function __newest_frontend_app_bundle(): ?string
    {
        $files = glob(storage_path('rsx-build/bundles/Frontend_Bundle__app.*.js'));
        if (empty($files)) {
            return null;
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    public static function test_models_evaluate_before_app_actions()
    {
        $bundle = static::__newest_frontend_app_bundle();
        if ($bundle === null) {
            static::__skip('No compiled Frontend_Bundle app JS present');

            return;
        }

        $content = file_get_contents($bundle);

        $base_model = static::__first_line($content, '/^class Base_Contact_Model\b/');
        $alias = static::__first_line($content, '/^class Contact_Model extends Base_Contact_Model/');
        $action = static::__first_line($content, '/^class Contacts_Index_Action extends/');
        $js_model_base = static::__first_line($content, '/^class Rsx_Js_Model\b/');

        static::__assert_not_null($base_model, 'Base_Contact_Model stub present in bundle');
        static::__assert_not_null($alias, 'Contact_Model alias present in bundle');
        static::__assert_not_null($action, 'Contacts_Index_Action present in bundle');
        static::__assert_not_null($js_model_base, 'Rsx_Js_Model present in bundle');

        // The full chain an eval-time model reference depends on:
        // Rsx_Js_Model -> model stubs -> concrete aliases -> app classes.
        static::__assert_true($js_model_base < $base_model, 'Rsx_Js_Model precedes model stubs');
        static::__assert_true($base_model < $alias, 'stub precedes its concrete alias');
        static::__assert_true(
            $alias < $action,
            "model alias (line {$alias}) must precede app action (line {$action}) - "
            . 'declared include order (rsx/models before the app dir) is the eval-order contract'
        );
    }

    public static function test_theme_precedes_app_per_declared_include_order()
    {
        $bundle = static::__newest_frontend_app_bundle();
        if ($bundle === null) {
            static::__skip('No compiled Frontend_Bundle app JS present');

            return;
        }

        $content = file_get_contents($bundle);

        // Frontend_Bundle declares 'rsx/theme' before __DIR__ (the app module dir).
        $theme = static::__first_line($content, '/^class Entity_Link\b/');
        $action = static::__first_line($content, '/^class Contacts_Index_Action extends/');

        static::__assert_not_null($theme, 'Entity_Link (theme) present in bundle');
        static::__assert_not_null($action, 'Contacts_Index_Action present in bundle');
        static::__assert_true(
            $theme < $action,
            'theme classes precede app classes per the declared include order'
        );
    }
}
