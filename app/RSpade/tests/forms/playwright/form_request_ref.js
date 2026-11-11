#!/usr/bin/env node
/**
 * The browser half of resubmission: Rsx_Form's request reference and Ajax.action().
 *
 * Every call Rsx_Form.submit() makes carries a _request_ref bound to the form's identity on
 * the browser history entry, so a submission whose answer was lost - or a form reached again
 * by Back after it saved - is answered from the request history instead of writing twice.
 *
 *   a. A LOST ANSWER IS RESENT UNDER THE SAME REFERENCE. The server committed, the answer came
 *      back as a 502; the second submit sends the same reference, resolves with the stored
 *      answer, fires 'replayed' and calls the replay notice. One row.
 *   b. A FORM THAT STAYS ON SCREEN gets a new reference after a success: its next submit is a
 *      new act, and writes a second row.
 *   c. A VALIDATION ERROR AND A QUESTION KEEP THE REFERENCE; the corrected submit runs.
 *   d. BACK. A success that redirects leaves the reference on the history entry, marked used.
 *      Back to that entry, a NEW form instance: already_submitted() is true and it holds the
 *      same reference. The same values replay (and redirect again); changed values reach the
 *      resubmit handler with {completed_at, original}; CANCELLED writes nothing, 'new' writes
 *      a second row under a new reference; an expired answer reaches it with expired: true.
 *   e. NO RESUBMIT HANDLER: the refusal renders as the form's error, and the next submit runs
 *      as new.
 *   f. A FORM IN A DIALOG is bound to its instance: nothing is written to the history entry.
 *      A second live form with an identity another live form holds is bound to its instance.
 *   g. Ajax.action(): a retry after a lost answer resends the reference, two endpoints get two,
 *      a success replaces it.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S. The page is the control panel at /_sys; the probe
 * forms are built at runtime inside the SPA action, so navigating away destroys them. The
 * endpoint is a fake answered by this script at the network level (the test tree is not in
 * the web server's manifest): it keeps a request history exactly as the server contract
 * states it - replay on the same fingerprint, request_ref_reused with {completed_at, original}
 * on a changed one - and counts the rows it "wrote". What is under test is the browser: the
 * real transport, the real Rsx_Form, the real history entry. The server half is
 * Request_History_Test.
 *
 * Self-contained: `node form_request_ref.js` against the dev web server on localhost.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page, poll_until } = require('../../_lib/playwright/sys_page.js');

const PROBE = 'Rsx_Form_Ref_Probe_Controller_Temp';
const AWAY = '/_sys/tasks';

function fail(msg) {
    console.log('FAIL: Rsx_Form request reference - ' + msg);
    process.exitCode = 1;
}

function pass(msg) {
    console.log('PASS: ' + msg);
}

/**
 * The fake endpoint's server: a request history keyed by reference, the rows it wrote, every
 * call it was sent, and sabotage the test arms for the next call.
 */
const server = {
    history: new Map(),
    rows: [],
    calls: [],
    lose_next: false,
    expire: new Set(),

    fingerprint(params) {
        const subject = {};
        for (const key of Object.keys(params).sort()) {
            if (!key.startsWith('_')) {
                subject[key] = params[key];
            }
        }
        return JSON.stringify(subject);
    },

    answer(call) {
        const params = call.params || {};
        const endpoint = call.controller + '::' + call.action;
        const ref = params._request_ref;
        this.calls.push({ endpoint: endpoint, params: params });

        if (params.title === '') {
            return { _success: false, error_code: 'validation', reason: 'Please correct the errors below.', metadata: { title: 'Required' } };
        }

        if (params.ask && !(params._answers && 'k' in params._answers)) {
            return { _success: false, error_code: 'question', reason: 'A question is pending', metadata: { key: 'k', question: { kind: 'confirm' } } };
        }

        const kept = ref ? this.history.get(ref) : undefined;

        if (kept) {
            if (kept.endpoint !== endpoint) {
                return { _success: false, error_code: 'request_ref_reused', reason: 'other endpoint', metadata: {} };
            }
            if (this.expire.has(ref)) {
                return { _success: false, error_code: 'request_response_expired', reason: 'expired', metadata: { completed_at: kept.completed_at } };
            }
            if (kept.fingerprint !== this.fingerprint(params)) {
                return { _success: false, error_code: 'request_ref_reused', reason: 'different parameters', metadata: { completed_at: kept.completed_at, original: kept.value } };
            }
            return { _success: true, _ajax_return_value: kept.value, _replayed: true, _completed_at: kept.completed_at, _duration_ms: 5 };
        }

        this.rows.push(params);
        const value = { id: this.rows.length };
        if (params.redirect_to) {
            value.redirect = params.redirect_to;
        }
        const completed_at = new Date(Date.UTC(2026, 10, 10, 12, 0, this.rows.length)).toISOString();
        if (ref) {
            this.history.set(ref, { endpoint: endpoint, fingerprint: this.fingerprint(params), value: value, completed_at: completed_at });
        }
        return { _success: true, _ajax_return_value: value, _completed_at: completed_at, _duration_ms: 5 };
    },
};

