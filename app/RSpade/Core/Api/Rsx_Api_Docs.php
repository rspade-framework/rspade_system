<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Openapi;
use App\RSpade\Core\Api\Api_Tester_Key;
use App\RSpade\Core\Files\Markdown_Rendition;

/**
 * Rsx_Api_Docs - the app-facing entry point for the API reference console.
 *
 * THE APPLICATION OWNS THE ROUTE AND THE GATE; the framework owns EVERYTHING ELSE - the
 * markup, the components, the styles and the bundle. The integration is one call, with the
 * simplicity of phpinfo(): there is nothing here for an application to restyle, extend or
 * carry a copy of. The one thing an application may hand it is CONTENT - a few static
 * sections of its own (an error-code inventory, its conventions), as Markdown, which the
 * console lists in its navigation beside the resources (see page()).
 *
 *     #[Auth('can_manage_integrations')]
 *     class Developer_Tools_Controller extends Rsx_Controller_Abstract
 *     {
 *         #[Route('/dev/api-reference')]
 *         public static function api_docs(Request $request, array $params = [])
 *         {
 *             return Rsx_Api_Docs::page();
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
 * There is no framework route for the console and no framework visibility setting: the
 * console exists if and only if an application declared a route for it, and it is protected
 * by whatever #[Auth] that route carries. That is a better answer than any config value could be - the developer
 * already has a permission vocabulary, and this reuses it instead of inventing a parallel
 * one that cannot express "our integrations team only".
 *
 * THE DOCUMENT A PROGRAM FETCHES IS THE FRAMEWORK'S: GET /api/vN/openapi.json
 * (Openapi_Api_Controller), read with any API key. The <mount>/openapi.json route above is
 * the console's own link - the same document downloaded by a person signed in to the
 * browser that shows the console, gated like the console. The console links to it there,
 * so a console mount declares both.
 *
 * page() renders a RESPONSE; openapi_document() returns an ARRAY, and the console route's
 * controller sets its own headers (inline or download), because that route is the
 * application's.
 */
class Rsx_Api_Docs
{
    /**
     * Whether the console being rendered lists only what the adopted key may call.
     *
     * Request-scoped, set by page() and read by rsxapp_data(), which _Apidocs_Bundle's
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
     * The application's own sections, validated and rendered by page() and exported by
     * rsxapp_data(): a list of {slug, title, html}. Request-scoped like the two above.
     */
    private static array $_sections = [];

    /**
     * Render the API reference console - the whole page, bundle and all.
     *
     * The framework supplies its own module bundle (_Apidocs_Bundle) rather than taking one:
     * the page hosts framework components exclusively, so an application bundle could only
     * ever be a wrapper naming framework directories. The request is read from the container
     * rather than passed, so the call site carries no argument it does not choose.
     *
     * $restrict_to_key (default TRUE) lists only the endpoints whose #[Auth] gates pass for
     * the API key the viewer supplied, scoped to the KEY'S user rather than the viewer's -
     * the console is answering "what can this integration do". With no key supplied nothing
     * is listed and the landing card asks for one.
     *
     * Pass false to list every published endpoint. That is about ADVERTISING, not access:
     * Api_Dispatcher gates every actual call regardless of what this page chose to show.
     *
     * $sections is the application's own prose - each ['title' => ..., 'markdown' => ...],
     * listed in that order under "Guides" at the top of the console's navigation and shown
     * as a page of its own at <mount>?section=<slug>, the slug derived from the title. They
     * are static and shown whatever key is adopted: a section documents the API as a whole
     * (its error codes and what to do about each, its conventions), never one endpoint - that
     * is the endpoint's docblock. A table is a Markdown (GFM) table. The Markdown goes
     * through Markdown_Rendition::render(), so raw HTML in it is discarded, never passed
     * through. A malformed section throws here, at the application's call.
     */
    public static function page(bool $restrict_to_key = true, array $sections = [])
    {
        self::$_restrict_to_key = $restrict_to_key;
        self::$_base_path = '/' . ltrim(request()->path(), '/');
        self::$_sections = self::__render_sections($sections);

        return rsx_view('_Apidocs_App');
    }

    /**
     * The console's window.rsxapp.page_data payload, for _Apidocs_Bundle's
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
                'sections' => self::$_sections,
            ],
        ];
    }

    /**
     * Validate the application's sections and render each one's Markdown, once, at page().
     *
     * Exactly the two keys, both non-empty strings: an unknown key is a typo ('content',
     * 'body') that would otherwise publish an empty page. The slug is the ?section= value,
     * so two titles that slug alike would make one section unreachable - refused.
     *
     * @return array list of {slug, title, html}
     */
    private static function __render_sections(array $sections): array
    {
        if (!array_is_list($sections)) {
            throw new \InvalidArgumentException('Rsx_Api_Docs::page() $sections must be a list of [\'title\' => ..., \'markdown\' => ...] arrays.');
        }

        $out = [];
        $seen = [];

        foreach ($sections as $i => $section) {
            if (!is_array($section)) {
                throw new \InvalidArgumentException("Rsx_Api_Docs::page() section {$i} must be an array with the keys 'title' and 'markdown'.");
            }

            $unknown = array_diff(array_keys($section), ['title', 'markdown']);

            if (!empty($unknown)) {
                throw new \InvalidArgumentException("Rsx_Api_Docs::page() section {$i} has unknown key(s) '" . implode("', '", $unknown) . "'; a section is exactly 'title' and 'markdown'.");
            }

            foreach (['title', 'markdown'] as $key) {
                if (!isset($section[$key]) || !is_string($section[$key]) || trim($section[$key]) === '') {
                    throw new \InvalidArgumentException("Rsx_Api_Docs::page() section {$i} needs a non-empty string '{$key}'.");
                }
            }

            $title = trim($section['title']);
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');

            if ($slug === '') {
                throw new \InvalidArgumentException("Rsx_Api_Docs::page() section title '{$title}' has no letter or digit to name its URL by.");
            }

            if (isset($seen[$slug])) {
                throw new \InvalidArgumentException("Rsx_Api_Docs::page() sections '{$seen[$slug]}' and '{$title}' share the URL slug '{$slug}'; give them distinct titles.");
            }

            $seen[$slug] = $title;

            $out[] = [
                'slug' => $slug,
                'title' => $title,
                'html' => Markdown_Rendition::render($section['markdown']),
            ];
        }

        return $out;
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
     * For the console's own <mount>/openapi.json link: the controller that declared that
     * route owns its status, its headers and whether the browser renders the JSON inline or
     * downloads it. The class docblock above shows the shape of a caller;
     * reference_app/app/apidocs/apidocs_controller.php is the worked one.
     *
     * $accessible_targets narrows the document to one identity's reachable endpoints - pass
     * Api_Tester_Key::accessible_targets_for_user($user). Omit it for the full surface, which
     * is what a public openapi.json wants.
     *
     * $version narrows it to one API version's surface, as the docs page shows that version
     * (the input for generating one version's client); omit it for every version.
     *
     * A KEY HOLDER reads it from the framework's GET /api/vN/openapi.json
     * (Openapi_Api_Controller), never from an application route.
     *
     * @return array The OpenAPI 3.1 document
     */
    public static function openapi_document(?array $accessible_targets = null, ?int $version = null): array
    {
        return Api_Openapi::document($accessible_targets, $version);
    }

    /**
     * The path the console is mounted at.
     */
    public static function base_path(): string
    {
        return self::$_base_path;
    }
}
