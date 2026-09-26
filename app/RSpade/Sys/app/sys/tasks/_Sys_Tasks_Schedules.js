/**
 * _Sys_Tasks_Schedules - the Schedules tab. See _Sys_Tasks_Schedules.jqhtml.
 *
 * Three states: loading, error, the table (or its empty state). Run now confirms,
 * dispatches the schedule as a one-shot (_Sys_Tasks_Controller.run_schedule_now -
 * the tracker's next run is untouched), flashes the outcome with a link to the row,
 * and fires "tasks_changed" so the screen refreshes the tabs that show it.
 */
class _Sys_Tasks_Schedules extends Component {
    on_create() {
        this.data.rows = [];
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Tasks_Controller.schedules();
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

        this.$.off('click._sys_tasks_schedules');

        this.$.on('click._sys_tasks_schedules', '[data-action="refresh"]', function () {
            that.reload();
        });

        this.$.on('click._sys_tasks_schedules', '[data-action="run_now"]', async function () {
            const $element = $(this);
            const row = that.data.rows[int($element.attr('data-index'))];
            const name = row.class_short + '::' + row.method;

            const confirmed = await _Sys_Modal.confirm(
                'Run ' + name + ' now?',
                'It is dispatched once now, on the "' + row.queue + '" queue, as a separate task. ' +
                    'The schedule is not changed: its next run stays as it is.',
                'Run now'
            );

            if (!confirmed) {
                return;
            }

            const result = await _Sys_Tasks_Controller.run_schedule_now({ class: row.class, method: row.method });
            const link = '<a href="' + escape_html(Rsx.Route('_Sys_Task_View_Action', result.id)) + '">task #' + result.id + '</a>';

            if (result.outcome === 'coalesced') {
                Flash_Alert.info(
                    escape_html(name) + ' is ' + escape_html(result.concurrency) +
                        ' and already has a queued run (' + link + '); nothing new was queued.'
                );
            } else {
                Flash_Alert.success('Dispatched ' + escape_html(name) + ' as ' + link + '.');
            }

            that.trigger('tasks_changed');
        });
    }
}
