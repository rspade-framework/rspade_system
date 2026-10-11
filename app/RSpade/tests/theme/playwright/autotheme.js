#!/usr/bin/env node
/**
 * The theme is on <html> before the page paints, and stays in step with the operating
 * system under auto (THEME-HEAD-ORDER, THEME-PREPAINT, THEME-OS-FOLLOW, THEME-EXPLICIT,
 * THEME-SET-MODE).
 *
 * The page is first loaded with every bundle script BLOCKED. Bundle scripts are deferred
 * and run after first paint, so a theme that is present with none of them loaded was put
 * there by autotheme.js alone - the synchronous script the bundle emits after
 * window.rsxapp and ahead of every stylesheet:
 *
 *   1. the script tag follows the rsxapp block, is neither deferred nor async, and
 *      precedes every stylesheet
 *   2. with no bundle script loaded, <html> carries the mode class, the dark class and
 *      the application's declared attributes for the resolved theme; <body> carries none
 *   3. under auto, a live OS scheme change flips the theme in place
 *   4. an explicit mode ignores the OS
 *   5. on a fully booted page, Rsx_Dark_Mode.set_mode() re-themes in place and
 *      Rsx_Dark_Mode.is_dark() answers what is showing
 *
 * Runs on the framework's own /_sys panel; self-contained, so a bare
 * `node autotheme.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const START_ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: autotheme - ' + msg);
    process.exitCode = 1;
}

function check(condition, label, detail) {
    if (condition) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/** Open START_ROUTE signed in. With block_bundles, every /_compiled/ script is aborted. */
async function open_page(context, block_bundles) {
    const page = await context.newPage();
    const auth_headers = {
        ...dev_auth_headers(START_ROUTE, USER_ID),
        'X-Playwright-Test': '1',
    };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        const url = request.url();
        if (!url.startsWith(BASE_URL)) {
            await route.continue();
            return;
        }
        if (block_bundles && request.resourceType() === 'script' && url.includes('/_compiled/')) {
            await route.abort();
            return;
        }
        const headers = { ...request.headers() };
        if (!initial_doc_sent && request.resourceType() === 'document') {
            Object.assign(headers, auth_headers);
            initial_doc_sent = true;
        } else {
            headers['X-Playwright-Test'] = '1';
        }
        await route.continue({ headers });
    });

    await page.goto(BASE_URL + START_ROUTE, { waitUntil: 'domcontentloaded' });
    return page;
}

/** What <html> and <body> say about the theme right now, against the shipped vocabulary. */
function read_theme(page) {
    return page.evaluate(() => {
        const theme = window.rsxapp.theme;
        const root = document.documentElement;
        const has_all = (set) => Object.keys(set).every((name) => root.getAttribute(name) === set[name]);

        return {
            mode: theme.mode,
            dark: root.classList.contains(theme.dark_class),
            mode_classes: ['light', 'dark', 'auto'].filter((name) => root.classList.contains(theme.mode_class_prefix + name)),
            dark_attributes: has_all(theme.dark_attributes),
            light_attributes: has_all(theme.light_attributes),
            declares_attributes: Object.keys(theme.dark_attributes).length > 0,
            body_dark: document.body.classList.contains(theme.dark_class),
            body_mode: /rsx-theme-/.test(document.body.className),
        };
    });
}

