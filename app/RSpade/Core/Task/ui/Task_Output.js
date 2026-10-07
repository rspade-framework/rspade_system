/**
 * Task_Output - a run's output, live, in an xterm.js terminal. See the .jqhtml.
 *
 * APPEND-ONLY, SO IMPERATIVE. A terminal is scrollback the reader is looking at; re-rendering
 * to show a new line would throw that away. So the template draws the frame once and new
 * lines are WRITTEN to the terminal: output is read from the last line id already shown
 * (Rsx_Task.output_after), and each Task_Output_Topic frame asks again from there. The cursor
 * makes a repeated read harmless, which is what lets the subscription's resync callback and
 * the first read overlap.
 *
 * Every read goes through ONE debounce(read, Rsx_Task.LIVE_UPDATE_DELAY), like every task
 * view: the first frame reads at once, frames during a read or the delay after it coalesce
 * into one follow-up read, and reads never overlap.
 */
class Task_Output extends Component {
    /** stdout: plain white. stderr and operator lines: bold amber. */
    static STREAM_STYLE = {
        stdout: '\x1b[0;37m',
        stderr: '\x1b[1;38;5;214m',
        operator: '\x1b[1;38;5;214m',
    };

    /**
     * The terminal's typeface: the framework's monospace stack (the rsx-text-mono mixin), stated
     * here because xterm draws on a canvas and never reads the page's CSS.
     */
    static FONT_FAMILY = 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", "Courier New", monospace';

    /** The library, loaded once per page on first use. */
    static _xterm_promise = null;

    /**
     * Load xterm.js and its fit addon from the framework's module routes (both realms).
     * ?v=build_key busts the long cache on a new deployment. The bare import() is deliberate:
     * see Pdf_Viewer._load_pdfjs().
     */
    static _load_xterm() {
        if (Task_Output._xterm_promise) {
            return Task_Output._xterm_promise;
        }

        const version = window.rsxapp && window.rsxapp.build_key ? window.rsxapp.build_key : '';
        const module_url = Rsx_Portal.internal_url(Rsx.Route('Rsx_Task_Controller::xterm_module')) + '?v=' + urlencode(version);
        const fit_url = Rsx_Portal.internal_url(Rsx.Route('Rsx_Task_Controller::xterm_fit_module')) + '?v=' + urlencode(version);

        Task_Output._xterm_promise = Promise.all([import(module_url), import(fit_url)]).then((modules) => {
            return { Terminal: modules[0].Terminal, FitAddon: modules[1].FitAddon };
        });

        return Task_Output._xterm_promise;
    }

    on_create() {
        if (!this.args.task_id) {
            throw new Error('Task_Output requires $task_id');
        }

        const streams = this.args.streams || 'both';
        if (!['both', 'stdout', 'stderr'].includes(streams)) {
            throw new Error('Task_Output: $streams must be both, stdout or stderr, got "' + streams + '"');
        }

        this.state = {
            streams: streams,
            last_id: null,
            lines: 0,
            is_live: null,
        };
        this._stopped = false;
        // The time of every line written, in order: line N of the terminal is entry N.
        this._line_times = [];
        this._read_soon = debounce(() => this.__read(), Rsx_Task.LIVE_UPDATE_DELAY);
    }

    async on_ready() {
        const that = this;

        this.$.off('click.task_output').on('click.task_output', '[data-streams]', function () {
            that.show_streams($(this).attr('data-streams'));
        }).on('click.task_output', '[data-action="refresh"]', function () {
            that.read();
        });

        const xterm = await Task_Output._load_xterm();
        if (this._stopped) {
            return;
        }

        this._terminal = new xterm.Terminal({
            convertEol: true,
            disableStdin: true,
            cursorStyle: 'bar',
            cursorInactiveStyle: 'none',
            fontFamily: Task_Output.FONT_FAMILY,
            fontSize: 12.5,
            scrollback: 1000,
            theme: { background: '#000000', foreground: '#e6e6e6' },
        });
        this._fit = new xterm.FitAddon();
        this._terminal.loadAddon(this._fit);
        this._terminal.open(this.$sid('screen')[0]);
        this._fit.fit();

        this._resize_observer = new ResizeObserver(() => that._fit.fit());
        this._resize_observer.observe(this.$sid('screen')[0]);

        // Hovering a line shows when it was written, as the row's title.
        this.$sid('screen').off('mousemove.task_output').on('mousemove.task_output', function (event) {
            that.__title_row_at(event.clientX, event.clientY);
        });

        this.read();
        this.subscribe('Task_Output_Topic', { id: int(this.args.task_id) }, () => that.read());
    }

