/**
 * Api_File_Tester - the "Try it" panel for an endpoint that takes multipart/form-data.
 *
 * A SEPARATE COMPONENT, not a mode of Api_Tester. The generic tester's whole request model is
 * a JSON document typed into a textarea, and a binary part cannot be expressed in one: the
 * file would arrive as the text of its own name and earn a 422 that taught the reader
 * nothing. Rather than thread a multipart branch through every method of the generic tester,
 * the card swaps this one in (Api_File_Endpoint_Card) and the two stay simple.
 *
 * WHAT IS SHARED, and why it is shared that way:
 *   - the adopted key, read from the SAME Rsx_Storage slot Api_Key_Bar writes and refreshed
 *     on the same 'apidocs:key_changed' event, so one key bar drives both testers;
 *   - the response pane, rendered by api_docs_response.js under this component's own BEM
 *     prefix, so there is one look for "here is what the server said";
 *   - current_values(), the same contract Api_Code_Samples reads to render "Fill in values",
 *     with the chosen file's name added.
 *
 * The request itself is a FormData sent by native fetch() with NO Content-Type header. The
 * browser must set that itself: it carries the multipart boundary, and a hand-written header
 * would name a boundary that is not in the body.
 *
 * args: endpoint (object) - a catalog endpoint declaring a `file` param.
 */
class Api_File_Tester extends Component {
    on_create() {
        this._key_listener = () => this._refresh_key_hint();
    }

    on_ready() {
        const that = this;

        this.$sid('send').off('click.afit').click_async(async function () {
            await that._send();
        });

        document.addEventListener('apidocs:key_changed', this._key_listener);
        this._refresh_key_hint();
    }

    on_stop() {
        document.removeEventListener('apidocs:key_changed', this._key_listener);
    }

    /**
     * The one storage slot, named by the key bar that owns it rather than re-declared here -
     * a third copy of the string is a third thing that can drift.
     */
    _adopted_key() {
        return Rsx_Storage.session_get(Api_Key_Bar.STORAGE_KEY);
    }

    _refresh_key_hint() {
        const $hint = this.$sid('key_hint');

        if (!$hint?.exists()) {
            return;
        }

        if (this._adopted_key()) {
            $hint.removeClass('Api_File_Tester__key-hint--warn').text('Using the key from the bar above.');
        } else {
            $hint.addClass('Api_File_Tester__key-hint--warn').text('No API key set - paste one in the bar above.');
        }
    }

    /**
     * The File the reader chose, or null when the input is empty.
     */
    selected_file() {
        const input = this.$sid('file')[0];

        return input?.files?.length ? input.files[0] : null;
    }

    /**
     * Everything the request is made of, read straight out of the form.
     *
     * SHARED WITH THE CODE SAMPLES, exactly as Api_Tester.current_values() is: "Fill in
     * values" renders the request this Send button would make, so both read the form through
     * this one method. The shape is deliberately the same as the generic tester's - path,
     * query_string, body_object, missing_path_params - with file_name added, so
     * Api_Code_Samples needs no idea which tester answered it.
     *
     * UNSET MEANS OMITTED, as it does everywhere else in the console: a blank field is not
     * sent as an empty string, because "" asserts a value the reader never gave.
     */
    current_values() {
        const ep = this.args.endpoint;
        const $form = this.$sid('form');

        const path_vals = {};
        const field_vals = {};

        $form.find('[data-param]').each(function () {
            const $element = $(this);

            if ($element.attr('type') === 'file') {
                return;
            }

            const name = $element.attr('data-param');
            const value = str($element.val());

            if (ep.pattern.indexOf(':' + name) !== -1) {
                path_vals[name] = value;
            } else if (value !== '') {
                field_vals[name] = value;
            }
        });

        // A path param left blank keeps its {name} placeholder, so a sample SHOWS what is
        // missing rather than producing a URL that would 404.
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

        const file = this.selected_file();

        return {
            verb: 'POST',
            path: path,
            path_values: path_vals,
            missing_path_params: missing_path_params,
            // Multipart carries every non-file field in the BODY, so there is never a query
            // string here. The keys are present and empty because the samples generator reads
            // the same shape from either tester.
            query_values: {},
            query_string: '',
            body_object: field_vals,
            file_name: file ? file.name : null,
            file_size: file ? file.size : null,
        };
    }

    async _send() {
        const ep = this.args.endpoint;
        const file_param = Api_Docs_Catalog.file_param(ep);

        const key = this._adopted_key();

        if (!key) {
            this._refresh_key_hint();
            this._render_message('No API key set. Paste one in the key bar above - create a key in Settings > API Keys.', 'warn');
            return;
        }

        const values = this.current_values();

        if (values.missing_path_params.length) {
            this._render_message(
                'Path parameter "' + values.missing_path_params[0] + '" is required - the URL cannot be built without it.',
                'warn'
            );
            return;
        }

        // THE TWO THINGS THE CLIENT CHECKS, and the reasons they are the only two.
        //
        // No file at all: there is no multipart body to build, so there is no request to send
        // and therefore no server answer worth showing - a 422 round trip would say less than
        // this line does.
        //
        // Over the ceiling: the server is and remains the enforcement boundary, but pushing a
        // doomed file up a slow connection for minutes to be told so is not a test result.
        // Same check and same wording as the internal uploader (Ajax.upload).
        //
        // NOTHING ELSE is validated here. A required field left blank must reach the endpoint
        // and come back as the server's own 422: the point of a tester is to see what the
        // SERVER says, not what a second client-side rulebook believes it would say.
        const file = this.selected_file();

        if (!file) {
            this._render_message('Choose a file first - "' + file_param.name + '" is the multipart part this endpoint requires.', 'warn');
            return;
        }

        const max_file_size = window.rsxapp?.files?.max_file_size || 0;

        if (max_file_size > 0 && file.size > max_file_size) {
            this._render_message(
                'File is too large: ' + bytes_to_human(file.size)
                + ' exceeds the ' + bytes_to_human(max_file_size) + ' limit.',
                'warn'
            );
            return;
        }

        const form_data = new FormData();
        form_data.append(file_param.name, file);

        for (const name of Object.keys(values.body_object)) {
            form_data.append(name, values.body_object[name]);
        }

        const url = window.location.origin + values.path;

        // NO Content-Type: fetch derives it from the FormData, boundary included. Setting it
        // by hand is the classic way to break an upload.
        const headers = { 'Authorization': 'Bearer ' + key };

        const started = performance.now();
        let res, text;

        try {
            res = await fetch(url, { method: 'POST', headers, body: form_data });
            text = await res.text();
        } catch (e) {
            this._render_message('Network error: ' + (e.message ? e.message : str(e)), 'error');
            return;
        }

        const ms = Math.round(performance.now() - started);

        api_render_response(this.$sid('response'), 'Api_File_Tester', res.status, res.statusText, ms, api_pretty_body(text));
    }

    _render_message(message, level) {
        api_render_response_note(this.$sid('response'), 'Api_File_Tester', message, level);
    }
}
