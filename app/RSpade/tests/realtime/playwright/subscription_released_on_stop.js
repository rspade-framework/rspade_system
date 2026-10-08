#!/usr/bin/env node
/**
 * A component's realtime subscriptions are open while it is in the document, and only then -
 * WHATEVER its on_stop().
 *
 * this.subscribe() promises that every subscription a component starts is stopped when the
 * component is destroyed. That cleanup used to be hung on the base on_stop(), which is the
 * hook a component author overrides - and, like every lifecycle hook, overrides without
 * calling super. So a component with an on_stop() of its own silently kept its subscription:
 * the stopped component's callback went on running for every frame and every reconnect
 * resync. Cleanup now rides the runtime's 'stop' event, which teardown fires for every
 * component after its own on_stop().
 *
 * Probes, in a real browser because subscriptions only exist there:
 *
 *   1. A component WITH its own on_stop() (no super) subscribes in on_create(); stopping it
 *      runs its on_stop() AND releases the subscription.
 *   2. A component with NO on_stop() releases the same way.
 *   3. Two components on the same topic and filter share one watch; it survives while one
 *      of them lives and goes when the last stops.
 *   4. The base on_stop() is not replaced - the runtime skips the on_stop() call for a
 *      component that does not define one, and wrapping the base defeated that for all.
 *   5. A child mounted inside its parent's render is in the document at on_create(), so it
 *      subscribes there (and its first load can wait for the subscription).
 *   6. A component built off-document opens nothing until it is inserted; then it opens,
 *      and the resync is its refetch.
 *   7. Native DOM removal - which never reaches stop() - closes the subscription; putting
 *      the element back opens it again.
 *   8. A component that subscribed in the document and left it before 'ready' has fired
 *      neither 'attach' nor 'detach'; the check at 'ready' closes it.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys, the topic
 * is the framework's Task_Changed_Topic, and the probe components are registered at runtime
 * in the browser, so the probe runs in any install with realtime enabled.
 *
 * Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js, so it runs
 * with a bare `node subscription_released_on_stop.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL:/SKIP: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: realtime subscription released on stop - ' + msg);
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

async function probe(page) {
    return await page.evaluate(async () => {
        const observed = { realtime: !!window.rsxapp?.realtime_url };
        if (!observed.realtime) {
            return observed;
        }

        // A real task run: the topic refuses a subscription to a run that does not exist. The
        // control panel's dashboard holds no watch on it, so the one counted below is the
        // probes'.
        const found = await Rsx_Task.find({});
        if (!found.tasks.length) {
            observed.no_task = true;

            return observed;
        }
        const filter = { id: found.tasks[0].id };
        let own_on_stop_ran = 0;
        const fired = {};

        class Rsx_Subscribe_With_Stop_Probe_Temp extends Component {
            on_create() { this.subscribe('Task_Changed_Topic', filter, () => {}); }
            on_stop() { own_on_stop_ran++; }
        }
        class Rsx_Subscribe_No_Stop_Probe_Temp extends Component {
            on_create() {
                this.subscribe('Task_Changed_Topic', filter, (data, meta) => {
                    const name = this.args.probe;
                    fired[name] = fired[name] || [];
                    fired[name].push(meta.resync === true ? 'resync' : 'live');
                });
            }
        }
        // A child mounted from inside its parent's render, as every child in a template is.
        class Rsx_Subscribe_Parent_Probe_Temp extends Component {
            on_render() {
                this.$.append('<div class="rsx_subscribe_child_slot_temp"></div>');
                this.$.find('.rsx_subscribe_child_slot_temp').component('Rsx_Subscribe_Child_Probe_Temp');
            }
        }
        class Rsx_Subscribe_Child_Probe_Temp extends Component {
            on_create() {
                observed.child_in_document_at_create = this.$[0].isConnected;
                this.subscribe('Task_Changed_Topic', filter, () => {});
                observed.child_open_at_create = Rsx_Realtime._component_is_open(this);
            }
        }
        for (const probe_class of [Rsx_Subscribe_With_Stop_Probe_Temp, Rsx_Subscribe_No_Stop_Probe_Temp, Rsx_Subscribe_Parent_Probe_Temp, Rsx_Subscribe_Child_Probe_Temp]) {
            jqhtml.register_component(probe_class.name, probe_class);
        }

        const open = (component) => Rsx_Realtime._component_is_open(component);
        const settle = () => sleep(600);

        const mount = async (name, args = {}, $into = null) => {
            const $el = $('<div>');
            if ($into) {
                $el.appendTo($into);
            }
            $el.component(name, args);
            const component = $el.component();
            await new Promise((resolve) => component.on('ready', resolve));
            await settle();

            return component;
        };

        observed.base_on_stop_untouched = Component.prototype.on_stop === _Base_Jqhtml_Component.prototype.on_stop;
        observed.watches_before = Rsx_Realtime._watches.size;

        // --- stop, with and without an on_stop() of the component's own ---
        const with_stop = await mount('Rsx_Subscribe_With_Stop_Probe_Temp', {}, $('body'));
        const no_stop = await mount('Rsx_Subscribe_No_Stop_Probe_Temp', { probe: 'no_stop' }, $('body'));

        observed.watches_mounted = Rsx_Realtime._watches.size;
        observed.both_open = open(with_stop) && open(no_stop);

        with_stop.stop();
        await sleep(300);

        observed.own_on_stop_ran = own_on_stop_ran;
        observed.with_stop_released = with_stop._realtime_stopped === true && !open(with_stop);
        observed.watches_one_left = Rsx_Realtime._watches.size;

        no_stop.stop();
        await sleep(300);

        observed.no_stop_released = no_stop._realtime_stopped === true && !open(no_stop);
        observed.watches_after = Rsx_Realtime._watches.size;

        with_stop.$.remove();
        no_stop.$.remove();

        // --- a child mounted inside its parent's render ---
        const parent = await mount('Rsx_Subscribe_Parent_Probe_Temp', {}, $('body'));
        parent.stop();
        parent.$.remove();
        await sleep(300);
        observed.watches_after_parent = Rsx_Realtime._watches.size;

        // --- built off-document, inserted later ---
        const late = await mount('Rsx_Subscribe_No_Stop_Probe_Temp', { probe: 'late' });
        observed.late_closed_while_off_document = !open(late) && Rsx_Realtime._watches.size === observed.watches_before;

        late.$.appendTo('body');
        await settle();
        observed.late_open_once_inserted = open(late);
        observed.late_resync_on_open = (fired.late || []).includes('resync');

        // --- removed by the DOM itself (never reaches stop()), then put back ---
        const element = late.$[0];
        fired.late = [];
        element.remove();
        await settle();
        observed.native_removal_closed = !open(late) && Rsx_Realtime._watches.size === observed.watches_before;
        observed.native_removal_not_stopped = late._stopped !== true;

        document.body.appendChild(element);
        await settle();
        observed.reinserted_open = open(late);
        observed.reinserted_resync = (fired.late || []).includes('resync');

        late.stop();
        late.$.remove();
        await sleep(300);
        observed.watches_end = Rsx_Realtime._watches.size;

        // --- in the document at subscribe, gone before ready ---
        const $early = $('<div>').appendTo('body');
        $early.component('Rsx_Subscribe_No_Stop_Probe_Temp', { probe: 'early' });
        const early = $early.component();
        observed.early_open_at_create = open(early);
        $early[0].remove();
        await new Promise((resolve) => early.on('ready', resolve));
        await settle();
        observed.early_closed_at_ready = !open(early) && Rsx_Realtime._watches.size === observed.watches_before;
        early.stop();

        return observed;
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
        ...dev_auth_headers(ROUTE, USER_ID),
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
        await page.goto(BASE_URL + ROUTE, { waitUntil: 'commit' });
        await await_spa_ready(page);

        const on_page = await page.evaluate(() => typeof Spa !== 'undefined' && !!(Spa.action && Spa.action()));
        if (!on_page) {
            fail('SPA action not present - auth may have failed');
            await browser.close();

            return;
        }

        const observed = await probe(page);

        if (!observed.realtime) {
            console.log('SKIP: realtime subscription released on stop - realtime is not enabled on this box');
            await browser.close();

            return;
        }

        if (observed.no_task) {
            console.log('SKIP: realtime subscription released on stop - this install has no task run to subscribe to');
            await browser.close();

            return;
        }

        const check = (ok, pass, failure) => {
            if (ok) {
                console.log('PASS: ' + pass);
            } else {
                fail(failure + ' ' + JSON.stringify(observed));
            }
        };

        check(observed.base_on_stop_untouched,
            'the base on_stop() is left alone',
            'the base on_stop() has been replaced - every component now looks as if it overrides it');

        check(observed.both_open && observed.watches_mounted === observed.watches_before + 1,
            'two components on one topic and filter share one watch',
            'two subscribed components did not share one open watch');

        check(observed.own_on_stop_ran === 1 && observed.with_stop_released,
            'a component with its own on_stop() releases its subscription when it stops',
            'a component that defines its own on_stop() kept its subscription after it stopped, or its on_stop() did not run once');

        check(observed.watches_one_left === observed.watches_before + 1 && observed.no_stop_released && observed.watches_after === observed.watches_before,
            'the watch survives while one subscriber lives and is removed when the last stops',
            'the shared watch did not follow its subscribers');

        check(observed.child_in_document_at_create === true && observed.child_open_at_create === true && observed.watches_after_parent === observed.watches_before,
            'a child mounted inside its parent\'s render subscribes at once, and is released with the parent',
            'a child mounted during its parent\'s render was not subscribed in on_create(), or outlived the parent');

        check(observed.late_closed_while_off_document && observed.late_open_once_inserted && observed.late_resync_on_open,
            'a component built off-document opens nothing until it is inserted, then opens and resyncs',
            'a component built off-document was subscribed before insertion, or was not opened and resynced after it');

        check(observed.native_removal_closed && observed.native_removal_not_stopped,
            'a component removed by the DOM itself - never stopped - gives up its subscription',
            'native DOM removal left the subscription open');

        check(observed.reinserted_open && observed.reinserted_resync && observed.watches_end === observed.watches_before,
            'put back in the document it opens again and resyncs; stopping it releases for good',
            're-inserting a removed component did not reopen and resync its subscription');

        check(observed.early_open_at_create && observed.early_closed_at_ready,
            'a component that leaves the document before it is ready is closed at ready',
            'a component removed before ready kept the subscription it opened in on_create()');
    } catch (e) {
        fail('threw: ' + e.message);
    }

    await browser.close();
}

run();
