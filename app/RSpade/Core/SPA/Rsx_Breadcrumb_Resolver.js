/**
 * Rsx_Breadcrumb_Resolver - Progressive breadcrumb resolution with caching
 *
 * Provides instant breadcrumb rendering using cached data while resolving
 * live labels progressively in the background.
 *
 * Resolution Flow:
 * 1. Keep previous breadcrumbs visible initially
 * 2. When the URL carries a breadcrumb origin (_via), wait for the keep/strip
 *    decision (settle_via()) - nothing is emitted before it, so a stripped origin
 *    never flashes a grafted trail
 * 3. Try to load cached chain - if complete, display immediately
 * 4. Walk breadcrumb_parent() / breadcrumb_parent_via() chain to get structure (URLs)
 * 5. Compare structure to cache:
 *    - Different length or no cache shown: render skeleton with blank labels
 *    - Same length: render skeleton pre-populated with cached labels
 * 6. Progressively resolve each label via breadcrumb_label()/breadcrumb_label_active()
 * 7. Cache final result when all labels resolved, and tell every layout in the chain
 *    (Spa_Layout.on_breadcrumb_complete(chain, meta))
 *
 * BREADCRUMB ORIGINS. A page reached from somewhere other than its home may show THAT
 * trail: the URL carries origin tokens in the reserved _via parameter, a page that
 * accepts the token's type (static breadcrumb_via) verifies it in
 * breadcrumb_parent_via(via) and names the alternate parent, and the walk passes the
 * remaining tokens up the chain. settle_via() decides, once per navigation, whether the
 * address bar keeps the _via or loses it (history.replaceState, never a new entry).
 * See rsx:man breadcrumb_origins.
 *
 * Every crumb carries url (its page's identity - matching, caching, the sidebar) and href
 * (the link to render): for a crumb above the current page, the page in the state the user
 * left it in when they came through it on this trail (Spa.return_url(), Spa_Journal). The
 * walk records each page's trail on its journal entry for that rule.
 *
 * Callbacks receive a meta argument: {grafted, root_url, decider} - whether an origin
 * replaced a parent somewhere in the trail, the URL the trail starts at, and the
 * identity of the page that accepted the URL's first origin (or null).
 *
 * Important: Actions that need loaded data in breadcrumb methods should
 * await their own 'loaded' event internally, not rely on ready() being called.
 *
 * Usage:
 *   const cancel = Rsx_Breadcrumb_Resolver.stream(action, url, callbacks);
 */
class Rsx_Breadcrumb_Resolver {
    /**
     * Generation counter for cancellation detection.
     */
    static _generation = 0;

    /**
     * Cache key prefix for breadcrumb chains
     */
    static CACHE_PREFIX = 'breadcrumb_chain:';

    /**
     * The longest trail the walk builds. A deeper chain is a parent loop that the URL
     * guard cannot see (each step a different URL); the walk stops there with a warning.
     */
    static MAX_DEPTH = 20;

    /**
     * Stream breadcrumb data as it becomes available
     *
     * @param {Spa_Action} action - The active action instance
     * @param {string} url - Current URL (pathname + search, no hash)
     * @param {object} callbacks - Callback functions:
     *   - on_chain(chain, meta): Called when chain structure is ready (may have null labels);
     *     each item {url, href, label, is_active, resolved} - render href, compare url
     *   - on_label_update(index, label): Called when individual label resolves
     *   - on_complete(chain, meta): Called when all labels resolved
     *   meta = {grafted: bool, root_url: string, decider: string|null}
     * @returns {Function} Cancel function - call to stop streaming
     */
    static stream(action, url, callbacks) {
        const generation = ++Rsx_Breadcrumb_Resolver._generation;

        // Normalize URL (strip hash if present)
        const normalized_url = Rsx_Breadcrumb_Resolver._normalize_url(url);

        // Start async resolution
        Rsx_Breadcrumb_Resolver._resolve(action, normalized_url, generation, callbacks);

        // Return cancel function
        return () => {
            if (generation === Rsx_Breadcrumb_Resolver._generation) {
                Rsx_Breadcrumb_Resolver._generation++;
            }
        };
    }

