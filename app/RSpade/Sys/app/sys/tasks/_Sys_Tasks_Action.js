/**
 * _Sys_Tasks_Action - the control panel's Tasks screen.
 *
 * A table of contents over two self-loading tabs (see the .jqhtml). The grid follows the
 * runs live; the one link the shell owns is the Schedules tab's Run now, which queues a run,
 * so it reloads the grid at once.
 *
 * Addressable state: #tab=tasks|schedules, plus the grid's keys - the Dashboard's
 * failed-tasks tile links here as {tab: 'tasks', tasks_f_status: 4, tasks_f_since: '24h'}.
 */
@route('/_sys/tasks')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Tasks')
class _Sys_Tasks_Action extends Spa_Action {
    on_ready() {
        const that = this;

        this.sid('schedules').on('tasks_changed', function () {
            that.sid('tasks').reload();
        });
    }
}
