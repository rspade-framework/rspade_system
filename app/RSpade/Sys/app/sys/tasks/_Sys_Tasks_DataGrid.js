/**
 * _Sys_Tasks_DataGrid - see the .jqhtml. Adds to the panel grid:
 *
 *   - LIVE RELOADS. Task_List_Changed_Topic (a run queued, started or finished) and
 *     Task_Changed_Topic for each RUNNING row on the page (its reports) all call one
 *     debounced reload - debounce(reload, Rsx_Task.LIVE_UPDATE_DELAY), like every task view:
 *     the first frame reloads at once, frames during a reload or the 250ms after it coalesce
 *     into ONE follow-up reload, and reloads never overlap. A live reload repaints directly,
 *     without the grid's loading dim (reload({dim: false})). The per-row watches are renewed
 *     after every load, for the rows that page shows running.
 *   - "Pause live updates": while checked, frames are ignored; clearing it reloads once.
 *   - "Play the sample task", the row click that opens a run, and a reload after an action
 *     taken from a row.
 */
class _Sys_Tasks_DataGrid extends _Sys_DataGrid_Abstract {
    on_create() {
        super.on_create();

        const that = this;
        this.state.live_paused = false;
        this._row_watches = [];
        this._live_reload = debounce(() => that.reload({ dim: false }), Rsx_Task.LIVE_UPDATE_DELAY);

        this.subscribe('Task_List_Changed_Topic', {}, () => that.__on_frame());
    }

    on_ready() {
        super.on_ready();

        const that = this;

        this.$.off('click._sys_tasks_rows').on('click._sys_tasks_rows', 'tr[data-task-id]', function () {
            const $element = $(this);
            _Sys_Task_Detail.open(int($element.attr('data-task-id')));
        });

        this.$.off('click._sys_sample').on('click._sys_sample', '[data-action="start_sample"]', async function () {
            const result = await _Sys_Tasks_Controller.start_sample();
            that.reload();
            _Sys_Task_Detail.open(result.task_id);
        });

        this.$.off('change._sys_pause').on('change._sys_pause', '[data-action="pause_live"]', function () {
            const $element = $(this);
            that.state.live_paused = $element.is(':checked');
            if (!that.state.live_paused) {
                that._live_reload();
            }
        });

        this.on('grid_loaded', function () {
            _Sys_Task_Actions.on_acted_within(that.$, () => that.reload());
            that.__watch_running_rows();
        });
    }

    /** A realtime frame: reload (debounced, undimmed) unless live updates are paused. */
    __on_frame() {
        if (this.state.live_paused) {
            return;
        }

        this._live_reload();
    }

    /** Watch each RUNNING row on this page for its reports; drop the previous page's watches. */
    __watch_running_rows() {
        const that = this;

        this.__stop_row_watches();

        this.$.find('tr[data-task-id]').each(function () {
            const $element = $(this);
            const $badge = $element.find('.Task_Status_Badge__badge--active');
            if ($badge.length) {
                that._row_watches.push(Rsx_Task.watch(int($element.attr('data-task-id')), (data, meta) => {
                    if (!meta.resync) {
                        that.__on_frame();
                    }
                }));
            }
        });
    }

    /** Rsx_Task.watch() resolves its handle, so each kept promise is stopped once it settles. */
    __stop_row_watches() {
        for (const watch of this._row_watches) {
            watch.then((handle) => handle.stop());
        }
        this._row_watches = [];
    }

    on_stop() {
        this.__stop_row_watches();
    }
}
