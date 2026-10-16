<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use RuntimeException;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Concatenator;
use App\RSpade\Core\Bundle\Minifier;
use App\RSpade\Core\JsParsers\Js_Transformer;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Integrations\Jqhtml\JqhtmlWebpackCompiler;

/**
 * The strict-production string obfuscation pass (config rsx.production.obfuscate_strings),
 * exercised through the BundleCompiler seams the compile itself calls -
 * _string_obfuscation_eligible() (which files, from the manifest's classification),
 * _obfuscate_sources() (the real string_obfuscation node subsystem over the ORIGINAL sources,
 * then babel over each rewritten copy of a babel-transformed file) and _concat_entries() (the
 * helper file's placement, the copies swapped in) - and the real babel and concat daemons.
 *
 * Fixture files (resource/), concatenated in this order, the way a bundle orders them
 * (non-class files first):
 *   string_obfuscation_plain.js            non-class file - never eligible; holds __collect()
 *   string_obfuscation_controller_stub.js  generated controller stub shape - never eligible
 *   string_obfuscation_model_stub.js       generated model stub shape - eligible (is_model_stub)
 *   string_obfuscation_class.js            ES6 class file, decorated - eligible (manifest JS
 *                                          class index)
 *   string_obfuscation_template.jqhtml     compiled by the real jqhtml compiler - eligible
 *
 * The plain and class files are babel-transformed, as source files are in a bundle; the two
 * stubs and the compiled template are copied into the tmp tree first, where - like the
 * generated files they stand for - babel never touches them.
 *
 * The ORIGINAL concatenation, the OBFUSCATED one and the obfuscated-then-MINIFIED one are
 * each evaluated as a classic script in a fresh node vm context
 * (resource/string_obfuscation_eval.js) and their __collect() values must be identical - the
 * proof that the dictionary and the printer reproduce every converted string byte for byte
 * and survive Terser as true globals. The rewritten copies then show WHICH strings were
 * converted and which were left literal.
 *
 * No DB. The node service is stopped after the class.
 */
