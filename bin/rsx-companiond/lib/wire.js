/**
 * The wire contract's primitives, shared by the daemon and the SDK. Pure: no I/O, no state.
 * The PHP twin is App\RSpade\Core\Companion\Companion_Verifier; Companion_Sdk_Wire_Test runs
 * both and compares them.
 */

const crypto = require('crypto');

/** A companion process exits with this when the application refused its contract key. */
const EXIT_CONTRACT_MISMATCH = 75;

/** rsx-lockd's own replay window, and the one the signed request uses. */
const WINDOW_SECONDS = 60;

function hmac(key, text) {
    return crypto.createHmac('sha256', key).update(text).digest('hex');
}

function sha256(data) {
    return crypto.createHash('sha256').update(data).digest('hex');
}

/** Both derived keys are used as their hex STRINGS, and APP_KEY is the raw .env string. */
function instance_secret(app_key, name, instance_id, started_at) {
    return hmac(app_key, 'companion|' + name + '|' + instance_id + '|' + started_at);
}

function daemon_key(app_key) {
    return hmac(app_key, 'companiond');
}

/** `site` is the literal '-' when the request names none. */
function canonical_string(method, uri, name, instance_id, started_at, contract_key, site, timestamp, nonce, body) {
    return [method, uri, name, instance_id, started_at, contract_key, site, timestamp, nonce, sha256(body)].join('|');
}

function daemon_canonical_string(method, uri, timestamp, nonce, body) {
    return canonical_string(method, uri, 'companiond', '-', '-', '-', '-', timestamp, nonce, body);
}

function new_nonce() {
    return crypto.randomBytes(24).toString('base64url');
}

/** Rsx_Connection_Scope::token(). */
function scope_token(database, host) {
    return crypto.createHash('md5').update(database + '@' + host).digest('hex');
}

/** Control socket hello: proves the peer holds the daemon key. */
function socket_hello_signature(key, timestamp) {
    return hmac(key, 'companiond-socket|' + timestamp);
}

module.exports = {
    EXIT_CONTRACT_MISMATCH,
    WINDOW_SECONDS,
    hmac,
    sha256,
    instance_secret,
    daemon_key,
    canonical_string,
    daemon_canonical_string,
    new_nonce,
    scope_token,
    socket_hello_signature,
};
