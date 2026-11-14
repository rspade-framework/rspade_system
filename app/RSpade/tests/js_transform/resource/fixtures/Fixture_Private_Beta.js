// The other half of Fixture_Private_Alpha: the same private member names, different values.
class Fixture_Private_Beta {

    static #SCALE = 7;

    #count = 2;

    static scale() {
        return Fixture_Private_Beta.#SCALE;
    }

    count() {
        return this.#count;
    }

    static reads_count_of(other) {
        return #count in other;
    }
}
