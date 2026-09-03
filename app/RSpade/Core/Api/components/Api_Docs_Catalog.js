/**
 * Api_Docs_Catalog - synchronous reader over the endpoint catalog baked into the page.
 *
 * The catalog is shipped in window.rsxapp.page_data.api_catalog by the app's docs bundle
 * (see Api_Docs_Bundle::load_rsxapp_data), so every lookup here is SYNCHRONOUS. That is the
 * point: the docs tool navigates between endpoints constantly, and an async catalog meant a
 * round trip plus a loading flash on every sidebar click for data that never changes within
 * a page load.
 *
 * Consequences worth knowing:
 *   - components read the catalog in on_create(), so the first paint is already correct;
 *   - there is no loading state anywhere in the docs UI, because there is nothing to wait for;
 *   - switching version is a client-side re-render, since every version is exported.
 */
class Api_Docs_Catalog {
    /**
     * Slugs are assigned once per page load, on first access.
     */
    static _slugged = false;

    /**
     * The raw exported catalog, or null when the page did not ship one.
     */
    static raw() {
        const page_data = (window.rsxapp && window.rsxapp.page_data) || null;

        return page_data?.api_catalog || null;
    }

    /**
     * True when there is a catalog with at least one version.
     */
    static exists() {
        const cat = Api_Docs_Catalog.raw();

        return !!cat?.versions?.length;
    }

    /**
     * Every version present, newest first.
     */
    static versions() {
        const cat = Api_Docs_Catalog.raw();

        return cat?.versions || [];
    }

    /**
     * The newest version, or null when the catalog is empty.
     */
    static newest_version() {
        const versions = Api_Docs_Catalog.versions();

        return versions.length ? versions[0] : null;
    }

    /**
     * Resolve a requested version to one that exists. An unknown or absent version falls
     * back to the newest rather than erroring - a stale link is a reason to show something,
     * not to break the tool.
     */
    static resolve_version(requested) {
        const versions = Api_Docs_Catalog.versions();

        if (requested && versions.indexOf(int(requested)) !== -1) {
            return int(requested);
        }

        return Api_Docs_Catalog.newest_version();
    }

    /**
     * The resource-grouped endpoint map for one version: {resource_key: {name, endpoints}}.
     */
    static groups(version) {
        const cat = Api_Docs_Catalog.raw();

        if (!cat || !cat.by_version) {
            return {};
        }

        Api_Docs_Catalog._assign_slugs();

        return cat.by_version[Api_Docs_Catalog.resolve_version(version)] || {};
    }

    /**
     * One endpoint by slug within a version, or null when the slug names nothing.
     */
    static find_endpoint(version, slug) {
        if (!slug) {
            return null;
        }

        const groups = Api_Docs_Catalog.groups(version);

        for (const rk of Object.keys(groups)) {
            for (const ep of groups[rk].endpoints) {
                if (ep._slug === slug) {
                    return ep;
                }
            }
        }

        return null;
    }

    /**
     * Stamp a stable _slug onto every endpoint, once per page load.
     *
     * resource + method, which is unique because a method appears once per controller and a
     * resource maps to one controller. It is the user-visible ?endpoint= value, hence
     * 'clients-list' rather than an FQCN.
     */
    static _assign_slugs() {
        if (Api_Docs_Catalog._slugged) {
            return;
        }

        const cat = Api_Docs_Catalog.raw();

        if (!cat || !cat.by_version) {
            return;
        }

        for (const version of Object.keys(cat.by_version)) {
            const groups = cat.by_version[version];

            for (const rk of Object.keys(groups)) {
                for (const ep of groups[rk].endpoints) {
                    ep._slug = (rk + '-' + ep.method)
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/(^-+|-+$)/g, '');
                }
            }
        }

