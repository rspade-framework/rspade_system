// Case (e): a NON-decorated class. The decorator transform must leave it as an ordinary
// class declaration; the bare name binds the way any class declaration does. Proves the
// fork and the contract assertion never touch undecorated code.
class Fixture_Plain extends Spa_Action {

    static VALUE = 42;

    get_value() {
        return Fixture_Plain.VALUE;
    }
}
