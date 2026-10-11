/**
 * _Sys_Task_System_Notice - see the .jqhtml.
 *
 * Every Task_List_Changed_Topic frame refetches through one debounced refresh
 * (Rsx_Task.LIVE_UPDATE_DELAY); refresh() repaints only when the answer changed.
 */
class _Sys_Task_System_Notice extends Component {
    on_create() {
        this.data.state = null;

        const refresh_soon = debounce(() => this.refresh(), Rsx_Task.LIVE_UPDATE_DELAY);
        this.subscribe('Task_List_Changed_Topic', {}, () => refresh_soon());
    }

    async on_load() {
        this.data.state = await _Sys_Tasks_Controller.system_state();
    }
}