    /**
     * Settle a page's breadcrumb origin: keep the URL's _via or strip it.
     *
     * Called by Spa for every action it creates, and by stream(); the first call for an
     * action does the work and every later one gets the same promise. With no _via in the
     * URL it resolves at once. Otherwise:
     *   - a malformed value is ignored and stripped (console_debug names it);
     *   - more than MAX_TOKENS tokens are cut from the END (the oldest origins);
     *   - the trail is walked with the tokens pending, and the page that accepts the FIRST
     *     token is the DECIDER (its class + its URL without _via and fragment);
     *   - KEEP when there is a decider and the _via arrived explicitly (a link that named
     *     it, a reload, a pasted URL, a new tab), or arrived CARRIED and the decider is the
     *     one the page being left had, or arrived by Back/Forward and the decider is the
     *     one this entry recorded; otherwise STRIP.
     * The decision is written with history.replaceState() - the address bar's _via
     * canonicalised or removed, and the decider recorded in the entry's state as
     * via_decider - never as a new entry, and only while this action is still the page.
     *
     * @param {Spa_Action} action
     * @param {string} url - The action's URL
     * @param {object|null} navigation - {arrival, previous} from Spa; null = explicit
     * @returns {Promise<{url: string, kept: boolean, decider: string|null, walk: object|null}>}
     *   url is the trail's URL after the decision (no fragment); walk is the kept trail.
     */
    static settle_via(action, url, navigation) {
        if (!action._spa_via_settlement) {
            action._spa_via_settlement = Rsx_Breadcrumb_Resolver._settle(
                action,
                Rsx_Breadcrumb_Resolver._normalize_url(url),
                navigation || { arrival: 'explicit', previous: null }
            );
        }

        return action._spa_via_settlement;
    }

    static async _settle(action, url, navigation) {
        const raw = Spa_Via.raw(url);

        if (raw === null) {
            return { url: url, kept: false, decider: null, walk: null };
        }

        const parsed = Spa_Via.parse_value(raw);

        if (!parsed.valid) {
            console_debug('Spa', `Ignoring malformed _via "${raw}" - stripped. Grammar: type[.id][,type[.id]...]; see rsx:man breadcrumb_origins`);
            Rsx_Breadcrumb_Resolver._write_decision(action, null, null);
            return { url: Spa_Via.without(url), kept: false, decider: null, walk: null };
        }

        if (parsed.truncated) {
            console_debug('Spa', `_via "${raw}" has more than ${Spa_Via.MAX_TOKENS} origins - the oldest were dropped`);
        }

        const canonical_url = Spa_Via.with_value(url, parsed.tokens);
        const walk = await Rsx_Breadcrumb_Resolver._walk_chain_structure(action, canonical_url, null, parsed.tokens);

        if (!walk) {
            return { url: canonical_url, kept: false, decider: null, walk: null };
        }

        // What the decider is judged against. A carried hop compares with the decision of
        // the page being left (a promise of it); a Back/Forward with what its entry recorded.
        let previous = navigation.previous;
        if (previous && typeof previous.then === 'function') {
            previous = (await previous).decider;
        }

        let kept;
        if (walk.decider === null) {
            kept = false;
        } else if (navigation.arrival === 'carried') {
            kept = previous === walk.decider;
        } else if (navigation.arrival === 'restored' && previous) {
            kept = previous === walk.decider;
        } else {
            kept = true;
        }

        if (kept) {
            Rsx_Breadcrumb_Resolver._write_decision(action, Spa_Via.serialize(parsed.tokens), walk.decider);
            return { url: canonical_url, kept: true, decider: walk.decider, walk: walk };
        }

        console_debug('Spa', `_via "${raw}" stripped: ` + (walk.decider === null
            ? 'no page in the trail accepted its first origin'
            : `${walk.decider} is not the page that decided it before`));
        Rsx_Breadcrumb_Resolver._write_decision(action, null, null);

        return { url: Spa_Via.without(url), kept: false, decider: null, walk: null };
    }

