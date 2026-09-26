/**
 * _Sys_Tasks_Action - the control panel's Tasks screen.
 *
 * A table of contents over three self-loading tabs (see the .jqhtml). The one thing
 * the shell owns is the link between them: a kill on the Running tab changes a row the
 * history grid may be showing, so it reloads the grid; a Run now on the Schedules tab
 * adds a row, so it reloads the grid and the Running tab.
 *
 * Addressable state: #tab=running|history|schedules, plus the grid's tasks_* keys - the
 * Dashboard links here as {tab: 'history', tasks_f_status: 'failed', tasks_f_since: '24h'}
 * and {tab: 'running'}.
 */
@route('/_sys/tasks')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Tasks')
class _Sys_Tasks_Action extends Spa_Action {
    on_ready() {
        const that = this;
        const running = this.sid('running');

        running.on('tasks_changed', function () {
            that.sid('history').reload();
        });

        this.sid('schedules').on('tasks_changed', function () {
            that.sid('history').reload();
            that.sid('running').reload();
        });
    }
}
