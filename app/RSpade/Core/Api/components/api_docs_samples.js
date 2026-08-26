/**
 * api_code_samples(ep) - generate copyable client code samples for one endpoint.
 *
 * Pure function of the catalog endpoint spec (no per-endpoint authored samples). Path params
 * are substituted into the URL from their example (or a {name} placeholder); remaining params
 * become a GET query string or a POST JSON body. The Authorization header always uses a
 * literal {API_KEY} placeholder - the in-page tester injects the real key, but copyable code
 * should never embed a throwaway credential.
 *
 * Returns [{ lang, hl, code }] where hl is the highlight.js language class.
 */
function _api_sample_example_value(p) {
    if (p.example !== null && p.example !== undefined) {
        return p.example;
    }
    switch (p.type) {
        case 'int': return 0;
        case 'float': return 0.0;
        case 'bool': return false;
        default: return 'string';
    }
}

function _api_sample_shape(ep) {
    const verb = (ep.methods && ep.methods[0]) || 'GET';
    const params = ep.api_params || [];
    const path_params = [];
    const other_params = [];

    for (const p of params) {
        if (ep.pattern.indexOf(':' + p.name) !== -1) {
            path_params.push(p);
        } else {
            other_params.push(p);
        }
    }

    let path = ep.pattern;
    for (const p of path_params) {
        // A real example value is URL-encoded; the {name} placeholder stays readable.
        const substitute = (p.example !== null && p.example !== undefined)
            ? encodeURIComponent(String(p.example))
            : ('{' + p.name + '}');
        path = path.replace(':' + p.name, substitute);
    }

    return { verb, path, other_params };
}

function _api_sample_query(other_params) {
    if (!other_params.length) {
        return '';
    }
    const parts = other_params.map(p =>
        encodeURIComponent(p.name) + '=' + encodeURIComponent(String(_api_sample_example_value(p)))
    );
    return '?' + parts.join('&');
}

function _api_sample_body_object(other_params) {
    const obj = {};
    for (const p of other_params) {
        obj[p.name] = _api_sample_example_value(p);
    }
    return obj;
}

/**
 * api_code_samples(ep, filled) - the copyable client samples for one endpoint.
 *
 * TWO MODES, one generator:
 *
 *   filled omitted  documentation. Values come from each param's declared example, and the
 *                   key is the literal {API_KEY} - copyable code must never carry somebody's
 *                   real credential into a file or a chat window.
 *
 *   filled given    "Fill in Values". The sample becomes the request the tester WOULD send:
 *                   the real key, the values actually typed, unset fields omitted, path
 *                   parameters merged in. Same source of truth as the Send button
 *                   (Api_Tester.current_values), so the two can never disagree.
 *
 * EVERY VALUE IS ESCAPED FOR ITS TARGET LANGUAGE, and URL values are escaped TWICE - once
 * percent-encoded for the URL, then quoted for the language the URL is embedded in. See
 * api_docs_escaping.js. This matters most in filled mode, where the values are whatever a
 * user typed: a search term containing a quote or a $ would otherwise produce a sample that
 * is a different request, or in a shell a different command.
 *
 * Returns [{ lang, hl, code }] where hl is the highlight.js language class.
 */