    /**
     * Write a keep/strip decision onto the CURRENT history entry, in place. Nothing is
     * written when the action is no longer the page on screen - the entry belongs to
     * whatever replaced it.
     *
     * @param {Spa_Action} action
     * @param {string|null} value - The _via to keep, or null to strip it
     * @param {string|null} decider
     */
    static _write_decision(action, value, decider) {
        if (Spa._action !== action || action.is_stopped()) {
            return;
        }

        const current = window.location.pathname + window.location.search;
        const next = (value === null ? Spa_Via.without(current) : Spa_Via.with_value(current, value)) + window.location.hash;

        history.replaceState({ ...(history.state || {}), via_decider: decider }, '', next);
        Spa_Journal.note_url();

        if (Spa._last_committed_url !== null) {
            Spa._last_committed_url = next;
        }
    }

    /**
     * Normalize URL by stripping hash
     */
    static _normalize_url(url) {
        const hash_index = url.indexOf('#');
        return hash_index >= 0 ? url.substring(0, hash_index) : url;
    }

    /**
     * Check if resolution should continue (not cancelled)
     */
    static _is_active(generation) {
        return generation === Rsx_Breadcrumb_Resolver._generation;
    }

    /**
     * Build cache key (Rsx_Storage already scopes by build_key, so no manual append)
     */
    static _cache_key(url) {
        return Rsx_Breadcrumb_Resolver.CACHE_PREFIX + url;
    }

    /**
     * Get cached {chain, meta} for a URL, or null
     */
    static _get_cached(url) {
        const cache_key = Rsx_Breadcrumb_Resolver._cache_key(url);
        const cached = Rsx_Storage.session_get(cache_key);

        if (!cached || !is_array(cached.chain) || cached.chain.length === 0 || !cached.meta) {
            return null;
        }

        return cached;
    }

    /**
     * Cache a resolved chain and its meta
     */
    static _cache_chain(url, chain, meta) {
        const cache_key = Rsx_Breadcrumb_Resolver._cache_key(url);
        // Store simplified chain data (url, label, is_active)
        const cache_data = chain.map(item => ({
            url: item.url,
            label: item.label,
            is_active: item.is_active
        }));
        Rsx_Storage.session_set(cache_key, { chain: cache_data, meta: meta });
    }

    /**
     * The meta argument for a walked chain.
     */
    static _meta(walk) {
        return {
            grafted: walk.grafted,
            root_url: walk.chain.length ? walk.chain[0].url : null,
            decider: walk.decider,
        };
    }

    /**
     * Give every crumb its href: the link a layout renders. For a crumb above the current
     * page it is Spa.return_url() of the crumb's url - the page in the state the user left it
     * in, when they came through it on this trail (Spa_Journal), else the url itself. The
     * active crumb's href is its url. Computed at every emission and never cached: the url is
     * the page's identity (per page, cached), the href is per journey.
     *
     * @param {Array<{url: string, is_active: boolean}>} chain
     * @returns {Array} the same items, each with href
     */
    static _linked(chain) {
        for (const item of chain) {
            item.href = item.is_active ? item.url : Spa.return_url(item.url);
        }

        return chain;
    }

