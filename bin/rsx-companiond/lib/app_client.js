/**
 * The daemon's two signed calls to the application. Key: the daemon key. Canonical string and
 * headers: Companion_Verifier::verify_daemon().
 *
 * No call here carries a timeout. A call that never answers leaves one companion WAITING with
 * the reason "asking the application", which is visible and harms nothing running.
 */

const http = require('http');
const https = require('https');

const wire = require('./wire.js');

class App_Client {
    constructor(base_url, daemon_key) {
        this.base_url = base_url;
        this.daemon_key = daemon_key;
    }

    /** Answers {status, body}; rejects when nothing answered. */
    _post(uri, body) {
        const url = new URL(this.base_url + uri);
        const transport = url.protocol === 'https:' ? https : http;

        const timestamp = String(Math.floor(Date.now() / 1000));
        const nonce = wire.new_nonce();
        const signature = wire.hmac(
            this.daemon_key,
            wire.daemon_canonical_string('POST', uri, timestamp, nonce, body)
        );

        return new Promise((resolve, reject) => {
            const request = transport.request(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(body),
                    'X-Rsx-Companion-Timestamp': timestamp,
                    'X-Rsx-Companion-Nonce': nonce,
                    'X-Rsx-Companion-Signature': signature,
                },
            }, (response) => {
                const chunks = [];
                response.on('data', (chunk) => chunks.push(chunk));
                response.on('end', () => resolve({
                    status: response.statusCode,
                    body: Buffer.concat(chunks).toString('utf8'),
                }));
                response.on('error', reject);
            });

            request.on('error', reject);
            request.end(body);
        });
    }

    /**
     * May this companion start? {ready, scope, reason}. Any failure to get an answer is "not
     * ready", never an error: during a maintenance window or early boot it is the normal case.
     */
    async ready(name) {
        let response;
        try {
            response = await this._post('/_companion/_daemon/ready/' + name, '');
        } catch (err) {
            return { ready: false, scope: 'application', reason: 'the application is not answering: ' + err.message };
        }

        if (response.status === 200) {
            try {
                const answer = JSON.parse(response.body);
                if (typeof answer.ready === 'boolean') return answer;
            } catch (err) {
                // Falls through to the plain answer below.
            }
        }

        return { ready: false, scope: 'application', reason: 'the application answered HTTP ' + response.status };
    }

    /** Fires companion.error. Resolves true when the application took it. */
    async event(payload) {
        try {
            const response = await this._post('/_companion/_daemon/event', JSON.stringify(payload));
            return response.status === 204;
        } catch (err) {
            return false;
        }
    }
}

module.exports = { App_Client };
