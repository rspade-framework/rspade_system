/**
 * Unit tests for the pure recognizers. Plain node, no editor, no test framework:
 *
 *     node ./out/test/run_recognizer_tests.js
 *
 * The recognizer module has no vscode dependency precisely so this can run.
 */

import {
    class_name_start_is_valid,
    CLASS_NAME_FRAGMENT,
    comment_flavor_for,
    comment_ranges,
    framework_property_gate_is_on,
    infer_auth_realm,
    is_class_name,
    is_css_class_candidate,
    parse_env_value,
    pascal_to_snake_case,
    recognize_auth_checks,
    recognize_css_classes,
    recognize_doc_references,
    recognize_js_decorators,
    recognize_php_attributes,
    Recognized_Token,
} from '../rspade_recognizers';
import {
    JS_DECORATORS,
    PHP_ATTRIBUTES,
    vocabulary_markdown,
} from '../framework_vocabulary';
import * as fs from 'fs';
import * as path from 'path';

let failures = 0;
let checks = 0;

function check(label: string, actual: unknown, expected: unknown): void {
    checks++;
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a !== e) {
        failures++;
        console.log(`[FAIL] ${label}\n       expected ${e}\n       actual   ${a}`);
    }
}

function values(tokens: Recognized_Token[]): string[] {
    return tokens.map(t => t.value);
}

// -------------------------------------------------------------------------
// A. auth check names
// -------------------------------------------------------------------------

check('auth: single name',
    values(recognize_auth_checks("    #[Auth('is_logged_in')]", 0)),
    ['is_logged_in']);

check('auth: variadic names',
    values(recognize_auth_checks("#[Auth('is_logged_in', 'can_view_billing')]", 0)),
    ['is_logged_in', 'can_view_billing']);

check('auth: leading backslash and spacing',
    values(recognize_auth_checks('#[ \\Auth( "public" )]', 0)),
    ['public']);

check('auth: JS decorator',
    values(recognize_auth_checks("@auth('is_logged_in')", 0)),
    ['is_logged_in']);

check('auth: Auth_Realm is not an Auth gate',
    values(recognize_auth_checks("#[Auth_Realm('portal')]", 0)),
    []);

check('auth: Auth_Check declaration is not a gate',
    values(recognize_auth_checks('#[Auth_Check]', 0)),
    []);

const auth_range = recognize_auth_checks("#[Auth('can_export_data')]", 7);
check('auth: range covers the name only, quotes excluded',
    auth_range.map(t => [t.line, t.start, t.end]),
    [[7, 8, 23]]);
check('auth: range slices back to the name',
    "#[Auth('can_export_data')]".substring(auth_range[0].start, auth_range[0].end),
    'can_export_data');

// -------------------------------------------------------------------------
// realm inference
// -------------------------------------------------------------------------

check('realm: explicit declaration wins',
    infer_auth_realm('rsx/app/frontend/x.php', "#[Auth_Realm('portal')]\nclass X {}"),
    'portal');

check('realm: explicit any',
    infer_auth_realm('rsx/portal/x.php', "#[Auth_Realm('any')]"),
    'any');

check('realm: application portal root',
    infer_auth_realm('rsx/portal/portal_main.php', 'class Portal_Main {}'),
    'portal');

check('realm: framework portal root',
    infer_auth_realm('system/app/RSpade/Lib/Portal/Portal_Dispatcher.php', 'class X {}'),
    'portal');

check('realm: manifest-relative framework portal root',
    infer_auth_realm('app/RSpade/Lib/Portal/Portal_Dispatcher.php', 'class X {}'),
    'portal');

check('realm: default is staff',
    infer_auth_realm('rsx/app/frontend/clients/clients_controller.php', 'class X {}'),
    'staff');

check('realm: a portal-named file outside a portal root is still staff',
    infer_auth_realm('rsx/app/frontend/portal_link_controller.php', 'class X {}'),
    'staff');

// -------------------------------------------------------------------------
// B. class / component names (Rsx_Identifier: /^_?[A-Z][A-Za-z0-9_]*$/)
// -------------------------------------------------------------------------

