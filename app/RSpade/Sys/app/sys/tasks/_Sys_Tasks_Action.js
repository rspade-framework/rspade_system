/**
 * _Sys_Tasks_Action - the control panel's Tasks screen.
 *
 * A placeholder page: the chrome, the route and the gate are real; the body is
 * one sentence naming what will live here.
 */
@route('/_sys/tasks')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Tasks')
class _Sys_Tasks_Action extends Spa_Action {
}