    /**
     * Title the terminal with the time of the line under the pointer. xterm's rows ignore the
     * pointer (the screen element above them receives it), so the row is found by position and
     * the title goes on the screen element. A row is one screen line, so the buffer line under
     * it is walked back over its wrapped continuations to the line's start, and that line's
     * position among the logical (unwrapped) lines is the index of the output entry it shows.
     */
    __title_row_at(x, y) {
        if (!this._terminal) {
            return;
        }

        const $screen = this.$sid('screen').find('.xterm-screen');
        const rows = this.$sid('screen').find('.xterm-rows').children().toArray();
        const index = rows.findIndex((row) => {
            const rect = row.getBoundingClientRect();
            return y >= rect.top && y < rect.bottom;
        });
        if (index < 0) {
            $screen.attr('title', '');
            return;
        }

        const buffer = this._terminal.buffer.active;
        let line = buffer.viewportY + index;
        while (line > 0 && buffer.getLine(line) && buffer.getLine(line).isWrapped) {
            line--;
        }

        let entry = 0;
        for (let i = 0; i < line; i++) {
            if (!buffer.getLine(i).isWrapped) {
                entry++;
            }
        }

        const at = this._line_times[entry];
        $screen.attr('title', at ? Task_Output.format_line_time(at) : '');
    }

    /** A line's time as its hover title shows it: date and time to the millisecond. */
    static format_line_time(at) {
        return Rsx_Time.format_in_timezone(at, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            fractionalSecondDigits: 3,
            hour12: true,
        });
    }

    /**
     * Show only stdout, only stderr, or both: the terminal is cleared and the output read again
     * from the start through the new filter.
     *
     * @param {string} streams both | stdout | stderr
     */
    show_streams(streams) {
        if (streams === this.state.streams) {
            return;
        }

        this.state.streams = streams;
        this.state.last_id = null;
        this.state.lines = 0;
        this.$.find('[data-streams]').each(function () {
            const $button = $(this);
            $button.toggleClass('Task_Output__filter_button--active', $button.attr('data-streams') === streams);
        });

        this._line_times = [];
        if (this._terminal) {
            this._terminal.reset();
            this.read();
        }
    }

    /**
     * Read the output after the last line written, and write what is new - debounced
     * (Rsx_Task.LIVE_UPDATE_DELAY). Resolves when that read has run.
     */
    read() {
        return this._read_soon();
    }

    /**
     * One read: every page after the last line written. Output read for a stream filter the
     * reader has since changed is dropped (show_streams() has already reset the terminal).
     */
    async __read() {
        if (!this._terminal) {
            return;
        }

        let more = true;
        while (more && !this._stopped) {
            const asked_streams = this.state.streams;
            const streams = asked_streams === 'both' ? ['stdout', 'stderr', 'operator'] : (asked_streams === 'stderr' ? ['stderr', 'operator'] : ['stdout']);
            const response = await Rsx_Task.output_after(this.args.task_id, this.state.last_id, streams);
            if (this._stopped || asked_streams !== this.state.streams) {
                return;
            }
            this.__write(response);
            more = response.more;
        }
    }

    __write(response) {
        // Scrollback is sized to the output, so all of it stays reachable however long it is.
        const needed = this.state.lines + response.lines.length + this._terminal.rows;
        if (this._terminal.options.scrollback < needed) {
            this._terminal.options.scrollback = needed;
        }

        for (const entry of response.lines) {
            this._terminal.writeln(Task_Output.STREAM_STYLE[entry.stream] + entry.line + '\x1b[0m');
            this._line_times.push(entry.at);
        }

        this.state.lines += response.lines.length;
        this.state.last_id = response.last_id;
        this.state.is_live = response.is_live;
        this.$sid('status').text(this.state.lines + (this.state.lines === 1 ? ' line' : ' lines'));
    }

    on_stop() {
        this._stopped = true;
        if (this._resize_observer) {
            this._resize_observer.disconnect();
        }
        if (this._terminal) {
            this._terminal.dispose();
        }
    }
}