check('name: ordinary class name', is_class_name('Sys_Layout'), true);
check('name: single leading underscore is a framework-application name', is_class_name('_Sys_Layout'), true);
check('name: two leading underscores are not a name', is_class_name('__Sys_Layout'), false);
check('name: snake_case is not a name', is_class_name('_sys_layout'), false);
check('name: a single segment is still a name', is_class_name('_Apidocs'), true);
check('name: a trailing underscore is allowed by Rsx_Identifier', is_class_name('Sys_'), true);
check('name: empty is not a name', is_class_name(''), false);

check('name: start guard rejects a second leading underscore',
    class_name_start_is_valid('__Bad_Thing', 1),
    false);
check('name: start guard accepts the head of a line',
    class_name_start_is_valid('_Sys_Card', 0),
    true);
check('name: start guard accepts a name after a dot',
    class_name_start_is_valid('this._Sys_Card', 5),
    true);

// The fragment is what every other recognizer embeds; these are the shapes it
// has to pull out of real source lines.
function fragment_match(text: string): string | null {
    const match = new RegExp(CLASS_NAME_FRAGMENT).exec(text);
    return match ? match[0] : null;
}

check("name: Rsx::Route('_Sys_Spa_Controller::index') class token",
    (() => {
        const route = new RegExp(
            `(?:Rsx::Route|Rsx\\.Route)\\s*\\(\\s*['\"](${CLASS_NAME_FRAGMENT}[A-Za-z0-9_:]*)['\"].*?\\)`);
        const match = route.exec("Rsx::Route('_Sys_Spa_Controller::index')");
        return match ? match[1].split('::')[0] : null;
    })(),
    '_Sys_Spa_Controller');

check("name: Rsx::Route('Frontend_Controller::view') is unchanged",
    (() => {
        const route = new RegExp(
            `(?:Rsx::Route|Rsx\\.Route)\\s*\\(\\s*['\"](${CLASS_NAME_FRAGMENT}[A-Za-z0-9_:]*)['\"].*?\\)`);
        const match = route.exec("Rsx::Route('Frontend_Controller::view', $id)");
        return match ? match[1] : null;
    })(),
    'Frontend_Controller::view');

check('name: <Define:_Sys_Card> extraction',
    (() => {
        const match = '<Define:_Sys_Card tag="div">'.match(new RegExp(`<Define:(${CLASS_NAME_FRAGMENT})`));
        return match ? match[1] : null;
    })(),
    '_Sys_Card');

check('name: <Define:Client_Card> extraction is unchanged',
    (() => {
        const match = '<Define:Client_Card>'.match(new RegExp(`<Define:(${CLASS_NAME_FRAGMENT})`));
        return match ? match[1] : null;
    })(),
    'Client_Card');

check('name: class _Sys_Sidebar_Nav extends Component',
    (() => {
        const match = 'class _Sys_Sidebar_Nav extends Component {'.match(
            new RegExp(`^(?:abstract\\s+|final\\s+)?class\\s+(${CLASS_NAME_FRAGMENT})`));
        return match ? match[1] : null;
    })(),
    '_Sys_Sidebar_Nav');

check('name: class __Bad is not a class definition',
    'class __Bad {'.match(new RegExp(`^(?:abstract\\s+|final\\s+)?class\\s+(${CLASS_NAME_FRAGMENT})`)),
    null);

check('name: fragment pulls the whole underscored token', fragment_match('  _Apidocs_App '), '_Apidocs_App');

// The rename UI's new-name validator is is_class_name.
check('rename: validator accepts _Sys_Layout', is_class_name('_Sys_Layout'), true);
check('rename: validator rejects __Sys_Layout', is_class_name('__Sys_Layout'), false);
check('rename: validator rejects a lowercase first letter', is_class_name('sys_Layout'), false);

// -------------------------------------------------------------------------
// B2. PascalCase to snake_case (the auto-rename filename suggestion)
// -------------------------------------------------------------------------

check('snake: plain name', pascal_to_snake_case('Sys_Card'), 'Sys_Card');
check('snake: leading underscore is preserved, never doubled', pascal_to_snake_case('_Sys_Card'), '_Sys_Card');
check('snake: lowercased suggestion for a framework-application name',
    pascal_to_snake_case('_Sys_Card').toLowerCase(), '_sys_card');
check('snake: lowercased suggestion for an ordinary name',
    pascal_to_snake_case('Sys_Card').toLowerCase(), 'sys_card');
