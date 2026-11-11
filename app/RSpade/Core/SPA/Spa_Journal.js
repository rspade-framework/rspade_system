/**
 * Spa_Journal - the per-tab record of the journey through an SPA's history entries.
 *
 * WHAT IT IS FOR. A breadcrumb's href is the bare route of the page it names, so following
 * one opens that page fresh: a list comes back with its default sort, no filters, page 1, at
 * the top. Back returns to the exact entry; a crumb is a new navigation. The journal lets a
 * crumb - or any link built with Spa.return_url() (a Back or Cancel button) - return to the
 * page in the state the user LEFT it in: its URL fragment (a grid's sort, filters and page, a
 * tab) and its scroll position. And only when the user really came through that page on the
 * trail now on screen: a crumb never borrows state from an unrelated visit to the same URL.
 *
 * THE RECORDS. One per history entry, keyed by the entry's identity - the spa_run / spa_index
 * stamp the SPA writes into history.state (Spa._history_run, Spa._history_index), which the
 * browser keeps across a reload, so an entry finds its own record again on Back/Forward or a
 * reload. Each record:
 *
 *   url     the entry's URL, fragment included, kept current while the page rewrites its
 *           fragment in place (Rsx.url_hash_set(), an address-bar rewrite, the breadcrumb
 *           origin's keep/strip decision) - note_url()
 *   scroll  {x, y}: where the page was scrolled when the entry was LEFT
 *   from    the key of the entry the user was on when they navigated FORWARD to this one (a
 *           link, Rsx.navigate(), a form's redirect, a full load through the navigation door).
 *           Back/Forward and a reload never rewrite it; an entry that REPLACES another
 *           (Rsx.redirect()) inherits the replaced entry's
 *   trail   the identities (Spa_Via.bare()) of every crumb the page's breadcrumb trail showed,
 *           recorded when Rsx_Breadcrumb_Resolver walks it; null when no trail was shown
 *
 * THE RULE (return_url()). For a target URL T, walk the `from` chain back from the entry on
 * screen. T inherits the state of the NEAREST entry in that chain whose identity is T's,
 * provided every entry passed on the way showed T in its own trail - the user never left
 * T's branch of the trail. Otherwise T is returned unchanged.
 *
 * WHERE IT LIVES. Session storage (Rsx_Storage, the framework's scoped wrapper): per tab,
 * kept across reloads and full page loads, empty in a new tab, cleared with the rest of the
 * framework's keys when the session, user, site or build changes. Capped at MAX_RECORDS,
 * the least recently written dropped first. Storage that is unavailable (private browsing,
 * quota) leaves the journal empty, and every link stays the bare route.
 *
 * It is the SPA's bookkeeping: Spa.js and Rsx_Navigation.js (the navigation door) write it,
 * Rsx_Breadcrumb_Resolver records trails and builds crumb hrefs from it, and applications
 * reach it only through Spa.return_url(). See rsx:man breadcrumb_origins, RETURNING TO THE
 * STATE YOU LEFT.
 */
class Spa_Journal {
    // The Rsx_Storage session key holding the whole journal.
    static STORAGE_KEY = 'spa_journal';

    // The most records one tab keeps. A crumb's chain is a handful of entries deep; a hundred
    // covers any journey a person retraces by breadcrumb.
    static MAX_RECORDS = 100;

    /**
     * The key of the history entry the page is on, or null before the SPA has stamped one
     * (and on every page that is not an SPA).
     *
     * @returns {string|null}
     */
    static current_key() {
        if (Spa._history_index === null || Spa._history_run === null) {
            return null;
        }

        return Spa._history_run + ':' + Spa._history_index;
    }

    /**
     * The `from` a navigation leaving the entry on screen hands to the entry it creates: the
     * current entry for a push, the current entry's own `from` for a replace (the entry it
     * replaces is gone, and so is its place in the journey).
     *
     * @param {string} history_mode - 'push' | 'replace'
     * @returns {string|null}
     */
    static from_for(history_mode) {
        const key = Spa_Journal.current_key();
        if (key === null || history_mode !== 'replace') {
            return key;
        }

        const record = Spa_Journal._read().records[key];

        return record ? record.from : null;
    }

