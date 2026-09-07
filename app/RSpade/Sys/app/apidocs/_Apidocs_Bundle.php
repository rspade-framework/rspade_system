<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Apidocs;

use App\RSpade\Core\Api\Rsx_Api_Docs;
use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

/**
 * _Apidocs_Bundle - the API reference console's assets. FRAMEWORK-OWNED, like the console.
 *
 * The application declares a route and an #[Auth] gate; everything behind that route is the
 * framework's, this bundle included. Rsx_Api_Docs::page() renders it - an application never
 * names it, never subclasses it and never adds to it.
 *
 * THE INVARIANT: THIS INCLUDE LIST NAMES NO rsx/ PATH AND NO APP-DEFINED CLASS.
 *
 * The console is framework code that happens to be mounted on an application route, and a
 * framework feature does not get to depend on the application hosting it. An app is free to
 * restyle its theme, redefine its Bootstrap build, or replace its Modal outright - all of
 * which are its own business, and any of which would break a console that had borrowed them.
 * So the console draws on the framework's OWN application tree instead: _Sys_Theme_Bundle's
 * Bootstrap build and --rsx-* tokens, the components beside them, and its own dialog
 * (_Apidocs_Confirm_Dialog). Nothing here reaches into rsx/, and CONV-BUNDLE-04 enforces
 * that for every framework-owned bundle.
 *
 * The remaining benefit is mutual: the console cannot disturb - or be disturbed by - the
 * rest of the app, because the two share no stylesheet at all.
 *
 * WHY THIS CAN BE FRAMEWORK-SIDE AT ALL. Rsx_Bundle_Abstract::__validate_path_coverage()
 * makes two checks and a FRAMEWORK VIEW (a view path starting `app/RSpade/`) waives both:
 * the view-coverage check at :880, and the controller-coverage check at :897-900, which a
 * framework bundle could never satisfy for an application controller under rsx/. When the
 * page's markup is the framework's, the app controller contributes nothing to the page but
 * its route, and there is no app JS for the bundle to be missing. _Apidocs_App.blade.php is
 * such a view.
 */
class _Apidocs_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',
                'lodash',
                '_Sys_Theme_Bundle',            // Bootstrap + the framework application's design tokens
                'app/RSpade/Sys/theme',         // the framework application's own components
                'app/RSpade/Sys/lib',           // shared code (classes only)
                __DIR__,                         // the console itself, and _Apidocs_Vendor_Bundle beside it
            ],
        ];
    }

    /**
     * The console needs its endpoint catalog baked into the page; the framework builds it.
     */
    public static function load_rsxapp_data(): array
    {
        return Rsx_Api_Docs::rsxapp_data();
    }
}
