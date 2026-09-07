// Case (a): decorated class DECLARATION with a static field.
// This is the exact shape upstream Babel drops the module-scope binding for; the
// vendored fork must emit `var Fixture_Static_Action = <uid>;` so the bare name is
// reachable in the concatenated bundle. The runtime harness supplies the decorator
// stubs (route/layout/spa) and the Spa_Action base class.
@route('/fixture/static')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
class Fixture_Static_Action extends Spa_Action {

    static TABS = ['overview', 'history'];

    get_tabs() {
        return Fixture_Static_Action.TABS;
    }
}
