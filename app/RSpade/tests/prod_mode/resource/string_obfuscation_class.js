// ES6 class file fixture for String_Obfuscation_Test (the manifest would index Fixture_Strings
// as this file's class). A decorated method makes babel transform it, as it does every class
// file of a bundle. Every string sits in exactly one of the scopes the string_obfuscation
// subsystem distinguishes; string_obfuscation_plain.js collects their runtime values.

function fixture_top_function() {
    return 'toplevel function phrase';
}

var fixture_top_code = 'toplevel code phrase';

class Fixture_Strings {
    static fixture_static_field = 'staticfield initializer phrase';
    static {
        this.fixture_static_block = 'staticblock body phrase';
    }
    fixture_instance_field = 'instancefield initializer phrase';

    static qualifying() {
        return 'qualifying method phrase';
    }

    static spaces() {
        const leading = '  leading pad';
        const trailing = 'trailing pad  ';
        const doubled = 'double  gap';
        const both = ' both  sides ';
        return leading + '|' + trailing + '|' + doubled + '|' + both + '|' + ' padonly ';
    }

    static indented(name) {
        return `<div>
                <span>${name}</span>
        </div>` + '|' + 'wide      gap';
    }

    static single_word() {
        const lonely = 'loneword';
        return lonely;
    }

    static literal_data() {
        return {
            word_value: 'objword',
            empty_value: '',
            list: ['arrword', '', { nested_value: 'deepword', nested_list: ['deeparr'] }]
        };
    }

    static keys() {
        return { 'spaced key name': 1, singlekey: 2 };
    }

    static template(name) {
        return `greeting template ${name} closing words here`;
    }

    static multiline_template() {
        return `first line words
second line words`;
    }

    static template_data(name) {
        return { t: `tplword${name}tplafter`, plain: `tplplain` };
    }

    static tagged(name) {
        return String.raw`tagged template ${name} stays`;
    }

    static directive() {
        'use strict';
        return 'directive method phrase';
    }

    static nested_arrow() {
        const inner = () => 'nested arrow phrase';
        return inner();
    }

    static with_default(label = 'default param phrase') {
        return label;
    }

    @fixture_label('decorator argument phrase')
    static decorated() {
        return 'decorated method phrase';
    }

    get getter_value() {
        return 'getter body phrase';
    }
}
