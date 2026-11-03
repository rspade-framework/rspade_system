/**
 * What a request IS, as far as scheduling cares: whose it is, and which caps and privileges
 * apply. Pure: headers and a path in, plain data out. Every request lands in php-fpm's one
 * pool, so a request's kind (page, Ajax, companion call) decides no pool - only its
 * privileges.
 *
 * Every decision is made on the RAW path (no query string, no decoding, no portal prefix
 * stripped): the whitelist patterns come from the application's route table.
 */

/**
 * The application's priority Ajax channel. Matched as a path SUFFIX: a portal served under a
 * path prefix posts `<prefix>/_ajax/_priority`. The proxy only grants queue order; the server
 * refuses any call on that channel to an endpoint not marked #[Priority].
 */
const PRIORITY_AJAX_SUFFIX = '/_ajax/_priority';

/**
 * Conditional request headers that make a request a REVALIDATION. Only these two: If-Match,
 * If-Unmodified-Since and If-Range guard a write or a partial read, and are no cheaper to answer.
 */
const REVALIDATION_HEADERS = ['if-none-match', 'if-modified-since'];

/**
 * Companion applications' calls (rsx:man companion_apps). A companion calls the box's own
 * nginx, so its address is loopback - but it is a CLIENT, scheduled like any other: queued,
 * counted, and round robined on its address, so a chatty companion cannot take the pool from
 * the site's visitors. Only the other loopback callers (rsx:debug, server-side rendering, a
 * test harness) keep the loopback exemption.
 */
const COMPANION_PATH_PREFIX = '/_companion/';

/**
 * Crawlers, by user agent. Hard-coded on purpose: a robot is a robot whatever the
 * application thinks, and the generic last entry catches the long tail that names itself.
 */
const ROBOT_PATTERNS = [
    /googlebot/i,
    /bingbot/i,
    /baiduspider/i,
    /yandex/i,
    /duckduckbot/i,
    /slurp/i,
    /applebot/i,
    /ahrefsbot/i,
    /semrushbot/i,
    /mj12bot/i,
    /petalbot/i,
    /bytespider/i,
    /gptbot/i,
    /claudebot/i,
    /ccbot/i,
    /facebookexternalhit/i,
    /bot|crawler|spider|crawling/i,
];

function path_of(url) {
    const query = url.indexOf('?');
    return query === -1 ? url : url.substring(0, query);
}

/** An IPv4-mapped IPv6 address is the IPv4 address: one client, one ledger key. */
function normalize_ip(ip) {
    const value = String(ip || '').trim();
    return value.toLowerCase().startsWith('::ffff:') && value.includes('.') ? value.substring(7) : value;
}

function is_loopback(ip) {
    const value = normalize_ip(ip);
    return value === '::1' || /^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(value);
}

/**
 * The client: nginx's resolved X-Real-IP (trusted because only nginx reaches the proxy's
 * loopback port), else the address of whoever opened the connection.
 */
function client_ip(headers, remote_address) {
    const header = headers['x-real-ip'];
    if (typeof header === 'string' && header.trim() !== '') {
        return normalize_ip(header);
    }
    return normalize_ip(remote_address);
}

function is_robot(user_agent) {
    if (typeof user_agent !== 'string' || user_agent === '') return false;
    return ROBOT_PATTERNS.some((pattern) => pattern.test(user_agent));
}

function is_multipart(headers) {
    const type = headers['content-type'];
    return typeof type === 'string' && type.trim().toLowerCase().startsWith('multipart/form-data');
}

function is_priority_ajax_path(path) {
    return path.endsWith(PRIORITY_AJAX_SUFFIX);
}

/**
 * A GET or HEAD carrying If-None-Match or If-Modified-Since is priority: a revalidation is
 * usually answered 304 with no body, so serving it first costs little and makes a returning
 * page fast. GET and HEAD only, because a header any client can add must not lift a write.
 */
function is_revalidation(method, headers) {
    const verb = String(method || '').toUpperCase();
    if (verb !== 'GET' && verb !== 'HEAD') return false;
    return REVALIDATION_HEADERS.some((name) => headers[name] !== undefined);
}

/**
 * One route pattern as a matcher. The application's syntax:
 *
 *     /exact/path            the path itself
 *     /files/:key            one segment
 *     /files/:key/:name?     an optional trailing segment
 *     /_compiled/*           a trailing * matches any remainder (a prefix match)
 *
 * Throws on anything else, so a malformed whitelist answer is refused as a whole.
 */
