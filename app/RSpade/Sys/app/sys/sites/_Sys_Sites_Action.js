/**
 * _Sys_Sites_Action - the control panel's Sites screen: the tenant grid. See the .jqhtml.
 *
 * Addressable state: the grid's sites_* hash keys - {sites_f_enabled: 'disabled'} lists
 * the disabled sites.
 */
@route('/_sys/sites')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Sites')
class _Sys_Sites_Action extends Spa_Action {
}
