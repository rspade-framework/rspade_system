/**
 * Pdf_Viewer - see Pdf_Viewer.jqhtml for the contract.
 *
 * pdf.js needs the live canvas, so document loading + first render happen in on_ready (not
 * on_load - the rendition url is already in this.args, there is nothing to fetch into this.data).
 *
 * pdf.js is lazy-loaded (never bundled) and memoized on the class so the ~450KB module + worker
 * load exactly once per page regardless of how many Pdf_Viewer instances render.
 *
 * RESIZE OBSERVATION. This class is the framework's first ResizeObserver, and the pattern it
 * establishes is: observe the ONE element that bounds the component (never the component's own
 * root, whose size is an OUTPUT of the last render), route the callback through the stdlib
 * debounce() - never a hand-rolled setTimeout - and disconnect() in on_stop(). The callback
 * re-measures rather than trusting the entry it was handed, so it is idempotent and safe to run
 * on any frame; it skips when nothing it cares about actually moved.
 */
class Pdf_Viewer extends Component {
    // Shared, class-wide memoized pdf.js module promise (see _load_pdfjs).
    static _pdfjs_promise = null;

    // Coalescing interval for container-resize re-renders. Deliberately slower than the modal
    // system's 100ms window-resize debounce (rsx/lib/modal/rsx_modal.js): a re-render here
    // re-rasters the whole page through pdf.js, so a pane drag or an animated column collapse must
    // issue ONE re-raster at the settled size, not thirty along the way.
    static RESIZE_DEBOUNCE_MS = 200;

    /**
     * Lazy-load the pdf.js module from the framework streaming route
     * (File_Preview_Controller::pdfjs / ::pdf_worker), memoized class-wide. The client appends
     * ?v=build_key so a new deployment busts the long-cache; GlobalWorkerOptions.workerSrc points
     * at the worker route (same versioned url).
     *
     * The bare `import(url)` is deliberate and it is load-bearing that it stays bare. It used to
     * be routed through `new Function('u','return import(u)')` to hide the token from the build,
     * because the transform rewrote a plain import() into a `require(...)` shim and require is
     * undefined in a browser. The transform now declares caller.supportsDynamicImport, so import()
     * survives as native syntax and the workaround is gone - it was an eval-family construct, which
     * the Content-Security-Policy blocks on every page that renders a PDF (JS-EVAL-01).
     *
     * Do not reintroduce a Function/eval wrapper here. If import() ever starts compiling to
     * require() again, the fix is in the transform's caller declaration, not at this call site.
     */
    static _load_pdfjs() {
        if (Pdf_Viewer._pdfjs_promise) {
            return Pdf_Viewer._pdfjs_promise;
        }

        const version = (window.rsxapp && window.rsxapp.build_key) ? window.rsxapp.build_key : '';
        const module_url = Rsx.Route('File_Preview_Controller::pdfjs') + '?v=' + urlencode(version);
        const worker_url = Rsx.Route('File_Preview_Controller::pdf_worker') + '?v=' + urlencode(version);

        Pdf_Viewer._pdfjs_promise = import(module_url).then((pdfjs) => {
            pdfjs.GlobalWorkerOptions.workerSrc = worker_url;
            return pdfjs;
        });

        return Pdf_Viewer._pdfjs_promise;
    }

    on_create() {
        const that = this;

        this.state = {
            page: this.args.page ? int(this.args.page) : 1,
            pages: 0,
            pdf: null,
            loading_task: null,
        };
        this._stopped = false;
        this._last_dims = { width: 0, height: 0, ratio: 0 };

        this._fit = this.args.fit ? str(this.args.fit) : 'width';
        if (this._fit !== 'width' && this._fit !== 'contain') {
            throw new Error('Pdf_Viewer: $fit must be "width" or "contain", got "' + this._fit + '"');
        }

        // Renders can overlap - set_page(), a resize and the initial load all call _render_page()
        // and every one of them awaits pdf.js twice. The sequence number is the only thing that
        // makes "the newest render wins" true: a superseded render bails after each await instead
        // of painting an older page (or an older size) over a newer one.
        this._render_seq = 0;

        // The frame box the current canvas was rendered FOR, so a resize that does not actually
        // change the box costs nothing.
        this._last_render_size = { width: 0, height: 0 };

        this._resize_observer = null;
        this._on_resize = debounce(() => that._handle_resize(), Pdf_Viewer.RESIZE_DEBOUNCE_MS);
    }

