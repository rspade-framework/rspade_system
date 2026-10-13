<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Database\Field_Length_Script;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Serves a compiled bundle's field-length script (Field_Length_Script) in both realms.
 *
 * PUBLIC, because the bundle it belongs to is: a page's bundle is fetched before anybody
 * has signed in, and this script goes with it. What bounds it is the NAME - only the name a
 * compiled bundle's own app script carries resolves, so the surface says nothing to a
 * caller who could not already fetch that bundle.
 */
#[Auth('public')]
#[Auth_Realm('any')]
class Field_Length_Controller extends Rsx_Controller_Abstract
{
    /**
     * GET /_field_lengths/<Bundle>__<hash>.js
     *
     * Any name that is not a compiled bundle's script is a 404. Served immutable when the
     * request's ?v= is the current state key - the URL the page was given - and uncacheable
     * otherwise: the body under any other key is still the current schema's, and must not
     * be stored under a URL that names another state.
     *
     * @param array $params script, v
     */
    #[Route('/_field_lengths/:script', methods: ['GET'])]
    #[Portal_Route('/_field_lengths/:script', methods: ['GET'])]
    public static function script(Request $request, array $params = [])
    {
        $bundle_name = Field_Length_Script::resolve((string) ($params['script'] ?? ''));

        if ($bundle_name === null) {
            return Response::make('Not found', 404, ['Content-Type' => 'text/plain']);
        }

        $current = ((string) ($params['v'] ?? '')) === Manifest::get_state_key();

        return Response::make(Field_Length_Script::body($bundle_name), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'no-store',
        ]);
    }
}
