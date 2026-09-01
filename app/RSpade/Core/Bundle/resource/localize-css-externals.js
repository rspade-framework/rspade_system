#!/usr/bin/env node

/**
 * localize-css-externals - rewrite every remote reference inside a stylesheet to a
 * locally mirrored file.
 *
 * A stylesheet that reaches an external host at render time is a CSP violation waiting
 * to happen: the page's policy whitelists what the PAGE declares, and it cannot know
 * about a font file a third-party stylesheet asks for. So every remote `@import` is
 * fetched and spliced in place, and every remote `url()` is mirrored to a FILE in the
 * cache directory and rewritten to `/_vendor/<name>`.
 *
 * USAGE
 *
 *   node localize-css-externals.js --cache-dir D --user-agent UA [--no-download] \
 *                                  --base-url U --in F --out F
 *   node localize-css-externals.js --name URL EXT      (prints the mirror filename)
 *
 * THE NAMING RULE - identical to App\RSpade\Core\Bundle\Cdn_Cache::filename_for(), and
 * test EXT-49 pins the two implementations together:
 *
 *   md5(<url exactly as given>) . '_' . <safe basename> . '.' . <ext>
 *
 * <safe basename> is the URL PATH's basename-without-extension with every character
 * outside [A-Za-z0-9_-] replaced by '_', truncated to 50 characters ('asset' when
 * empty). <ext> is the path's lowercased extension when it is a known asset extension,
 * otherwise the DECLARED type (for a `url()` with no recognisable extension the declared
 * type is derived from the response Content-Type; an unmappable type is a failure). The
 * path is used RAW - never percent-decoded.
 *
 * WHAT IS REWRITTEN
 *
 *   - `@import url(https://...)`, `@import "https://..."`, `@import url("//host/...")`:
 *     fetched through the cache, localized RECURSIVELY against ITS own URL, and spliced
 *     in place of the at-rule. A media / layer() / supports() prelude is preserved by
 *     wrapping the spliced body in the corresponding at-rule.
 *   - every remaining `url()` in any declaration (font src, background, mask, cursor...)
 *     whose target is absolute (http:, https:, file:) or protocol-relative (//). The rest
 *     of the declaration value - `format(...)` hints, layer lists, fallbacks - is
 *     untouched. A `#fragment` on the original survives on the rewritten URL (SVG
 *     fragment ids are load-bearing); a query string does not, because it is already
 *     part of the file's identity through the md5.
 *
 * WHAT IS LEFT ALONE
 *
 *   - `data:` and `blob:` URIs, bare `#fragment` references, and root-relative `/...`.
 *   - RELATIVE references, unless the stylesheet's own base URL is itself absolute
 *     (scheme://). A stylesheet fetched from a remote host has a remote base, so its
 *     relative references resolve against it and are mirrored; a locally concatenated
 *     bundle does not, and relative `url()` in application SCSS is out of scope for this
 *     script - nothing localizes it today.
 *
 * FETCHING is node http/https with the configured User-Agent, following 3xx `location`
 * (a relative Location resolves against the request URL); `file://` reads from disk (the
 * test seam - the suite never touches the network). A present file in the cache directory
 * is valid: it is never re-fetched, and with --no-download a miss is a failure rather
 * than a request.
 *
 * OUTPUT: the localized CSS is written to --out, and ONE JSON line is printed as the LAST
 * line of stdout:
 *
 *   {"written":[names],"hits":[names],"failures":[{"url":...,"error":...}]}
 *
 * Exit code is 1 when `failures` is non-empty, 0 otherwise.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const https = require('https');
const http = require('http');
const { URL, fileURLToPath } = require('url');
const postcss = require('postcss');

// -----------------------------------------------------------------------------
// Naming (mirror of Cdn_Cache::filename_for)
// -----------------------------------------------------------------------------

const EXTENSIONS = [
    'js', 'css',
    'woff2', 'woff', 'ttf', 'otf', 'eot',
    'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
];

const EXTENSION_BY_MIME = {
    'application/javascript': 'js',
    'text/javascript': 'js',
    'text/css': 'css',
    'font/woff2': 'woff2',
    'application/font-woff2': 'woff2',
    'font/woff': 'woff',
    'application/font-woff': 'woff',
    'font/ttf': 'ttf',
    'application/x-font-ttf': 'ttf',
    'font/otf': 'otf',
    'application/vnd.ms-fontobject': 'eot',
    'image/svg+xml': 'svg',
    'image/png': 'png',
    'image/jpeg': 'jpg',
    'image/gif': 'gif',
    'image/webp': 'webp',
    'image/x-icon': 'ico',
    'image/vnd.microsoft.icon': 'ico',
};

/**
 * PHP pathinfo() semantics for the basename of a path.
 */