    async on_ready() {
        const that = this;
        try {
            const pdfjs = await Pdf_Viewer._load_pdfjs();
            if (this._stopped) return;

            const loading_task = pdfjs.getDocument({ url: this.args.url });
            this.state.loading_task = loading_task;

            const pdf = await loading_task.promise;
            if (this._stopped) {
                try { pdf.destroy(); } catch (e) { /* already torn down */ }
                return;
            }

            this.state.pdf = pdf;
            this.state.pages = pdf.numPages;

            let page = this.state.page;
            if (page < 1) page = 1;
            if (page > this.state.pages) page = this.state.pages;
            this.state.page = page;

            await this._render_page(page);
            if (this._stopped) return;

            // Observe only AFTER the first render: the observer fires once on observe(), and doing
            // that before there is a rendered size would race the initial render for the canvas.
            this._observe_container();

            that.trigger('preview_loaded', {
                pages: this.state.pages,
                width: this._last_dims.width,
                height: this._last_dims.height,
                ratio: this._last_dims.ratio,
            });
        } catch (e) {
            this._show_error(e);
        }
    }

    on_stop() {
        this._stopped = true;
        if (this._resize_observer) {
            this._resize_observer.disconnect();
            this._resize_observer = null;
        }
        if (this.state && this.state.pdf) {
            try { this.state.pdf.destroy(); } catch (e) { /* best effort */ }
            this.state.pdf = null;
        }
        if (this.state && this.state.loading_task && is_function(this.state.loading_task.destroy)) {
            try { this.state.loading_task.destroy(); } catch (e) { /* best effort */ }
            this.state.loading_task = null;
        }
    }

    // -- Public API ----------------------------------------------------------

    set_page(n) {
        n = int(n);
        if (!this.state.pages) return;
        if (n < 1) n = 1;
        if (n > this.state.pages) n = this.state.pages;
        if (n === this.state.page) return;

        this.state.page = n;
        this._render_page(n).then((painted) => {
            if (!painted || this._stopped) return;
            this.trigger('page_changed', { page: n });
        });
    }

    get_page() {
        return this.state.page;
    }

    get_pages() {
        return this.state.pages;
    }

    // -- Internal ------------------------------------------------------------

    /**
     * Render page N onto the canvas at devicePixelRatio (crisp on retina), scaled per $fit:
     *
     *   width    the canvas is drawn at the container width and displayed at CSS width 100%.
     *   contain  the canvas is drawn at min(width scale, height scale) so the WHOLE page fits the
     *            bounded box the host gave, and is displayed at that exact CSS px size - width:100%
     *            would stretch the bitmap straight back out to the container width.
     *
     * A contain fit with no bounded height to divide by falls back to the width fit; it never
     * renders at a zero scale.
     *
     * Returns true when THIS call is the one that painted, false when it was superseded by a newer
     * render (or the component stopped) before it finished.
     */
    async _render_page(n) {
        const token = ++this._render_seq;

        const pdf = this.state.pdf;
        if (!pdf) return false;

        const page = await pdf.getPage(n);
        if (this._stopped || token !== this._render_seq) return false;

        const canvas = this.$sid('canvas')[0];
        const container_width = this.$.width() || 800;
        const frame_box = this._measure_frame();

        const unscaled = page.getViewport({ scale: 1 });
        const dpr = window.devicePixelRatio || 1;

        let css_scale = container_width / unscaled.width;
        let contained = false;
        if (this._fit === 'contain' && frame_box.height > 0 && unscaled.height > 0) {
            const height_scale = frame_box.height / unscaled.height;
            if (height_scale < css_scale) {
                css_scale = height_scale;
            }
            contained = true;
        }

        const viewport = page.getViewport({ scale: css_scale * dpr });

        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        if (contained) {
            canvas.style.width = Math.floor(unscaled.width * css_scale) + 'px';
            canvas.style.height = Math.floor(unscaled.height * css_scale) + 'px';
        } else {
            canvas.style.width = '100%';
            canvas.style.height = 'auto';
        }

        // Page geometry is a property of the page, not of the paint, so record it before the render
        // await - preview_loaded describes the page that is now on screen.
        this._last_dims = {
            width: unscaled.width,
            height: unscaled.height,
            ratio: unscaled.width ? (unscaled.height / unscaled.width) : 0,
        };
        this._last_render_size = frame_box;

        const ctx = canvas.getContext('2d');
        await page.render({ canvasContext: ctx, viewport: viewport }).promise;

        return !this._stopped && token === this._render_seq;
    }