    /**
     * Main resolution orchestrator
     */
    static async _resolve(action, url, generation, callbacks) {
        // Step 1: a URL carrying an origin waits for the keep/strip decision before anything
        // is shown, and continues with the URL that decision left (the kept trail is reused,
        // a stripped one is walked again without _via).
        const settlement = Rsx_Breadcrumb_Resolver.settle_via(action, url, null);
        let walk = null;

        if (Spa_Via.raw(url) !== null) {
            const settled = await settlement;
            if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;
            url = settled.url;
            walk = settled.walk;
        }

        // Step 2: Try to get cached chain
        const cached = Rsx_Breadcrumb_Resolver._get_cached(url);
        const cached_chain = cached ? cached.chain : null;
        let showed_cached = false;

        if (cached) {
            // Show cached chain immediately
            if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;
            callbacks.on_chain(Rsx_Breadcrumb_Resolver._linked(cached_chain.map(item => ({
                url: item.url,
                label: item.label,
                is_active: item.is_active,
                resolved: true
            }))), cached.meta);
            showed_cached = true;
        }

        // Step 3: Walk the chain structure to get URLs
        if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;

        if (!walk) {
            walk = await Rsx_Breadcrumb_Resolver._walk_chain_structure(action, url, generation, []);
        }
        if (!walk || !Rsx_Breadcrumb_Resolver._is_active(generation)) return;

        const chain_structure = walk.chain;
        const meta = Rsx_Breadcrumb_Resolver._meta(walk);

        // The trail this page shows, recorded on its journal entry: a later page's crumb
        // returns to a page in the state it was left in only while every page between showed
        // that crumb (Spa_Journal.return_url()).
        if (Spa._action === action) {
            Spa_Journal.set_trail(Spa_Journal.current_key(), chain_structure.map(item => item.url));
        }

        // Step 4: Compare to cache and build display chain
        const chain_length_matches = showed_cached && cached_chain.length === chain_structure.length;
        const should_use_cached_labels = showed_cached && chain_length_matches;

        // Build the chain with either cached labels or nulls
        const chain = chain_structure.map((item, index) => {
            let label = null;
            if (should_use_cached_labels && cached_chain[index]) {
                label = cached_chain[index].label;
            }
            return {
                url: item.url,
                label: label,
                is_active: item.is_active,
                resolved: false,
                action: item.action // Keep action reference for label resolution
            };
        });

        // Step 5: Emit chain structure (with cached labels or nulls)
        // Only emit if we didn't show cached OR if structure differs
        if (!showed_cached || !chain_length_matches) {
            if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;
            callbacks.on_chain(Rsx_Breadcrumb_Resolver._linked(chain.map(item => ({
                url: item.url,
                label: item.label,
                is_active: item.is_active,
                resolved: item.label !== null
            }))), meta);
        }

        // Step 6: Resolve labels progressively
        const label_promises = chain.map(async (item, index) => {
            if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;

            try {
                let label;
                if (item.is_active) {
                    // Active item uses breadcrumb_label_active()
                    label = await item.action.breadcrumb_label_active();
                } else {
                    // Parent items use breadcrumb_label()
                    label = await item.action.breadcrumb_label();
                }

                if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;

                // Update chain and notify
                chain[index].label = label;
                chain[index].resolved = true;
                callbacks.on_label_update(index, label);
            } catch (e) {
                console.warn('Breadcrumb label resolution failed:', e);
                // Use generic label when resolution fails
                chain[index].label = item.is_active ? 'Page' : 'Link';
                chain[index].resolved = true;
                callbacks.on_label_update(index, chain[index].label);
            }
        });

        // Wait for all labels to resolve
        await Promise.all(label_promises);

        if (!Rsx_Breadcrumb_Resolver._is_active(generation)) return;

        // Step 7: Cache the final result and notify completion
        Rsx_Breadcrumb_Resolver._cache_chain(url, chain, meta);

        const final_chain = Rsx_Breadcrumb_Resolver._linked(chain.map(item => ({
            url: item.url,
            label: item.label,
            is_active: item.is_active,
            resolved: true
        })));

        callbacks.on_complete(final_chain, meta);

        // Every layout in the chain hears about the current page's finished trail, so one
        // that does not render breadcrumbs (a sidebar's owner) can still follow its root.
        if (Spa._action === action) {
            for (const layout of Spa._collect_all_layouts()) {
                layout.on_breadcrumb_complete(final_chain, meta);
            }
        }
    }

