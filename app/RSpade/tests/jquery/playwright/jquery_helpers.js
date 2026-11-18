#!/usr/bin/env node
/**
 * The framework's jQuery helpers (rsx:man jquery, JQUERY HELPERS, CLICK OVERRIDE, BLOCKED).
 *
 * Rsx_Jq_Helpers._on_framework_core_define() installs them on every page. Each is small and
 * used everywhere, so each documented contract is pinned briefly here:
 *
 *   a. .exists(), .is_visible(), .is_in_dom(), .is_in_viewport(), .tagname(), .is_external(),
 *      the empty-set answers of the predicates, and .scroll_up_to()
 *   b. the :focus pseudo-selector
 *   c. .checkValidity() / .reportValidity() / .requestSubmit(), including the empty set
 *   d. .closest_sibling(selector)
 *   e. .click(handler) calls preventDefault() for the handler (and warns when the handler
 *      calls it again); .click() with no handler triggers; .click_allow_default(handler)
 *      leaves the default alone
 *   f. .click_async(handler): a non-function throws; the busy state while the promise is
 *      pending; a re-entrant click is ignored; the busy state clears on settle; a detached
 *      element is left alone on settle; chainable; default prevented
 *   g. $.ajax() to a local URL throws, /_upload with its own message and an endpoint URL
 *      naming the call to make instead; an external URL is handed to jQuery; the
 *      shorthands ($.get, $.post, $.getJSON, $.getScript, .load) throw the same way
 *
 * Not covered here: .shallowFind() is the jqhtml library's (covered upstream); .rsx_numeric()
 * is rsx_numeric.js; Button_Utils.set_loader() is button_utils_loader.js.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and every
 * element is created at run time in the browser, so it runs in any install.
 *
 * Self-contained: runs with a bare `node jquery_helpers.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page, poll_until } = require('../../_lib/playwright/sys_page.js');

const EXTERNAL_URL = 'https://jquery-helpers-probe.invalid/answer.json';

function fail(msg) {
    console.log('FAIL: jquery helpers - ' + msg);
    process.exitCode = 1;
}

function check(label, actual, expected) {
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a === e) {
        console.log('PASS: ' + label);
        return true;
    }
    fail(label + ' - expected ' + e + ', got ' + a);
    return false;
}

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        await page.evaluate(async () => { await Spa.action().ready(); });

        // --- a. existence, visibility, attachment, viewport, tag, external --------------------
        const state = await page.evaluate(async () => {
            const frame = () => new Promise((resolve) => requestAnimationFrame(() => resolve()));
            $('#jq_helpers_probe_temp').remove();
            $('body').prepend(
                '<div id="jq_helpers_probe_temp">'
                + '<div class="jq_shown">shown</div>'
                + '<div class="jq_hidden" style="display:none">hidden</div>'
                + '<div style="display:none"><span class="jq_hidden_child">inside</span></div>'
                + '<a class="jq_relative" href="/_sys/tasks">r</a>'
                + '<a class="jq_same_host" href="' + window.location.origin + '/_sys">s</a>'
                + '<a class="jq_external" href="https://example.com/x">e</a>'
                + '<div class="jq_far" style="margin-top:6000px">far</div>'
                + '</div>'
            );
            const $detached = $('<div class="jq_detached">x</div>');
            const out = {
                exists_match: $('#jq_helpers_probe_temp .jq_shown').exists(),
                exists_none: $('#jq_helpers_probe_temp .jq_no_such').exists(),
                visible_shown: $('.jq_shown').is_visible(),
                visible_hidden: $('.jq_hidden').is_visible(),
                visible_hidden_parent: $('.jq_hidden_child').is_visible(),
                in_dom_attached: $('.jq_shown').is_in_dom(),
                in_dom_detached: $detached.is_in_dom(),
                tag_div: $('.jq_shown').tagname(),
                tag_a: $('.jq_relative').tagname(),
                external_relative: $('.jq_relative').is_external(),
                external_same_host: $('.jq_same_host').is_external(),
                external_other_host: $('.jq_external').is_external(),
            };

            window.scrollTo({ left: 0, top: 0, behavior: 'instant' });
            await frame();
            out.viewport_top_at_top = $('.jq_shown').is_in_viewport();
            out.viewport_far_at_top = $('.jq_far').is_in_viewport();
            window.scrollTo({ left: 0, top: $('.jq_far').offset().top, behavior: 'instant' });
            await frame();
            out.viewport_top_scrolled = $('.jq_shown').is_in_viewport();
            out.viewport_far_scrolled = $('.jq_far').is_in_viewport();
            window.scrollTo({ left: 0, top: 0, behavior: 'instant' });

            out.empty_in_dom = $().is_in_dom();
            out.empty_in_viewport = $().is_in_viewport();
            out.empty_tagname = $().tagname() === undefined;

            // .scroll_up_to(): the far element is the scroll target; the window starts below
            // it (the element above the viewport) and must end with the element's top at the
            // viewport's top. Starting above it, nothing moves (it only scrolls up).
            const far_top = Math.round($('.jq_far').offset().top);
            $('#jq_helpers_probe_temp').append('<div style="height:8000px"></div>');
            window.scrollTo({ left: 0, top: far_top + 3000, behavior: 'instant' });
            await frame();
            out.scroll_start = Math.round(window.scrollY);
            $('.jq_far').scroll_up_to(0);
            await frame();
            out.scroll_after_up = Math.round(window.scrollY);
            out.scroll_far_top = far_top;
            window.scrollTo({ left: 0, top: 0, behavior: 'instant' });
            await frame();
            $('.jq_far').scroll_up_to(0);
            await frame();
            out.scroll_after_below = Math.round(window.scrollY);
            out.scroll_empty_chain = $().scroll_up_to(0).length;
            window.scrollTo({ left: 0, top: 0, behavior: 'instant' });
            return out;
        });
        check('.exists() is true for a match', state.exists_match, true);
        check('.exists() is false for no match', state.exists_none, false);
        check('.is_visible() is true for a displayed element', state.visible_shown, true);
        check('.is_visible() is false for display:none', state.visible_hidden, false);
        check('.is_visible() is false inside a display:none parent', state.visible_hidden_parent, false);
        check('.is_in_dom() is true for an attached element', state.in_dom_attached, true);
        check('.is_in_dom() is false for a detached element', state.in_dom_detached, false);
        check('.tagname() is the lowercase tag (div)', state.tag_div, 'div');
        check('.tagname() is the lowercase tag (a)', state.tag_a, 'a');
        check('.is_external() is false for a relative href', state.external_relative, false);
        check('.is_external() is false for this host', state.external_same_host, false);
        check('.is_external() is true for another host', state.external_other_host, true);
        check('.is_in_viewport() at the top: the top element is in view', state.viewport_top_at_top, true);
        check('.is_in_viewport() at the top: the far element is not', state.viewport_far_at_top, false);
        check('.is_in_viewport() scrolled to the far element: the top element is not in view', state.viewport_top_scrolled, false);
        check('.is_in_viewport() scrolled to the far element: it is', state.viewport_far_scrolled, true);
        check('$().is_in_dom() is false for an empty set', state.empty_in_dom, false);
        check('$().is_in_viewport() is false for an empty set', state.empty_in_viewport, false);
        check('$().tagname() is undefined for an empty set', state.empty_tagname, true);
        check('.scroll_up_to(): the window starts below the element', state.scroll_start, state.scroll_far_top + 3000);
        check('.scroll_up_to(): an element above the viewport is scrolled to', state.scroll_after_up, state.scroll_far_top);
        check('.scroll_up_to(): an element below the viewport is left alone (up only)', state.scroll_after_below, 0);
        check('.scroll_up_to() on an empty set does nothing and chains', state.scroll_empty_chain, 0);

        // --- b. :focus ------------------------------------------------------------------
        const focus = await page.evaluate(() => {
            $('#jq_helpers_probe_temp').append('<input type="text" class="jq_focus_a"><input type="text" class="jq_focus_b">');
            $('.jq_focus_a')[0].focus();
            return {
                a: $('.jq_focus_a').is(':focus'),
                b: $('.jq_focus_b').is(':focus'),
                selected: $('#jq_helpers_probe_temp input:focus').length,
            };
        });
        check(':focus matches the focused input', focus.a, true);
        check(':focus does not match another input', focus.b, false);
        check('input:focus selects exactly the focused one', focus.selected, 1);

        // --- c. form validation wrappers ---------------------------------------------------
        const validity = await page.evaluate(() => {
            $('#jq_helpers_probe_temp').append('<form class="jq_form"><input type="text" name="v" required></form>');
            const $form = $('.jq_form');
            let submitted = 0;
            $form.on('submit', (e) => { e.preventDefault(); submitted++; });
            const out = {
                check_empty_required: $form.checkValidity(),
                report_empty_required: $form.reportValidity(),
            };
            $form.find('input').val('filled');
            out.check_filled = $form.checkValidity();
            out.report_filled = $form.reportValidity();
            out.check_input = $form.find('input').checkValidity();
            const returned = $form.requestSubmit();
            out.request_submit_fired = submitted;
            out.request_submit_returns_set = returned === $form;
            const $none = $('.jq_no_such_form');
            out.check_none = $none.checkValidity();
            out.report_none = $none.reportValidity();
            out.request_none_returns_set = $none.requestSubmit() === $none;
            return out;
        });
        check('.checkValidity() is false with a required field empty', validity.check_empty_required, false);
        check('.reportValidity() is false with a required field empty', validity.report_empty_required, false);
        check('.checkValidity() is true once it is filled', validity.check_filled, true);
        check('.reportValidity() is true once it is filled', validity.report_filled, true);
        check('.checkValidity() works on a field', validity.check_input, true);
        check('.requestSubmit() submits the form once', validity.request_submit_fired, 1);
        check('.requestSubmit() returns the set', validity.request_submit_returns_set, true);
        check('.checkValidity() on an empty set is false', validity.check_none, false);
        check('.reportValidity() on an empty set is false', validity.report_none, false);
        check('.requestSubmit() on an empty set returns the set', validity.request_none_returns_set, true);

        // --- d. closest_sibling ---------------------------------------------------------------
        const sibling = await page.evaluate(() => {
            $('#jq_helpers_probe_temp').append(
                '<div class="jq_section">'
                + '<div class="jq_row"><div class="jq_country"></div></div>'
                + '<div class="jq_row"><div class="jq_state" data-n="near"></div></div>'
                + '</div>'
                + '<div class="jq_state" data-n="far"></div>'
            );
            return {
                found: $('.jq_country').closest_sibling('.jq_state').map(function () { return $(this).attr('data-n'); }).get(),
                none: $('.jq_country').closest_sibling('.jq_no_such_thing').length,
                none_is_jquery: $('.jq_country').closest_sibling('.jq_no_such_thing') instanceof jQuery,
            };
        });
        check('.closest_sibling() finds the nearest ancestor\'s match, not a farther one', sibling.found, ['near']);
        check('.closest_sibling() with no match is an empty set', sibling.none, 0);
        check('.closest_sibling() with no match is still a jQuery', sibling.none_is_jquery, true);

        // --- e. the click override ---------------------------------------------------------------
        await page.evaluate(() => {
            history.replaceState(history.state, '', window.location.pathname);
            window.__jq_click = { ran: 0, prevented: null, warnings: [] };
            const real_warn = console.warn;
            window.__jq_real_warn = real_warn;
            console.warn = function (...args) {
                window.__jq_click.warnings.push(String(args[0]));
                return real_warn.apply(this, args);
            };
            $('#jq_helpers_probe_temp').append(
                '<a class="jq_click" href="#jq_click_default=1">c</a>'
                + '<a class="jq_click_explicit" href="#jq_click_explicit=1">x</a>'
                + '<a class="jq_allow" href="#jq_allow_default=1">a</a>'
            );
            $('.jq_click').click(function (e) {
                window.__jq_click.ran++;
                window.__jq_click.prevented = e.isDefaultPrevented();
            });
            $('.jq_click_explicit').click(function (e) {
                e.preventDefault();
            });
            $('.jq_allow').click_allow_default(function (e) {
                window.__jq_click.allow_ran = true;
                window.__jq_click.allow_prevented = e.isDefaultPrevented();
            });
        });
        await page.click('#jq_helpers_probe_temp .jq_click');
        let click = await page.evaluate(() => ({ ...window.__jq_click, hash: window.location.hash }));
        check('.click(handler) runs the handler', click.ran, 1);
        check('.click(handler) has prevented the default before the handler runs', click.prevented, true);
        check('.click(handler): the link did not navigate', click.hash, '');
        check('.click(handler) alone warns about nothing', click.warnings.filter((w) => w.includes('preventDefault')).length, 0);

        await page.click('#jq_helpers_probe_temp .jq_click_explicit');
        click = await page.evaluate(() => window.__jq_click);
        check('a handler calling preventDefault() itself is warned that it is automatic',
            click.warnings.filter((w) => w.includes('preventDefault() is called automatically')).length, 1);

        await page.evaluate(() => $('.jq_click').click());
        click = await page.evaluate(() => window.__jq_click);
        check('.click() with no handler triggers the bound handler', click.ran, 2);

        await page.click('#jq_helpers_probe_temp .jq_allow');
        click = await page.evaluate(() => ({ ...window.__jq_click, hash: window.location.hash }));
        check('.click_allow_default(handler) runs the handler', click.allow_ran, true);
        check('.click_allow_default(handler) does not prevent the default', click.allow_prevented, false);
        check('.click_allow_default(handler): the link navigated', click.hash, '#jq_allow_default=1');
        await page.evaluate(() => {
            console.warn = window.__jq_real_warn;
            history.replaceState(history.state, '', window.location.pathname);
        });

        // --- f. click_async ------------------------------------------------------------------------
        const async_setup = await page.evaluate(() => {
            const out = {};
            try {
                $('<button>').click_async('not a function');
                out.non_function = 'did not throw';
            } catch (e) {
                out.non_function = e.message;
            }
            $('#jq_helpers_probe_temp').append('<button type="submit" class="btn jq_async">Go</button>');
            window.__jq_async = { runs: 0, resolvers: [], prevented: [] };
            const $btn = $('.jq_async');
            out.chain = $btn.click_async(function (e) {
                window.__jq_async.runs++;
                window.__jq_async.prevented.push(e.isDefaultPrevented());
                return new Promise((resolve) => window.__jq_async.resolvers.push(resolve));
            }) === $btn;
            return out;
        });
        check('.click_async() with a non-function throws', async_setup.non_function, '$.fn.click_async() requires a function handler');
        check('.click_async() returns the set', async_setup.chain, true);

        await page.evaluate(() => { $('.jq_async')[0].click(); });
        let busy = await page.evaluate(() => ({
            runs: window.__jq_async.runs,
            prevented: window.__jq_async.prevented,
            submitting: $('.jq_async').hasClass('btn--submitting'),
            text: $('.jq_async').text(),
        }));
        check('.click_async(): the handler ran once', busy.runs, 1);
        check('.click_async(): the default was prevented', busy.prevented, [true]);
        check('.click_async(): busy while the promise is pending', busy.submitting, true);
        check('.click_async(): the content is swapped for the loader', busy.text, '');

        await page.evaluate(() => { $('.jq_async').trigger('click'); $('.jq_async')[0].click(); });
        busy = await page.evaluate(() => window.__jq_async.runs);
        check('.click_async(): a click while busy is ignored', busy, 1);

        await page.evaluate(() => window.__jq_async.resolvers[0]());
        await poll_until(page, 'the click_async busy state clears after the promise settles',
            () => !$('.jq_async').hasClass('btn--submitting'));
        busy = await page.evaluate(() => $('.jq_async').text());
        check('.click_async(): the content is restored on settle', busy, 'Go');

        // A settle after the element has left the DOM leaves it alone and raises nothing.
        const detached = await page.evaluate(async () => {
            $('.jq_async')[0].click();
            const $btn = $('.jq_async').detach();
            const before = { submitting: $btn.hasClass('btn--submitting'), html: $btn.html() };
            window.__jq_async.resolvers[1]();
            // A macrotask runs only after every queued microtask, so the handler's
            // .finally() has run by then - ordering, not a delay.
            await Promise.resolve();
            await new Promise((resolve) => setTimeout(resolve, 0));
            return {
                runs: window.__jq_async.runs,
                same: $btn.hasClass('btn--submitting') === before.submitting && $btn.html() === before.html,
            };
        });
        check('.click_async(): a second run starts once the first has settled', detached.runs, 2);
        check('.click_async(): a detached element is left untouched on settle', detached.same, true);

        // --- g. the $.ajax() block ----------------------------------------------------------------
        const ajax = await page.evaluate(async (external_url) => {
            const attempt = (fn) => {
                try {
                    fn();
                    return 'did not throw';
                } catch (e) {
                    return e.message;
                }
            };
            const out = {
                relative: attempt(() => $.ajax({ url: '/_sys/anything' })),
                url_string: attempt(() => $.ajax('/_sys/anything')),
                same_origin: attempt(() => $.ajax({ url: window.location.origin + '/_sys/anything' })),
                upload: attempt(() => $.ajax({ url: '/_upload', method: 'POST' })),
                endpoint: attempt(() => $.ajax({ url: '/_rsx_api/Some_Controller/some_method' })),
            };
            // An external URL is handed to jQuery's own $.ajax(). The page's CSP forbids
            // connecting anywhere else, so the request is cancelled from beforeSend: reaching
            // beforeSend at all is jQuery running it, where a block would have thrown.
            let before_send = 0;
            out.external_returned = attempt(() => $.ajax({
                url: external_url,
                beforeSend: () => { before_send++; return false; },
            }));
            out.external_before_send = before_send;

            // The shorthands call the ajax of jQuery's own function object, which is not the
            // global $ the page sees (jqhtml wraps it): each must meet the same block.
            out.get = attempt(() => $.get('/_sys/anything'));
            out.post = attempt(() => $.post('/_sys/anything', { a: 1 }));
            out.get_json = attempt(() => $.getJSON('/_sys/anything'));
            out.get_script = attempt(() => $.getScript('/_sys/anything.js'));
            out.load = attempt(() => $('<div>').load('/_sys/anything'));
            out.jquery_get = attempt(() => jQuery.get(window.location.origin + '/_sys/anything'));
            return out;
        }, EXTERNAL_URL);
        const blocked = 'AJAX requests to localhost via $.ajax() are prohibited.';
        check('$.ajax({url}) to a relative URL throws', ajax.relative.includes(blocked), true);
        check('$.ajax(url) to a relative URL throws', ajax.url_string.includes(blocked), true);
        check('$.ajax() to this origin\'s absolute URL throws', ajax.same_origin.includes(blocked), true);
        check('$.ajax() to /_upload throws naming Ajax.upload()', ajax.upload.includes('Ajax.upload(form_data'), true);
        check('$.ajax() to an endpoint URL names the controller call to use',
            ajax.endpoint.includes('await Some_Controller.some_method(parameters)'), true);
        check('$.ajax() to another host does not throw', ajax.external_returned, 'did not throw');
        check('$.ajax() to another host is run by jQuery (beforeSend reached)', ajax.external_before_send, 1);
        check('$.get() to a local URL throws', ajax.get.includes(blocked), true);
        check('$.post() to a local URL throws', ajax.post.includes(blocked), true);
        check('$.getJSON() to a local URL throws', ajax.get_json.includes(blocked), true);
        check('$.getScript() to a local URL throws', ajax.get_script.includes(blocked), true);
        check('.load() to a local URL throws', ajax.load.includes(blocked), true);
        check('jQuery.get() to this origin\'s absolute URL throws', ajax.jquery_get.includes(blocked), true);

        await page.evaluate(() => $('#jq_helpers_probe_temp').remove());
        // The $.ajax() block reports through shouldnt_happen(), which logs its banner, its
        // message and the stack with console.error before throwing: those are expected.
        const unexpected = errors.filter((e) => !/^=+$/.test(e)
            && e !== 'SANITY CHECK FAILURE'
            && !e.startsWith('Fatal: shouldnt_happen()')
            && !e.startsWith('Stack trace:'));
        check('no unexpected page errors', unexpected, []);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: jquery helpers');
    } else {
        console.log('PASS: jquery helpers');
    }
}

run();
