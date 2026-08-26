/**
 * Api_Tester - inline "Try it" panel that fires a real request at the live endpoint.
 *
 * Path-token params render as individual typed inputs (they build the URL); GET query params
 * render as typed inputs; POST endpoints get a raw JSON body textarea (prefilled from param
 * examples). The Send button reads the active key from Rsx_Storage (written by Api_Key_Bar),
 * then uses native fetch() with an Authorization: Bearer header - $.ajax is blocked; fetch is
 * not. The response pane shows a colored status badge, elapsed time, and pretty-printed JSON
 * (highlight.js). No key set -> an inline prompt to set one via the key bar.
 *
 * args: endpoint (object).
 */
class Api_Tester extends Component {
    static STORAGE_KEY = 'apidocs_tester_key';

    on_create() {
        this._key_listener = () => this._refresh_key_hint();
    }

    on_ready() {
        const that = this;

        // Prefill the POST body textarea from the param examples (can't interpolate into a
        // <textarea> in markup - set the value here).
        const ep = this.args.endpoint;
        const verb = (ep.methods && ep.methods[0]) || 'GET';
        const $body = this.$sid('body');
        if (verb === 'POST' && $body && $body.exists()) {
            const other = (ep.api_params || []).filter(p => ep.pattern.indexOf(':' + p.name) === -1);
            const obj = {};
            for (const p of other) {
                obj[p.name] = (p.example !== null && p.example !== undefined)
                    ? p.example
                    : (p.type === 'int' ? 0 : p.type === 'float' ? 0.0 : p.type === 'bool' ? false : '');
            }
            $body.val(JSON.stringify(obj, null, 2));
        }

        this.$sid('send').off('click.apit').click_async(async function () {
            await that._send();
        });

        document.addEventListener('apidocs:key_changed', this._key_listener);
        this._refresh_key_hint();
    }

    on_stop() {
        document.removeEventListener('apidocs:key_changed', this._key_listener);
    }

    _refresh_key_hint() {
        const $hint = this.$sid('key_hint');
        if (!$hint || !$hint.exists()) {
            return;
        }
        const key = Rsx_Storage.session_get(Api_Tester.STORAGE_KEY);
        if (key) {
            $hint.removeClass('Api_Tester__key-hint--warn').text('Using the key from the bar above.');
        } else {
            $hint.addClass('Api_Tester__key-hint--warn').text('No API key set - paste one in the bar above.');
        }
    }

    /**
     * Everything the request is made of, read straight out of the form.
     *
     * SHARED WITH THE CODE SAMPLES: "Fill in Values" renders the same request the Send button
     * would make, so both must read the form the same way. Two implementations of "what would
     * we send" would drift, and a sample that disagreed with the tester beside it would be
     * worse than no sample at all.
     *
     * UNSET MEANS OMITTED, throughout. A blank query parameter is not sent as an empty
     * string, and a blank body field is dropped from the JSON: sending "" would assert a
     * value the user never gave, which for a PATCH-shaped update is the difference between
     * "leave this alone" and "clear it".
     */
    current_values() {
        const ep = this.args.endpoint;
        const verb = (ep.methods && ep.methods[0]) || 'GET';
        const $form = this.$sid('form');

        const path_vals = {};
        const query_vals = {};

        $form.find('[data-param]').each(function () {
            const $element = $(this);
            const name = $element.attr('data-param');
            const value = str($element.val());

            if (ep.pattern.indexOf(':' + name) !== -1) {
                path_vals[name] = value;
            } else if (value !== '') {
                query_vals[name] = value;
            }
        });

        // Path params keep their placeholder when unfilled, so a sample can SHOW what is
        // missing rather than silently producing a URL that would 404.
        const missing_path_params = [];
        let path = ep.pattern;

        for (const p of (ep.api_params || [])) {
            if (ep.pattern.indexOf(':' + p.name) === -1) {
                continue;
            }

            const value = path_vals[p.name] || '';

            if (value === '') {
                missing_path_params.push(p.name);
                path = path.replace(':' + p.name, '{' + p.name + '}');
            } else {
                path = path.replace(':' + p.name, encodeURIComponent(value));
            }
        }

        const query_string = Object.keys(query_vals)
            .map((name) => encodeURIComponent(name) + '=' + encodeURIComponent(query_vals[name]))
            .join('&');

        const body_raw = verb === 'POST' ? str(this.$sid('body').val()).trim() : '';
        let body_object = null;
        let body_to_send = body_raw;

        if (body_raw !== '') {
            try {
                const parsed = JSON.parse(body_raw);

                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    body_object = {};

                    for (const k of Object.keys(parsed)) {
                        if (parsed[k] !== '' && parsed[k] !== null) {
                            body_object[k] = parsed[k];
                        }
                    }

                    body_to_send = JSON.stringify(body_object);
                }
            } catch (e) {
                // Left as typed: the server's 400 is the useful answer, not ours.
            }
        }

