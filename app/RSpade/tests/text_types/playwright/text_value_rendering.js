#!/usr/bin/env node
/**
 * The browser half of declared TEXT column types: how a value PRINTS and how it is EDITED.
 *
 * The PHP contract is pinned by Text_Value_Contract_Test. What that cannot reach is the
 * part that only exists in a browser - jqhtml routing an object at an interpolation site
 * through the printer chain, the escaping rule a printer's string return obeys, dynamic
 * component name validation, and an input refusing a value it does not edit. Each is the
 * FEATURE and not decoration: if any silently no-ops, a template renders the wrong thing
 * with nothing to show for it.
 *
 * Whether a descriptor actually MOUNTS is deliberately NOT here. That is jqhtml's own
 * contract, exercised through its compiled template pipeline, and reproducing that pipeline
 * by hand would test this file's idea of it rather than the real thing. The application
 * suite covers the real mount on a real page, where using an application screen is allowed.
 *
 * Everything here is registered at runtime in the browser against fixture types of this
 * test's own making, and the page is the framework's own control panel at /_sys. No
 * application screen, model, column or bundle is involved, so the script runs in any
 * install. A test-tree fixture page could not serve this: the test trees enter the manifest
 * only while rsx:test runs, and this drives the ordinary web server.
 *
 * Self-contained: mints its own dev-auth headers through the node twin of Dev_Auth_Token,
 * so it runs with a bare `node text_value_rendering.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const ROUTE_EXPECTED = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: text value rendering - ' + msg);
    process.exitCode = 1;
}

async function await_spa_ready(page) {
    await page.evaluate(() => new Promise((resolve) => {
        if (typeof Rsx !== 'undefined' && Rsx.on) {
            Rsx.on('_debug_ready', () => resolve());
            setTimeout(resolve, 15000);
        } else {
            setTimeout(resolve, 3000);
        }
    }));

    await page.evaluate(() => { if (typeof Rsx !== 'undefined') Rsx.validate_session = async () => true; });
}

/**
 * The framework's printer is registered at boot and must recognise a real text value and
 * decline anything else - declining is what lets an application's own printer sit beside
 * it in the same chain.
 */
async function probe_printer_registered(page) {
    return await page.evaluate(() => {
        class Rsx_Probe_Print_Text extends Rsx_Text_Abstract {
            static PRINTER = 'Rsx_Probe_Text_Display';
        }
        window.Rsx_Probe_Print_Text = Rsx_Probe_Print_Text;

        const outcomes = { declined_foreign: null, handled_text_value: null, observed: null };

        // A foreign object must be declined by every printer, which surfaces as the
        // unhandled-object throw. Declining is what lets printers coexist in one chain.
        try {
            jqhtml.print_object({ not: 'ours' }, 'escape');
            outcomes.declined_foreign = false;
        } catch (e) {
            outcomes.declined_foreign = true;
        }

        // A real text value must come back as a mount of the type's own PRINTER.
        const value = Rsx_Probe_Print_Text.from_storage('hello');
        const result = jqhtml.print_object(value, 'escape');
        outcomes.observed = JSON.stringify(result);
        outcomes.handled_text_value = !!result && typeof result === 'object'
            && JSON.stringify(result).indexOf('Rsx_Probe_Text_Display') !== -1;

        return outcomes;
    });
}

/**
 * A string return is escaped exactly as a string literal at that site - so a printer
 * cannot smuggle markup past the construct's escaping rule.
 */
async function probe_string_return_is_escaped(page) {
    return await page.evaluate(() => {
        jqhtml.add_object_printer(function (value) {
            return value && value.__rsx_probe_string ? '<b>not markup</b>' : undefined;
        });

        const escaped = jqhtml.print_object({ __rsx_probe_string: true }, 'escape');
        const raw = jqhtml.print_object({ __rsx_probe_string: true }, 'raw');

        return {
            escaped_has_entities: typeof escaped === 'string' && escaped.indexOf('&lt;b&gt;') !== -1,
            raw_is_untouched: raw === '<b>not markup</b>',
        };
    });
}

