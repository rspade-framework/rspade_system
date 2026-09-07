/**
 * _Sys_Debug_Flags_Action - the control panel's Debug Flags screen.
 *
 * A placeholder page: the chrome, the route and the gate are real; the body is
 * one sentence naming what will live here.
 */
@route('/_sys/debug-flags')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Debug Flags')
class _Sys_Debug_Flags_Action extends Spa_Action {
}
