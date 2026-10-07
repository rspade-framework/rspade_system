/**
 * _Sys_Task_View_Action - one run as a page: the same three-column composite the run dialog
 * shows (_Sys_Task_Detail), under the run's heading. Each column follows the run live.
 */
@route('/_sys/tasks/:id')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Task')
class _Sys_Task_View_Action extends Spa_Action {
    page_title() {
        return 'Task #' + this.args.id;
    }
}