/** An object nothing handles must THROW rather than rendering [object Object]. */
async function probe_unhandled_throws(page) {
    return await page.evaluate(() => {
        try {
            jqhtml.print_object(new (class Rsx_Probe_Unknown_Value {})(), 'escape');
            return { threw: false, message: null };
        } catch (e) {
            return { threw: true, message: String(e.message || e) };
        }
    });
}

/**
 * A dynamic component tag resolves its name at render time. Both halves matter: a valid
 * name mounts that component, and a valid name that names NOTHING renders the ordinary
 * placeholder rather than failing - jqhtml's documented behaviour, which dynamic tags must
 * not be an exception to.
 */
async function probe_dynamic_tag(page) {
    return await page.evaluate(async () => {
        class Rsx_Probe_Dynamic_A extends Component {
            on_render() { this.$.text('component-a'); }
        }
        class Rsx_Probe_Dynamic_B extends Component {
            on_render() { this.$.text('component-b'); }
        }
        jqhtml.register_component('Rsx_Probe_Dynamic_A', Rsx_Probe_Dynamic_A);
        jqhtml.register_component('Rsx_Probe_Dynamic_B', Rsx_Probe_Dynamic_B);

        const mount = async (name) => {
            const $host = $('<div class="rsx_probe_dyn_host"></div>').appendTo('body');
            $host.component(jqhtml.dynamic_component_name(name));
            await new Promise((r) => setTimeout(r, 200));
            const text = $host.text();
            const classes = $host.children().length ? $host[0].className : $host[0].className;
            $host.remove();
            return { text: text, classes: classes };
        };

        const a = await mount('Rsx_Probe_Dynamic_A');
        const b = await mount('Rsx_Probe_Dynamic_B');
        const undefined_name = await mount('Rsx_Probe_Dynamic_Nonexistent');

        const invalid = [];
        for (const bad of ['', 'lowercase_start', 'Has Space', 'Has-Dash']) {
            try {
                jqhtml.dynamic_component_name(bad);
                invalid.push({ input: bad, threw: false });
            } catch (e) {
                invalid.push({ input: bad, threw: true });
            }
        }

        let valid_accepted = true;
        for (const good of ['Foo', '_Foo', 'Foo_Bar_9']) {
            try {
                jqhtml.dynamic_component_name(good);
            } catch (e) {
                valid_accepted = false;
            }
        }

        return {
            mounts_a: a.text === 'component-a',
            mounts_b: b.text === 'component-b',
            undefined_name_is_placeholder: undefined_name.text === ''
                && undefined_name.classes.indexOf('Rsx_Probe_Dynamic_Nonexistent') !== -1,
            invalid_all_threw: invalid.every((r) => r.threw),
            valid_all_accepted: valid_accepted,
        };
    });
}

/**
 * A typed input refuses a value it does not edit, in BOTH directions. This is the control
 * that stops a column silently being wired to the wrong widget.
 */
async function probe_input_accepts(page) {
    return await page.evaluate(async () => {
        class Rsx_Probe_Other_Text extends Rsx_Text_Abstract {
            static PRINTER = 'Rsx_Probe_Text_Display';
        }
        window.Rsx_Probe_Other_Text = Rsx_Probe_Other_Text;

        class Rsx_Probe_Typed_Input extends Form_Input_Abstract {
            static ACCEPTS = 'Rsx_Probe_Print_Text';
            _get_value() { return this._v ?? null; }
            _set_value(v) { this._v = v; }
            on_render() { this._mark_ready(); }
        }
        jqhtml.register_component('Rsx_Probe_Typed_Input', Rsx_Probe_Typed_Input);

        // Manifest resolution is how ACCEPTS is turned back into a class.
        Manifest._define([[Rsx_Probe_Print_Text, 'Rsx_Probe_Print_Text', 'Rsx_Text_Abstract']]);

        const $host = $('<div class="rsx_probe_input_host"></div>').appendTo('body');
        $host.component('Rsx_Probe_Typed_Input', { name: 'probe' });
        await new Promise((r) => setTimeout(r, 200));
        const input = $host.component();

        const outcome = { accepts_right_type: false, refuses_wrong_type: false, refuses_string: false };

        try {
            input.val(Rsx_Probe_Print_Text.from_storage('ok'));
            outcome.accepts_right_type = true;
        } catch (e) {
            outcome.accepts_right_type = false;
        }

        try {
            input.val(Rsx_Probe_Other_Text.from_storage('wrong'));
        } catch (e) {
            outcome.refuses_wrong_type = true;
        }

        try {
            input.val('a bare string');
        } catch (e) {
            outcome.refuses_string = true;
        }

        $host.remove();
        return outcome;
    });
}

