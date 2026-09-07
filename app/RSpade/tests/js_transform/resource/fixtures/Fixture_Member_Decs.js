// Case (b): a class decorator PLUS a member decorator (@debounce-style) plus a static
// field. Exercises the member-decorator path alongside the class-binding fix; the bare
// name must still bind and the output must parse/execute.
@route('/fixture/member')
class Fixture_Member_Decs extends Spa_Action {

    static TABS = ['a', 'b'];

    @debounce(250)
    async do_search() {
        return Fixture_Member_Decs.TABS;
    }
}
