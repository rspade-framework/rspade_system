#!/usr/bin/env node
/**
 * Serialization harness for Rsx_Js_Model (MODELFETCH-ORM-TOJSON).
 *
 * Loads the REAL Core/Js/Rsx_Js_Model.js source into this process - the file is a bare
 * class declaration in the bundle scope, so it is evaluated as-is and the class handed
 * back - then exercises the JSON.stringify protocol against it.
 *
 * toObject()/toJSON()/the constructor touch no browser or bundle globals, so no DOM,
 * no Manifest and no Ajax are needed here.
 *
 * Prints ONE line of JSON describing what was observed. Assertions live in the PHP test.
 */
const fs = require('fs');

const MODEL_SOURCE = '/var/www/html/system/app/RSpade/Core/Js/Rsx_Js_Model.js';

function load_model_class() {
    const source = fs.readFileSync(MODEL_SOURCE, 'utf8');
    // eslint-disable-next-line no-new-func
    return new Function(source + '\nreturn Rsx_Js_Model;')();
}

function main() {
    const Rsx_Js_Model = load_model_class();

    class Harness_Parent_Model extends Rsx_Js_Model {}
    class Harness_Child_Model extends Rsx_Js_Model {}

    const parent = new Harness_Parent_Model({ id: 7, name: 'Parent Record' });
    const child = new Harness_Child_Model({
        id: 42,
        title: 'Child Record',
        parent_id: 7,
        parent: parent,
    });

    const cloned = JSON.parse(JSON.stringify(child));
    const wrapped = JSON.parse(JSON.stringify({ rec: child }));

    const result = {
        to_json_type: typeof child.toJSON(),
        cloned_type: typeof cloned,
        cloned_is_array: Array.isArray(cloned),
        cloned_id: cloned === null || typeof cloned !== 'object' ? null : cloned.id,
        cloned_title: cloned === null || typeof cloned !== 'object' ? null : cloned.title,
        cloned_nested_type: cloned === null || typeof cloned !== 'object' ? null : typeof cloned.parent,
        cloned_nested_name:
            cloned === null || typeof cloned !== 'object' || cloned.parent === null || typeof cloned.parent !== 'object'
                ? null
                : cloned.parent.name,
        wrapped_rec_type: typeof wrapped.rec,
        wrapped_rec_id: wrapped.rec === null || typeof wrapped.rec !== 'object' ? null : wrapped.rec.id,
        // With a string-returning toJSON() the clone is the model's own JSON TEXT, so this
        // is the first thing a consumer sees go wrong: a string where a record belongs.
        cloned_preview: typeof cloned === 'string' ? cloned.slice(0, 40) : null,
    };

    console.log(JSON.stringify(result));
}

main();