/** toString() throws rather than yielding [object Object] - the silent-wrong-render guard. */
async function probe_tostring_throws(page) {
    return await page.evaluate(() => {
        const value = Rsx_Probe_Print_Text.from_storage('x');
        try {
            const coerced = `${value}`;
            return { threw: false, coerced: coerced };
        } catch (e) {
            return { threw: true, coerced: null };
        }
    });
}

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });

    try {
        const context = await browser.newContext({ ignoreHTTPSErrors: true });
        const page = await context.newPage();

        // The dev-auth token is bound to the URL it authorises, and only the FIRST document
        // request needs it - the session cookie carries from there. A context-wide header
        // would be a token for the wrong URL on every subsequent request.
        const auth_headers = { ...dev_auth_headers(ROUTE, USER_ID), 'X-Playwright-Test': '1' };
        let initial_doc_sent = false;

        await page.route('**/*', async (route, request) => {
            const url = request.url();

            if (!url.startsWith(BASE_URL)) {
                await route.continue();
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

        const response = await page.goto(BASE_URL + ROUTE, { waitUntil: 'commit' });

        if (!response || response.status() !== 200) {
            fail(`${ROUTE} returned ${response ? response.status() : 'no response'}`);
            await browser.close();
            return;
        }

        await await_spa_ready(page);

        // Assert we are actually on the page this test claims. A dev-auth failure lands on
        // /login, where the framework JS these probes touch still exists - so every probe
        // would pass while testing the wrong page.
        const landed = await page.evaluate(() => location.pathname);
        if (landed !== ROUTE_EXPECTED) {
            fail(`expected to land on ${ROUTE_EXPECTED}, got ${landed} - dev auth did not take`);
            await browser.close();
            return;
        }

        const registered = await probe_printer_registered(page);
        if (!registered.declined_foreign) {
            fail('a printer claimed a foreign object; declining with undefined is what lets printers coexist');
        }
        if (!registered.handled_text_value) {
            fail(`the framework printer did not route a text value to its PRINTER (observed: ${registered.observed})`);
        }

        const escaping = await probe_string_return_is_escaped(page);
        if (!escaping.escaped_has_entities) {
            fail('a printer string return was NOT escaped under <%= %>');
        }
        if (!escaping.raw_is_untouched) {
            fail('a printer string return was altered under <%!= %>');
        }

        const unhandled = await probe_unhandled_throws(page);
        if (!unhandled.threw) {
            fail('an object no printer handled did not throw');
        }

        const dynamic = await probe_dynamic_tag(page);
        if (!dynamic.mounts_a || !dynamic.mounts_b) {
            fail('a dynamic component name did not mount the named component');
        }
        if (!dynamic.undefined_name_is_placeholder) {
            fail('an undefined-but-valid dynamic name did not render the ordinary placeholder');
        }
        if (!dynamic.invalid_all_threw) {
            fail('an invalid dynamic component name was accepted');
        }
        if (!dynamic.valid_all_accepted) {
            fail('a valid component name was rejected by dynamic name validation');
        }

        const accepts = await probe_input_accepts(page);
        if (!accepts.accepts_right_type) {
            fail('a typed input refused the type it declares it edits');
        }
        if (!accepts.refuses_wrong_type) {
            fail('a typed input accepted a DIFFERENT text type');
        }
        if (!accepts.refuses_string) {
            fail('a typed input accepted a bare string');
        }

        const coercion = await probe_tostring_throws(page);
        if (!coercion.threw) {
            fail(`toString() did not throw; it produced ${JSON.stringify(coercion.coerced)}`);
        }

        if (!process.exitCode) {
            console.log('PASS: text value rendering - printer chain, escaping rules, dynamic component names, typed inputs');
        }
    } catch (error) {
        fail(error.message);
    } finally {
        await browser.close();
    }
})();
