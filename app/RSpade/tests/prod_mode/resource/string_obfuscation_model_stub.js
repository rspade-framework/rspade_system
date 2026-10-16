/**
 * Model stub fixture for String_Obfuscation_Test - the shape of a generated Base_<Model> stub
 * (the manifest flags such a file is_model_stub).
 */
class Base_Obf_Fixture_Model {
    static __MODEL = 'Obf_Fixture_Model';

    static STATUS_ACTIVE = 1;
    static STATUS_PAUSED = 2;

    static __status_id__enum = null;
    static status_id__enum(enum_value) {
        if (!this.__status_id__enum) {
            const data = {};
            const order = [];
            data[1] = {"constant":"STATUS_ACTIVE","label":"Active Status Label"};
            order.push(1);
            data[2] = {"constant":"STATUS_PAUSED","label":"Paused"};
            order.push(2);
            this.__status_id__enum = { data, order };
        }
        return enum_value === undefined ? this.__status_id__enum.data : this.__status_id__enum.data[enum_value];
    }
}