async function run() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    try {
        // ---- No bundle script loaded: autotheme.js alone -------------------------------
        const context = await browser.newContext({
            ignoreHTTPSErrors: true,
            viewport: { width: 1280, height: 800 },
            colorScheme: 'dark',
        });
        const page = await open_page(context, true);

        const order = await page.evaluate(() => {
            const nodes = Array.from(document.head.querySelectorAll('script, link[rel="stylesheet"]'));
            const index = nodes.findIndex((node) => node.tagName === 'SCRIPT' && node.src.includes('/_rsx/autotheme.js'));
            const script = nodes[index];
            const first_sheet = nodes.findIndex((node) => node.tagName === 'LINK');

            return {
                index: index,
                previous_inline: index > 0 && nodes[index - 1].tagName === 'SCRIPT' && !nodes[index - 1].src,
                deferred: script ? script.defer || script.async : null,
                before_sheets: first_sheet === -1 || index < first_sheet,
                bundle_loaded: typeof Rsx !== 'undefined',
            };
        });
        check(order.index === 1 && order.previous_inline, 'the script tag directly follows the rsxapp block', order);
        check(order.deferred === false, 'the script tag is neither deferred nor async', order);
        check(order.before_sheets, 'the script tag precedes every stylesheet', order);
        check(order.bundle_loaded === false, 'no bundle script ran on this page', order);

        const stored = await read_theme(page);
        const expect_dark = stored.mode === 2 ? true : stored.mode === 1;
        check(stored.dark === expect_dark, 'the resolved theme is on <html> with no bundle script loaded', stored);
        check(stored.mode_classes.length === 1 && stored.mode_classes[0] === ['light', 'dark', 'auto'][stored.mode],
            'exactly one mode class names the stored mode', stored);
        check(!stored.body_dark && !stored.body_mode, '<body> carries no theme class', stored);

        // Auto, whatever this identity has stored.
        await page.evaluate(() => window.rsx_autotheme.set_mode(2));
        const auto_dark = await read_theme(page);
        check(auto_dark.dark && auto_dark.dark_attributes && auto_dark.mode_classes.join() === 'auto',
            'auto on a dark OS is dark, with the declared dark attributes', auto_dark);

        await page.emulateMedia({ colorScheme: 'light' });
        await page.waitForFunction(() => !document.documentElement.classList.contains(window.rsxapp.theme.dark_class));
        const auto_light = await read_theme(page);
        check(!auto_light.dark && auto_light.light_attributes && (!auto_light.declares_attributes || !auto_light.dark_attributes),
            'a live OS change to light flips the theme in place', auto_light);

        await page.emulateMedia({ colorScheme: 'dark' });
        await page.waitForFunction(() => document.documentElement.classList.contains(window.rsxapp.theme.dark_class));
        check(true, 'a live OS change back to dark flips it again');

        // Explicit light on a dark OS, then the OS moving underneath it.
        await page.evaluate(() => window.rsx_autotheme.set_mode(0));
        await page.emulateMedia({ colorScheme: 'light' });
        await page.emulateMedia({ colorScheme: 'dark' });
        const explicit_light = await read_theme(page);
        check(!explicit_light.dark && explicit_light.mode_classes.join() === 'light', 'explicit light ignores a dark OS', explicit_light);

        await page.evaluate(() => window.rsx_autotheme.set_mode(1));
        await page.emulateMedia({ colorScheme: 'light' });
        const explicit_dark = await read_theme(page);
        check(explicit_dark.dark && explicit_dark.mode_classes.join() === 'dark', 'explicit dark ignores a light OS', explicit_dark);

        await context.close();

        // ---- A fully booted page: the in-bundle API ------------------------------------
        const booted_context = await browser.newContext({
            ignoreHTTPSErrors: true,
            viewport: { width: 1280, height: 800 },
            colorScheme: 'light',
        });
        const booted = await open_page(booted_context, false);
        await booted.waitForFunction(() => typeof Rsx_Dark_Mode !== 'undefined');

        const api = await booted.evaluate(() => {
            const out = {};
            const dark_class = window.rsxapp.theme.dark_class;
            const showing = () => document.documentElement.classList.contains(dark_class);

            Rsx_Dark_Mode.set_mode(Rsx_Dark_Mode.MODE_DARK);
            out.dark = { showing: showing(), is_dark: Rsx_Dark_Mode.is_dark(), mode: Rsx_Dark_Mode.mode() };

            Rsx_Dark_Mode.set_mode(Rsx_Dark_Mode.MODE_AUTO);
            out.auto = { showing: showing(), is_dark: Rsx_Dark_Mode.is_dark(), mode: Rsx_Dark_Mode.mode() };

            return out;
        });
        check(api.dark.showing && api.dark.is_dark === true && api.dark.mode === 1, 'Rsx_Dark_Mode.set_mode(dark) re-themes the page in place', api.dark);
        check(!api.auto.showing && api.auto.is_dark === false && api.auto.mode === 2, 'Rsx_Dark_Mode.set_mode(auto) returns to the OS answer', api.auto);

        await booted_context.close();
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: autotheme');
    } else {
        console.log('PASS: autotheme');
    }
}

run();
