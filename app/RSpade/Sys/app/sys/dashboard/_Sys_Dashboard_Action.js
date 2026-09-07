/**
 * _Sys_Dashboard_Action - the control panel's Dashboard screen.
 *
 * A placeholder page: the chrome, the route and the gate are real; the body is
 * one sentence naming what will live here.
 */
@route('/_sys')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Dashboard')
class _Sys_Dashboard_Action extends Spa_Action {
}
