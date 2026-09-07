// Case (d): a decorated class with a static METHOD but no static FIELDS. Control case -
// upstream keeps the class declaration (so the name survives) rather than taking the
// statics branch; the fork emits nothing extra here. The contract assertion must still
// pass and the bare name must bind.
@route('/fixture/method')
class Fixture_Method_Only extends Spa_Action {

    static make() {
        return new Fixture_Method_Only();
    }
}
