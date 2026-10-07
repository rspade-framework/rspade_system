/**
 * _Sys_Task_Workers_Action - the worker pools at work. See the .jqhtml.
 *
 * Refreshes on every Task_List_Changed_Topic frame, through one debounced refresh
 * (Rsx_Task.LIVE_UPDATE_DELAY), so a run starting
 * or finishing moves between the sections without a reload.
 */
@route('/_sys/workers')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Task Workers')
class _Sys_Task_Workers_Action extends Spa_Action {
    /** Display names of the pools. */
    static POOL_LABELS = {
        on_demand: 'On-demand pool',
        scheduled: 'Scheduled pool',
        kill: 'Kill workers',
    };

    on_create() {
        this.data.workers = null;
        this.data.error_data = null;

        const that = this;
        const refresh_soon = debounce(() => that.refresh(), Rsx_Task.LIVE_UPDATE_DELAY);
        this.subscribe('Task_List_Changed_Topic', {}, () => refresh_soon());
    }

    async on_load() {
        try {
            this.data.workers = await _Sys_Tasks_Controller.workers();
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_workers');
        this.$.on('click._sys_workers', '[data-action="refresh"]', function () {
            that.reload();
        });
        this.$.on('click._sys_workers', 'tr[data-task-id], [data-action="open_run"]', function (e) {
            const $element = $(this);
            e.preventDefault();
            _Sys_Task_Detail.open(int($element.attr('data-task-id')));
        });
        _Sys_Task_Actions.on_acted_within(this.$, () => that.reload());
    }
}
