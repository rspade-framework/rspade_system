/**
 * _Sys_Task_Action_Form - the confirmation dialog for a lifecycle action on a run, shared by
 * the grids and the run detail. See _Sys_Task_Action_Form.jqhtml.
 */
class _Sys_Task_Action_Form extends Component {
    /** What each action does, as the dialog says it. */
    static DESCRIPTIONS = {
        stop: 'Ask the task to stop at its next stop check. Nothing is killed: a task that never checks runs to completion.',
        force_stop: 'Ask the task to stop, and kill its worker if it is still running when the grace period ends.',
        force_kill: 'Kill the task\'s worker now. Whatever the task was doing is cut off mid-step.',
        cancel: 'Take the queued run off the queue. It never runs.',
        rerun: 'Dispatch the same task with the same parameters again, as a new run.',
    };

    /** Each action's button label and dialog title. */
    static LABELS = {
        stop: 'Graceful stop',
        force_stop: 'Force stop',
        force_kill: 'Force kill',
        cancel: 'Cancel',
        rerun: 'Run again',
    };

    /**
     * Confirm and perform an action on a run.
     *
     * @param {object} task The run: {id, name}
     * @param {string} action stop | force_stop | force_kill | cancel | rerun
     * @returns {Promise<object|false>} {task, rerun_id} after the action; false when dismissed
     */
    static async open(task, action) {
        const result = await _Sys_Modal.form({
            title: _Sys_Task_Action_Form.LABELS[action] + ' - task #' + task.id + ' (' + task.name + ')',
            component: '_Sys_Task_Action_Form',
            component_args: { task_id: task.id, action: action },
            submit_label: _Sys_Task_Action_Form.LABELS[action],
        });

        if (result) {
            if (action === 'rerun') {
                Flash_Alert.success('Task #' + task.id + ' runs again as task #' + result.rerun_id + '.');
            } else {
                Flash_Alert.success(_Sys_Task_Action_Form.LABELS[action] + ': task #' + task.id + '.');
            }
        }

        return result;
    }
}