check('snake: camel run is segmented', pascal_to_snake_case('TestComponent1'), 'Test_Component_1');
check('snake: leading underscore with a camel run',
    pascal_to_snake_case('_TestComponent1').toLowerCase(), '_test_component_1');

// -------------------------------------------------------------------------
// C. css class tokens
// -------------------------------------------------------------------------

check('css: token rule accepts PascalCase_With_Underscore', is_css_class_candidate('Backend_Index'), true);
check('css: token rule accepts digits', is_css_class_candidate('Bootstrap5_Grid'), true);
check('css: token rule rejects a single segment', is_css_class_candidate('Backend'), false);
check('css: token rule rejects kebab-case', is_css_class_candidate('btn-primary'), false);
check('css: token rule rejects snake_case', is_css_class_candidate('btn_primary'), false);
check('css: token rule rejects a lowercase second segment', is_css_class_candidate('Backend_index'), false);

check('css: jQuery selector',
    values(recognize_css_classes('const $el = $(".Backend_Index");', 0, false)),
    ['Backend_Index']);

check('css: closest with single quotes',
    values(recognize_css_classes("this.$.closest('.Foo_Bar').hide();", 0, false)),
    ['Foo_Bar']);

check('css: scss rule',
    values(recognize_css_classes('.Client_Card {', 0, false)),
    ['Client_Card']);

// A BEM child class is not a component name, so it is not a navigation target.
check('css: BEM child class is rejected',
    values(recognize_css_classes('.Client_Card__header { }', 0, false)),
    []);

check('css: token rule accepts a single leading underscore', is_css_class_candidate('_Sys_Card'), true);
check('css: token rule rejects two leading underscores', is_css_class_candidate('__Bad'), false);
check('css: token rule rejects a lowercase underscored name', is_css_class_candidate('_sys_card'), false);

check('css: framework-application selector',
    values(recognize_css_classes('._Sys_Card {', 0, false)),
    ['_Sys_Card']);

check('css: framework-application jQuery selector',
    values(recognize_css_classes('$("._Sys_Card").hide();', 0, false)),
    ['_Sys_Card']);

check('css: double-underscore selector is not a name',
    values(recognize_css_classes('.__Bad_Thing { }', 0, false)),
    []);

check('css: framework-application class attribute',
    values(recognize_css_classes('<div class="_Sys_Card mt-2">', 0, true)),
    ['_Sys_Card']);

check('css: ordinary css class is untouched',
    values(recognize_css_classes('$(".btn-primary").addClass("card");', 0, true)),
    []);

check('css: property access is not a selector',
    values(recognize_css_classes('const x = this.Foo_Bar;', 0, false)),
    []);

check('css: namespaced property access is not a selector',
    values(recognize_css_classes('window.Rsx_Form.submit();', 0, false)),
    []);

check('css: hyphenated tail is rejected',
    values(recognize_css_classes('.Foo_Bar-baz { }', 0, false)),
    []);

check('css: class attribute in markup',
    values(recognize_css_classes('<div class="Client_Card mt-2">', 0, true)),
    ['Client_Card']);

check('css: class attribute ignored where markup is not expected',
    values(recognize_css_classes('<div class="Client_Card mt-2">', 0, false)),
    []);

check('css: two selectors on one line',
    values(recognize_css_classes('.Foo_Bar > .Baz_Qux {', 0, false)),
    ['Foo_Bar', 'Baz_Qux']);

// -------------------------------------------------------------------------
// D. man page and skill references
// -------------------------------------------------------------------------

function doc(text: string, flavor: 'c_like' | 'jqhtml' | 'blade' | 'plain' = 'plain'): string[] {
    return values(recognize_doc_references(text, flavor));
}

check('doc: rsx:man form', doc('rsx:man tasks'), ['tasks']);
check('doc: php artisan form', doc('php artisan rsx:man tasks'), ['tasks']);
check('doc: bare man form', doc('see man tasks'), ['tasks']);
check('doc: see does not swallow the rsx: prefix', doc('see rsx:man tasks'), ['tasks']);
check('doc: see also list', doc('see also tasks, locks, realtime'), ['tasks', 'locks', 'realtime']);
check('doc: see single', doc('see routing'), ['routing']);
check('doc: section number form', doc('jqhtml(7)'), ['jqhtml']);
check('doc: filename form', doc('migrations.txt'), ['migrations']);
check('doc: skill with keyword', doc('skill rspade:background-tasks'), ['background-tasks']);
check('doc: skill in backticks', doc('`rspade:realtime`'), ['realtime']);
check('doc: skill in parentheses', doc('skill (rspade:portal-core)'), ['portal-core']);

