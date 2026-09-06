/**
 * dev-auth.js - the ONE node-side minter of the rsx:debug dev-auth headers.
 *
 * A standalone Playwright test drives the running development site as a chosen user by
 * asserting that identity in headers, signed so that only a process with local disk
 * access to this box can make the assertion. This module is the node twin of
 * App\RSpade\Core\Debug\Dev_Auth_Token (PHP), which is what mints for rsx:debug itself
 * and what verifies both.
 *
 * THE KEY IS THE LOCAL GRANT SECRET, NOT APP_KEY. The framework writes
 * storage/rsx-ide-bridge/ide-grant-<hex>.token in development - a JSON document
 * {"secret", "app_url", "issued_at"} - and the NEWEST of those secrets signs the
 * assertion. Possession proves local read access, which is the whole grant. APP_KEY is
 * deliberately not used: it also encrypts every cookie and rides along in backups, so
 * signing "log in as any user" with it made an APP_KEY disclosure an authentication
 * bypass.
 *
 * THE WIRE FORMAT, byte for byte. Headers:
 *
 *     X-Dev-Auth-User-Id      staff login_users.id
 *     X-Dev-Auth-Exp          expiry, unix seconds, decimal digits
 *     X-Dev-Auth-Token        lowercase hex HMAC-SHA256
 *
 * The signed payload is PHP's json_encode of, in this key order:
 *
 *     {"url":"\/contacts","user_id":1,"portal":false,"exp":1757203200}
 *
 * PHP json_encode escapes forward slashes as \/, so the JSON produced here is escaped
 * to match byte for byte. url is the REQUEST URI the assertion is scoped to - a token
 * minted for /contacts does NOT validate a request to /engagements, and only the FIRST
 * document request is verified (the session cookie carries every request after it).
 *
 * THE ASSERTION EXPIRES (LIFETIME_SECONDS). That is a credential lifetime, not a
 * timeout: nothing is aborted when it passes, the signature simply stops being
 * accepted. Mint immediately before navigating - which is what every caller does.
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

/**
 * Project root: this file is <root>/system/bin/dev-auth.js.
 *
 * It lives beside route-debug.js rather than under tests/ because the framework JS
 * parser scans tests/ and rejects module.exports there (bundle files are concatenated,
 * not required) - system/bin/ is the tree's home for node modules that ARE required.
 */
const PROJECT_ROOT = path.resolve(__dirname, '../..');

/** Must match App\RSpade\Core\Debug\Dev_Auth_Token::LIFETIME_SECONDS. */
const LIFETIME_SECONDS = 60;

/** Must match App\RSpade\Core\Ide\Ide_Bridge_Token::ACTIVE_GRANTS. */
const ACTIVE_GRANTS = 2;

/**
 * Every established grant secret, newest issued_at first, at most ACTIVE_GRANTS of
 * them - the same slice the PHP verifier accepts.
 *
 * @returns {string[]}
 */
function active_secrets() {
    const dir = path.join(PROJECT_ROOT, 'storage/rsx-ide-bridge');
    let entries;
    try {
        entries = fs.readdirSync(dir);
    } catch (e) {
        throw new Error(
            'No development grant store at ' + dir + '. Browse the site once in development, '
            + 'or run `php artisan rsx:debug /login`, to establish one.'
        );
    }

    const grants = [];
    for (const entry of entries) {
        if (!entry.startsWith('ide-grant-') || !entry.endsWith('.token')) {
            continue;
        }
        let document;
        try {
            document = JSON.parse(fs.readFileSync(path.join(dir, entry), 'utf8'));
        } catch (e) {
            continue;
        }
        if (!document || typeof document.secret !== 'string' || document.secret === '') {
            continue;
        }
        grants.push({ secret: document.secret, issued_at: Number(document.issued_at) || 0 });
    }

    if (grants.length === 0) {
        throw new Error('No readable grant document in ' + dir);
    }

    grants.sort((a, b) => b.issued_at - a.issued_at);

    return grants.slice(0, ACTIVE_GRANTS).map((grant) => grant.secret);
}

/**
 * Mint the dev-auth headers for one URL and staff user.
 *
 * @param {string} url    The request URI the assertion is scoped to (no fragment).
 * @param {number} user_id
 * @returns {Object} The three X-Dev-Auth-* headers, ready to spread into a header set.
 */
function dev_auth_headers(url, user_id) {
    const secret = active_secrets()[0];
    const exp = Math.floor(Date.now() / 1000) + LIFETIME_SECONDS;

    // PHP json_encode key order and its \/ slash escaping, reproduced byte for byte.
    const payload = JSON.stringify({
        url: url,
        user_id: user_id,
        portal: false,
        exp: exp,
    }).replace(/\//g, '\\/');

    return {
        'X-Dev-Auth-User-Id': String(user_id),
        'X-Dev-Auth-Exp': String(exp),
        'X-Dev-Auth-Token': crypto.createHmac('sha256', secret).update(payload).digest('hex'),
    };
}

module.exports = { dev_auth_headers, active_secrets, LIFETIME_SECONDS };
