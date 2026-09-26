/**
 * _Sys_Users_Action - the control panel's Users screen: the login-identity grid. See
 * the .jqhtml.
 *
 * Addressable state: the grid's users_* hash keys - {users_f_developer: 'yes'} lists the
 * developers, {users_f_status: 'suspended'} the suspended identities.
 */
@route('/_sys/users')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Users')
class _Sys_Users_Action extends Spa_Action {
}
