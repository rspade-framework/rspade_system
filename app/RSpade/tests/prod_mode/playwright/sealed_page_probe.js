#!/usr/bin/env node
/**
 * sealed_page_probe - render signed-in pages on a SEALED box and report what they loaded.
 *
 * rsx:debug cannot do this: its browser arrives as a user through the development grant
 * store, and a sealed build has none. So this probe signs in the way a person does - the
 * application's own login form, with an account the calling script created - and then
 * drives headless Chromium at the pages it is given.
 *
 * HOW THE BROWSER REACHES THE SITE. A production box terminates TLS upstream; on the box
 * itself the web server answers plain http on the loopback interface only. The session
 * cookie outside development is `__Host-rsx`, which a browser holds for a secure origin and
 * no other. So the browser is pointed at https://<APP_URL host> and never opens a
 * connection of its own: every request to that origin is answered here, by forwarding it to
 * the loopback server with the declared Host header and the signed-in session cookie. The
 * pages, bundles and scripts the browser executes are exactly the bytes the box serves.
 * The realtime socket is not HTTP and has nowhere to go, so its connection failure is the
 * one console message the probe expects and drops.
 *
 * Usage:
 *
 *   PROBE_EMAIL=... PROBE_PASSWORD=... node sealed_page_probe.js <app host> <state key> <code key> <path> [<path> ...]
 *
 * A path given as `@landing` is the page the sign-in redirected to - whatever this
 * application's signed-in home is.
 *
 * AN ANONYMOUS PAGE FIRST, when PROBE_ANON_PATH and PROBE_ANON_MARKER are set: before it
 * signs in, the probe renders PROBE_ANON_PATH as a visitor with no session and checks that
 * it answers 200, reports no error, and that its rendered DOM carries PROBE_ANON_MARKER -
 * the lifecycle's proof that a component an application overrides is what the sealed
 * bundle draws. Whatever session that visit opened is discarded before the sign-in, which
 * starts, as a person's does, with none.
 *
 * FOR EVERY PAGE: the document answers 200; the page reports no error; rsxapp.build_key is
 * the state key and rsxapp.code_key the code key it was told to expect; and when the page
 * carries a field-length script tag, the tag carries that state key and the script has
 * assigned Rsx.__field_length_data.
 *
 * FOR /_sys (the framework's own page): the tag is required, and the framework's
 * User_Model answers a number for first_name through the bundle's own field_length().
 *
 * Prints one PASS:/FAIL: line per check, then for the caller to read:
 *
 *   FIELD_LENGTH_URL=<path and query of the /_sys page's field-length script>
 *   FIELD_LENGTH_SAMPLE=<what User_Model.field_length('first_name') answered>
 *
 * Exit 0 when every check passed, 1 otherwise. Nothing here has a deadline: a wait ends when
 * the thing waited for has happened.
 */
const http = require('http');
const path_module = require('path');
const { chromium } = require(path_module.resolve(__dirname, '../../../../../node_modules/playwright'));

const [app_host, expected_state_key, expected_code_key, ...paths] = process.argv.slice(2);
const email = process.env.PROBE_EMAIL || '';
const password = process.env.PROBE_PASSWORD || '';
const anon_path = process.env.PROBE_ANON_PATH || '';
const anon_marker = process.env.PROBE_ANON_MARKER || '';

if (!app_host || !expected_state_key || !expected_code_key || paths.length === 0 || !email || !password) {
    console.log('FAIL: usage - PROBE_EMAIL=.. PROBE_PASSWORD=.. node sealed_page_probe.js <app host> <state key> <code key> <path>...');
    process.exit(1);
}

const ORIGIN = 'https://' + app_host;
const SYS_PATH = '/_sys';

let session_cookie = null;

function check(ok, what, detail) {
    if (ok) {
        console.log('PASS: ' + what);
    } else {
        console.log('FAIL: ' + what + (detail ? ' - ' + detail : ''));
        process.exitCode = 1;
    }
}

/**
 * The session cookie a response set, as "name=value", or null. Both names the framework
 * uses are read: the secure one, and the plain one a development box sets over http.
 */
function session_cookie_from(headers) {
    for (const line of headers['set-cookie'] || []) {
        const match = line.match(/^(__Host-rsx|rsx)=([^;]*)/);
        if (match && match[2] !== '' && match[2] !== 'deleted') {
            return match[1] + '=' + match[2];
        }
    }
    return null;
}

/** One request to the loopback web server, carrying the declared Host and the session. */
function loopback_request(method, path, headers, body) {
    return new Promise((resolve, reject) => {
        const request_headers = { ...headers, host: app_host };
        delete request_headers['accept-encoding'];
        if (session_cookie) {
            request_headers.cookie = session_cookie;
        }
        if (body) {
            request_headers['content-length'] = Buffer.byteLength(body);
        }

        const request = http.request({ host: '127.0.0.1', port: 80, method, path, headers: request_headers }, (response) => {
            const chunks = [];
            response.on('data', (chunk) => chunks.push(chunk));
            response.on('end', () => {
                const rotated = session_cookie_from(response.headers);
                if (rotated) {
                    session_cookie = rotated;
                }
                resolve({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks) });
            });
        });
        request.on('error', reject);
        if (body) {
            request.write(body);
        }
        request.end();
    });
}

/**
 * Sign in through the application's login form. Returns the path it redirected to.
 *
 * An anonymous browser has no session, so the POST carries no cookie and needs no CSRF
 * token (rsx:man csrf: the synchronizer token binds to a session, and there is none yet).
 * The sign-in creates the session and the response sets its cookie.
 */
