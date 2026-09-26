/**
 * _Sys_Log_View_Action - one log file, read in windows. See the .jqhtml for the layout.
 *
 * The file opens at its TAIL (the last page of lines). "Load earlier" reads the page
 * ending where the loaded range starts and prepends it; Follow polls every 2 seconds
 * for complete lines appended after the loaded range's end. The filter (hash key "q")
 * is applied server-side to every window read; changing it re-reads the tail.
 *
 * Every read is by byte offset, so the loaded range is [state.start, state.end).
 * Each read's entries are mounted as one _Sys_Log_Batch, appended or prepended.
 *
 * Follow stops when the screen stops (on_stop) and pauses while the tab is hidden. A
 * poll answering {rotated: true} - the file was rotated or truncated under us - says
 * so and restarts from the tail. A .gz is never followed.
 */
@route('/_sys/logs/:file')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Log')
class _Sys_Log_View_Action extends Spa_Action {
    /** Lines per read. */
    static PAGE_LINES = 200;

    /** Follow's poll interval, ms. An interval between polls, not a bound on any work. */
    static FOLLOW_INTERVAL_MS = 2000;

    /** Numbers each instance's document-level handler namespace. */
    static _instance_count = 0;

    on_create() {
        this.data.first = null;
        this.data.load_error = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            this.data.first = await _Sys_Logs_Controller.read({
                file: this.args.file,
                lines: _Sys_Log_View_Action.PAGE_LINES,
                filter: Rsx.url_hash_get('q') || '',
            });
        } catch (e) {
            // Plain data: this.data keeps no Error object, so the code and the message
            // are copied out. A not_found code renders the missing-file state.
            if (e.code !== Ajax.ERROR_NOT_FOUND) {
                console.error(e);
            }
            this.data.load_error = { code: e.code || null, message: e.message || String(e) };
        }
        this.data.loading = false;
    }

    page_title() {
        return this.args.file;
    }

    on_ready() {
        const that = this;

        if (!this.data.first) {
            return;
        }

        this._generation = 0;
        this._poll_timer = null;
        this._stopped = false;
        _Sys_Log_View_Action._instance_count++;
        this._visibility_ns = 'visibilitychange._sys_log_view_' + _Sys_Log_View_Action._instance_count;

        this.state.filter = Rsx.url_hash_get('q') || '';
        this.state.following = false;
        this.state.busy = false;
        this._show_window(this.data.first, null);

        this.$.off('click._sys_log_view');

        this.$.on('click._sys_log_view', '[data-action="earlier"]', function () {
            that._load_earlier();
        });

        this.$.on('click._sys_log_view', '[data-action="tail"]', function () {
            that._load_tail(null);
        });

        this.$.on('click._sys_log_view', '[data-action="follow"]', function () {
            that._set_following(!that.state.following);
        });

        const apply_filter = debounce(function () {
            const value = str(that.$sid('filter').val()).trim();

            if (value === that.state.filter) {
                return;
            }

            that.state.filter = value;
            Rsx.url_hash_set_single('q', value);
            that._load_tail(null);
        }, 300);

        this.$sid('filter').off('input._sys_log_view').on('input._sys_log_view', apply_filter);

        $(document).off(this._visibility_ns).on(this._visibility_ns, function () {
            that._paint_status(null);

            if (!document.hidden && that.state.following && that._poll_timer === null) {
                that._poll();
            }
        });
    }

    on_stop() {
        this._stopped = true;

        if (this._poll_timer !== null && this._poll_timer !== undefined) {
            clearTimeout(this._poll_timer);
            this._poll_timer = null;
        }

        if (this._visibility_ns) {
            $(document).off(this._visibility_ns);
        }
    }

    /**
     * Replace everything loaded with one window (a tail read), then scroll to its end.
     *
     * @param {Object} response What _Sys_Logs_Controller.read answered
     * @param {string|null} notice A line to show above the status (rotation)
     */
    _show_window(response, notice) {
        this._generation++;
        this.state.start = response.start;
        this.state.end = response.end;
        this.state.size = response.size;
        this.state.identity = response.identity;
        this.state.lines_loaded = response.line_count;
        this.state.entries_shown = response.entries.length;

        this.$sid('batches').empty();
        this._mount(response.entries, 'append');
        this._paint_status(notice);

        const $pane = this.$sid('pane');
        $pane.scrollTop($pane[0].scrollHeight);
    }

    /**
     * Mount one read's entries as a _Sys_Log_Batch.
     *
     * @param {Array} entries
     * @param {string} where 'append' | 'prepend'
     * @returns {Promise} resolves when the batch is rendered
     */
    _mount(entries, where) {
        if (!entries.length) {
            return Promise.resolve();
        }

        const $host = $('<div>');

        if (where === 'prepend') {
            this.$sid('batches').prepend($host);
        } else {
            this.$sid('batches').append($host);
        }

        return $host.component('_Sys_Log_Batch', { entries: entries, format: this.data.first.format }).ready();
    }

    async _load_tail(notice) {
        if (this.state.busy) {
            return;
        }

        this.state.busy = true;
        this._paint_status(null);

        try {
            const response = await _Sys_Logs_Controller.read({
                file: this.args.file,
                lines: _Sys_Log_View_Action.PAGE_LINES,
                filter: this.state.filter,
            });

            if (!this._stopped) {
                this._show_window(response, notice);
            }
        } finally {
            this.state.busy = false;
            this._paint_status(notice);
        }
    }

    async _load_earlier() {
        if (this.state.busy || this.state.start <= 0) {
            return;
        }

        this.state.busy = true;
        this._paint_status(null);
        const generation = this._generation;

        try {
            const response = await _Sys_Logs_Controller.read({
                file: this.args.file,
                before: this.state.start,
                lines: _Sys_Log_View_Action.PAGE_LINES,
                filter: this.state.filter,
            });

            if (this._stopped || generation !== this._generation) {
                return;
            }

            // Keep the lines the reader was looking at where they are on screen.
            const $pane = this.$sid('pane');
            const before_height = $pane[0].scrollHeight;
            await this._mount(response.entries, 'prepend');
            $pane.scrollTop($pane.scrollTop() + ($pane[0].scrollHeight - before_height));

            this.state.start = response.start;
            this.state.lines_loaded += response.line_count;
            this.state.entries_shown += response.entries.length;
        } finally {
            this.state.busy = false;
            this._paint_status(null);
        }
    }

    _set_following(on) {
        this.state.following = on;

        this.$sid('follow').toggleClass('btn-primary', on).toggleClass('btn-secondary', !on)
            .attr('aria-pressed', on ? 'true' : 'false');

        if (on) {
            this._poll();
        } else if (this._poll_timer !== null) {
            clearTimeout(this._poll_timer);
            this._poll_timer = null;
        }

        this._paint_status(null);
    }

    /**
     * One Follow poll, then the next one scheduled. A poll that came back with a
     * full page (more) runs the next at once; a hidden tab schedules nothing - the
     * visibility handler resumes it.
     */
    async _poll() {
        this._poll_timer = null;

        if (this._stopped || !this.state.following || document.hidden) {
            return;
        }

        const generation = this._generation;
        let more = false;

        try {
            const response = await _Sys_Logs_Controller.follow({
                file: this.args.file,
                offset: this.state.end,
                identity: this.state.identity,
                lines: _Sys_Log_View_Action.PAGE_LINES,
                filter: this.state.filter,
            });

            if (this._stopped || generation !== this._generation) {
                return;
            }

            if (response.rotated) {
                await this._load_tail('The file was rotated or truncated; showing its new tail.');
            } else if (response.end > this.state.end) {
                const $pane = this.$sid('pane');
                const at_bottom = $pane[0].scrollHeight - $pane.scrollTop() - $pane.innerHeight() < 40;

                await this._mount(response.entries, 'append');
                this.state.end = response.end;
                this.state.size = response.size;
                this.state.lines_loaded += response.line_count;
                this.state.entries_shown += response.entries.length;
                this._paint_status(null);

                if (at_bottom) {
                    $pane.scrollTop($pane[0].scrollHeight);
                }

                more = response.more;
            }
        } catch (e) {
            console.error(e);
            this._set_following(false);
            Flash_Alert.error('Follow stopped: ' + (e.message || String(e)));
            return;
        }

        if (!this._stopped && this.state.following && this._poll_timer === null) {
            const that = this;
            this._poll_timer = setTimeout(function () {
                that._poll();
            }, more ? 0 : _Sys_Log_View_Action.FOLLOW_INTERVAL_MS);
        }
    }

    /**
     * The line under the toolbar: the loaded range and what the filter kept.
     */
    _paint_status(notice) {
        const s = this.state;
        const parts = [];

        parts.push(s.lines_loaded + ' line' + (s.lines_loaded === 1 ? '' : 's') + ' loaded');
        parts.push('bytes ' + s.start.toLocaleString() + ' - ' + s.end.toLocaleString()
            + (s.size !== null && s.size !== undefined ? ' of ' + s.size.toLocaleString() : ''));

        if (s.filter) {
            parts.push(s.entries_shown + ' match "' + s.filter + '"');
        }

        if (s.following) {
            parts.push(document.hidden ? 'following (paused while hidden)' : 'following');
        }

        if (s.busy) {
            parts.push('loading...');
        }

        this.$sid('status').text(parts.join(' | '));
        this.$sid('notice').text(notice || '').toggle(!!notice);
        this.$sid('earlier').toggle(s.start > 0);
        this.$sid('earlier').find('button').prop('disabled', !!s.busy);
        this.$sid('bof').toggle(s.start <= 0);
        this.$sid('empty').toggle(s.entries_shown === 0);
    }
}
