/**
 * _Sys_Dashboard_Action - the control panel's Dashboard screen.
 *
 * A table of contents: two self-loading regions, each with its own on_load and its
 * own loading/error/content states, so the slow health probes never hold up the
 * headline numbers.
 *
 *     _Sys_Dashboard_Summary - the tiles (_Sys_Dashboard_Controller.summary)
 *     _Sys_Dashboard_Health  - the rsx:health report (_Sys_Dashboard_Controller.health),
 *                              with its own "Re-run checks" action
 */
@route('/_sys')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Dashboard')
class _Sys_Dashboard_Action extends Spa_Action {
}
