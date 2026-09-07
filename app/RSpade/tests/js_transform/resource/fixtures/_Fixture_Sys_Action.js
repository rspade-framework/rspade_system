// Framework-application name, DECORATED and carrying a static member - the exact shape the
// vendored decorator fork emits `var <Name> = <uid>;` for. Proves the two mechanisms
// compose: the fork restores the bare binding and the prefix plugin leaves it alone
// because the author declared it.
@title('Root Probe')
class _Fixture_Sys_Action extends Spa_Action {

    static TABS = ['overview'];

    get_tabs() {
        return _Fixture_Sys_Action.TABS;
    }
}