        Api_Docs_Catalog._slugged = true;
    }

    /**
     * The multipart file part an endpoint declares, or null when it declares none.
     *
     * ONE PREDICATE, read from the catalog rather than repeated at each site. A `file` param
     * is POST-only, can never be a :route token and can never carry a default (all three are
     * manifest-scan failures), so its presence is the whole test for "this endpoint takes
     * multipart/form-data" - and the PART NAME is read from here rather than assumed, because
     * nothing in the contract fixes it to 'file'.
     */
    static file_param(ep) {
        for (const p of (ep?.api_params || [])) {
            if (p.type === 'file') {
                return p;
            }
        }

        return null;
    }

    /**
     * True when the endpoint's request body is multipart/form-data rather than JSON.
     */
    static is_file_endpoint(ep) {
        return !!Api_Docs_Catalog.file_param(ep);
    }

    /**
     * Whether the page is listing only what the adopted key may call.
     */
    static is_restricted() {
        const cat = Api_Docs_Catalog.raw();

        return !!cat?.restricted;
    }

    /**
     * The identifying prefix of the adopted key, or null when none is adopted. Never the
     * secret - only its prefix is ever sent to the page.
     */
    static key_prefix() {
        const cat = Api_Docs_Catalog.raw();

        return cat?.key_prefix || null;
    }

    /**
     * Whether the adopted key carries scopes: true (scoped), false (unrestricted), or
     * null when no key is adopted.
     *
     * Three-valued deliberately - see Rsx_Api_Docs::rsxapp_data. A listing drawn for a
     * scoped key is a listing of what THAT CREDENTIAL can reach, which is a narrower claim
     * than what its owner can reach, and the console says which.
     */
    static key_scoped() {
        const cat = Api_Docs_Catalog.raw();

        if (!cat || !isset(cat.key_scoped)) {
            return null;
        }

        return cat.key_scoped;
    }

    /**
     * Is the adopted key READ-ONLY (true), read+write (false), or is there no adopted key
     * (null)?
     *
     * Three-valued for the same reason key_scoped() is. A read-only key's listing is missing
     * every write endpoint, and a reader who does not know that reads the absence as "this
     * API has no write endpoints".
     */
    static key_read_only() {
        const cat = Api_Docs_Catalog.raw();

        if (!cat || !isset(cat.key_read_only)) {
            return null;
        }

        return cat.key_read_only;
    }

    /**
     * Total endpoints listed for a version, across every resource.
     */
    static endpoint_count(version) {
        let total = 0;

        for (const group of Object.values(Api_Docs_Catalog.groups(version))) {
            total += group.endpoints.length;
        }

        return total;
    }

    /**
     * The path the console is mounted at.
     *
     * Exported by the server rather than hardcoded: the APPLICATION chooses where the
     * console lives (Rsx_Api_Docs::page), so the framework cannot assume '/apidocs'.
     */
    static base_path() {
        const cat = Api_Docs_Catalog.raw();

        return cat?.base_path || '/';
    }

    /**
     * URL of the OpenAPI document, derived from where the console is mounted.
     *
     * NOT Rsx.Route(): the framework declares no route for the console or the document -
     * the APPLICATION declares both - so there is no framework route target to name. The
     * document sits beside the console at <mount>/openapi.json, which is the contract the
     * template's Apidocs_Controller implements.
     */
    static openapi_url() {
        return Api_Docs_Catalog.base_path().replace(/\/+$/, '') + '/openapi.json';
    }

    /**
     * The console's landing page for a version.
     *
     * The version rides in the query string rather than the path, because the path belongs
     * to the application's route and may carry no version segment at all. It is omitted when
     * it is the newest, so the ordinary URL stays clean.
     */
    static home_url(version) {
        const resolved = Api_Docs_Catalog.resolve_version(version);
        const base = Api_Docs_Catalog.base_path();

        return resolved === Api_Docs_Catalog.newest_version()
            ? base
            : base + '?version=' + encodeURIComponent(resolved);
    }

    /**
     * The console's page for one endpoint.
     */
    static endpoint_url(version, ep) {
        const resolved = Api_Docs_Catalog.resolve_version(version);
        const base = Api_Docs_Catalog.base_path();
        const parts = ['endpoint=' + encodeURIComponent(ep._slug)];

        if (resolved !== Api_Docs_Catalog.newest_version()) {
            parts.push('version=' + encodeURIComponent(resolved));
        }

        return base + '?' + parts.join('&');
    }
}
