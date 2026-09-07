#!/usr/bin/env node
/**
 * SPA page-title ladder, client half (SPA-TITLE-01..04).
 *
 * The ladder only exists in a browser, so this is where it is proved:
 *
 *   1. STATIC: an action declaring @title and NOT overriding page_title() exposes that
 *      string through get_static_title(), and a layout painting through
 *      Spa_Layout.resolve_page_title() receives it SYNCHRONOUSLY - before the returned
 *      promise is awaited, i.e. with zero latency at dispatch time.
 *   2. The same string is what page_title() resolves to, and what the layout actually
 *      put on screen (the header title element and document.title).
 *   3. DYNAMIC: an action that overrides page_title() reports get_static_title() === null
 *      even when its class carries a @title value - the decorator string is generic route
 *      metadata there, and painting it would flash a wrong title.
 *   4. A dynamic action's title still resolves from this.data (its override awaits
 *      Spa_Action.await_loaded()), and nothing is painted synchronously for it.
 *
 * Self-contained: mints its own dev-auth headers through tests/_lib/dev_auth.js (the
 * node twin of Dev_Auth_Token, keyed on the local development grant), so it runs with a bare
 * `node spa_action_title.js`. Runs against the dev web server on localhost (the same
 * target rsx:debug uses) and reads a real contact id off the list page it lands on.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const STATIC_ROUTE = '/contacts';
const STATIC_TITLE = 'Contacts';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: SPA title ladder - ' + msg);
    process.exitCode = 1;
}

/** Wait for the SPA action's full lifecycle on the page. */
async function await_spa_ready(page) {
    await page.evaluate(() => new Promise((resolve) => {
        if (typeof Rsx !== 'undefined' && Rsx.on) {
            Rsx.on('_debug_ready', () => resolve());
            setTimeout(resolve, 15000);
        } else {
            setTimeout(resolve, 3000);
        }
    }));

    // Prevent false-positive session-hash reloads in the dev-auth environment.
    await page.evaluate(() => { if (typeof Rsx !== 'undefined') Rsx.validate_session = async () => true; });
}

/**
 * Drive the ladder on whatever action is currently dispatched and report what it did.
 * `painted_sync` is what reached the paint callback before the promise was ever awaited.
 */
async function probe_title(page) {
    return await page.evaluate(async () => {
        const painted = [];
        const promise = Spa.layout.resolve_page_title((title) => painted.push(title));
        const painted_sync = painted.slice();
        const live = await promise;

        return {
            static_title: Spa.action().get_static_title(),
            live: live,
            painted_sync: painted_sync,
            painted_all: painted,
            document_title: document.title,
            header: (document.querySelector('[data-sid=page_title]') || {}).textContent,
        };
    });
}

async function run() {

    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: { width: 1920, height: 1080 },
    });
    const page = await context.newPage();

    // Auth headers ONLY on the initial document request (per the SPA test harness contract).
    const auth_headers = {
        ...dev_auth_headers(STATIC_ROUTE, USER_ID),
        'X-Playwright-Test': '1',
    };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        const url = request.url();
        if (url.startsWith(BASE_URL)) {
            const headers = { ...request.headers() };
            if (!initial_doc_sent && request.resourceType() === 'document') {
                Object.assign(headers, auth_headers);
                initial_doc_sent = true;
            } else {
                headers['X-Playwright-Test'] = '1';
            }
            await route.continue({ headers });
        } else {
            await route.continue();
        }
    });

    try {
        await page.goto(BASE_URL + STATIC_ROUTE, { waitUntil: 'commit' });
        await await_spa_ready(page);

        const on_page = await page.evaluate(() => typeof Spa !== 'undefined' && !!(Spa.action && Spa.action()));
        if (!on_page) {
            fail('SPA action not present - auth may have failed');
            await browser.close();
            return;
        }

        // --- 1 + 2. static-title action ---
        const fixed = await probe_title(page);

        if (fixed.static_title !== STATIC_TITLE) {
            fail('get_static_title() on an @title-only action returned "' + fixed.static_title + '", expected "' + STATIC_TITLE + '"');
        } else {
            console.log('PASS: @title-only action reports its static title');
        }

        if (fixed.painted_sync.join(',') !== STATIC_TITLE) {
            fail('static title was not painted synchronously (painted before await: [' + fixed.painted_sync.join(',') + '])');
        } else {
            console.log('PASS: static title paints synchronously, before any await');
        }

        if (fixed.live !== STATIC_TITLE || fixed.header !== STATIC_TITLE || fixed.document_title.indexOf(STATIC_TITLE) === -1) {
            fail('static title did not reach the page: live="' + fixed.live + '" header="' + fixed.header + '" document.title="' + fixed.document_title + '"');
        } else {
            console.log('PASS: page_title(), the header element and document.title all carry the static title');
        }

        // A real contact id, straight off the rendered datagrid rows (href = /contacts/view/:id).
        const contact_ids = await page.evaluate(() => {
            const ids = [];
            $('tr[data-href]').each(function () {
                const m = ($(this).attr('data-href') || '').match(/\/contacts\/view\/(\d+)/);
                if (m) {
                    ids.push(parseInt(m[1], 10));
                }
            });
            return ids;
        });

        if (contact_ids.length === 0) {
            fail('need at least one contact row on ' + STATIC_ROUTE + ' to exercise the dynamic case');
            await browser.close();
            return;
        }

        // --- 3 + 4. data-dependent action ---
        await page.evaluate((id) => Spa.dispatch('/contacts/view/' + id), contact_ids[0]);
        await page.evaluate(() => Spa.action().ready());

        const dynamic = await probe_title(page);

        if (dynamic.static_title !== null) {
            fail('an action overriding page_title() must report get_static_title() === null, got "' + dynamic.static_title + '"');
        } else {
            console.log('PASS: an overriding action reports no static title');
        }

        if (dynamic.painted_sync.length !== 0) {
            fail('a dynamic title must not paint synchronously, painted [' + dynamic.painted_sync.join(',') + ']');
        } else {
            console.log('PASS: nothing is painted synchronously for a dynamic title');
        }

        if (!dynamic.live || dynamic.live === '(title not set)' || dynamic.live !== dynamic.header) {
            fail('dynamic title did not resolve from this.data: live="' + dynamic.live + '" header="' + dynamic.header + '"');
        } else {
            console.log('PASS: dynamic title resolves from loaded data ("' + dynamic.live + '")');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: SPA title ladder');
    } else {
        console.log('PASS: SPA title ladder');
    }
}

run();
