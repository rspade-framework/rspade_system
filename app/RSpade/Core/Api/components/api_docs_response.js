/**
 * The response pane shared by every tester in the console.
 *
 * There is exactly ONE look for "here is what the server said" - a coloured status badge, the
 * elapsed time, and a pretty-printed body - and a second tester must not invent a second one.
 * The markup is parameterised by BEM prefix rather than copied, so Api_Tester and
 * Api_File_Tester render an identical pane under their own component-scoped class names.
 *
 * These are plain functions, not methods: the two testers share no class (their forms and
 * their transports have nothing in common) and inheriting a whole component to reuse two
 * renderers would be the wrong seam.
 */

/**
 * Replace the response pane with a single inline note - a refusal, or a network failure.
 *
 * @param {jQuery} $target The tester's response element
 * @param {string} prefix  The tester's component name, used as the BEM prefix
 * @param {string} message Plain text, escaped here
 * @param {string} level   'warn' or 'error'
 */
function api_render_response_note($target, prefix, message, level) {
    const cls = prefix + '__response-note ' + prefix + '__response-note--' + (level || 'warn');

    $target.html('<div class="' + cls + '">' + html(message) + '</div>');
}

/**
 * Paint a real server answer: status badge, elapsed time, and the body.
 *
 * The body text is written through textContent rather than interpolated into the markup - it
 * is whatever the endpoint returned, and a response is data, never markup.
 *
 * @param {jQuery} $target     The tester's response element
 * @param {string} prefix      The tester's component name, used as the BEM prefix
 * @param {number} status      HTTP status
 * @param {string} status_text HTTP status text
 * @param {number} ms          Round trip, milliseconds
 * @param {string} pretty      The body, already pretty-printed when it parsed as JSON
 */
function api_render_response($target, prefix, status, status_text, ms, pretty) {
    const bucket = Math.floor(status / 100);
    const badge_cls = prefix + '__status--' + bucket + 'xx';

    const parts = [];
    parts.push('<div class="' + prefix + '__response-head">');
    parts.push('<span class="' + prefix + '__status ' + badge_cls + '">' + status + ' ' + html(status_text || '') + '</span>');
    parts.push('<span class="' + prefix + '__timing">' + ms + ' ms</span>');
    parts.push('</div>');
    // An insufficient_scope refusal is the one error whose REMEDY is not in the message.
    // The body says the key is not scoped for this endpoint; the 'required' field names the
    // route pattern a scope would have to reach
    // rule would have to exist, verbatim in the rule language - so it is lifted out of the
    // JSON rather than left for the reader to find in it.
    const required = api_scope_required(pretty);

    if (required !== null) {
        parts.push('<div class="' + prefix + '__response-note ' + prefix + '__response-note--error">'
            + 'This API key is not scoped for this endpoint. Requires: <code>' + html(required) + '</code>'
            + '</div>');
    }

    parts.push('<pre class="' + prefix + '__response-pre"><code class="language-json"></code></pre>');

    $target.html(parts.join(''));

    // Addressed through the response block, not as "the first code element": the scope note
    // above may carry a <code> of its own, and a bare find('code') would write the body into
    // it and leave the response empty.
    const code_el = $target.find('.' + prefix + '__response-pre code')[0];
    code_el.textContent = pretty;

    if (typeof hljs !== 'undefined') {
        hljs.highlightElement(code_el);
    }
}

/**
 * Pretty-print a response body when it is JSON, and leave it exactly as received when it is
 * not. A non-JSON body (an HTML error page, a plain-text refusal) is shown verbatim: it is
 * the useful evidence, and reformatting it would hide what actually came back.
 */
function api_pretty_body(text) {
    try {
        return JSON.stringify(JSON.parse(text), null, 2);
    } catch (e) {
        return text;
    }
}

/**
 * The `required` target of an insufficient_scope refusal, or null for any other body.
 *
 * Reads the already-pretty-printed text rather than taking a parsed object, because that is
 * what the renderer holds and a body that did not parse as JSON cannot be this error anyway.
 */
function api_scope_required(pretty) {
    let body;

    try {
        body = JSON.parse(pretty);
    } catch (e) {
        return null;
    }

    if (body?.error?.code !== 'insufficient_scope') {
        return null;
    }

    const required = str(body.error.required || '').trim();

    return required === '' ? null : required;
}
