/**
 * _Sys_Sites_Action - the control panel's Sites screen.
 *
 * A placeholder page: the chrome, the route and the gate are real; the body is
 * one sentence naming what will live here.
 */
@route('/_sys/sites')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Sites')
class _Sys_Sites_Action extends Spa_Action {
}