async function intercept(route, request) {
    if (!request.url().includes('/_ajax/_batch')) {
        return false;
    }

    const calls = JSON.parse(request.postData() || '{}').batch_calls || [];
    if (!calls.length || !calls.every((call) => call.controller.startsWith(PROBE))) {
        return false;
    }

    const body = {};
    for (const call of calls) {
        body['C_' + call.call_id] = server.answer(call);
    }

    if (server.lose_next) {
        // The server committed and recorded; the answer never reached the browser.
        server.lose_next = false;
        await route.fulfill({ status: 502, contentType: 'text/html', body: '<h1>502</h1>' });
        return true;
    }

    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    return true;
}

/**
 * Mount a probe form inside the SPA action (or a role="dialog" wrapper in it): a real <form>,
 * one <Form_Errors />, and native hidden inputs - what vals() serializes with no application
 * input component present. Each probe records its own 'replayed' events and replay notices.
 */
async function build(page, options) {
    return await page.evaluate(async (options) => {
        await Spa.action().ready();
        const id = options.id;
        const $host = Spa.action().$;
        const $mount = $('<form></form>').attr('id', id);

        if (options.in_dialog) {
            $host.append($('<div role="dialog"></div>').attr('id', id + '_dialog').append($mount));
        } else {
            $host.append($mount);
        }

        $mount.component('Rsx_Form', { controller: options.controller, method: 'save' });
        await $mount.component().ready();

        const form = $mount.component();
        $mount.append($('<div></div>').attr('id', id + '_errors'));
        $('#' + id + '_errors').component('Form_Errors');
        for (const [name, value] of Object.entries(options.fields)) {
            $mount.append($('<input type="hidden" />').attr('name', name).val(value));
        }
        await $('#' + id + '_errors').component().ready();

        window.__ref_events = window.__ref_events || {};
        window.__ref_events[id] = { replayed: [], notices: [], resubmits: [] };
        form.on('replayed', (f, info) => window.__ref_events[id].replayed.push(info));
        form.replay_notice = (result, context) => window.__ref_events[id].notices.push(context.completed_at);

        return true;
    }, options);
}

/** Run fn(form, id) in the page against the probe with this id; returns what fn returns. */
async function on_probe(page, id, fn_source, arg) {
    return await page.evaluate(async ({ id, fn_source, arg }) => {
        const form = $('#' + id).component();
        const fn = new Function('form', 'id', 'arg', 'return (async () => {' + fn_source + '})();');
        return await fn(form, id, arg);
    }, { id: id, fn_source: fn_source, arg: arg });
}

const refs_sent = (from) => server.calls.slice(from).map((call) => call.params._request_ref);

async function back_to_dashboard(page) {
    await page.evaluate(() => history.back());
    await poll_until(page, 'Back returned to /_sys and its action is ready', () => {
        return location.pathname === '/_sys' && Spa.action() && Spa.action().constructor.name === '_Sys_Dashboard_Action';
    });
    await page.evaluate(async () => { await Spa.action().ready(); });
}