check('doc: multi-column rsx:man grid, each token its own reference',
    doc('    rsx:man tasks        rsx:man locks        rsx:man realtime'),
    ['tasks', 'locks', 'realtime']);

check('doc: SEE ALSO compact row',
    doc('SEE ALSO\n    tasks - background work\n'),
    ['tasks']);

check('doc: SEE ALSO column row',
    doc('SEE ALSO\n    health           the Web Exposure probe\n    jqhtml           the component system\n'),
    ['health', 'jqhtml']);

// A long topic narrows the column gap to a single space; it is still a row.
check('doc: SEE ALSO column row with a one-space gap',
    doc('SEE ALSO\n    coding_standards the RSX naming conventions\n'),
    ['coding_standards']);

check('doc: a SEE ALSO continuation line is not a topic',
    doc('SEE ALSO\n    health           the Web Exposure probe that proves\n                     served\n'),
    ['health']);

check('doc: a compact row outside a SEE ALSO section is not a topic',
    doc('DESCRIPTION\n    tasks - background work\n'),
    []);

check('doc: an all-caps heading closes the SEE ALSO section',
    doc('SEE ALSO\n    tasks - background work\nDESCRIPTION\n    locks - critical sections\n'),
    ['tasks']);

check('doc: a topic that is not a page is still recognized (the index decides)',
    doc('rsx:man not_a_real_topic'),
    ['not_a_real_topic']);

check('doc: no duplicate token when two forms overlap',
    doc('rsx:man tasks'),
    ['tasks']);

// Comment restriction in code files.
check('doc: reference inside a line comment is recognized',
    doc('// see rsx:man tasks for the worker pool', 'c_like'),
    ['tasks']);

check('doc: reference in live code is not recognized',
    doc('const x = "rsx:man tasks";', 'c_like'),
    []);

check('doc: reference inside a block comment is recognized',
    doc('/**\n * Details: rsx:man locks\n */', 'c_like'),
    ['locks']);

check('doc: reference inside a jqhtml comment is recognized',
    doc('<%-- see rsx:man jqhtml --%>', 'jqhtml'),
    ['jqhtml']);

check('doc: reference inside a blade comment is recognized',
    doc('{{-- see rsx:man spa --}}', 'blade'),
    ['spa']);

check('doc: a PHP attribute is not a hash comment',
    doc("#[Auth('is_logged_in')] // rsx:man auth_gates", 'c_like'),
    ['auth_gates']);

check('doc: a PHP hash comment is a comment',
    doc('# rsx:man helpers', 'c_like'),
    ['helpers']);

check('comment_ranges: plain covers the whole document',
    comment_ranges('abc', 'plain'),
    [{ start: 0, end: 3 }]);

check('flavor: man page is plain', comment_flavor_for('/x/man/tasks.txt', 'plaintext'), 'plain');
check('flavor: jqhtml', comment_flavor_for('/x/Foo.jqhtml', 'html'), 'jqhtml');
check('flavor: blade', comment_flavor_for('/x/foo.blade.php', 'php'), 'blade');
check('flavor: php', comment_flavor_for('/x/foo.php', 'php'), 'c_like');
check('flavor: scss', comment_flavor_for('/x/foo.scss', 'scss'), 'c_like');

// -------------------------------------------------------------------------
// E. framework property gate
// -------------------------------------------------------------------------

check('env: reads a value', parse_env_value('A=1\nIS_FRAMEWORK_DEVELOPER=true\n', 'IS_FRAMEWORK_DEVELOPER'), 'true');
check('env: strips double quotes', parse_env_value('IS_FRAMEWORK_DEVELOPER="true"', 'IS_FRAMEWORK_DEVELOPER'), 'true');
check('env: strips single quotes', parse_env_value("IS_FRAMEWORK_DEVELOPER='true'", 'IS_FRAMEWORK_DEVELOPER'), 'true');
check('env: skips a commented line', parse_env_value('#IS_FRAMEWORK_DEVELOPER=true\nIS_FRAMEWORK_DEVELOPER=false', 'IS_FRAMEWORK_DEVELOPER'), 'false');
check('env: first definition wins', parse_env_value('IS_FRAMEWORK_DEVELOPER=false\nIS_FRAMEWORK_DEVELOPER=true', 'IS_FRAMEWORK_DEVELOPER'), 'false');
check('env: absent key is null', parse_env_value('APP_URL=https://x', 'IS_FRAMEWORK_DEVELOPER'), null);