    /**
     * Start watching the bounded box for size changes. One observer, disconnected in on_stop().
     */
    _observe_container() {
        const that = this;
        const frame = this._frame_element();
        if (!frame) return;

        this._resize_observer = new ResizeObserver(() => that._on_resize());
        this._resize_observer.observe(frame);
    }

    /**
     * Debounced resize handler. Re-measures rather than trusting the observer entry, so it is
     * idempotent; skips a box that has not moved since the current canvas was drawn.
     */
    _handle_resize() {
        if (this._stopped) return;
        if (!this.state.pdf) return;

        const box = this._measure_frame();
        if (box.width === this._last_render_size.width && box.height === this._last_render_size.height) {
            return;
        }

        this._render_page(this.state.page);
    }

    /**
     * The element that BOUNDS this viewer - the box a contain fit divides by and the box the
     * resize observer watches.
     *
     * It is never the Pdf_Viewer root: that element is display:block with no height of its own, so
     * its height IS the canvas we last drew, and dividing by it would be circular. Document_Preview
     * always wraps a viewer in .Document_Preview__frame, which is where the host's $width/$height
     * (or the host element's own box) actually lands. A viewer mounted outside Document_Preview
     * falls back to its offsetParent, the nearest ancestor that has a box of its own.
     */
    _frame_element() {
        const frame = this.$.closest('.Document_Preview__frame');
        if (frame.exists()) {
            return frame[0];
        }
        const el = this.$[0];
        return el.offsetParent || el.parentElement || null;
    }

    /**
     * The frame's content box, measured HONESTLY - with the canvas pulled out of flow.
     *
     * .Document_Preview__frame is height:100%, which resolves to auto whenever the host gave it no
     * bounded height - and auto means "as tall as my content", i.e. as tall as the canvas we last
     * drew. Measuring it with the canvas in flow would therefore report a height derived from the
     * previous render and shrink the page a little more on every pass. Taking the canvas out of
     * flow for the read collapses an unbounded frame to ~0 (the fall-back-to-width signal) while a
     * bounded frame still reports the height the host gave it. The style is restored in the same
     * JS turn, so nothing repaints in between.
     */
    _measure_frame() {
        const frame = this._frame_element();
        if (!frame) {
            return { width: 0, height: 0 };
        }

        const canvas = this.$sid('canvas')[0];
        if (!canvas) {
            return { width: frame.clientWidth, height: frame.clientHeight };
        }

        const prior_display = canvas.style.display;
        canvas.style.display = 'none';
        const box = { width: frame.clientWidth, height: frame.clientHeight };
        canvas.style.display = prior_display;

        return box;
    }

    /**
     * Fail loud VISUALLY - a rendition-endpoint failure shows a message, never a blank pane.
     */
    _show_error(e) {
        console_debug('preview', 'Pdf_Viewer: failed to render document', e);
        if (this.$ && this.$.exists()) {
            this.$sid('canvas').hide();
            this.$sid('error').show();
        }
    }
}