    /**
     * A forward move: the entry `from_key` is left (at `from_url`, scrolled to `scroll`) and a
     * new entry `to_key` begins at `to_url`, its `from` the entry left. `same_page` (an
     * address-bar push, a fragment entry): the page on screen is unchanged, so the new entry
     * shows the trail the left one recorded.
     *
     * @param {string|null} from_key
     * @param {string|null} from_url - null keeps the URL the record holds
     * @param {{x: number, y: number}|null} scroll
     * @param {string} to_key
     * @param {string} to_url
     * @param {boolean} same_page
     */
    static forward(from_key, from_url, scroll, to_key, to_url, same_page) {
        Spa_Journal._update((journal) => {
            let trail = null;

            if (from_key !== null) {
                const left = Spa_Journal._record(journal, from_key);
                if (from_url !== null) {
                    left.url = from_url;
                }
                left.scroll = scroll;
                trail = same_page ? left.trail : null;
            }

            journal.records[to_key] = Spa_Journal._new_record(journal, to_url, from_key, trail);
        });
    }

    /**
     * The entry `key` now shows another page in place (a replacing navigation): the new page
     * keeps the replaced one's `from` and nothing else.
     *
     * @param {string} key
     * @param {string} url
     */
    static replaced(key, url) {
        Spa_Journal._update((journal) => {
            const previous = journal.records[key];
            journal.records[key] = Spa_Journal._new_record(journal, url, previous ? previous.from : null, null);
        });
    }

    /**
     * The page arrived at entry `key` without a forward move of this document: the first
     * page of a document, or Back/Forward. A record the entry already has keeps its `from`
     * and trail (a reload, a return); a missing one is created with `from`.
     *
     * @param {string|null} key
     * @param {string} url
     * @param {string|null} from
     */
    static arrived(key, url, from) {
        if (key === null) {
            return;
        }

        Spa_Journal._update((journal) => {
            if (journal.records[key]) {
                Spa_Journal._record(journal, key).url = url;
            } else {
                journal.records[key] = Spa_Journal._new_record(journal, url, from === key ? null : from, null);
            }
        });
    }

    /**
     * The entry `key` is being left: record where its page was scrolled, and its URL when
     * known (null keeps the URL the record holds - a Back/Forward has already moved the
     * address bar when it is heard).
     *
     * @param {string|null} key
     * @param {string|null} url
     * @param {{x: number, y: number}} scroll
     */
    static left(key, url, scroll) {
        if (key === null) {
            return;
        }

        Spa_Journal._update((journal) => {
            const record = Spa_Journal._record(journal, key);
            if (url !== null) {
                record.url = url;
            }
            record.scroll = scroll;
        });
    }

    /**
     * The page rewrote the address of its own entry in place (its fragment state, an
     * address-bar rewrite, a stripped breadcrumb origin): the record follows it.
     */
    static note_url() {
        const key = Spa_Journal.current_key();
        if (key === null) {
            return;
        }

        const url = window.location.pathname + window.location.search + window.location.hash;

        Spa_Journal._update((journal) => {
            Spa_Journal._record(journal, key).url = url;
        });
    }

    /**
     * Record the breadcrumb trail the page on entry `key` shows.
     *
     * @param {string|null} key
     * @param {string[]} urls - every crumb's URL, root first
     */
    static set_trail(key, urls) {
        if (key === null) {
            return;
        }

        const trail = urls.map((url) => Spa_Via.bare(url));

        Spa_Journal._update((journal) => {
            Spa_Journal._record(journal, key).trail = trail;
        });
    }

