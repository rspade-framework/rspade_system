/**
 * _Sys_Logs_Action - the control panel's Logs screen.
 *
 * A placeholder page: the chrome, the route and the gate are real; the body is
 * one sentence naming what will live here.
 */
@route('/_sys/logs')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Logs')
class _Sys_Logs_Action extends Spa_Action {
}
