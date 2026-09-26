/**
 * _Sys_Task_View_Action - one _tasks row. See the .jqhtml for the layout.
 *
 * Kill opens the shared dialog (_Sys_Task_Kill_Form.open) and reloads the record;
 * Re-dispatch confirms, dispatches the same service/method/params/queue as a NEW row
 * (_Sys_Tasks_Controller.redispatch) and navigates to that row. The server decides
 * which of the two apply (can_kill / can_redispatch) and refuses the rest.
 */
@route('/_sys/tasks/:id')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Task')
class _Sys_Task_View_Action extends Spa_Action {
    on_create() {
        this.data.task = null;
        this.data.load_error = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Tasks_Controller.detail({ id: this.args.id });
            this.data.task = response.task;
        } catch (e) {
            // Plain data: this.data keeps no Error object, so the code and the message
            // are copied out. A not_found code renders the missing-record state.
            if (e.code !== Ajax.ERROR_NOT_FOUND) {
                console.error(e);
            }
            this.data.load_error = { code: e.code || null, message: e.message || String(e) };
        }
        this.data.loading = false;
    }

    page_title() {
        return 'Task #' + this.args.id;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_task_view');

        this.$.on('click._sys_task_view', '[data-action="kill"]', async function () {
            if (await _Sys_Task_Kill_Form.open(that.data.task)) {
                that.reload();
            }
        });

        this.$.on('click._sys_task_view', '[data-action="redispatch"]', async function () {
            const task = that.data.task;
            const confirmed = await _Sys_Modal.confirm(
                'Re-dispatch task #' + task.id + '?',
                task.class_short + '::' + task.method + ' is queued again as a new task, with the same params and queue. This task is left as it is.',
                'Re-dispatch'
            );

            if (!confirmed) {
                return;
            }

            const result = await _Sys_Tasks_Controller.redispatch({ id: task.id });
            Flash_Alert.success('Dispatched as task #' + result.id + '.');
            Spa.dispatch(Rsx.Route('_Sys_Task_View_Action', result.id));
        });
    }
}