async function run() {
    const { browser, page } = await open_sys_page({ on_request: intercept });

    try {
        // --- a. a lost answer is resent under the same reference and replayed ---
        await build(page, { id: 'probe_a', controller: PROBE + '_a', fields: { title: 'first' } });
        let mark = server.calls.length;
        server.lose_next = true;
        const a = await on_probe(page, 'probe_a', `
            const first = await form.submit();
            const error_text = $('#' + id + '_errors').text().trim();
            const second = await form.submit();
            return { first, error_text, second, events: window.__ref_events[id] };
        `);
        const a_refs = refs_sent(mark);
        if (a.first !== false || !a.error_text) {
            fail('(a) a lost answer must fail the submit visibly, got ' + JSON.stringify(a.first));
        } else if (a_refs.length !== 2 || !a_refs[0] || a_refs[0] !== a_refs[1]) {
            fail('(a) the resend must carry the same reference: ' + JSON.stringify(a_refs));
        } else if (!a.second || a.second.id !== 1 || server.rows.length !== 1) {
            fail('(a) the resend must resolve the stored answer with one row written: ' + JSON.stringify(a.second) + ', rows ' + server.rows.length);
        } else if (a.events.replayed.length !== 1 || a.events.replayed[0].result.id !== 1 || !a.events.replayed[0].completed_at) {
            fail("(a) 'replayed' must fire once with {result, completed_at}: " + JSON.stringify(a.events.replayed));
        } else if (a.events.notices.length !== 1) {
            fail('(a) the replay notice must be called once: ' + JSON.stringify(a.events.notices));
        } else {
            pass('a lost answer is resent under the same reference and answered as a replayed success');
        }

        // --- b. a form that stays on screen gets a new reference ---
        mark = server.calls.length;
        const b = await on_probe(page, 'probe_a', 'return await form.submit();');
        const b_refs = refs_sent(mark);
        if (!b || b_refs.length !== 1 || b_refs[0] === a_refs[0] || server.rows.length !== 2) {
            fail('(b) the next submit of a form that stayed must be new: refs ' + JSON.stringify(b_refs) + ', rows ' + server.rows.length);
        } else {
            pass('a success that leaves the form on screen replaces its reference');
        }

        // --- c. a validation error and a question keep the reference ---
        await build(page, { id: 'probe_c', controller: PROBE + '_c', fields: { title: '', ask: '1' } });
        mark = server.calls.length;
        const c = await on_probe(page, 'probe_c', `
            form.question_handler = async () => true;
            const invalid = await form.submit();
            $('#' + id + ' input[name="title"]').val('fixed');
            const saved = await form.submit();
            return { invalid, saved };
        `);
        const c_refs = refs_sent(mark);
        if (c.invalid !== false || !c.saved || c_refs.length !== 3 || new Set(c_refs).size !== 1) {
            fail('(c) validation, question and the corrected submit must share one reference: ' + JSON.stringify(c_refs) + ' ' + JSON.stringify(c));
        } else {
            pass('a validation error and a question round keep the reference; the corrected submit runs');
        }

        // --- d. Back to a form whose success redirected ---
        await page.evaluate(() => { $('#probe_a').remove(); $('#probe_c').remove(); });
        await build(page, { id: 'probe_d', controller: PROBE + '_d', fields: { title: 'back case', redirect_to: AWAY } });
        mark = server.calls.length;
        const rows_before = server.rows.length;
        const d_first = await on_probe(page, 'probe_d', 'return { ref: form.__bind_ref().ref, result: await form.submit() };');
        await poll_until(page, 'the redirect reached ' + AWAY, (away) => location.pathname === away, AWAY);
        await back_to_dashboard(page);

        await build(page, { id: 'probe_d', controller: PROBE + '_d', fields: { title: 'back case', redirect_to: AWAY } });
        const d_back = await on_probe(page, 'probe_d', `
            return { used: form.already_submitted(), ref: form.__bind_ref().ref };
        `);
        if (!d_first.result || d_back.used !== true || d_back.ref !== d_first.ref) {
            fail('(d) the form reached by Back must hold the used reference: ' + JSON.stringify({ d_first, d_back }));
        } else {
            pass('a form reached by Back holds the reference its entry used, marked already submitted');
        }

        const d_replay = await on_probe(page, 'probe_d', `
            const result = await form.submit();
            return { result, events: window.__ref_events[id] };
        `);
        await poll_until(page, 'the replayed redirect reached ' + AWAY, (away) => location.pathname === away, AWAY);
        if (!d_replay.result || d_replay.result.id !== d_first.result.id || server.rows.length !== rows_before + 1 || d_replay.events.replayed.length !== 1) {
            fail('(d) the same values must replay to the original record: ' + JSON.stringify(d_replay) + ', rows ' + server.rows.length);
        } else {
            pass('the same values resubmitted from Back replay the original answer and follow its redirect');
        }

        await back_to_dashboard(page);
        await build(page, { id: 'probe_d', controller: PROBE + '_d', fields: { title: 'changed', redirect_to: AWAY } });
        const d_changed = await on_probe(page, 'probe_d', `
            const contexts = [];
            form.resubmit_handler = async (context) => { contexts.push({ completed_at: context.completed_at, original: context.original, expired: context.expired, has_form: context.form === form }); return Rsx_Form.CANCELLED; };
            const cancelled = await form.submit();
            const error_text = $('#' + id + '_errors').text().trim();
            const value = $('#' + id + ' input[name="title"]').val();
            form.resubmit_handler = async () => Rsx_Form.RESUBMIT_AS_NEW;
            const ref_before = form.__bind_ref().ref;
            const created = await form.submit();
            return { contexts, cancelled, error_text, value, created, ref_before };
        `);
        await poll_until(page, 'the second record redirect reached ' + AWAY, (away) => location.pathname === away, AWAY);
        const d_refs = refs_sent(server.calls.length - 3);
        const ctx = d_changed.contexts[0] || {};
        if (d_changed.cancelled !== false || d_changed.error_text !== '' || d_changed.value !== 'changed') {
            fail('(d) CANCELLED must stop with nothing rendered and the values kept: ' + JSON.stringify(d_changed));
        } else if (ctx.completed_at === undefined || !ctx.original || ctx.original.redirect !== AWAY || ctx.expired !== false || !ctx.has_form) {
            fail('(d) the resubmit handler must receive {form, completed_at, original, expired}: ' + JSON.stringify(ctx));
        } else if (!d_changed.created || d_changed.created.id === d_first.result.id || server.rows.length !== rows_before + 2) {
            fail("(d) 'new' must write a second record: " + JSON.stringify(d_changed.created) + ', rows ' + server.rows.length);
        } else if (d_refs[2] === d_changed.ref_before || d_refs[1] !== d_changed.ref_before) {
            fail("(d) 'new' must resend under a fresh reference: " + JSON.stringify(d_refs));
        } else {
            pass("changed values reach the resubmit handler with the original; CANCELLED writes nothing, 'new' writes under a new reference");
        }

        // expired: the entry's reference whose stored answer is gone
        await back_to_dashboard(page);
        await build(page, { id: 'probe_d', controller: PROBE + '_d', fields: { title: 'changed', redirect_to: AWAY } });
        const d_expired = await on_probe(page, 'probe_d', `
            let seen = null;
            form.resubmit_handler = async (context) => { seen = { expired: context.expired, completed_at: context.completed_at, original: context.original }; return Rsx_Form.CANCELLED; };
            const ref = form.__bind_ref().ref;
            return { ref, seen_before: seen };
        `);
        server.expire.add(d_expired.ref);
        const d_expired_after = await on_probe(page, 'probe_d', `
            let seen = null;
            form.resubmit_handler = async (context) => { seen = { expired: context.expired, completed_at: context.completed_at, original: context.original }; return Rsx_Form.CANCELLED; };
            await form.submit();
            return seen;
        `);
        if (!d_expired_after || d_expired_after.expired !== true || !d_expired_after.completed_at || d_expired_after.original !== null) {
            fail('(d) an expired answer must reach the handler with expired: true: ' + JSON.stringify(d_expired_after));
        } else {
            pass('request_response_expired reaches the resubmit handler with expired: true');
        }
        server.expire.clear();

        // --- e. no resubmit handler: rendered, and the next submit runs as new ---
        const e = await on_probe(page, 'probe_d', `
            const global_handler = Rsx_Form._resubmit_handler;
            Rsx_Form.set_resubmit_handler(null);
            form.resubmit_handler = null;
            $('#' + id + ' input[name="title"]').val('changed again');
            try {
                const refused = await form.submit();
                const error_text = $('#' + id + '_errors').text().trim();
                const ref_after = form.__bind_ref().ref;
                return { refused, error_text, ref_after };
            } finally {
                Rsx_Form.set_resubmit_handler(global_handler);
            }
        `);
        if (e.refused !== false || !e.error_text.includes('already submitted') || e.ref_after === d_expired.ref) {
            fail('(e) with no handler the refusal must render and the reference be replaced: ' + JSON.stringify(e));
        } else {
            const rows = server.rows.length;
            const again = await on_probe(page, 'probe_d', 'return await form.submit();');
            await poll_until(page, 'the resubmitted redirect reached ' + AWAY, (away) => location.pathname === away, AWAY);
            if (!again || server.rows.length !== rows + 1) {
                fail('(e) the next submit must run as new: ' + JSON.stringify(again));
            } else {
                pass('with no resubmit handler the refusal renders in the form and submitting again saves as new');
            }
        }

        // --- f. a dialog's form and a second live form are bound to their instances ---
        await back_to_dashboard(page);
        await build(page, { id: 'probe_f1', controller: PROBE + '_f', fields: { title: 'one' } });
        await build(page, { id: 'probe_f2', controller: PROBE + '_f', fields: { title: 'two' } });
        await build(page, { id: 'probe_f3', controller: PROBE + '_g', fields: { title: 'dialog' }, in_dialog: true });
        const f = await page.evaluate(() => {
            const one = $('#probe_f1').component().__bind_ref().ref;
            const two = $('#probe_f2').component().__bind_ref().ref;
            const dialog = $('#probe_f3').component().__bind_ref().ref;
            const kept = (history.state && history.state[Rsx_Form.ENTRY_STATE_KEY]) || {};
            const kept_refs = Object.values(kept).map((slot) => slot.ref);
            return { one_kept: kept_refs.includes(one), two_kept: kept_refs.includes(two), dialog_kept: kept_refs.includes(dialog), distinct: one !== two };
        });
        if (!f.one_kept || f.two_kept || f.dialog_kept || !f.distinct) {
            fail('(f) only the first live form of an identity outside a dialog is the entry\'s: ' + JSON.stringify(f));
        } else {
            pass('a form in a dialog, and a second live form of one identity, are bound to their instances');
        }

        // --- g. Ajax.action() ---
        mark = server.calls.length;
        server.lose_next = true;
        const g = await page.evaluate(async ({ probe }) => {
            const act = Ajax.action();
            const one = '/_ajax/' + probe + '_x/save';
            const other = '/_ajax/' + probe + '_y/save';
            let lost = null;
            try {
                await act.call(one, { title: 'act' });
            } catch (error) {
                lost = error.code;
            }
            const retried = await act.call(one, { title: 'act' });
            const replayed = act.replayed;
            const next = await act.call(one, { title: 'act' });
            const elsewhere = await act.call(other, { title: 'act' });
            return { lost, retried, replayed, next, elsewhere, replayed_after: act.replayed };
        }, { probe: PROBE });
        const g_refs = refs_sent(mark);
        if (g.lost !== 'server_error' || g_refs[0] !== g_refs[1]) {
            fail('(g) a retry after a lost answer must resend the reference: ' + JSON.stringify(g_refs));
        } else if (g.replayed !== true || !(g.next.id > g.retried.id)) {
            fail('(g) the retry must be answered as a replay: ' + JSON.stringify(g));
        } else if (g_refs[2] === g_refs[1] || g_refs[3] === g_refs[2] || g.replayed_after !== false) {
            fail('(g) a success must replace the reference and each endpoint get its own: ' + JSON.stringify(g_refs));
        } else {
            pass('Ajax.action() resends a reference across retries, replaces it on success, one per endpoint');
        }
    } catch (error) {
        fail('exception: ' + (error && error.message ? error.message : String(error)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Rsx_Form request reference');
    } else {
        console.log('PASS: Rsx_Form request reference');
    }
}

run();
