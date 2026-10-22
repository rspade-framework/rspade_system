#!/usr/bin/env node
/**
 * The portal SPA router matches only under the page's own portal base, and the client
 * portal address follows window.rsxapp.portal (per site in multi tenancy).
 *
 *   a. On a portal page, a path under the page's base is matched with the base removed.
 *   b. On a portal page, a path OUTSIDE the base is no route of this SPA, however its
 *      remainder reads - a staff path on the same host, another tenant's portal.
 *   c. A portal at the root of its own host (prefix '') claims every path.
 *   d. A staff page whose site has no portal (base null): Rsx_Portal.Route() answers false,
 *      is_on_portal_host() false, is_under_prefix() false.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys, and the
 * portal context is SIMULATED in the page - Rsx_Portal._is_portal, window.rsxapp.portal and a
 * probe SPA route registered in Spa.routes - then restored. Exit 0 on pass, 1 on any failure.
 * Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

function check(label, actual, expected) {
    if (actual === expected) {
        console.log('PASS: ' + label);
        return;
    }
    console.log('FAIL: portal_spa_prefix - ' + label + ' - expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
    process.exitCode = 1;
}

async function run() {
    const { browser, page } = await open_sys_page();

    try {
        const r = await page.evaluate(() => {
            const saved_portal = window.rsxapp.portal;
            const saved_is_portal = Rsx_Portal._is_portal;

            class Spa_Prefix_Probe_Action extends Spa_Action {}
            Spa.routes['/spa-prefix-probe'] = Spa_Prefix_Probe_Action;

            const matched = (url) => {
                const m = Spa.match_url_to_route(url);
                return m ? m.action_class.name : null;
            };

            const out = {};

            try {
                Rsx_Portal._is_portal = true;
                window.rsxapp.portal = {
                    origin: window.location.origin,
                    prefix: '/_portal/acme',
                    separate_host: false,
                    base: window.location.origin + '/_portal/acme',
                    app_host_prefix: '/_portal',
                };

                out.under = matched('/_portal/acme/spa-prefix-probe');
                out.under_query = matched('/_portal/acme/spa-prefix-probe?x=1');
                out.staff_path = matched('/spa-prefix-probe');
                out.other_tenant = matched('/_portal/other/spa-prefix-probe');
                out.look_alike = matched('/_portal/acmex/spa-prefix-probe');

                window.rsxapp.portal = { ...window.rsxapp.portal, prefix: '', base: window.location.origin, separate_host: true, app_host_prefix: null };
                out.root = matched('/spa-prefix-probe');

                Rsx_Portal._is_portal = false;
                window.rsxapp.portal = { origin: null, prefix: null, separate_host: false, base: null, app_host_prefix: '/_portal' };
                Rsx_Portal._routes.Spa_Prefix_Probe_Controller = { index: ['/spa-prefix-probe'] };
                out.no_portal_route = Rsx_Portal.Route('Spa_Prefix_Probe_Controller');
                out.no_portal_on_host = Rsx_Portal.is_on_portal_host();
                out.no_portal_under = Rsx_Portal.is_under_prefix('/_portal/acme');
            } finally {
                delete Spa.routes['/spa-prefix-probe'];
                delete Rsx_Portal._routes.Spa_Prefix_Probe_Controller;
                window.rsxapp.portal = saved_portal;
                Rsx_Portal._is_portal = saved_is_portal;
            }

            return out;
        });

        check('a. under the page base, the route matches', r.under, 'Spa_Prefix_Probe_Action');
        check('a. a query string does not change the match', r.under_query, 'Spa_Prefix_Probe_Action');
        check('b. a staff path on the same host is no portal route', r.staff_path, null);
        check('b. another tenant\'s portal is no route of this one', r.other_tenant, null);
        check('b. a segment look-alike of the base is outside it', r.look_alike, null);
        check('c. a portal at the root of its own host claims every path', r.root, 'Spa_Prefix_Probe_Action');
        check('d. no portal: Route() answers false', r.no_portal_route, false);
        check('d. no portal: is_on_portal_host() is false', r.no_portal_on_host, false);
        check('d. no portal: is_under_prefix() is false', r.no_portal_under, false);
    } finally {
        await browser.close();
    }
}

run().catch((e) => {
    console.log('FAIL: portal_spa_prefix - ' + e.message);
    process.exitCode = 1;
});