function php_pathinfo(p) {
    const trimmed = p.replace(/\/+$/, '');
    const base = trimmed.slice(trimmed.lastIndexOf('/') + 1);
    const dot = base.lastIndexOf('.');

    if (dot === -1) {
        return { filename: base, extension: '' };
    }

    return { filename: base.slice(0, dot), extension: base.slice(dot + 1) };
}

/**
 * The URL's raw path, PHP parse_url(PHP_URL_PATH) semantics.
 */
function url_path_of(target) {
    const probe = target.startsWith('//') ? 'https:' + target : target;

    try {
        return new URL(probe).pathname;
    } catch (err) {
        return '';
    }
}

function filename_for(target, type) {
    const info = php_pathinfo(url_path_of(target));

    let safe = info.filename.replace(/[^A-Za-z0-9_-]/g, '_').slice(0, 50);
    if (safe === '') {
        safe = 'asset';
    }

    let ext = info.extension.toLowerCase();
    if (!EXTENSIONS.includes(ext)) {
        ext = type;
    }

    return crypto.createHash('md5').update(target).digest('hex') + '_' + safe + '.' + ext;
}

// -----------------------------------------------------------------------------
// Arguments
// -----------------------------------------------------------------------------

const argv = process.argv.slice(2);

if (argv[0] === '--name') {
    if (argv.length !== 3) {
        console.error('Usage: node localize-css-externals.js --name URL EXT');
        process.exit(1);
    }
    console.log(filename_for(argv[1], argv[2]));
    process.exit(0);
}

const options = { cacheDir: null, userAgent: null, noDownload: false, baseUrl: null, in: null, out: null };

for (let i = 0; i < argv.length; i++) {
    switch (argv[i]) {
        case '--cache-dir': options.cacheDir = argv[++i]; break;
        case '--user-agent': options.userAgent = argv[++i]; break;
        case '--no-download': options.noDownload = true; break;
        case '--base-url': options.baseUrl = argv[++i]; break;
        case '--in': options.in = argv[++i]; break;
        case '--out': options.out = argv[++i]; break;
        default:
            console.error(`Unknown argument: ${argv[i]}`);
            process.exit(1);
    }
}

// baseUrl is checked for PRESENCE, not truth: the EMPTY string is a legitimate value.
// A bundle-level stylesheet is a local concatenation with no origin, and an empty base is
// exactly what tells is_localizable() that only absolute references are in scope.
if (options.baseUrl === null) {
    console.error('Missing required argument: --base-url');
    process.exit(1);
}

for (const required of ['cacheDir', 'userAgent', 'in', 'out']) {
    if (!options[required]) {
        console.error(`Missing required argument: --${required.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())}`);
        process.exit(1);
    }
}

// -----------------------------------------------------------------------------
// Run state
// -----------------------------------------------------------------------------

const written = new Set();
const hits = new Set();
const failures = [];

/** absolute url => mirror filename (binaries), or localized text (stylesheets) */
const binary_memo = new Map();
const stylesheet_memo = new Map();

function record_failure(target, error) {
    failures.push({ url: target, error: error });
}

function missing_mirror_message(target) {
    return `not mirrored and downloading is disabled: ${target} (remedy: php artisan rsx:cdn_externals:refresh)`;
}

// -----------------------------------------------------------------------------
// Reference classification and resolution
// -----------------------------------------------------------------------------

const ABSOLUTE_BASE = /^[a-z][a-z0-9+.-]*:\/\//i;

