/**
 * _Sys_Portal_Users_Action - the control panel's Portal Users screen: the portal-user grid.
 * See the .jqhtml.
 *
 * Addressable state: the grid's portal_users_* hash keys - {portal_users_f_site: 3} lists
 * one site's portal users, {portal_users_f_status: 'suspended'} the suspended ones.
 */
@route('/_sys/portal-users')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Portal Users')
class _Sys_Portal_Users_Action extends Spa_Action {
}