function compile_pattern(pattern) {
    if (typeof pattern !== 'string' || !pattern.startsWith('/')) {
        throw new Error('A pattern must be a string starting with /, got ' + JSON.stringify(pattern));
    }

    let body = pattern;
    let prefix = false;
    if (body.endsWith('*')) {
        body = body.substring(0, body.length - 1);
        prefix = true;
    }
    if (body.includes('*')) {
        throw new Error('A * is allowed only at the end of a pattern: ' + pattern);
    }

    if (!prefix && !body.includes(':')) {
        return { pattern, exact: body };
    }

    const segments = body.split('/').slice(1);
    let regex = '';
    for (let i = 0; i < segments.length; i++) {
        const segment = segments[i];
        const last = i === segments.length - 1;

        if (segment.startsWith(':')) {
            const optional = segment.endsWith('?');
            const name = optional ? segment.substring(1, segment.length - 1) : segment.substring(1);
            if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(name)) {
                throw new Error('Bad parameter "' + segment + '" in pattern ' + pattern);
            }
            regex += optional ? '(?:/[^/]+)?' : '/[^/]+';
        } else {
            regex += '/' + segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        if (!last && segment.endsWith('?') && segment.startsWith(':')) {
            throw new Error('An optional parameter must be the last segment: ' + pattern);
        }
    }

    return { pattern, regex: new RegExp('^' + regex + (prefix ? '' : '$')) };
}

/** A set of patterns, compiled: exact paths in a Set, the rest as regexes. */
class Pattern_Set {
    constructor(patterns) {
        this.exact = new Set();
        this.regexes = [];
        this.count = 0;

        for (const pattern of patterns || []) {
            const compiled = compile_pattern(pattern);
            if (compiled.exact !== undefined) {
                this.exact.add(compiled.exact);
            } else {
                this.regexes.push(compiled.regex);
            }
            this.count++;
        }
    }

    matches(path) {
        if (this.exact.has(path)) return true;
        return this.regexes.some((regex) => regex.test(path));
    }
}

/** The whitelist before the application has answered: nothing in it. */
function empty_whitelist() {
    return {
        unthrottled: new Pattern_Set([]),
        priority: new Pattern_Set([]),
        media: new Pattern_Set([]),
    };
}

/** Compile `{unthrottled, priority, media}` (arrays of pattern strings). Throws when malformed. */
function compile_whitelist(answer) {
    if (answer === null || typeof answer !== 'object' || Array.isArray(answer)) {
        throw new Error('The answer is not a JSON object');
    }

    const compiled = {};
    for (const key of ['unthrottled', 'priority', 'media']) {
        const list = answer[key];
        if (!Array.isArray(list) || !list.every((entry) => typeof entry === 'string')) {
            throw new Error('"' + key + '" is not an array of strings');
        }
        compiled[key] = new Pattern_Set(list);
    }
    return compiled;
}

/**
 * Everything the scheduler needs to know about one request.
 *
 * @param {{method: string, url: string, headers: object, remote_address: string}} request
 * @param {object} whitelist  compile_whitelist() / empty_whitelist()
 */
function classify(request, whitelist) {
    const path = path_of(request.url);
    const ip = client_ip(request.headers, request.remote_address);

    return {
        path,
        ip,
        loopback: is_loopback(ip) && !path.startsWith(COMPANION_PATH_PREFIX),
        robot: is_robot(request.headers['user-agent']),
        media: is_multipart(request.headers) || whitelist.media.matches(path),
        priority: is_priority_ajax_path(path)
            || is_revalidation(request.method, request.headers)
            || whitelist.priority.matches(path),
        unthrottled: whitelist.unthrottled.matches(path),
    };
}

module.exports = {
    PRIORITY_AJAX_SUFFIX,
    COMPANION_PATH_PREFIX,
    REVALIDATION_HEADERS,
    ROBOT_PATTERNS,
    path_of,
    normalize_ip,
    is_loopback,
    client_ip,
    is_robot,
    is_multipart,
    is_priority_ajax_path,
    is_revalidation,
    compile_pattern,
    Pattern_Set,
    empty_whitelist,
    compile_whitelist,
    classify,
};
