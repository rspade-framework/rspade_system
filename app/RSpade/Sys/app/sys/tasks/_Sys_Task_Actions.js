/**
 * _Sys_Task_Actions - see the .jqhtml.
 */
class _Sys_Task_Actions extends Component {
    /** Bootstrap button tone per action. */
    static BUTTON_TONES = {
        stop: 'secondary',
        force_stop: 'warning',
        force_kill: 'danger',
        cancel: 'secondary',
        rerun: 'primary',
    };

    /**
     * Call $callback after an action taken from any _Sys_Task_Actions inside $container - for a
     * grid or list that hosts one per row. Call it again after each repaint: a repaint builds
     * new components.
     *
     * @param {jQuery} $container
     * @param {Function} callback
     */
    static on_acted_within($container, callback) {
        $container.find('._Sys_Task_Actions').each(function () {
            const $element = $(this);
            $element.component().on('acted', callback);
        });
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_task_actions').on('click._sys_task_actions', '[data-task-action]', async function (e) {
            const $element = $(this);
            // A grid row opens the run detail on click; an action button is not that click.
            e.stopPropagation();

            const action = $element.attr('data-task-action');
            const result = await _Sys_Task_Action_Form.open(that.args.task, action);
            if (result) {
                that.trigger('acted', { action: action, result: result });
            }
        });
    }
}