        return {
            verb: verb,
            path: path,
            path_values: path_vals,
            missing_path_params: missing_path_params,
            query_values: query_vals,
            query_string: query_string,
            body_raw: body_raw,
            body_object: body_object,
            body_to_send: body_to_send,
        };
    }

    async _send() {
        const ep = this.args.endpoint;
        const verb = (ep.methods && ep.methods[0]) || 'GET';

        const key = Rsx_Storage.session_get(Api_Tester.STORAGE_KEY);
        if (!key) {
            this._refresh_key_hint();
            this._render_message('No API key set. Paste one in the key bar above - create a key in Settings > API Keys.', 'warn');
            return;
        }

        const values = this.current_values();

        // PATH PARAMETERS ARE THE ONE THING THE CLIENT CHECKS, because a URL cannot be built
        // without them - there is no request to send and therefore no server answer to show.
        // Everything else is deliberately NOT validated here: the point of a tester is to see
        // what the SERVER says, including its validation errors, so a required field left
        // blank must reach the endpoint and come back as a 422 rather than being stopped at
        // the door by a second, client-side rulebook that can drift from the real one.
        if (values.missing_path_params.length) {
            this._render_message(
                'Path parameter "' + values.missing_path_params[0] + '" is required - the URL cannot be built without it.',
                'warn'
            );
            return;
        }

        let url = window.location.origin + values.path;

        if (verb === 'GET' && values.query_string !== '') {
            url += '?' + values.query_string;
        }

        const headers = { 'Authorization': 'Bearer ' + key };
        let body;

        if (verb === 'POST' && values.body_raw !== '') {
            // Sent even when it does not parse. An unparseable body is a real thing to test:
            // the dispatcher answers 400 invalid_json, which is more useful to see than a
            // message this page invented.
            headers['Content-Type'] = 'application/json';
            body = values.body_to_send;
        }

        const started = performance.now();
        let res, text;
        try {
            res = await fetch(url, { method: verb, headers, body });
            text = await res.text();
        } catch (e) {
            this._render_message('Network error: ' + (e.message ? e.message : str(e)), 'error');
            return;
        }
        const ms = Math.round(performance.now() - started);

        let pretty = text;
        try {
            pretty = JSON.stringify(JSON.parse(text), null, 2);
        } catch (e) {
            // Non-JSON body: show as-is.
        }

        this._render_response(res.status, res.statusText, ms, pretty);
    }

    _render_message(message, level) {
        const cls = 'Api_Tester__response-note Api_Tester__response-note--' + (level || 'warn');
        this.$sid('response').html('<div class="' + cls + '">' + html(message) + '</div>');
    }

    _render_response(status, status_text, ms, pretty) {
        const bucket = Math.floor(status / 100);
        const badge_cls = 'Api_Tester__status--' + bucket + 'xx';

        const parts = [];
        parts.push('<div class="Api_Tester__response-head">');
        parts.push('<span class="Api_Tester__status ' + badge_cls + '">' + status + ' ' + html(status_text || '') + '</span>');
        parts.push('<span class="Api_Tester__timing">' + ms + ' ms</span>');
        parts.push('</div>');
        parts.push('<pre class="Api_Tester__response-pre"><code class="language-json"></code></pre>');

        const $resp = this.$sid('response');
        $resp.html(parts.join(''));

        const code_el = $resp.find('code')[0];
        code_el.textContent = pretty;
        if (typeof hljs !== 'undefined') {
            hljs.highlightElement(code_el);
        }
    }
}