function api_code_samples(ep, filled) {
    const origin = window.location.origin;
    const is_filled = !!filled;
    const shape = _api_sample_shape(ep);
    const verb = shape.verb;
    const is_post = verb === 'POST';

    const api_key = is_filled && filled.api_key ? filled.api_key : '{API_KEY}';
    const path = is_filled ? filled.path : shape.path;

    // GET carries values in the query string, POST in the body - never both, because a sample
    // showing both would not match what the tester sends.
    const query = is_post
        ? ''
        : (is_filled
            ? (filled.query_string ? '?' + filled.query_string : '')
            : _api_sample_query(shape.other_params));

    const url = origin + path + query;

    const body_obj = is_filled
        ? (filled.body_object || {})
        : _api_sample_body_object(shape.other_params);

    const has_body = is_post && Object.keys(body_obj).length > 0;
    const body_json_inline = JSON.stringify(body_obj);

    const auth = 'Authorization: Bearer ' + api_key;

    // -- cURL ------------------------------------------------------------------
    // Single-quoted shell literals throughout: inside them a shell expands nothing, so a
    // value containing $, a backtick or a quote is data rather than code.
    let curl = 'curl' + (is_post ? ' -X POST' : '') + ' ' + api_bash_literal(url) + ' \\\n';
    curl += '  -H ' + api_bash_literal(auth);

    if (has_body) {
        curl += ' \\\n  -H ' + api_bash_literal('Content-Type: application/json');
        curl += ' \\\n  -d ' + api_bash_literal(body_json_inline);
    }

    // -- PHP -------------------------------------------------------------------
    let php = '<?php\n$ch = curl_init(' + api_php_literal(url) + ');\n';
    php += '$options = [\n    CURLOPT_RETURNTRANSFER => true,\n';

    if (is_post) {
        php += '    CURLOPT_POST => true,\n';
    }

    if (has_body) {
        php += '    CURLOPT_POSTFIELDS => ' + api_php_literal(body_json_inline) + ',\n';
        php += '    CURLOPT_HTTPHEADER => [\n        ' + api_php_literal(auth)
            + ',\n        ' + api_php_literal('Content-Type: application/json') + ',\n    ],\n';
    } else {
        php += '    CURLOPT_HTTPHEADER => [' + api_php_literal(auth) + '],\n';
    }

    php += '];\ncurl_setopt_array($ch, $options);\n$response = curl_exec($ch);\ncurl_close($ch);\n$data = json_decode($response, true);';

    // -- JavaScript ------------------------------------------------------------
    let js = 'const res = await fetch(' + api_js_literal(url) + ', {\n';

    if (is_post) {
        js += '  method: "POST",\n';
    }

    if (has_body) {
        js += '  headers: {\n    "Authorization": ' + api_js_literal('Bearer ' + api_key)
            + ',\n    "Content-Type": "application/json"\n  },\n';
        js += '  body: JSON.stringify(' + JSON.stringify(body_obj) + ')\n';
    } else {
        js += '  headers: { "Authorization": ' + api_js_literal('Bearer ' + api_key) + ' }\n';
    }

    js += '});\nconst data = await res.json();';

    // -- Python ----------------------------------------------------------------
    let py = 'import requests\n\n';
    py += 'res = requests.' + (is_post ? 'post' : 'get') + '(\n    '
        + api_python_literal(origin + path) + ',\n';
    py += '    headers={"Authorization": ' + api_python_literal('Bearer ' + api_key) + '}';

    if (is_post && has_body) {
        py += ',\n    json=' + _api_python_dict(body_obj);
    } else if (!is_post) {
        const get_params = is_filled ? (filled.query_values || {}) : body_obj_from(shape.other_params);

        if (Object.keys(get_params).length) {
            py += ',\n    params=' + _api_python_dict(get_params);
        }
    }

    py += '\n)\nprint(res.json())';

    // -- PowerShell ------------------------------------------------------------
    let ps = '$headers = @{ "Authorization" = ' + api_powershell_literal('Bearer ' + api_key);

    if (has_body) {
        ps += '; "Content-Type" = "application/json"';
    }

    ps += ' }\n';

    if (has_body) {
        ps += '$body = ' + api_powershell_literal(body_json_inline) + '\n';
    }

    ps += 'Invoke-RestMethod -Method ' + (is_post ? 'Post' : 'Get')
        + ' -Uri ' + api_powershell_literal(url) + ' -Headers $headers'
        + (has_body ? ' -Body $body' : '');

    return [
        { lang: 'cURL', hl: 'bash', code: curl },
        { lang: 'PHP', hl: 'php', code: php },
        { lang: 'JavaScript', hl: 'javascript', code: js },
        { lang: 'Python', hl: 'python', code: py },
        { lang: 'PowerShell', hl: 'powershell', code: ps },
    ];
}

/**
 * api_tester_input(p) - HTML for one typed tester input, keyed by data-param="name".
 * bool -> select(true/false); int/float -> number input; string -> text input. Prefilled
 * from the param example when present. Attribute values are escaped via html().
 */
function api_tester_input(p) {
    const ex = (p.example !== null && p.example !== undefined) ? String(p.example) : '';
    const name_attr = ' data-param="' + html(p.name) + '"';

    if (p.type === 'bool') {
        const t_sel = ex === 'true' ? ' selected' : '';
        const f_sel = ex === 'false' ? ' selected' : '';
        return '<select class="Api_Tester__input"' + name_attr + '>'
            + '<option value="">(unset)</option>'
            + '<option value="true"' + t_sel + '>true</option>'
            + '<option value="false"' + f_sel + '>false</option>'
            + '</select>';
    }

    const type = (p.type === 'int' || p.type === 'float') ? 'number' : 'text';
    const step = (p.type === 'float') ? ' step="any"' : '';
    return '<input type="' + type + '"' + step + ' class="Api_Tester__input"' + name_attr
        + ' value="' + html(ex) + '" placeholder="' + html(ex || p.type) + '">';
}

// Build a params dict for GET query in Python style from an array of param specs.
function body_obj_from(other_params) {
    const obj = {};
    for (const p of other_params) {
        obj[p.name] = _api_sample_example_value(p);
    }
    return obj;
}

// Render a JS object as a Python dict literal (values: strings quoted, bools Title-cased).
function _api_python_dict(obj) {
    const entries = Object.keys(obj).map(k => {
        const v = obj[k];
        let rv;
        if (typeof v === 'boolean') {
            rv = v ? 'True' : 'False';
        } else if (typeof v === 'number') {
            rv = String(v);
        } else {
            rv = '"' + String(v).replace(/"/g, '\\"') + '"';
        }
        return '"' + k + '": ' + rv;
    });
    return '{' + entries.join(', ') + '}';
}