check('gate: off in the monorepo', framework_property_gate_is_on('IS_FRAMEWORK_DEVELOPER=true'), false);
check('gate: off is case-insensitive', framework_property_gate_is_on('IS_FRAMEWORK_DEVELOPER=TRUE'), false);
check('gate: off with surrounding whitespace', framework_property_gate_is_on('IS_FRAMEWORK_DEVELOPER=  true  '), false);
check('gate: on when false', framework_property_gate_is_on('IS_FRAMEWORK_DEVELOPER=false'), true);
check('gate: on when 1 (only the literal true disarms it)', framework_property_gate_is_on('IS_FRAMEWORK_DEVELOPER=1'), true);
check('gate: on when empty', framework_property_gate_is_on('IS_FRAMEWORK_DEVELOPER='), true);
check('gate: on when the key is absent', framework_property_gate_is_on('APP_URL=https://x'), true);
check('gate: on when .env is unreadable', framework_property_gate_is_on(null), true);

// -------------------------------------------------------------------------
// F. framework attributes and decorators
// -------------------------------------------------------------------------

function php(body: string): string[] {
    return values(recognize_php_attributes('<?php\n' + body));
}

function js(body: string): string[] {
    return values(recognize_js_decorators(body));
}

check('php attr: bare marker', php('#[Writer]\npublic static function x() {}'), ['Writer']);
check('php attr: with arguments', php("#[Task('Refresh')]"), ['Task']);
check('php attr: On_Build', php('#[On_Build]'), ['On_Build']);
check('php attr: leading backslash', php('#[\\Auth_Check]'), ['Auth_Check']);
check('php attr: a namespaced name is not the framework attribute', php('#[\\App\\Attr\\Writer]'), []);
check('php attr: a relative namespaced name is not either', php('#[Foo\\Writer]'), []);
check('php attr: grouped list colours every framework name',
    php("#[Task('Refresh'), Exclusive, On_Build]"),
    ['Task', 'Exclusive', 'On_Build']);
check('php attr: grouped list with a non-framework name in between',
    php("#[Route('/x'), Deprecated, Writer]"),
    ['Route', 'Writer']);
check('php attr: commas and brackets inside arguments end no name',
    php("#[Api_Param('ids', default: [1, 2], description: 'a, b ] c'), Api_Param('x')]"),
    ['Api_Param', 'Api_Param']);
check('php attr: a group spanning lines',
    php("#[\n    Auth('is_logged_in'),\n    Writer,\n]"),
    ['Auth', 'Writer']);
check('php attr: prefix is not the longer name', php('#[Ajax_Endpoint_Model_Fetch]'), ['Ajax_Endpoint_Model_Fetch']);
check('php attr: unknown name', php('#[Task_Attribute]'), []);
check('php attr: stale Task_Attribute is not vocabulary', Object.prototype.hasOwnProperty.call(PHP_ATTRIBUTES, 'Task_Attribute'), false);
check('php attr: in a line comment', php('// #[Writer] goes on the endpoint'), []);
check('php attr: in a docblock', php('/**\n * Declares #[Writer].\n */'), []);
check('php attr: in a string', php("$x = '#[Writer]';"), []);
check('php attr: in a heredoc', php('$x = <<<EOT\n#[Writer]\nEOT;\n#[Reader]'), ['Reader']);
check('php attr: in inline HTML outside <?php',
    values(recognize_php_attributes("<p>Don't #[Writer]</p>\n<?php\n#[Reader]")),
    ['Reader']);
check('php attr: after ?> and back in',
    values(recognize_php_attributes("<?php #[Writer] ?>\n<p>it's #[Media]</p>\n<?php #[Reader]")),
    ['Writer', 'Reader']);

const php_range = recognize_php_attributes("<?php\n    #[\\Auth('x'), Writer]");
check('php attr: ranges cover the names only',
    php_range.map(t => [t.line, t.start, t.end, t.kind]),
    [[1, 7, 11, 'php_attribute'], [1, 18, 24, 'php_attribute']]);

