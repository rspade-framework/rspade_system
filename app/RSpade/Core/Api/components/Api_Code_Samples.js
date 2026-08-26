/**
 * Api_Code_Samples - client-generated code samples (cURL / PHP / JS / Python / PowerShell)
 * for one endpoint, with a tab strip and per-snippet copy button.
 *
 * Snippets are generated from the catalog spec by api_code_samples() (never authored). Tabs
 * toggle visibility via DOM class swaps (no re-render, so highlighting is not repeated).
 *
 * "FILL IN VALUES" swaps the samples to the request the tester beside them would actually
 * send - real key, typed values, unset fields omitted, path parameters merged in - and keeps
 * doing so as the reader types. It reads Api_Tester.current_values(), the same method the
 * Send button uses, so a sample can never describe a different request from the one that
 * would be made.
 *
 * A path parameter left blank stays as {name}, marked red with a tooltip, rather than being
 * silently invented: a sample that looked runnable and was not would be worse than one that
 * says what is missing.
 *
 * args: endpoint (object) - one resolved catalog endpoint.
 */
class Api_Code_Samples extends Component {
    on_create() {
        this.state = {
            active: 0,
            fill: false,
            samples: api_code_samples(this.args.endpoint),
            missing: [],
        };
    }

    on_ready() {
        const that = this;

        this._paint();

        this.$sid('fill').off('change.acs').on('change.acs', function () {
            that.state.fill = $(this).is(':checked');
            that._regenerate();
        });

        // Live: every keystroke in the tester beside us reshapes the request, so the sample
        // follows it. Delegated on the CARD rather than the tester, so it survives the
        // tester re-rendering its own form.
        this._values_handler = () => {
            if (that.state.fill) {
                that._regenerate();
            }
        };

        this.$.closest('.Api_Endpoint_Card')
            .off('input.acs change.acs')
            .on('input.acs change.acs', '.Api_Tester__input, .Api_Tester__body', this._values_handler);

        // The key lives outside the card entirely.
        document.addEventListener('apidocs:key_changed', this._values_handler);

        this.$sid('tabs').off('click.acs').on('click.acs', 'button[data-idx]', function () {
            that._activate(int($(this).attr('data-idx')));
        });

        this.$sid('panes').off('click.acs').on('click.acs', 'button.Api_Code_Samples__copy', function () {
            that._copy(int($(this).attr('data-idx')), $(this));
        });
    }

    on_stop() {
        if (this._values_handler) {
            document.removeEventListener('apidocs:key_changed', this._values_handler);
            this._values_handler = null;
        }
    }

    /**
     * Rebuild the samples for the current mode and repaint, keeping the selected tab.
     */
    _regenerate() {
        const filled = this.state.fill ? this._filled_values() : null;

        this.state.missing = filled ? filled.missing_path_params : [];
        this.state.samples = api_code_samples(this.args.endpoint, filled);

        this._paint();
    }

    /**
     * What the tester would send, plus the adopted key.
     *
     * Reads the SIBLING tester rather than re-deriving from the form, so there is exactly one
     * implementation of "what would we send".
     */
    _filled_values() {
        const tester = this.$.closest('.Api_Endpoint_Card').find('.Api_Tester').component();

        const values = tester ? tester.current_values() : {
            path: this.args.endpoint.pattern,
            query_string: '',
            query_values: {},
            body_object: null,
            missing_path_params: [],
        };

        values.api_key = Rsx_Storage.session_get(Api_Key_Bar.STORAGE_KEY) || '{API_KEY}';

        return values;
    }

    /**
     * Write each sample into its <pre>, highlight it, then mark unfilled path placeholders.
     *
     * The marking happens AFTER highlighting because highlight.js replaces innerHTML: a span
     * inserted first would be destroyed. The placeholder text survives highlighting intact
     * (it sits inside a string literal, which hljs emits as one node), so a targeted replace
     * on the highlighted HTML is safe.
     */
    _paint() {
        const samples = this.state.samples;
        const missing = this.state.missing;

        this.$sid('panes').find('pre[data-idx]').each(function () {
            const $pre = $(this);
            const idx = int($pre.attr('data-idx'));
            const hl = $pre.attr('data-hl');

            this.textContent = samples[idx].code;
            $pre.addClass('language-' + hl);
            $pre.removeAttr('data-highlighted');

            if (typeof hljs !== 'undefined') {
                hljs.highlightElement(this);
            }

            for (const name of missing) {
                const needle = '{' + name + '}';
                const marker = api_missing_marker(name);

                this.innerHTML = this.innerHTML.split(needle).join(marker);
            }
        });
    }

    _activate(idx) {
        this.state.active = idx;

        const $tabs = this.$sid('tabs');
        $tabs.find('button[data-idx]').removeClass('Api_Code_Samples__tab--active');
        $tabs.find('button[data-idx="' + idx + '"]').addClass('Api_Code_Samples__tab--active');

        const $panes = this.$sid('panes');
        $panes.find('.Api_Code_Samples__pane').addClass('Api_Code_Samples__pane--hidden');
        $panes.find('.Api_Code_Samples__pane[data-idx="' + idx + '"]').removeClass('Api_Code_Samples__pane--hidden');
    }

    async _copy(idx, $btn) {
        const code = this.state.samples[idx].code;
        try {
            await navigator.clipboard.writeText(code);
            const $span = $btn.find('span');
            const prev = $span.text();
            $span.text('Copied');
            setTimeout(() => $span.text(prev), 1200);
        } catch (e) {
            // Clipboard unavailable (insecure context / permissions) - silent, non-critical.
        }
    }
}
