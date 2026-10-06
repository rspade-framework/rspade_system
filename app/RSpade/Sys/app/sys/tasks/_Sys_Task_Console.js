/**
 * _Sys_Task_Console - one task's log, live, in an xterm.js terminal. See the .jqhtml.
 *
 * APPEND-ONLY, SO IMPERATIVE. A terminal is scrollback the reader is looking at; re-rendering
 * the component to show a new line would throw that away. So the template draws the frame
 * once and new lines are WRITTEN to the terminal: the log is read from the offset already
 * shown (_Sys_Tasks_Controller.logs), and each Task_Changed_Topic frame - "the row changed" -
 * asks again from there. The offset makes a repeated read harmless, which is what lets the
 * subscription's resync callback and the first read overlap.
 *
 * One read at a time: a frame that arrives while a read is in flight marks one more read to
 * run after it, so a chatty task costs at most one read behind the one in flight.
 *
 * Fires 'status_changed' {status} when the row's status moves after the first read (the
 * detail screen reloads its facts on it).
 */
class _Sys_Task_Console extends Component {
    /** ANSI SGR colour per log level: info cyan, error red, debug grey, captured stdout yellow. */
    static LEVEL_COLORS = {
        info: '36',
        error: '31',
        debug: '90',
        output: '33',
    };

    on_create() {
        if (!this.args.task_id) {
            throw new Error('_Sys_Task_Console requires $task_id');
        }

        this.state = {
            offset: 0,
            run: null,
            status: null,
            last_heartbeat_at: null,
            live: !!window.rsxapp?.realtime_url,
        };
        this._reading = false;
        this._read_again = false;
    }

    on_ready() {
        const that = this;

        this._terminal = new _Sys_Xterm_Terminal({
            convertEol: true,
            disableStdin: true,
            cursorStyle: 'bar',
            cursorInactiveStyle: 'none',
            fontFamily: getComputedStyle(document.documentElement).getPropertyValue('--rsx-mono').trim() || 'monospace',
            fontSize: 12.5,
            scrollback: 1000,
            theme: { background: '#0d1117', foreground: '#d0d7de' },
        });
        this._fit = new _Sys_Xterm_Fit_Addon();
        this._terminal.loadAddon(this._fit);
        this._terminal.open(this.$sid('screen')[0]);
        this._fit.fit();

        this._resize_observer = new ResizeObserver(() => that._fit.fit());
        this._resize_observer.observe(this.$sid('screen')[0]);

        this.$.off('click._sys_task_console').on('click._sys_task_console', '[data-action="refresh"]', function () {
            that.read();
        });

        this.read();
        this.subscribe('Task_Changed_Topic', { id: int(this.args.task_id) }, () => that.read());
    }

    /**
     * Read the log from the offset already written, and write what is new.
     */
    async read() {
        if (this._reading) {
            this._read_again = true;
            return;
        }

        this._reading = true;
        try {
            do {
                this._read_again = false;
                const response = await _Sys_Tasks_Controller.logs({
                    id: this.args.task_id,
                    offset: this.state.offset,
                    run: this.state.run,
                });
                this.__write(response);
            } while (this._read_again);
        } finally {
            this._reading = false;
        }
    }

    __write(response) {
        if (response.restart) {
            this._terminal.reset();
        }

        // Scrollback is sized to the log, so the whole log stays reachable however long it is.
        const needed = response.offset + this._terminal.rows;
        if (this._terminal.options.scrollback < needed) {
            this._terminal.options.scrollback = needed;
        }

        for (const line of response.lines) {
            this._terminal.writeln(_Sys_Task_Console.colorize(line));
        }

        const previous = this.state.status;
        this.state.offset = response.offset;
        this.state.run = response.run;
        this.state.status = response.status;
        this.state.last_heartbeat_at = response.last_heartbeat_at;
        this.__paint_status();

        if (previous !== null && previous !== response.status) {
            this.trigger('status_changed', { status: response.status });
        }
    }

    __paint_status() {
        const parts = [this.state.status, this.state.offset + (this.state.offset === 1 ? ' line' : ' lines')];
        if (this.state.status === 'running') {
            parts.push(this.state.last_heartbeat_at
                ? 'heartbeat ' + Rsx_Time.relative(this.state.last_heartbeat_at)
                : 'no heartbeat');
        }
        this.$sid('status').text(parts.join(' - '));
    }

    on_stop() {
        if (this._resize_observer) {
            this._resize_observer.disconnect();
        }
        if (this._terminal) {
            this._terminal.dispose();
        }
    }

    /**
     * A log line as Task_Instance::log() writes it - "[Y-m-d H:i:s] [level] message" - with
     * ANSI colour: the timestamp dimmed, the level coloured, an error's message red. A line
     * of any other shape (a multi-line message's continuation) is written as it is.
     *
     * @param {string} line
     * @returns {string}
     */
    static colorize(line) {
        const match = /^\[([^\]]+)\] \[([a-z]+)\] ?(.*)$/s.exec(line);
        if (!match) {
            return line;
        }

        const level_color = _Sys_Task_Console.LEVEL_COLORS[match[2]] || '37';
        const message = match[2] === 'error' ? '\x1b[31m' + match[3] + '\x1b[0m' : match[3];

        return '\x1b[90m' + match[1] + '\x1b[0m \x1b[' + level_color + 'm' + match[2].padEnd(6) + '\x1b[0m ' + message;
    }
}