check('js dec: class decorators with arguments',
    js("@route('/contacts')\n@layout('Frontend_Layout')\n@spa('Frontend_Spa_Controller::index')\n@auth('is_logged_in')\n@title('Contacts')\nclass A extends Spa_Action {}"),
    ['route', 'layout', 'spa', 'auth', 'title']);
check('js dec: on one line', js("@route('/x') @spinner_until_ready('always') class A {}"), ['route', 'spinner_until_ready']);
check('js dec: portal_spa', js("@portal_spa('Portal_Spa_Controller::index')"), ['portal_spa']);
check('js dec: without arguments', js('    @mutex\n    async save() {}'), ['mutex']);
check('js dec: method decorator with arguments', js('    @debounce(250)\n    refresh() {}'), ['debounce']);
check('js dec: JSDoc tags are not decorators', js('/**\n * @param {string} x\n * @decorator\n * @route not code\n */'), []);
check('js dec: line comment', js('// @route(\'/x\')'), []);
check('js dec: in strings', js("const a = '@route'; const b = \"@media\"; const c = `@spa ${'@auth'}`;"), []);
check('js dec: an email address is not a decorator', js('send(user@route.com)'), []);
check('js dec: a longer name is not the decorator', js('@routes @layout_name @Route'), []);
check('js dec: unknown decorator', js('@media'), []);

const js_range = recognize_js_decorators("class A {\n  @debounce(250)\n  x() {}\n}");
check('js dec: range covers @name',
    js_range.map(t => [t.line, t.start, t.end, t.kind, t.value]),
    [[1, 2, 11, 'js_decorator', 'debounce']]);

check('vocabulary: markdown names the attribute and its docs',
    (vocabulary_markdown('php_attribute', 'On_Build') || '').split('\n\n')[0],
    '**#[On_Build]** - RSpade attribute');
check('vocabulary: markdown for a decorator',
    (vocabulary_markdown('js_decorator', 'route') || '').split('\n\n')[0],
    '**@route** - RSpade decorator');
check('vocabulary: unknown name has no markdown', vocabulary_markdown('php_attribute', 'Task_Attribute'), undefined);
check('vocabulary: object prototype names are not vocabulary', vocabulary_markdown('js_decorator', 'constructor'), undefined);

// Every hover cites a man page or skill that SHIPS. The extension lives at
// system/app/RSpade/resource/vscode_extension; out/test is two levels below it.
const rspade_dir = path.resolve(__dirname, '..', '..', '..', '..');
const PUBLISH_STRIPPED = ['code_quality', 'manifest_api', 'manifest_build', 'ast_sourcecode_parsers', 'vs_code_extension'];
const doc_failures: string[] = [];
for (const [kind, table] of [['php_attribute', PHP_ATTRIBUTES], ['js_decorator', JS_DECORATORS]] as const) {
    for (const [name, entry] of Object.entries(table)) {
        if (entry.docs.length === 0) {
            doc_failures.push(`${kind} ${name}: no docs`);
        }
        for (const reference of entry.docs) {
            const man = /^rsx:man ([a-z][a-z0-9_]*)$/.exec(reference);
            const skill = /^skill rspade:([a-z][a-z0-9-]*)$/.exec(reference);
            if (man) {
                if (PUBLISH_STRIPPED.includes(man[1])) {
                    doc_failures.push(`${kind} ${name}: ${reference} is publish-stripped`);
                } else if (!fs.existsSync(path.join(rspade_dir, 'man', man[1] + '.txt'))) {
                    doc_failures.push(`${kind} ${name}: ${reference} does not exist`);
                }
            } else if (skill) {
                if (!fs.existsSync(path.join(rspade_dir, 'docs', 'skills', 'shared', skill[1], 'SKILL.md'))) {
                    doc_failures.push(`${kind} ${name}: ${reference} is not a shipped skill`);
                }
            } else {
                doc_failures.push(`${kind} ${name}: '${reference}' is not spelled rsx:man topic / skill rspade:name`);
            }
        }
    }
}
check('vocabulary: every docs reference resolves to a shipped man page or skill', doc_failures, []);

console.log(`\n${checks - failures}/${checks} checks passed`);
if (failures > 0) {
    process.exit(1);
}