class String_Obfuscation_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Compiled jqhtml template, written once per run into the tmp tree. */
    private static ?string $template_js = null;

    /** Scratch files to remove in teardown. */
    private static array $scratch = [];

    public static function teardown()
    {
        foreach (static::$scratch as $file) {
            @unlink($file);
        }
        static::$scratch = [];
        static::$template_js = null;

        Rsx_Node_Service::stop(force: true);
    }

    private static function __resource(string $name): string
    {
        return base_path('app/RSpade/tests/prod_mode/resource/' . $name);
    }

    private static function __scratch_file(string $name, string $content): string
    {
        $path = Rsx_Project_Paths::tmp_path('string_obfuscation_test_' . $name);
        file_put_contents($path, $content);
        static::$scratch[] = $path;

        return $path;
    }

    /** The fixture template, through the real jqhtml compiler. */
    private static function __template_js(): string
    {
        if (static::$template_js === null) {
            $compiled = (new JqhtmlWebpackCompiler())->compile_file(static::__resource('string_obfuscation_template.jqhtml'));
            static::$template_js = static::__scratch_file('template.js', $compiled);
        }

        return static::$template_js;
    }

    /**
     * Scratch SOURCE file beside the fixtures (outside the tmp tree, so the bundle would
     * babel-transform it). The resource directory is never scanned by the manifest.
     */
    private static function __source_scratch_file(string $name, string $content): string
    {
        $path = static::__resource('string_obfuscation_' . $name . '-temp.js');
        file_put_contents($path, $content);
        static::$scratch[] = $path;

        return $path;
    }

    /** A resource fixture copied into the tmp tree (a generated file's home). */
    private static function __tmp_copy(string $name): string
    {
        return static::__scratch_file($name, file_get_contents(static::__resource($name)));
    }

    /** The five fixture files in bundle order, keyed by role. */
    private static function __files(): array
    {
        return [
            'plain' => static::__resource('string_obfuscation_plain.js'),
            'controller_stub' => static::__tmp_copy('string_obfuscation_controller_stub.js'),
            'model_stub' => static::__tmp_copy('string_obfuscation_model_stub.js'),
            'class' => static::__resource('string_obfuscation_class.js'),
            'template' => static::__template_js(),
        ];
    }

    /** The manifest key of a path, as _string_obfuscation_eligible() derives it. */
    private static function __key(string $path): string
    {
        return str_replace(base_path() . '/', '', Rsx_Project_Paths::key_for($path));
    }

    /**
     * A manifest slice describing the fixtures the way the real manifest describes their
     * counterparts: the class file in the JS class index, the model stub flagged
     * is_model_stub, the controller stub flagged is_stub (and absent from the class index),
     * the plain file with no class.
     */
    private static function __manifest(array $files): array
    {
        $manifest_files = [
            static::__key($files['plain']) => ['extension' => 'js'],
            static::__key($files['controller_stub']) => ['extension' => 'js', 'class' => 'Obf_Fixture_Controller', 'is_stub' => true],
            static::__key($files['model_stub']) => ['extension' => 'js', 'class' => 'Base_Obf_Fixture_Model', 'is_model_stub' => true],
            static::__key($files['class']) => ['extension' => 'js', 'class' => 'Fixture_Strings'],
        ];
        $js_classes = [
            'Fixture_Strings' => ['file' => static::__key($files['class'])],
        ];

        return [$manifest_files, $js_classes];
    }

    private static function __eligible(array $files): array
    {
        [$manifest_files, $js_classes] = static::__manifest($files);

        return BundleCompiler::_string_obfuscation_eligible(array_values($files), $manifest_files, $js_classes, [$files['template']], null);
    }

    private static function __concat(array $entries): string
    {
        $output = Rsx_Project_Paths::tmp_path('string_obfuscation_test_concat.js');
        Concatenator::concat_js($entries, $output);
        $js = file_get_contents($output);
        unlink($output);

        return $js;
    }

    /** The cached babel transform of every babel-candidate file, as the compile reads it. */
    private static function __babel_read_paths(array $files): array
    {
        $read_paths = [];
        foreach ($files as $file) {
            if (BundleCompiler::_is_babel_candidate($file)) {
                $read_paths[$file] = Js_Transformer::transform_to_file($file);
            }
        }

        return $read_paths;
    }

    /**
     * Run the pass over a file list: {original, obfuscated, entries, copies, final, helper,
     * result}. `copies` holds each rewritten file's obfuscated SOURCE (the pass run without
     * babel), `final` the file the obfuscated concatenation reads (babel applied), both keyed
     * by original path.
     */
    private static function __pipeline(array $files, ?array $eligible = null, string $vendor_path = ''): array
    {
        $eligible ??= static::__eligible($files);
        $files = array_values($files);
        $babel_read_paths = static::__babel_read_paths($files);

        $copies = static::__run_pass($files, $eligible, $vendor_path, false)['contents'];
        $run = static::__run_pass($files, $eligible, $vendor_path, true, $babel_read_paths);

        return [
            'original' => static::__concat(BundleCompiler::_concat_entries($files, $babel_read_paths)),
            'obfuscated' => $run['obfuscated'],
            'entries' => $run['entries'],
            'copies' => $copies,
            'final' => $run['contents'],
            'helper' => $run['helper'],
            'result' => $run['result'],
        ];
    }

    private static function __run_pass(array $files, array $eligible, string $vendor_path, bool $babel, array $babel_read_paths = []): array
    {
        $pass = BundleCompiler::_obfuscate_sources($files, $eligible, $vendor_path, 'Fixture_Bundle', $babel);

        try {
            $contents = [];
            foreach ($pass['read_paths'] as $original => $read_path) {
                $contents[$original] = file_get_contents($read_path);
            }
            $helper = $pass['helper_path'] !== null ? file_get_contents($pass['helper_path']) : null;
            $entries = BundleCompiler::_concat_entries($files, $pass['read_paths'] + $babel_read_paths, $eligible, $pass['helper_path']);
            $obfuscated = $babel ? static::__concat($entries) : null;
        } finally {
            foreach ($pass['scratch_files'] as $scratch_file) {
                @unlink($scratch_file);
            }
        }

        return ['contents' => $contents, 'helper' => $helper, 'entries' => $entries, 'obfuscated' => $obfuscated, 'result' => $pass['result']];
    }

    /**
     * Evaluate a script in a fresh vm context and decode JSON.stringify(__collect()). No
     * timeout: evaluating a small file takes as long as the machine needs.
     */
    private static function __evaluate(string $js): array
    {
        $file = tempnam(sys_get_temp_dir(), 'string_obfuscation_');
        file_put_contents($file, $js);

        try {
            $process = new Process([
                'node',
                static::__resource('string_obfuscation_eval.js'),
                $file,
            ]);
            $process->setWorkingDirectory(base_path());
            $process->setTimeout(null);
            $process->run();
        } finally {
            unlink($file);
        }

        if (!$process->isSuccessful()) {
            static::__fail("Evaluation failed:\n" . $process->getOutput() . "\n" . $process->getErrorOutput() . "\n--- source ---\n{$js}");
        }

        $decoded = json_decode($process->getOutput(), true);
        if (!is_array($decoded)) {
            static::__fail('Evaluation produced non-JSON output: ' . $process->getOutput());
        }

        return $decoded;
    }

    /**
     * Eligibility comes from the manifest's classification and the compiler's own lists:
     * the JS class index file, the is_model_stub record, the compiled template and the
     * concrete model classes file are eligible; the non-class file, the controller stub, a
     * class record the class index does not point at, and a file unknown to the manifest are
     * not.
     */
    public static function test_eligible_files_come_from_the_manifest_classification()
    {
        $files = static::__files();
        [$manifest_files, $js_classes] = static::__manifest($files);

        $concrete = static::__scratch_file('concrete_models.js', "class Obf_Fixture_Model extends Base_Obf_Fixture_Model {}\n");
        $unknown = static::__scratch_file('unknown.js', "var unknown_file = 1;\n");
        $not_indexed = static::__resource('string_obfuscation_eval.js');
        $manifest_files[static::__key($not_indexed)] = ['extension' => 'js', 'class' => 'Fixture_Strings'];

        $eligible = BundleCompiler::_string_obfuscation_eligible(
            [$files['plain'], $files['controller_stub'], $files['model_stub'], $concrete, $files['class'], $not_indexed, $unknown, $files['template']],
            $manifest_files,
            $js_classes,
            [$files['template']],
            $concrete
        );

        static::__assert_equals([$files['model_stub'], $concrete, $files['class'], $files['template']], $eligible);
    }

    /**
     * Every value the fixtures produce is identical after the pass and after the pass plus
     * Terser - spaces (leading, trailing, doubled), empty strings, templates with
     * substitutions and line breaks, computed keys, the model stub's enum data, the
     * template's output and all. The expected values are spelled out too, so a fixture that
     * silently stopped exercising a case would fail here.
     */
    public static function test_obfuscated_and_minified_bundle_evaluates_to_the_original_values()
    {
        $run = static::__pipeline(static::__files());
        $minified_js = Minifier::minify_js($run['obfuscated'], 'string_obfuscation_fixture.js', true);

        $original = static::__evaluate($run['original']);

        static::__assert_equals('qualifying method phrase', $original['qualifying']);
        static::__assert_equals('  leading pad|trailing pad  |double  gap| both  sides | padonly ', $original['spaces']);
        static::__assert_equals(
            ['word_value' => 'objword', 'empty_value' => '', 'list' => ['arrword', '', ['nested_value' => 'deepword', 'nested_list' => ['deeparr']]]],
            $original['literal_data']
        );
        static::__assert_equals(['spaced key name' => 1, 'singlekey' => 2], $original['keys']);
        static::__assert_equals('greeting template NAME closing words here', $original['template']);
        static::__assert_equals("first line words\nsecond line words", $original['multiline_template']);
        static::__assert_equals(['t' => 'tplwordNAMEtplafter', 'plain' => 'tplplain'], $original['template_data']);
        static::__assert_equals('default param phrase', $original['with_default']);
        static::__assert_equals('instancefield initializer phrase', $original['instance_field']);
        static::__assert_equals('decorated method phrase', $original['decorated']);
        static::__assert_equals('decorator argument phrase', $original['decorated_label']);
        static::__assert_equals(
            ['1' => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active Status Label'], '2' => ['constant' => 'STATUS_PAUSED', 'label' => 'Paused']],
            $original['model_enum']
        );
        static::__assert_equals('Obf_Fixture_Model', $original['model_name']);
        static::__assert_contains('A paragraph of template prose.', json_encode($original['card_output']));

        static::__assert_equals($original, static::__evaluate($run['obfuscated']), 'the obfuscated bundle evaluates differently from the original');
        static::__assert_equals($original, static::__evaluate($minified_js), 'the obfuscated and minified bundle evaluates differently from the original');
    }

    /**
     * Which strings are converted, read from the rewritten copies. Class file - converted: an
     * inner-space string in a method (spaces in every position), object values and array
     * elements of any length (single words and '' included, nested literals too), a
     * qualifying key (as a computed key), template quasis, a multi-line template (line count
     * kept), an arrow nested in a method, a getter body, a body under a 'use strict'
     * directive, a top-level function's body. Literal: a single word in a method that is not
     * literal data, a string with only leading/trailing spaces, a single-word key, the tagged
     * template, the directive itself, field initializers (static and instance), the static
     * block, a parameter default and top-level code. Model stub: the enum data values
     * convert, static __MODEL stays literal. Template: its render function's strings
     * convert, the template object's top-level name stays literal.
     */
    public static function test_only_qualifying_strings_inside_function_bodies_of_eligible_files_convert()
    {
        $files = static::__files();
        $run = static::__pipeline($files);
        $class_out = $run['copies'][$files['class']] ?? static::__fail('the class file was not rewritten');

        $converted = [
            "'qualifying method phrase'",
            "'  leading pad'",
            "'trailing pad  '",
            "'double  gap'",
            "' both  sides '",
            "'objword'",
            "empty_value: ''",
            "'arrword'",
            "'deepword'",
            "'deeparr'",
            "'spaced key name'",
            'greeting template',
            'closing words here',
            'first line words',
            '`tplword',
            '`tplplain`',
            "'nested arrow phrase'",
            "'getter body phrase'",
            "'directive method phrase'",
            "'toplevel function phrase'",
            "'decorated method phrase'",
        ];
        foreach ($converted as $text) {
            static::__assert_false(str_contains($class_out, $text), "expected {$text} to be converted, but it is still literal");
        }

        $literal = [
            "'loneword'",
            "' padonly '",
            'singlekey: 2',
            'String.raw`tagged template ${name} stays`',
            "'use strict';",
            "static fixture_static_field = 'staticfield initializer phrase';",
            "this.fixture_static_block = 'staticblock body phrase';",
            "fixture_instance_field = 'instancefield initializer phrase';",
            "static with_default(label = 'default param phrase') {",
            "var fixture_top_code = 'toplevel code phrase';",
            "@fixture_label('decorator argument phrase')",
        ];
        foreach ($literal as $text) {
            static::__assert_contains($text, $class_out, "expected {$text} to stay literal");
        }

        // A qualifying key becomes computed; the nested arrow and the template call the printer.
        static::__assert_true((bool) preg_match('/\{ \[_\$\$\([0-9,]+\)\]: 1, singlekey: 2 \}/', $class_out), 'the qualifying key is not a computed _$$() key');
        static::__assert_true((bool) preg_match('/const inner = \(\) => _\$\$\([0-9,]+\);/', $class_out), 'the nested arrow was not converted');
        static::__assert_true((bool) preg_match('/`\$\{_\$\$\([0-9,]+\)\}\$\{name\}\$\{_\$\$\([0-9,]+\)\}`/', $class_out), 'the template quasis were not rewritten as substitutions');
        static::__assert_equals(substr_count(file_get_contents($files['class']), "\n"), substr_count($class_out, "\n"), 'the rewritten class file changed its line count');

        $stub_out = $run['copies'][$files['model_stub']] ?? static::__fail('the model stub was not rewritten');
        static::__assert_contains("static __MODEL = 'Obf_Fixture_Model';", $stub_out);
        static::__assert_true((bool) preg_match('/data\[1\] = \{"constant":_\$\$\([0-9]+\),"label":_\$\$\([0-9,]+\)\};/', $stub_out), 'the model stub enum data values were not converted');

        $template_out = $run['copies'][$files['template']] ?? static::__fail('the compiled template was not rewritten');
        static::__assert_contains("name: 'Obf_Fixture_Card',", $template_out);
        static::__assert_false(str_contains($template_out, 'card heading tooltip'), 'the template attribute value was not converted');
        static::__assert_false(str_contains($template_out, 'A paragraph of template prose.'), 'the template prose was not converted');
    }

    /**
     * The pass runs on the ORIGINAL source and babel runs on its output: in the file the
     * bundle actually reads, the instance field initializer babel lowered into the
     * constructor is still literal, babel's own injected helper strings are untouched, and
     * the method strings are printer calls.
     */
    public static function test_babel_transforms_the_obfuscated_source()
    {
        $files = static::__files();
        $run = static::__pipeline($files);
        $final = $run['final'][$files['class']] ?? static::__fail('the class file was not rewritten');

        static::__assert_contains('_defineProperty(this, "fixture_instance_field", \'instancefield initializer phrase\')', $final, 'the instance field initializer is not literal in the babel output');
        static::__assert_contains('attempted to call addInitializer after decoration was finished', $final, 'a babel helper string was converted');
        static::__assert_contains("'decorator argument phrase'", $final);
        static::__assert_false(str_contains($final, 'decorated method phrase'), 'the decorated method body was not converted');
        static::__assert_false(str_contains($final, 'qualifying method phrase'), 'a method string was not converted');
        static::__assert_true(str_contains($final, '_applyDecs'), 'the class file was not babel-transformed');
    }

    /**
     * A file that is not eligible is never rewritten and reaches the concatenation byte for
     * byte: the non-class file and the controller stub - both full of strings that would
     * qualify inside an eligible file.
     */
    public static function test_non_eligible_files_are_untouched()
    {
        $files = static::__files();
        $run = static::__pipeline($files);

        foreach (['plain', 'controller_stub'] as $role) {
            static::__assert_false(isset($run['copies'][$files[$role]]), "the {$role} file was rewritten");
        }
        static::__assert_contains(rtrim(file_get_contents($files['controller_stub'])), $run['obfuscated'], 'the controller stub does not reach the bundle verbatim');

        // The plain file reaches the bundle as its ordinary cached babel transform.
        $plain_entry = array_values(array_filter($run['entries'], fn ($entry) => $entry['source'] === $files['plain']))[0];
        static::__assert_equals(Js_Transformer::transform_to_file($files['plain']), $plain_entry['path']);

        static::__assert_contains("note: 'controller stub phrase'", $run['obfuscated']);
        static::__assert_contains("plain_key: 'plain function phrase'", $run['obfuscated']);
    }

    /**
     * The generated helper file (`var _$$SC = [...]`, `function _$$`) is inserted into the
     * concatenation list directly before the FIRST eligible file - whichever kind that is -
     * and every eligible entry after it is its rewritten copy, attributed to its original.
     */
    public static function test_helper_file_is_placed_directly_before_the_first_eligible_file()
    {
        $files = static::__files();

        $run = static::__pipeline($files);
        $paths = array_column($run['entries'], 'path');
        static::__assert_count(6, $run['entries']);
        static::__assert_equals($files['plain'], $run['entries'][0]['source']);
        static::__assert_equals($files['controller_stub'], $run['entries'][1]['path']);
        static::__assert_true(str_ends_with($paths[2], 'string_dictionary.js'), 'the helper file is not directly before the model stub');
        static::__assert_equals([$files['model_stub'], $files['class'], $files['template']], array_column(array_slice($run['entries'], 3), 'source'));
        static::__assert_contains('var _$$SC = [', $run['helper']);
        static::__assert_contains('function _$$(', $run['helper']);
        static::__assert_equals(1, substr_count($run['obfuscated'], 'var _$$SC'), 'the dictionary was emitted more than once');

        // With the class file first in output order, the helper precedes it instead.
        $reordered = ['plain' => $files['plain'], 'class' => $files['class'], 'model_stub' => $files['model_stub'], 'template' => $files['template']];
        $reordered_run = static::__pipeline($reordered, static::__eligible($files));
        $reordered_paths = array_column($reordered_run['entries'], 'path');
        static::__assert_true(str_ends_with($reordered_paths[1], 'string_dictionary.js'), 'the helper file is not directly before the class file');
        static::__assert_equals($files['class'], $reordered_run['entries'][2]['source']);
    }

    /**
     * Identical input gives byte-identical output; a changed input still round-trips and
     * produces a different dictionary order.
     */
    public static function test_output_is_deterministic_and_a_changed_input_still_round_trips()
    {
        $files = static::__files();
        $first = static::__pipeline($files);
        static::__assert_equals($first['obfuscated'], static::__pipeline($files)['obfuscated'], 'two runs over the same input differ');

        $changed_class = str_replace(
            "    static single_word() {",
            "    static added() {\n        return 'an added sentence of new words';\n    }\n\n    static single_word() {",
            file_get_contents($files['class'])
        );
        $changed_plain = str_replace(
            "        getter: instance.getter_value,\n",
            "        getter: instance.getter_value,\n        added: Fixture_Strings.added(),\n",
            file_get_contents($files['plain'])
        );
        $changed_files = $files;
        $changed_files['class'] = static::__source_scratch_file('changed_class', $changed_class);
        $changed_files['plain'] = static::__scratch_file('changed_plain.js', $changed_plain);

        $eligible = [$changed_files['model_stub'], $changed_files['class'], $changed_files['template']];
        $changed = static::__pipeline($changed_files, $eligible);

        $values = static::__evaluate($changed['obfuscated']);
        static::__assert_equals('an added sentence of new words', $values['added'] ?? null);
        static::__assert_equals(static::__evaluate($changed['original']), $values, 'the changed input does not round-trip');
        static::__assert_not_equals($first['helper'], $changed['helper'], 'a changed word set kept the same dictionary');
    }

    /**
     * Nothing to convert means nothing emitted: no eligible file at all, and eligible files
     * holding no qualifying string, both return the concatenation list unchanged with no
     * helper.
     */
    public static function test_nothing_converted_leaves_the_list_unchanged()
    {
        $files = static::__files();
        $list = [$files['plain'], $files['controller_stub']];

        $none = BundleCompiler::_obfuscate_sources($list, [], '', 'Fixture_Bundle', true);
        static::__assert_null($none['helper_path']);
        static::__assert_equals([], $none['read_paths']);
        static::__assert_equals([], $none['scratch_files']);
        static::__assert_equals(
            BundleCompiler::_concat_entries($list, []),
            BundleCompiler::_concat_entries($list, $none['read_paths'], [], $none['helper_path'])
        );

        $quiet = static::__scratch_file('quiet.js', "class Quiet { static x = 'field phrase here'; y = 'instance phrase here'; m(a = 'default phrase') { return 'word'; } }\n");
        $quiet_list = array_merge($list, [$quiet]);
        $nothing = BundleCompiler::_obfuscate_sources($quiet_list, [$quiet], '', 'Fixture_Bundle', true);
        static::__assert_null($nothing['helper_path']);
        static::__assert_equals([], $nothing['read_paths']);
        static::__assert_equals(0, $nothing['result']['converted']);
        static::__assert_equals(
            BundleCompiler::_concat_entries($quiet_list, []),
            BundleCompiler::_concat_entries($quiet_list, $nothing['read_paths'], [$quiet], $nothing['helper_path'])
        );
    }

    /**
     * Either reserved name already in use - in an eligible file, in another app file of the
     * bundle, or in the vendor output - fails the build, naming the bundle and the identifier.
     */
    public static function test_reserved_name_collision_throws()
    {
        $files = static::__files();

        $eligible_printer = static::__scratch_file('collide_eligible.js', "function _\$\$() {}\n" . file_get_contents($files['class']));
        $colliding = $files;
        $colliding['class'] = $eligible_printer;
        static::__assert_throws(
            RuntimeException::class,
            fn () => static::__pipeline($colliding, [$colliding['class']]),
            "bundle Fixture_Bundle already uses the identifier '_\$\$' in its app"
        );

        $other_dictionary = static::__scratch_file('collide_other.js', "var _\$\$SC = 1;\n");
        $with_other = ['other' => $other_dictionary] + $files;
        static::__assert_throws(
            RuntimeException::class,
            fn () => static::__pipeline($with_other, static::__eligible($files)),
            "bundle Fixture_Bundle already uses the identifier '_\$\$SC' in its app"
        );

        $vendor = static::__scratch_file('collide_vendor.js', "window._\$\$=function(){};\n");
        static::__assert_throws(
            RuntimeException::class,
            fn () => static::__pipeline($files, null, $vendor),
            "bundle Fixture_Bundle already uses the identifier '_\$\$' in its vendor"
        );
    }
}
