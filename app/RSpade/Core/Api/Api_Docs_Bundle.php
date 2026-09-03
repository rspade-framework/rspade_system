<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Api\Rsx_Api_Docs;
use App\RSpade\Core\Bundle\Rsx_Module_Bundle_Abstract;

/**
 * Api_Docs_Bundle - the API reference console's assets. FRAMEWORK-OWNED, like the console.
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
 * So the console ships its own equivalents: the midnight look is self-contained --api-*
 * custom properties in Core/Api/components, and the one dialog it needs is
 * Api_Confirm_Dialog, beside them. Nothing here reaches into rsx/, and CONV-BUNDLE-04
 * enforces that for every framework-owned bundle.
 *
 * The remaining benefit is mutual: the console cannot disturb - or be disturbed by - the
 * rest of the app, because the two share no stylesheet at all.
 *
 * WHY THIS CAN BE FRAMEWORK-SIDE AT ALL. Rsx_Bundle_Abstract validates at render time that
 * the bundle covers the directory of the CONTROLLER that dispatched the page - which an app
 * controller under rsx/ never is, for a framework bundle. That check is waived for a
 * FRAMEWORK VIEW (app/RSpade/**), symmetrically with the view-coverage check beside it: when
 * the page's markup is the framework's, the app controller contributes nothing to the page
 * but its route, and there is no app JS for the bundle to be missing. Api_Docs_App.blade.php
 * is such a view. See Rsx_Bundle_Abstract::__validate_path_coverage().
 */
class Api_Docs_Bundle extends Rsx_Module_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',
                'lodash',
                'app/RSpade/Core/Api',   // the console components + the vendor asset bundle
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
