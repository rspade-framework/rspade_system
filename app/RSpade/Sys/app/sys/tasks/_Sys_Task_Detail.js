/**
 * _Sys_Task_Detail - see the .jqhtml.
 */
class _Sys_Task_Detail extends Component {
    /**
     * Show one run in a dialog. Resolves when the dialog closes.
     *
     * @param {number} task_id
     * @returns {Promise<boolean>}
     */
    static async open(task_id) {
        const $body = $('<div>');
        $body.component('_Sys_Task_Detail', { task_id: int(task_id) });

        return _Sys_Modal.show({
            title: 'Task #' + task_id,
            body: $body,
            max_width: 1600,
            // At most 1075px tall, and 80% of the window (90% below 1024px wide); the
            // detail fills the body (_Sys_Task_Detail.scss).
            height: 'min(1075px, 80vh)',
            mobile_height: 'min(1075px, 90vh)',
            buttons: [
                { label: 'Open as page', value: false, class: 'btn-secondary', callback: () => Spa.dispatch(Rsx.Route('_Sys_Task_View_Action', int(task_id))) },
                { label: 'Close', value: true, class: 'btn-primary', default: true },
            ],
        });
    }
}