async function sign_in() {
    const form = new URLSearchParams({ email, password, __turnstile: 'inactive' }).toString();
    const answer = await loopback_request('POST', '/login', { 'content-type': 'application/x-www-form-urlencoded' }, form);

    if (answer.status !== 302 || !answer.headers.location) {
        throw new Error('POST /login answered ' + answer.status + ' (a sign-in redirects)');
    }

    if (!session_cookie) {
        throw new Error('POST /login set no session cookie');
    }

    const location = new URL(answer.headers.location, ORIGIN);

    if (location.pathname === '/login') {
        throw new Error('POST /login redirected back to the login page - the sign-in was refused');
    }

    return location.pathname + location.search;
}

async function probe_page(context, path) {
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (err) => errors.push(err.message));
    page.on('console', (msg) => {
        // The realtime socket: see the file header.
        if (msg.type() === 'error' && !/WebSocket|wss:\/\//.test(msg.text())) {
            errors.push(msg.text());
        }
    });

    const response = await page.goto(ORIGIN + path, { waitUntil: 'load', timeout: 0 });
    const status = response ? response.status() : 0;
    check(status === 200, path + ' answered 200', 'answered ' + status);

    if (status !== 200) {
        await page.close();
        return;
    }

    // A SPA page is ready once its action is; Spa.action() throws until then.
    const is_spa = await page.evaluate(() => typeof Spa !== 'undefined');
    if (is_spa) {
        await page.waitForFunction(() => {
            try {
                return !!Spa.action();
            } catch (e) {
                return false;
            }
        }, null, { timeout: 0 });
    }
    await page.waitForLoadState('networkidle', { timeout: 0 });

    const seen = await page.evaluate(() => {
        const tag = Array.from(document.scripts)
            .map((script) => script.getAttribute('src') || '')
            .find((src) => src.includes('/_field_lengths/')) || null;

        return {
            tag,
            state_key: window.rsxapp ? window.rsxapp.build_key : null,
            code_key: window.rsxapp ? window.rsxapp.code_key : null,
            has_data: typeof Rsx !== 'undefined' && Rsx.__field_length_data !== null && typeof Rsx.__field_length_data === 'object',
        };
    });

    check(seen.state_key === expected_state_key, path + ' carries the state key as rsxapp.build_key', seen.state_key + ' (expected ' + expected_state_key + ')');
    check(seen.code_key === expected_code_key, path + ' carries the code key as rsxapp.code_key', seen.code_key + ' (expected ' + expected_code_key + ')');

    if (seen.tag !== null) {
        check(seen.tag.endsWith('?v=' + expected_state_key), path + ' names its field-length script under the state key', seen.tag);
        check(seen.has_data, path + ' - the field-length script assigned Rsx.__field_length_data');
    }

    if (path === SYS_PATH) {
        check(seen.tag !== null, path + ' carries a field-length script tag');

        const sample = await page.evaluate(() => {
            try {
                return { value: User_Model.field_length('first_name'), id: User_Model.field_length('id') };
            } catch (e) {
                return { error: e.message };
            }
        });

        check(
            typeof sample.value === 'number' && sample.value > 0 && sample.id === null,
            path + ' - User_Model.field_length() answers through the compiled bundle',
            sample.error || JSON.stringify(sample)
        );

        if (seen.tag !== null) {
            const url = new URL(seen.tag, ORIGIN);
            console.log('FIELD_LENGTH_URL=' + url.pathname + url.search);
        }
        console.log('FIELD_LENGTH_SAMPLE=' + sample.value);
    }

    check(errors.length === 0, path + ' reported no error', errors.join(' | '));
    await page.close();
}

/**
 * Render one page as an anonymous visitor and look for the marker in what it drew.
 */
async function probe_anonymous_page(context, path, marker) {
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (err) => errors.push(err.message));
    page.on('console', (msg) => {
        // The realtime socket: see the file header.
        if (msg.type() === 'error' && !/WebSocket|wss:\/\//.test(msg.text())) {
            errors.push(msg.text());
        }
    });

    const response = await page.goto(ORIGIN + path, { waitUntil: 'load', timeout: 0 });
    const status = response ? response.status() : 0;
    check(status === 200, path + ' (anonymous) answered 200', 'answered ' + status);

    if (status === 200) {
        await page.waitForLoadState('networkidle', { timeout: 0 });

        const has_marker = await page.evaluate((needle) => document.documentElement.innerHTML.includes(needle), marker);
        check(has_marker, path + ' (anonymous) rendered the overriding component (marker ' + marker + ')');
        check(errors.length === 0, path + ' (anonymous) reported no error', errors.join(' | '));
    }

    await page.close();
}

(async () => {
    let browser = null;

    try {
        browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });
        const context = await browser.newContext({ ignoreHTTPSErrors: true });

        await context.route('**/*', async (route, request) => {
            const url = new URL(request.url());

            if (url.origin !== ORIGIN) {
                await route.abort();
                return;
            }

            const answer = await loopback_request(request.method(), url.pathname + url.search, request.headers(), request.postDataBuffer());
            const headers = { ...answer.headers };
            delete headers['content-length'];
            delete headers['transfer-encoding'];
            delete headers['content-encoding'];
            delete headers.connection;
            // The probe holds the session itself (loopback_request reads every rotation).
            delete headers['set-cookie'];

            await route.fulfill({ status: answer.status, headers, body: answer.body });
        });

        if (anon_path !== '' && anon_marker !== '') {
            await probe_anonymous_page(context, anon_path, anon_marker);
            session_cookie = null;
        }

        const landing = await sign_in();
        check(true, 'signed in through /login (landed on ' + landing + ')');

        for (const path of paths) {
            await probe_page(context, path === '@landing' ? landing : path);
        }
    } catch (e) {
        check(false, 'the probe ran to completion', e.message);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();