function is_localizable(raw, base_url) {
    if (raw === '') {
        return false;
    }
    if (/^(data:|blob:|#)/i.test(raw)) {
        return false;
    }
    if (raw.startsWith('//')) {
        return true;
    }
    if (/^(https?|file):\/\//i.test(raw)) {
        return true;
    }
    if (raw.startsWith('/')) {
        // Root-relative: out of scope - it names a path on our own origin.
        return false;
    }

    // A relative reference is only meaningful when the stylesheet itself came from
    // somewhere absolute.
    return ABSOLUTE_BASE.test(base_url);
}

/**
 * Resolve a reference against its stylesheet's base URL, with the fragment removed.
 *
 * @return {{url: string, fragment: string}}
 */
function resolve_reference(raw, base_url) {
    const hash = raw.indexOf('#');
    const fragment = hash === -1 ? '' : raw.slice(hash);
    const bare = hash === -1 ? raw : raw.slice(0, hash);

    if (bare.startsWith('//')) {
        const scheme = ABSOLUTE_BASE.test(base_url) ? base_url.slice(0, base_url.indexOf(':')) : 'https';

        return { url: scheme + ':' + bare, fragment: fragment };
    }

    if (/^(https?|file):\/\//i.test(bare)) {
        return { url: bare, fragment: fragment };
    }

    return { url: new URL(bare, base_url).href, fragment: fragment };
}

// -----------------------------------------------------------------------------
// Fetching
// -----------------------------------------------------------------------------

function download(target) {
    if (target.startsWith('file://')) {
        return Promise.resolve({ buffer: fs.readFileSync(fileURLToPath(target)), content_type: '' });
    }

    return new Promise((resolve, reject) => {
        const protocol = target.startsWith('https://') ? https : http;

        const request = protocol.get(target, {
            headers: { 'User-Agent': options.userAgent, 'Accept': '*/*' },
        }, (response) => {
            if (response.statusCode >= 300 && response.statusCode < 400 && response.headers.location) {
                response.resume();
                download(new URL(response.headers.location, target).href).then(resolve).catch(reject);

                return;
            }

            if (response.statusCode < 200 || response.statusCode > 299) {
                response.resume();
                reject(new Error(`HTTP ${response.statusCode}`));

                return;
            }

            const chunks = [];
            response.on('data', (chunk) => chunks.push(chunk));
            response.on('end', () => resolve({
                buffer: Buffer.concat(chunks),
                content_type: (response.headers['content-type'] || '').split(';')[0].trim().toLowerCase(),
            }));
            response.on('error', reject);
        });

        request.on('error', reject);
    });
}

function known_extension(target) {
    const ext = php_pathinfo(url_path_of(target)).extension.toLowerCase();

    return EXTENSIONS.includes(ext) ? ext : null;
}

/**
 * A file already in the store whose name begins with this URL's md5, whatever its
 * extension. This is how a URL with no recognisable path extension is found again
 * without another request.
 */
function find_existing(target) {
    const prefix = crypto.createHash('md5').update(target).digest('hex') + '_';

    if (!fs.existsSync(options.cacheDir)) {
        return null;
    }

    for (const entry of fs.readdirSync(options.cacheDir)) {
        if (entry.startsWith(prefix)) {
            return entry;
        }
    }

    return null;
}

/**
 * Mirror a binary (or otherwise non-stylesheet) reference; returns its filename.
 *
 * @return {Promise<string|null>} null when it failed (recorded in `failures`).
 */
async function mirror_binary(target) {
    if (binary_memo.has(target)) {
        return binary_memo.get(target);
    }

    const existing = find_existing(target);
    if (existing !== null) {
        hits.add(existing);
        binary_memo.set(target, existing);

        return existing;
    }

    if (options.noDownload) {
        record_failure(target, missing_mirror_message(target));

        return null;
    }

    let response;
    try {
        response = await download(target);
    } catch (err) {
        record_failure(target, err.message);

        return null;
    }

    let ext = known_extension(target);

    if (ext === null) {
        ext = EXTENSION_BY_MIME[response.content_type] || null;

        if (ext === null) {
            record_failure(
                target,
                `cannot name the mirror file: the URL path carries no known extension and the `
                + `Content-Type '${response.content_type || '(none)'}' maps to none`
            );

            return null;
        }
    }

    const name = filename_for(target, ext);
    fs.mkdirSync(options.cacheDir, { recursive: true });
    fs.writeFileSync(path.join(options.cacheDir, name), response.buffer);

    written.add(name);
    binary_memo.set(target, name);

    return name;
}

/**
 * Mirror an imported stylesheet, localized against its own URL; returns its CSS text.
 *
 * @return {Promise<string|null>} null when it failed (recorded in `failures`).
 */
async function mirror_stylesheet(target) {
    if (stylesheet_memo.has(target)) {
        return stylesheet_memo.get(target);
    }

    const name = filename_for(target, 'css');
    const file_path = path.join(options.cacheDir, name);

    if (fs.existsSync(file_path)) {
        const cached = fs.readFileSync(file_path, 'utf-8');
        hits.add(name);
        stylesheet_memo.set(target, cached);

        return cached;
    }

    if (options.noDownload) {
        record_failure(target, missing_mirror_message(target));

        return null;
    }

    let response;
    try {
        response = await download(target);
    } catch (err) {
        record_failure(target, err.message);

        return null;
    }

    const localized = await localize_text(response.buffer.toString('utf-8'), target);

    fs.mkdirSync(options.cacheDir, { recursive: true });
    fs.writeFileSync(file_path, localized, 'utf-8');

    written.add(name);
    stylesheet_memo.set(target, localized);

    return localized;
}

// -----------------------------------------------------------------------------
// Localization
// -----------------------------------------------------------------------------

const URL_TOKEN = /url\(\s*(?:"([^"]*)"|'([^']*)'|([^)'"]*))\s*\)/g;

