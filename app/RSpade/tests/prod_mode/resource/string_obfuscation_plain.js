// Non-class file fixture for String_Obfuscation_Test (no class in its manifest record): never
// eligible, so it reaches the bundle byte for byte. __collect() gathers the runtime value of
// every case in every fixture file of the concatenation.

var fixture_plain_value = 'plain top level phrase';

/** @decorator */
function fixture_label(text) {
    return function (value, context) {
        value.label_text = text;
        return value;
    };
}

function fixture_plain_function() {
    return { plain_key: 'plain function phrase', list: ['plainword'] };
}

function __collect() {
    const instance = new Fixture_Strings();
    const card_args = { title: 'Card Title', flag: true };
    const card = template_Obf_Fixture_Card.render.call(
        { _cid: 'c1', args: card_args },
        {}, card_args, null,
        { escape_html: (value) => String(value), print_object: (value) => JSON.stringify(value) }
    );
    return {
        plain_value: fixture_plain_value,
        plain_function: fixture_plain_function(),
        top_function: fixture_top_function(),
        top_code: fixture_top_code,
        static_field: Fixture_Strings.fixture_static_field,
        static_block: Fixture_Strings.fixture_static_block,
        instance_field: instance.fixture_instance_field,
        qualifying: Fixture_Strings.qualifying(),
        spaces: Fixture_Strings.spaces(),
        indented: Fixture_Strings.indented('NAME'),
        logging: Fixture_Strings.logging(true),
        single_word: Fixture_Strings.single_word(),
        literal_data: Fixture_Strings.literal_data(),
        keys: Fixture_Strings.keys(),
        template: Fixture_Strings.template('NAME'),
        multiline_template: Fixture_Strings.multiline_template(),
        template_data: Fixture_Strings.template_data('NAME'),
        tagged: Fixture_Strings.tagged('NAME'),
        directive: Fixture_Strings.directive(),
        nested_arrow: Fixture_Strings.nested_arrow(),
        with_default: Fixture_Strings.with_default(),
        getter: instance.getter_value,
        decorated: Fixture_Strings.decorated(),
        decorated_label: Fixture_Strings.decorated.label_text,
        model_name: Base_Obf_Fixture_Model.__MODEL,
        model_enum: Base_Obf_Fixture_Model.status_id__enum(),
        controller: Obf_Fixture_Controller.save(),
        controller_path: Obf_Fixture_Controller.save.path,
        card_name: template_Obf_Fixture_Card.name,
        card_output: card[0]
    };
}
