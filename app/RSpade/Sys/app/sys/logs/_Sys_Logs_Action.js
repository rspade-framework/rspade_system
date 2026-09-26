/**
 * _Sys_Logs_Action - the control panel's Logs screen: the log directory's files, each
 * row opening the viewer (_Sys_Log_View_Action). See the .jqhtml.
 */
@route('/_sys/logs')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Logs')
class _Sys_Logs_Action extends Spa_Action {
}