    /**
     * THE RULE: the URL that returns to `url` in the state the user left it in, when they came
     * through it on this trail - else `url` unchanged. See the class comment.
     *
     * The result is `url`'s path and query (its _via included) with the fragment of the entry
     * found, plus the reserved scroll key (Rsx.HASH_SCROLL_KEY) when that entry was left
     * scrolled. The `at` anchor is left out when a position is carried: the position is
     * where the user was.
     *
     * @param {string} url
     * @returns {string}
     */
    static return_url(url) {
        const key = Spa_Journal.current_key();
        if (key === null || typeof url !== 'string' || url === '') {
            return url;
        }

        const records = Spa_Journal._read().records;
        const identity = Spa_Via.bare(url);
        const visited = new Set([key]);
        let step = records[key] ? records[key].from : null;

        while (step && !visited.has(step)) {
            visited.add(step);

            const entry = records[step];
            if (!entry || typeof entry.url !== 'string') {
                return url;
            }

            if (Spa_Via.bare(entry.url) === identity) {
                return Spa_Journal._stateful_url(url, entry);
            }

            if (!is_array(entry.trail) || !entry.trail.includes(identity)) {
                return url;
            }

            step = entry.from;
        }

        return url;
    }

    /**
     * `url` (path and query) carrying the fragment and the scroll position of `entry`.
     */
    static _stateful_url(url, entry) {
        const hash_at = url.indexOf('#');
        const base = hash_at >= 0 ? url.substring(0, hash_at) : url;

        const entry_hash_at = entry.url.indexOf('#');
        let pairs = entry_hash_at >= 0 ? entry.url.substring(entry_hash_at + 1).split('&').filter((pair) => pair !== '') : [];

        const key_of = (pair) => decodeURIComponent(pair.includes('=') ? pair.substring(0, pair.indexOf('=')) : Rsx.HASH_ANCHOR_KEY);
        pairs = pairs.filter((pair) => key_of(pair) !== Rsx.HASH_SCROLL_KEY);

        const scroll = entry.scroll;
        const x = scroll ? Math.max(0, Math.round(Number(scroll.x) || 0)) : 0;
        const y = scroll ? Math.max(0, Math.round(Number(scroll.y) || 0)) : 0;

        if (x !== 0 || y !== 0) {
            pairs = pairs.filter((pair) => key_of(pair) !== Rsx.HASH_ANCHOR_KEY);
            pairs.push(encodeURIComponent(Rsx.HASH_SCROLL_KEY) + '=' + (x !== 0 ? x + ',' + y : String(y)));
        }

        return pairs.length ? base + '#' + pairs.join('&') : base;
    }

    /**
     * Read, change and write the journal in one step. Nothing is written when session storage
     * is unavailable (Rsx_Storage answers null and ignores the write).
     */
    static _update(change) {
        const journal = Spa_Journal._read();
        change(journal);

        const keys = Object.keys(journal.records);
        if (keys.length > Spa_Journal.MAX_RECORDS) {
            keys.sort((a, b) => journal.records[a].seq - journal.records[b].seq);
            for (const key of keys.slice(0, keys.length - Spa_Journal.MAX_RECORDS)) {
                delete journal.records[key];
            }
        }

        Rsx_Storage.session_set(Spa_Journal.STORAGE_KEY, journal);
    }

    /**
     * The stored journal, or an empty one when none is stored (or what is stored is not one).
     *
     * @returns {{seq: number, records: Object<string, object>}}
     */
    static _read() {
        const stored = Rsx_Storage.session_get(Spa_Journal.STORAGE_KEY);

        if (stored && typeof stored === 'object' && Number.isInteger(stored.seq)
            && stored.records && typeof stored.records === 'object' && !Array.isArray(stored.records)) {
            return stored;
        }

        return { seq: 0, records: {} };
    }

    /**
     * The record for `key`, created empty when missing, and marked as the most recently written.
     */
    static _record(journal, key) {
        if (!journal.records[key]) {
            journal.records[key] = Spa_Journal._new_record(journal, null, null, null);
        } else {
            journal.records[key].seq = ++journal.seq;
        }

        return journal.records[key];
    }

    static _new_record(journal, url, from, trail) {
        return { url: url, scroll: null, from: from, trail: trail, seq: ++journal.seq };
    }
}