const IMPORT_PARAMS = /^\s*(?:url\(\s*(?:"([^"]*)"|'([^']*)'|([^)'"]*))\s*\)|"([^"]*)"|'([^']*)')\s*(.*)$/s;

function parse_import(params) {
    const match = params.match(IMPORT_PARAMS);

    if (match === null) {
        return null;
    }

    const target = match[1] ?? match[2] ?? match[3] ?? match[4] ?? match[5] ?? '';

    return { url: target.trim(), prelude: (match[6] || '').trim() };
}

/**
 * Wrap spliced import nodes in the at-rules its prelude asked for.
 */
function wrap_prelude(nodes, prelude) {
    let rest = prelude;

    const layer = rest.match(/^layer\(([^)]*)\)\s*/);
    if (layer !== null) {
        rest = rest.slice(layer[0].length);
    }

    const supports = rest.match(/^supports\((.*?)\)\s*/);
    if (supports !== null) {
        rest = rest.slice(supports[0].length);
    }

    let current = nodes;

    const media = rest.trim();
    if (media !== '') {
        current = [wrap_in_at_rule('media', media, current)];
    }

    if (supports !== null) {
        current = [wrap_in_at_rule('supports', supports[1], current)];
    }

    if (layer !== null) {
        current = [wrap_in_at_rule('layer', layer[1], current)];
    }

    return current;
}

function wrap_in_at_rule(name, params, nodes) {
    const rule = postcss.atRule({ name: name, params: params });
    rule.raws.between = ' ';
    rule.append(nodes);

    return rule;
}

/**
 * Localize one stylesheet's PostCSS root in place.
 */
async function localize_root(root, base_url) {
    // ---- @import -----------------------------------------------------------
    const imports = [];
    root.walkAtRules('import', (rule) => imports.push(rule));

    for (const rule of imports) {
        const parsed = parse_import(rule.params);

        if (parsed === null || !is_localizable(parsed.url, base_url)) {
            continue;
        }

        const reference = resolve_reference(parsed.url, base_url);
        const body = await mirror_stylesheet(reference.url);

        if (body === null) {
            continue;
        }

        const spliced = postcss.parse(body, { from: reference.url });
        rule.replaceWith(wrap_prelude(spliced.nodes, parsed.prelude));
    }

    // ---- url() in declarations ---------------------------------------------
    const declarations = [];
    root.walkDecls((decl) => {
        if (decl.value.includes('url(')) {
            declarations.push(decl);
        }
    });

    const rewrites = new Map();

    for (const decl of declarations) {
        URL_TOKEN.lastIndex = 0;
        let match;
        while ((match = URL_TOKEN.exec(decl.value)) !== null) {
            const raw = (match[1] ?? match[2] ?? match[3] ?? '').trim();

            if (rewrites.has(raw) || !is_localizable(raw, base_url)) {
                continue;
            }

            const reference = resolve_reference(raw, base_url);
            const name = await mirror_binary(reference.url);

            if (name === null) {
                continue;
            }

            rewrites.set(raw, '/_vendor/' + name + reference.fragment);
        }
    }

    if (rewrites.size === 0) {
        return;
    }

    for (const decl of declarations) {
        decl.value = decl.value.replace(URL_TOKEN, (whole, double, single, bare) => {
            const raw = (double ?? single ?? bare ?? '').trim();

            if (!rewrites.has(raw)) {
                return whole;
            }

            return 'url("' + rewrites.get(raw) + '")';
        });
    }
}

async function localize_text(css, base_url) {
    const root = postcss.parse(css, { from: base_url });
    await localize_root(root, base_url);

    return root.toString();
}

// -----------------------------------------------------------------------------
// Entry point
// -----------------------------------------------------------------------------

async function main() {
    if (!fs.existsSync(options.in)) {
        console.error(`Input file not found: ${options.in}`);
        process.exit(1);
    }

    const css = fs.readFileSync(options.in, 'utf-8');
    const has_inline_map = /sourceMappingURL=data:/.test(css);

    const root = postcss.parse(css, { from: options.in });
    await localize_root(root, options.baseUrl);

    const result_options = { from: options.in, to: options.out };
    if (has_inline_map) {
        result_options.map = { inline: true, annotation: false };
    }

    const result = root.toResult(result_options);

    fs.mkdirSync(path.dirname(options.out), { recursive: true });
    fs.writeFileSync(options.out, result.css, 'utf-8');

    console.log(JSON.stringify({
        written: Array.from(written),
        hits: Array.from(hits),
        failures: failures,
    }));

    process.exit(failures.length > 0 ? 1 : 0);
}

main().catch((err) => {
    console.log(JSON.stringify({
        written: Array.from(written),
        hits: Array.from(hits),
        failures: failures.concat([{ url: options.baseUrl, error: err.message }]),
    }));
    process.exit(1);
});
