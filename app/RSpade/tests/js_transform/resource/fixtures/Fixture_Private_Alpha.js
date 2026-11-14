// Private members whose NAMES collide with Fixture_Private_Beta's. The transform lowers each
// private member to a generated top-level holder (`static #SCALE` -> `var _SCALE = {_: ...}`,
// `#count` -> `var _count = new WeakMap()`); concatenated with Beta into one script, each
// class must still read its own values.
class Fixture_Private_Alpha {

    static #SCALE = 100;

    #count = 1;

    static scale() {
        return Fixture_Private_Alpha.#SCALE;
    }

    count() {
        return this.#count;
    }

    static reads_count_of(other) {
        return #count in other;
    }
}
