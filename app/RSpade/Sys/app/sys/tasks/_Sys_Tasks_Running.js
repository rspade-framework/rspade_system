/**
 * _Sys_Tasks_Running - the Running tab. See _Sys_Tasks_Running.jqhtml.
 *
 * Three states: loading, error, the table (or its empty state). Fires "tasks_changed"
 * after a kill, so the screen can refresh the history beside it.
 */
class _Sys_Tasks_Running extends Component {
    on_create() {
        this.data.rows = [];
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Tasks_Controller.running();
            this.data.rows = response.rows;
        } catch (e) {
            // The MESSAGE: this.data keeps plain data, and an Error's message would not survive.
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_tasks_running');

        this.$.on('click._sys_tasks_running', '[data-action="refresh"]', function () {
            that.reload();
        });

        this.$.on('click._sys_tasks_running', '[data-action="kill"]', async function () {
            const $element = $(this);
            const id = int($element.attr('data-task-id'));
            const task = that.data.rows.find((row) => row.id === id);

            if (await _Sys_Task_Kill_Form.open(task)) {
                that.trigger('tasks_changed');
                that.reload();
            }
        });
    }
}