    /**
     * Walk the chain structure by following breadcrumb_parent(), and
     * breadcrumb_parent_via() where a page accepts a pending origin.
     *
     * At each page, when origins are pending and the page's class lists the first one's
     * type in breadcrumb_via, breadcrumb_parent_via(token) is asked: a URL makes the page
     * the consumer of that token and its parent, and the REST of the list rides on that
     * parent URL as its _via; null declines, and the page's breadcrumb_parent() is used
     * with the WHOLE list riding on it. Tokens a parent URL already carries go in front
     * (capped at Spa_Via.MAX_TOKENS). A parent URL already in the trail ends the walk (a
     * loop), as does MAX_DEPTH.
     *
     * @param {Spa_Action} action - The page the trail ends at
     * @param {string} url - Its URL (no fragment)
     * @param {number|null} generation - The stream's generation; null for settle_via()
     * @param {Array<{type, id}>} tokens - The origins pending at the page
     * @returns {Promise<{chain: Array<{url, is_active, action}>, accepted: boolean,
     *   decider: string|null, grafted: boolean}|null>} root first; null when abandoned
     */
    static async _walk_chain_structure(action, url, generation, tokens) {
        const chain = [];
        const seen = new Set();
        const first_token = tokens.length ? tokens[0] : null;
        let pending = tokens.slice();
        let accepted = false;
        let decider = null;
        let grafted = false;

        const abandoned = () => (generation === null ? action.is_stopped() : !Rsx_Breadcrumb_Resolver._is_active(generation));

        // Start with current action
        let current_url = url;
        let current_action = action;

        while (current_action && current_url) {
            if (abandoned()) return null;

            if (chain.length >= Rsx_Breadcrumb_Resolver.MAX_DEPTH) {
                console.warn(`Breadcrumb trail deeper than ${Rsx_Breadcrumb_Resolver.MAX_DEPTH} - stopped at ${current_url}`);
                break;
            }

            chain.unshift({
                url: current_url,
                is_active: current_action === action,
                action: current_action
            });
            seen.add(Spa_Via.bare(current_url));

            // An accepted origin names the parent; otherwise the location-based one.
            let parent_url = null;
            let remainder = pending;
            const accepts = current_action.constructor.breadcrumb_via;

            if (pending.length && is_array(accepts) && accepts.includes(pending[0].type)) {
                const token = pending[0];
                let via_parent = null;
                try {
                    via_parent = await current_action.breadcrumb_parent_via({ type: token.type, id: token.id });
                } catch (e) {
                    console.warn('breadcrumb_parent_via() failed:', e);
                }

                if (via_parent) {
                    parent_url = via_parent;
                    remainder = pending.slice(1);
                    grafted = true;
                    if (token === first_token) {
                        accepted = true;
                        decider = current_action.constructor.name + '@' + Spa_Via.bare(current_url);
                    }
                }
            }

            if (!parent_url) {
                try {
                    parent_url = await current_action.breadcrumb_parent();
                } catch (e) {
                    console.warn('breadcrumb_parent() failed:', e);
                }
            }

            if (!parent_url) break;

            // The parent URL's own origins go in front of what is still pending.
            let own = [];
            const own_raw = Spa_Via.raw(parent_url);
            if (own_raw !== null) {
                const own_parsed = Spa_Via.parse_value(own_raw);
                if (own_parsed.valid) {
                    own = own_parsed.tokens;
                } else {
                    console.warn(`Breadcrumb parent ${parent_url} carries a malformed _via - ignored`);
                }
            }

            pending = own.concat(remainder).slice(0, Spa_Via.MAX_TOKENS);
            parent_url = Spa_Via.with_value(parent_url, pending);

            if (seen.has(Spa_Via.bare(parent_url))) {
                console.warn(`Breadcrumb parent loop: ${parent_url} is already in the trail - stopped`);
                break;
            }

            // Load parent action (detached)
            if (abandoned()) return null;

            const parent_action = await Spa.load_detached_action(parent_url, {
                use_cached_data: true
            });

            if (!parent_action) break;

            current_url = parent_url;
            current_action = parent_action;
        }

        return { chain: chain, accepted: accepted, decider: decider, grafted: grafted };
    }

    /**
     * Clear cached chain for a URL (if needed for invalidation)
     */
    static clear_cache(url) {
        if (url) {
            const cache_key = Rsx_Breadcrumb_Resolver._cache_key(url);
            Rsx_Storage.session_remove(cache_key);
        }
    }
}
