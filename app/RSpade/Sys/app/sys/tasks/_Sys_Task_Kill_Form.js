/**
 * _Sys_Task_Kill_Form - the Kill dialog, shared by the Running tab and the task
 * detail screen. See _Sys_Task_Kill_Form.jqhtml.
 */
class _Sys_Task_Kill_Form extends Component {
    /**
     * Ask for an explanation and kill a running task. The kill itself waits for the
     * worker to exit (a 5s grace before SIGKILL), so the dialog's submit spinner can
     * run that long.
     *
     * @param {object} task A running row: {id, class_short, method}
     * @returns {Promise<object|false>} {id, outcome} after a kill; false when cancelled
     */
    static async open(task) {
        const result = await _Sys_Modal.form({
            title: 'Kill task #' + task.id,
            component: '_Sys_Task_Kill_Form',
            component_args: {
                task_id: task.id,
                task_label: task.class_short + '::' + task.method,
            },
            submit_label: 'Kill task',
        });

        if (result) {
            Flash_Alert.success(_Sys_Task_Kill_Form.OUTCOMES[result.outcome] || ('Task ' + result.id + ': ' + result.outcome));
        }

        return result;
    }

    /** Task_Killer::kill()'s outcomes, as a sentence. */
    static OUTCOMES = {
        killed: 'The task was killed.',
        killed_no_process: 'The task had no live process; it is marked killed.',
        recycled: 'The scheduled task was stopped and put back to pending.',
    };
}
