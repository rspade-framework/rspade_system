<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Openapi;
use App\RSpade\Core\Api\Api_Tester_Key;

/**
 * Rsx_Api_Docs - the app-facing entry point for the API reference console.
 *
 * THE APPLICATION OWNS THE ROUTE AND THE GATE; the framework owns what renders inside it:
 *
 *     #[Auth('can_manage_integrations')]
 *     class Developer_Tools_Controller extends Rsx_Controller_Abstract
 *     {
 *         #[Route('/dev/api-reference')]
 *         public static function api_docs(Request $request, array $params = [])
 *         {
 *             return Rsx_Api_Docs::page($request);
 *         }
 *
 *         #[Route('/dev/api-reference/openapi.json')]
 *         public static function api_openapi(Request $request, array $params = [])
 *         {
 *             return response()->json(Rsx_Api_Docs::openapi_document(), 200, [
 *                 'Content-Type' => 'application/json; charset=utf-8',
 *                 'Content-Disposition' => 'attachment; filename="openapi.json"',
 *             ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
 *         }
 *     }
 *
 * There is no framework route and no framework visibility setting: the console exists if and
 * only if an application declared a route for it, and it is protected by whatever #[Auth]
 * that route carries. That is a better answer than any config value could be - the developer
 * already has a permission vocabulary, and this reuses it instead of inventing a parallel
 * one that cannot express "our integrations team only".
 *
 * The page and the document are SEPARATE calls on purpose. Gating them differently is a real
 * requirement: a public openapi.json beside a staff-only console is a normal arrangement, and
 * one combined helper would force them to share a gate.
 *
 * They are also different KINDS of call: page() renders a RESPONSE, openapi_document()
 * returns an ARRAY. The framework has no business deciding the document's content type or
 * whether the browser renders it inline or downloads it - the route's controller sets its
 * own headers, because the route is what the application owns.
 */
class Rsx_Api_Docs
{
    /**
     * Whether the console being rendered lists only what the adopted key may call.
     *
     * Request-scoped, set by page() and read by rsxapp_data(), which the application bundle's
     * load_rsxapp_data() forwards to. That hook is a static with no arguments, so this is how
     * the caller's choice reaches it. Never assigned outside page().
     */
    private static bool $_restrict_to_key = true;

    /**
     * The path the console is mounted at, so its own links can be built without the
     * framework guessing where the application put it.
     */
    private static string $_base_path = '/';

    /**
     * Render the API reference console.
     *
     * $restrict_to_key (default TRUE) lists only the endpoints whose #[Auth] gates pass for
     * the API key the viewer supplied, scoped to the KEY'S user rather than the viewer's -
     * the console is answering "what can this integration do". With no key supplied nothing
     * is listed and the landing card asks for one.
     *
     * Pass false to list every published endpoint. That is about ADVERTISING, not access:
     * Api_Dispatcher gates every actual call regardless of what this page chose to show.
     */
    public static function page(Request $request, string $bundle, bool $restrict_to_key = true)
    {
        self::$_restrict_to_key = $restrict_to_key;
        self::$_base_path = '/' . ltrim($request->path(), '/');

        return rsx_view('Api_Docs_App', ['bundle' => $bundle]);
    }

    /**
     * The console's window.rsxapp.page_data payload, for the application bundle's
     * load_rsxapp_data() hook.
     *
     * The catalog is BAKED INTO THE PAGE rather than fetched: the console navigates between
     * endpoints constantly and the catalog is identical for every one of them, so a fetch per
     * navigation was a round trip and a loading flash for data already known at render.
     *
     * Every version is exported, not just the selected one, so the version selector is a
     * client-side redraw rather than another request.
     */
    public static function rsxapp_data(): array
    {
        $restricted = self::$_restrict_to_key;

        // Resolved ONCE for the whole export, not per version: it establishes and tears down
        // a headless API identity, and repeating that in one render would needlessly widen
        // the window in which that identity exists.
        $accessible = $restricted ? Api_Tester_Key::accessible_targets() : null;

        $by_version = [];

        foreach (Api_Catalog::get_versions() as $version) {
            $groups = Api_Catalog::resolve_for_version($version);

            if ($restricted) {
                $groups = self::__restrict_groups($groups, $accessible);
            }

            $by_version[$version] = $groups;
        }

        return [
            'api_catalog' => [
                'versions' => Api_Catalog::get_versions(),
                'by_version' => $by_version,
                'restricted' => $restricted,
                'key_prefix' => Api_Tester_Key::current_prefix(),
                // Three-valued: null when no key is adopted, otherwise whether that key
                // carries scopes. The console says which, because "everything this
                // key can reach" and "everything its owner can reach" are different
                // sentences and the reader cannot tell them apart from a listing alone.
                'key_scoped' => Api_Tester_Key::current_is_scoped(),
                // Three-valued in the same way, and a different sentence again: a read-only
                // key lists only the endpoints it may GET, and the reader is entitled to
                // know that is why the write endpoints are absent.
                'key_read_only' => Api_Tester_Key::current_is_read_only(),
                'base_path' => self::$_base_path,
            ],
        ];
    }

    /**
     * Keep only the endpoints the adopted key may call, dropping any resource left empty.
     *
     * A null $accessible means NO KEY IS ADOPTED, which lists nothing: when the listing is
     * restricted the key IS the question being asked, and answering it with the full
     * catalogue would defeat the setting. The landing card says so rather than the page
     * looking broken.
     */
    private static function __restrict_groups(array $groups, ?array $accessible): array
    {
        if ($accessible === null) {
            return [];
        }

        $out = [];

        foreach ($groups as $resource => $group) {
            $endpoints = [];

            foreach ($group['endpoints'] as $endpoint) {
                $target = $endpoint['class'] . '::' . $endpoint['method'];

                if (!empty($accessible[$target])) {
                    $endpoints[] = $endpoint;
                }
            }

            if (!empty($endpoints)) {
                $group['endpoints'] = $endpoints;
                $out[$resource] = $group;
            }
        }

        return $out;
    }

    /**
     * The OpenAPI 3.1 description of the API, as DATA.
     *
     * The framework produces the document; the APPLICATION produces the response. The
     * controller that declared the route owns its status, its headers and whether the
     * browser renders the JSON inline or downloads it - a presentation decision belonging
     * to the route, not to the generator. The class docblock above shows the shape of a
     * caller; reference_app/app/apidocs/apidocs_controller.php is the worked one.
     *
     * $accessible_targets narrows the document to one identity's reachable endpoints - pass
     * Api_Tester_Key::accessible_targets_for_user($user). Omit it for the full surface, which
     * is what a public openapi.json wants.
     *
     * @return array The OpenAPI 3.1 document
     */
    public static function openapi_document(?array $accessible_targets = null): array
    {
        return Api_Openapi::document($accessible_targets);
    }

    /**
     * The path the console is mounted at.
     */
    public static function base_path(): string
    {
        return self::$_base_path;
    }
}
