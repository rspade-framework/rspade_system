<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Openapi;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;

/**
 * Openapi_Api_Controller - GET /api/vN/openapi.json
 *
 * The API's own OpenAPI 3.1 description, read with an API key: the input for a client
 * generator, a contract test or an agent toolchain, fetched from the version it calls. The
 * ONE way a program obtains the document - an /apidocs console's openapi.json link is the
 * same document downloaded by a person signed in to a browser.
 *
 * WHY THE FRAMEWORK OWNS IT. Like /me, it has exactly one sensible shape and every API needs
 * it; an application-declared copy per version drifted from the versions actually served and
 * made generic tooling guess the path. Here the path, verb and content are a contract across
 * every install.
 *
 * ONE VERSION'S SURFACE. The document is the version the request addressed
 * (Api_Openapi::document(null, N): per verb + path, the newest endpoint at or below N, at its
 * real path; info.version = vN), the full published surface rather than the caller's
 * reachable part, so a generated client is the same whoever fetched it. It carries x-build,
 * the build that answered.
 *
 * UNSTAMPED. Answered through Rsx_Api::document(): the body is the document exactly, without
 * the _duration_ms / _completed_at every other answer carries, which an OpenAPI root does not
 * admit.
 *
 * ANY KEY WITH API ACCESS READS IT. The gate is /me's (is_logged_in: the key is valid and its
 * user has API access, users.is_api_access_enabled - the application's Main::pre_dispatch may
 * still refuse an account it does not admit to the API). Key SCOPES never refuse it:
 * Api_Scopes grants /api/vN/openapi.json to every scope set, because the document describes
 * calls, not data, and a scoped integration still needs the description of what it calls. A
 * read-only key reads it (GET). It lists itself like any endpoint, under its own resource.
 *
 * SERVED AT EVERY VERSION THE APPLICATION SERVES (#[Api_Versions(every: true)]), as /me is.
 */
#[Auth('is_logged_in')]
#[Api_Versions(every: true)]
#[Api_Resource('OpenAPI')]
class Openapi_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * The OpenAPI 3.1 description of the API version this request addressed.
     *
     * @api-response
     * {
     *   "openapi": "3.1.0",
     *   "info": { "title": "RSpade API", "version": "v1" },
     *   "paths": {},
     *   "x-build": {
     *     "commit": "2e262230537ac17f9897277513a9c6b6341f83b5",
     *     "built_at": "2026-11-10T23:00:29.000Z",
     *     "framework_revision": "2e262230537ac17f9897277513a9c6b6341f83b5",
     *     "code_key": "4f1c2a9be07d3e55"
     *   }
     * }
     */
    #[Api_Endpoint('/api/v1/openapi.json', methods: ['GET'])]
    public static function document(Request $request, array $params = [])
    {
        // The version is the one the dispatcher routed: a follower's handler is the same at
        // every version, so the address is the only thing that says which one was asked for.
        $version = Api_Catalog::parse_version('/' . ltrim($request->path(), '/'));

        if ($version === 0) {
            shouldnt_happen("Openapi_Api_Controller::document() was dispatched for '{$request->path()}', which has no /api/vN prefix.");
        }

        return Rsx_Api::document(Api_Openapi::document(null, $version));
    }
}
