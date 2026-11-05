/**
 * Spa_Via - the grammar of the reserved `_via` query parameter (breadcrumb origins)
 *
 * `_via` is an ordered, comma-separated list of ORIGIN TOKENS, nearest origin first:
 *
 *     _via=<token>[,<token>...]       token = <type>[.<id>]
 *     type = [a-z][a-z0-9_]*          id    = [A-Za-z0-9_-]+
 *
 * A token is a CLAIM about where the user came from ("engagement.156"); a page that can stand
 * under that origin verifies it in breadcrumb_parent_via() before the breadcrumb trail is
 * grafted onto it. This class is only the grammar and the URL surgery - parse, serialize, add
 * and remove the parameter without touching the rest of a URL. The behaviour lives in Spa
 * (carrying on navigation, the new-tab href rewrite) and Rsx_Breadcrumb_Resolver (the walk
 * and the keep/strip decision). Applications use Spa.via() and Spa.via_push().
 *
 * A malformed value is never thrown: a URL is user input. parse_value() reports it, and the
 * caller ignores and strips it. See rsx:man breadcrumb_origins.
 */
class Spa_Via {
    /** The reserved query parameter. */
    static PARAM = '_via';

    /** The most tokens one URL carries; the oldest origins are dropped past it. */
    static MAX_TOKENS = 4;

    static TYPE_PATTERN = /^[a-z][a-z0-9_]*$/;
    static ID_PATTERN = /^[A-Za-z0-9_-]+$/;

    /**
     * Parse a `_via` VALUE (already URL-decoded).
     *
     * @param {string} value
     * @returns {{valid: boolean, tokens: Array<{type: string, id: string|null}>, truncated: boolean}}
     *   valid false: the whole value is malformed and tokens is empty.
     */
    static parse_value(value) {
        if (typeof value !== 'string' || value === '') {
            return { valid: false, tokens: [], truncated: false };
        }

        const tokens = [];

        for (const part of value.split(',')) {
            const token = Spa_Via.parse_token(part);
            if (!token) {
                return { valid: false, tokens: [], truncated: false };
            }
            tokens.push(token);
        }

        const truncated = tokens.length > Spa_Via.MAX_TOKENS;

        return { valid: true, tokens: tokens.slice(0, Spa_Via.MAX_TOKENS), truncated: truncated };
    }

    /**
     * One token, or null when it does not match the grammar.
     *
     * @param {string} text
     * @returns {{type: string, id: string|null}|null}
     */
    static parse_token(text) {
        const dot = text.indexOf('.');
        const type = dot === -1 ? text : text.substring(0, dot);
        const id = dot === -1 ? null : text.substring(dot + 1);

        if (!Spa_Via.TYPE_PATTERN.test(type)) {
            return null;
        }

        if (id !== null && !Spa_Via.ID_PATTERN.test(id)) {
            return null;
        }

        return { type: type, id: id };
    }

    /**
     * Serialize a token list (capped at MAX_TOKENS, oldest dropped). Commas are written
     * unencoded: every character the grammar allows is safe in a query string.
     *
     * @param {Array<{type: string, id: string|null}>} tokens
     * @returns {string}
     */
    static serialize(tokens) {
        return tokens
            .slice(0, Spa_Via.MAX_TOKENS)
            .map((token) => (token.id === null || token.id === undefined ? token.type : token.type + '.' + token.id))
            .join(',');
    }

    /**
     * The raw (decoded) `_via` value a URL or query string carries, or null when it names none.
     *
     * @param {string} url - A URL, a path with a query, or a bare '?query'
     * @returns {string|null}
     */
    static raw(url) {
        const query = Spa_Via._split(url).query;
        if (query === '') {
            return null;
        }

        let found = null;

        for (const pair of query.split('&')) {
            const eq = pair.indexOf('=');
            const key = Spa_Via._decode(eq === -1 ? pair : pair.substring(0, eq));
            if (key === Spa_Via.PARAM) {
                found = eq === -1 ? '' : Spa_Via._decode(pair.substring(eq + 1));
            }
        }

        return found;
    }

    /**
     * The URL without its `_via` parameter. Every other byte of the URL is left as it was,
     * the fragment included.
     *
     * @param {string} url
     * @returns {string}
     */
    static without(url) {
        const parts = Spa_Via._split(url);

        const kept = parts.query === '' ? [] : parts.query.split('&').filter((pair) => {
            const eq = pair.indexOf('=');
            return Spa_Via._decode(eq === -1 ? pair : pair.substring(0, eq)) !== Spa_Via.PARAM;
        });

        return parts.base + (kept.length ? '?' + kept.join('&') : '') + parts.hash;
    }

    /**
     * The URL with `_via` set to a value (replacing any it had), placed before the fragment.
     * An empty value - or an empty token list - removes the parameter.
     *
     * @param {string} url
     * @param {string|Array<{type: string, id: string|null}>} value - A serialized value or a token list
     * @returns {string}
     */
    static with_value(url, value) {
        if (Array.isArray(value)) {
            value = Spa_Via.serialize(value);
        }

        const bare = Spa_Via.without(url);
        if (!value) {
            return bare;
        }

        const parts = Spa_Via._split(bare);
        const query = (parts.query === '' ? '' : parts.query + '&') + Spa_Via.PARAM + '=' + value;

        return parts.base + '?' + query + parts.hash;
    }

    /**
     * The URL with neither `_via` nor a fragment - the identity of a page in a trail.
     *
     * @param {string} url
     * @returns {string}
     */
    static bare(url) {
        const parts = Spa_Via._split(Spa_Via.without(url));
        return parts.base + (parts.query === '' ? '' : '?' + parts.query);
    }

    /**
     * {base, query, hash} - query without its '?', hash with its '#'.
     */
    static _split(url) {
        let rest = String(url);
        let hash = '';

        const hash_index = rest.indexOf('#');
        if (hash_index !== -1) {
            hash = rest.substring(hash_index);
            rest = rest.substring(0, hash_index);
        }

        const query_index = rest.indexOf('?');
        if (query_index === -1) {
            return { base: rest, query: '', hash: hash };
        }

        return { base: rest.substring(0, query_index), query: rest.substring(query_index + 1), hash: hash };
    }

    /**
     * decodeURIComponent that reads '+' as a space and never throws: a URL is user input,
     * and a stray '%' must not take the page down.
     */
    static _decode(text) {
        try {
            return decodeURIComponent(text.replace(/\+/g, ' '));
        } catch (e) {
            return text;
        }
    }
}
