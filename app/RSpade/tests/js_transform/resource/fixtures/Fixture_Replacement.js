// Case (c): a class decorator that RETURNS A REPLACEMENT class. With a static field the
// class routes through the statics branch the fork patches, and the bound bare name must
// hold the REPLACEMENT (not the original). The harness's @replace_class stub returns a
// `class Replacement extends original` carrying a marker static, so the runtime check can
// prove the replacement won while the original stays reachable up the prototype chain.
@replace_class
class Fixture_Replacement extends Spa_Action {

    static ORIG = true;
}
