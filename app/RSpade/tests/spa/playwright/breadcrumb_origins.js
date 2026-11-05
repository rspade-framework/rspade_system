#!/usr/bin/env node
/**
 * Breadcrumb origins - the reserved _via parameter (SPA-VIA-01..13, rsx:man breadcrumb_origins).
 *
 * A page reached from somewhere other than its home may show THAT trail: the URL carries
 * origin tokens in _via, a page that accepts the token's type verifies it in
 * breadcrumb_parent_via() and names the alternate parent, the framework carries _via onto
 * every in-app navigation, and after each walk it keeps the _via or strips it with
 * history.replaceState(). Only a browser has a real history stack and real link events, so
 * this is where the behaviour is proved.
 *
 * Runs on the framework's own /_sys panel (a browser test cannot see the test tree) and
 * declares the breadcrumb structure at run time, in the browser, on panel actions:
 *
 *   /_sys/sites                 root (no parent)
 *   /_sys/sites/:id             parent /_sys/sites; accepts 'users_home' (returns /_sys/users
 *                               carrying its own derived _via=dash_home)
 *   /_sys/users                 parent /_sys; accepts 'dash_home' (returns /_sys)
 *   /_sys/users/:id             parent /_sys/users; accepts 'site', VERIFIES the id is '1',
 *                               returns /_sys/sites/1#tab=users          <- the decider
 *   /_sys/system-info           parent /_sys/users/1                     <- a page beneath it
 *   /_sys/workers               accepts 'site' too (always)              <- another decider
 *   /_sys/logs <-> /_sys/companions                                       a parent loop
 *   /_sys/performance?n=N       parent /_sys/performance?n=N+1           an endless trail
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

function fail(msg) {
    console.log('FAIL: breadcrumb origins - ' + msg);
    process.exitCode = 1;
}

function check(ok, pass_msg, detail) {
    if (ok) {
        console.log('PASS: ' + pass_msg);
    } else {
        fail(pass_msg + ' - got ' + JSON.stringify(detail));
    }
}

async function install(page) {
    await page.evaluate(() => {
        const parent = (cls, fn) => { cls.prototype.breadcrumb_parent = async function () { return fn.call(this); }; };

        parent(_Sys_Sites_Action, () => null);
        parent(_Sys_Site_View_Action, () => '/_sys/sites');
        parent(_Sys_Users_Action, () => '/_sys');
        parent(_Sys_User_View_Action, () => '/_sys/users');
        parent(_Sys_System_Info_Action, () => '/_sys/users/1');
        parent(_Sys_Task_Workers_Action, () => '/_sys');
        parent(_Sys_Tasks_Action, () => '/_sys');
        parent(_Sys_Logs_Action, () => '/_sys/companions');
        parent(_Sys_Companions_Action, () => '/_sys/logs');
        parent(_Sys_Performance_Action, function () { return '/_sys/performance?n=' + (int(this.args.n || 0) + 1); });

        _Sys_User_View_Action.breadcrumb_via = ['site'];
        _Sys_User_View_Action.prototype.breadcrumb_parent_via = async function (via) {
            // The verification: only site 1 is a true origin for this probe.
            return via.id === '1' ? '/_sys/sites/' + via.id + '#tab=users' : null;
        };

        _Sys_Site_View_Action.breadcrumb_via = ['users_home'];
        _Sys_Site_View_Action.prototype.breadcrumb_parent_via = async function () {
            return '/_sys/users?_via=dash_home';
        };

        _Sys_Users_Action.breadcrumb_via = ['dash_home'];
        _Sys_Users_Action.prototype.breadcrumb_parent_via = async function () {
            return '/_sys';
        };

        _Sys_Task_Workers_Action.breadcrumb_via = ['site'];
        _Sys_Task_Workers_Action.prototype.breadcrumb_parent_via = async function (via) {
            return '/_sys/sites/' + via.id;
        };

        window.__via = {
            // Path and query only: the panel writes its own #tab= fragments.
            here: () => location.pathname + location.search,

            // Step back one entry and wait for the page it lands on to settle its origin.
            async back() {
                const leaving = Spa.action();
                history.back();
                await new Promise((resolve) => {
                    const poll = () => {
                        const now = Spa._action;
                        if (now && now !== leaving && now._spa_via_settlement) {
                            resolve();
                        } else {
                            requestAnimationFrame(poll);
                        }
                    };
                    poll();
                });
                return Spa.action()._spa_via_settlement;
            },

            // Navigate, then wait for the keep/strip decision of the page that lands.
            async go(url, options = {}) {
                await Spa.dispatch(url, options);
                const length_at_dispatch = history.length;
                const arrival = Spa._via_navigation ? Spa._via_navigation.arrival : null;
                const settled = await Spa.action()._spa_via_settlement;
                return {
                    loc: window.__via.here(),
                    decider: history.state ? (history.state.via_decider ?? null) : null,
                    kept: settled.kept,
                    arrival: arrival,
                    length_changed: history.length !== length_at_dispatch,
                };
            },

            // Stream the current page's trail and return what on_complete and every layout saw.
            trail() {
                return new Promise((resolve) => {
                    const layout_saw = [];
                    for (const layout of Spa._collect_all_layouts()) {
                        layout.on_breadcrumb_complete = (chain, meta) => layout_saw.push(meta);
                    }
                    let first_chain_meta = null;
                    Rsx_Breadcrumb_Resolver.stream(Spa.action(), location.pathname + location.search, {
                        on_chain: (chain, meta) => { if (!first_chain_meta) first_chain_meta = meta; },
                        on_label_update: () => {},
                        on_complete: (chain, meta) => {
                            setTimeout(() => resolve({
                                urls: chain.map((c) => c.url),
                                meta: meta,
                                chain_meta: first_chain_meta,
                                layout_saw: layout_saw,
                            }), 0);
                        },
                    });
                });
            },
        };
    });
}

async function run() {
    const { browser, page } = await open_sys_page();

    try {
        await install(page);

        // --- 1. explicit origin accepted ---
        const explicit = await page.evaluate(() => window.__via.go('/_sys/users/1?_via=site.1'));
        check(explicit.kept && explicit.loc === '/_sys/users/1?_via=site.1' && explicit.decider === '_Sys_User_View_Action@/_sys/users/1',
            'an explicit _via the page accepts is kept, and its decider is recorded in history.state', explicit);

        const explicit_trail = await page.evaluate(() => window.__via.trail());
        check(JSON.stringify(explicit_trail.urls) === JSON.stringify(['/_sys/sites', '/_sys/sites/1#tab=users', '/_sys/users/1?_via=site.1']),
            'the trail ends at the hook\'s parent, its fragment kept on the crumb', explicit_trail.urls);

        // --- 13. meta and the layout hook ---
        check(explicit_trail.meta.grafted === true && explicit_trail.meta.root_url === '/_sys/sites'
            && explicit_trail.meta.decider === '_Sys_User_View_Action@/_sys/users/1',
            'on_complete receives meta {grafted, root_url, decider}', explicit_trail.meta);
        check(explicit_trail.chain_meta && explicit_trail.chain_meta.grafted === true,
            'on_chain receives the same meta', explicit_trail.chain_meta);
        check(explicit_trail.layout_saw.length >= 1 && explicit_trail.layout_saw[0].root_url === '/_sys/sites',
            'every layout in the chain gets on_breadcrumb_complete(chain, meta)', explicit_trail.layout_saw);

        // --- 3. carry, same decider ---
        const same = await page.evaluate(() => window.__via.go('/_sys/system-info'));
        check(same.arrival === 'carried' && same.kept && same.loc === '/_sys/system-info?_via=site.1',
            'a carried _via is kept when the page beneath the decider walks to the same decider', same);
        const same_trail = await page.evaluate(() => window.__via.trail());
        check(same_trail.urls[0] === '/_sys/sites' && same_trail.urls[same_trail.urls.length - 1] === '/_sys/system-info?_via=site.1',
            'the subpage\'s trail is grafted through the decider', same_trail.urls);

        // --- 4. carry, different decider ---
        const different = await page.evaluate(() => window.__via.go('/_sys/workers'));
        check(different.arrival === 'carried' && !different.kept && different.loc === '/_sys/workers' && !different.length_changed,
            'a carried _via accepted by a DIFFERENT page is stripped with replaceState (no new entry)', different);
        const different_trail = await page.evaluate(() => window.__via.trail());
        check(JSON.stringify(different_trail.urls) === JSON.stringify(['/_sys', '/_sys/workers']) && different_trail.meta.grafted === false,
            'the stripped page shows its plain trail', different_trail);

        // --- 12. Back/Forward: the decider recorded on the entry ---
        const back = await page.evaluate(async () => {
            const settled = await window.__via.back();
            return { loc: window.__via.here(), kept: settled.kept, arrival: Spa._via_navigation.arrival, decider: history.state.via_decider };
        });
        check(back.arrival === 'restored' && back.kept && back.loc === '/_sys/system-info?_via=site.1',
            'Back to a kept entry keeps it: the walk reaches the decider the entry recorded', back);

        const back_forged = await page.evaluate(async () => {
            history.replaceState({ ...history.state, via_decider: 'Other_Action@/elsewhere' }, '');
            await window.__via.go('/_sys/tasks', { via: false });
            const settled = await window.__via.back();
            return { loc: window.__via.here(), kept: settled.kept };
        });
        check(!back_forged.kept && back_forged.loc === '/_sys/system-info',
            'Back to an entry whose recorded decider differs strips it', back_forged);

        // --- 5. carry, no decider ---
        await page.evaluate(() => window.__via.go('/_sys/users/1?_via=site.1'));
        const none = await page.evaluate(() => window.__via.go('/_sys/tasks'));
        check(none.arrival === 'carried' && !none.kept && none.loc === '/_sys/tasks',
            'a carried _via into an unrelated page is stripped', none);

        // --- 2. claim declined ---
        const declined = await page.evaluate(() => window.__via.go('/_sys/users/1?_via=site.999'));
        check(!declined.kept && declined.loc === '/_sys/users/1' && !declined.length_changed && declined.decider === null,
            'a claim the hook declines is stripped with replaceState', declined);
        const declined_trail = await page.evaluate(() => window.__via.trail());
        check(JSON.stringify(declined_trail.urls) === JSON.stringify(['/_sys', '/_sys/users', '/_sys/users/1']),
            'a declined claim shows the plain trail', declined_trail.urls);

        // --- 6. explicit wins ---
        await page.evaluate(() => window.__via.go('/_sys/users/1?_via=site.1'));
        const wins = await page.evaluate(() => window.__via.go('/_sys/system-info?_via=site.1,users_home'));
        check(wins.arrival === 'explicit' && wins.kept && wins.loc === '/_sys/system-info?_via=site.1,users_home',
            'a target naming its own _via keeps it (explicit wins over carrying)', wins);

        // --- 7. opt-out ---
        const opt_out = await page.evaluate(async () => {
            await window.__via.go('/_sys/users/1?_via=site.1');
            await Spa.dispatch('/_sys/system-info', { via: false });
            return { loc: window.__via.here(), arrival: Spa._via_navigation.arrival };
        });
        check(opt_out.loc === '/_sys/system-info' && opt_out.arrival === null,
            '{via: false} does not carry', opt_out);

        // --- 8. nesting ---
        const nested = await page.evaluate(async () => {
            const user_view = await Spa.load_detached_action('/_sys/users/1');
            const walk = await Rsx_Breadcrumb_Resolver._walk_chain_structure(
                user_view, '/_sys/users/1?_via=site.1,users_home,extra', null,
                Spa_Via.parse_value('site.1,users_home,extra').tokens);
            return { urls: walk.chain.map((c) => c.url), decider: walk.decider, grafted: walk.grafted };
        });
        check(JSON.stringify(nested.urls) === JSON.stringify([
            '/_sys?_via=extra',
            '/_sys/users?_via=dash_home,extra',
            '/_sys/sites/1?_via=users_home,extra#tab=users',
            '/_sys/users/1?_via=site.1,users_home,extra',
        ]) && nested.decider === '_Sys_User_View_Action@/_sys/users/1',
        'nesting: each consumer pops its head, a hook-supplied _via goes in front of the remainder, an unconsumed token rides to the root', nested);

        // --- 9. malformed and over-long ---
        const malformed = await page.evaluate(() => window.__via.go('/_sys/users/1?_via=Site.1'));
        check(!malformed.kept && malformed.loc === '/_sys/users/1',
            'a malformed _via is ignored and stripped', malformed);

        const long = await page.evaluate(() => window.__via.go('/_sys/users/1?_via=site.1,a,b,c,d'));
        check(long.kept && long.loc === '/_sys/users/1?_via=site.1,a,b,c',
            'more than 4 tokens are truncated at the end', long);

        const api = await page.evaluate(() => {
            const pushed = Spa.via_push('contact', 42);
            let threw = null;
            try { Spa.via_push('Bad Type', 1); } catch (e) { threw = e.message; }
            return { via: Spa.via(), pushed: pushed, threw: threw };
        });
        check(api.via.length === 4 && api.via[0].type === 'site' && api.via[0].id === '1'
            && api.pushed === 'contact.42,site.1,a,b' && api.threw !== null,
            'Spa.via() parses the list; Spa.via_push() puts the origin in front, caps at 4, and throws on a bad token', api);

        // --- 10. loop guard and depth cap ---
        const guard = await page.evaluate(async () => {
            await Spa.dispatch('/_sys/logs', { via: false });
            const loop = await Rsx_Breadcrumb_Resolver._walk_chain_structure(Spa.action(), '/_sys/logs', null, []);
            await Spa.dispatch('/_sys/performance', { via: false });
            const deep = await Rsx_Breadcrumb_Resolver._walk_chain_structure(Spa.action(), '/_sys/performance', null, []);
            return { loop: loop.chain.map((c) => c.url), deep: deep.chain.length };
        });
        check(JSON.stringify(guard.loop) === JSON.stringify(['/_sys/companions', '/_sys/logs']),
            'a parent already in the trail ends the walk', guard.loop);
        check(guard.deep === 20, 'the walk stops at the depth cap (20)', guard.deep);

        // --- 11. new-tab href rewrite ---
        const rewrite = await page.evaluate(async () => {
            await window.__via.go('/_sys/users/1?_via=site.1');
            const make = (href) => {
                const a = document.createElement('a');
                a.setAttribute('href', href);
                a.textContent = href;
                document.body.appendChild(a);
                return a;
            };
            const links = {
                app: make('/_sys/tasks'),
                own: make('/_sys/tasks?_via=other.2'),
                external: make('https://example.com/_sys/tasks'),
                not_spa: make('/no-such-spa-route-probe'),
            };
            for (const name of Object.keys(links)) {
                links[name].dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 1 }));
            }
            const after = {};
            for (const name of Object.keys(links)) {
                after[name] = links[name].getAttribute('href');
            }

            // A left click on the rewritten link navigates as CARRIED, not as an explicit claim.
            const clicked = new Promise((resolve) => { Rsx.on('spa_dispatch_ready', function handler() { resolve(); }); });
            links.app.click();
            await clicked;
            const arrival = Spa._via_navigation.arrival;
            for (const name of Object.keys(links)) {
                links[name].remove();
            }
            return { after: after, arrival: arrival };
        });
        check(rewrite.after.app === '/_sys/tasks?_via=site.1', 'mousedown writes the carried _via into an in-app link', rewrite.after);
        check(rewrite.after.own === '/_sys/tasks?_via=other.2' && rewrite.after.external === 'https://example.com/_sys/tasks'
            && rewrite.after.not_spa === '/no-such-spa-route-probe',
            'a link with its own _via, an external link and a non-SPA link are untouched', rewrite.after);
        check(rewrite.arrival === 'carried', 'clicking a rewritten link is judged as a carried hop', rewrite.arrival);
    } finally {
        await browser.close();
    }
}

run().catch((err) => {
    fail(err.stack || String(err));
});
