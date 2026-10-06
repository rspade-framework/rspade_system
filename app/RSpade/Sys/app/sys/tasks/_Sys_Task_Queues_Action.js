/**
 * _Sys_Task_Queues_Action - the queue labels in use and the worker pool that drains them.
 * See the .jqhtml for what each figure means.
 *
 * Self-loading (_Sys_Tasks_Controller.queues); Refresh reloads the screen.
 */
@route('/_sys/queues')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Task Queues')
class _Sys_Task_Queues_Action extends Spa_Action {
    on_create() {
        this.data.pool = null;
        this.data.queues = [];
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Tasks_Controller.queues();
            this.data.pool = response.pool;
            this.data.queues = response.queues;
        } catch (e) {
            // The MESSAGE: this.data keeps plain data, and an Error's message would not survive.
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_task_queues').on('click._sys_task_queues', '[data-action="refresh"]', function () {
            that.reload();
        });
    }
}
