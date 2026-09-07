<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Bundles\Asset;

use RuntimeException;
use App\RSpade\Core\Bundle\Concatenator;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The bundle concatenator joins a bundle's files without a ceiling on how many there are.
 *
 * THE DEFECT THESE TESTS EXIST FOR. Concatenation used to run as a shell command that named
 * every file in the bundle: `node concat-js.js <output> <file> <file> ...`, assembled into a
 * single string and handed to the process spawner as ONE argv element. Linux caps one argument
 * at MAX_ARG_STRLEN (32 pages = 131072 bytes), so a bundle whose file list crossed that
 * total could not be spelled on a command line at all - and because the limit is a cliff
 * rather than a gradient, the build did not slow down or degrade: it failed on every
 * invocation from that moment on, taking every route in the application to a 500 and
 * blaming the innocent file whose addition tipped it over. A downstream application hit it
 * at 1,310 bundled JS files with roughly 18 files of headroom left.
 *
 * That is invisible in this monorepo, whose largest bundle carries 82 JavaScript files, so
 * no fixture built from the template app would ever have caught it. CONCAT-04 therefore
 * builds a file list deliberately past the ceiling and proves it now compiles - the test
 * whose absence let the defect ship.
 *
 * Everything is written into a scratch directory under this concern's `resource/` directory,
 * which is framework-ignored, so the manifest never indexes the fixtures; teardown() removes
 * it. The live `rsx/` tree is never touched.
 */
class Bundle_Concat_Service_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * base_path()-relative scratch root for the fixtures.
     */
    protected const FIXTURE_RELATIVE = 'app/RSpade/tests/bundles/asset/resource/concat_fixture-temp';

    /**
     * Absolute scratch root.
     */
    protected static function __fixture_root(): string
    {
        return base_path(static::FIXTURE_RELATIVE);
    }

    /**
     * Write one fixture file and return its absolute path.
     */
    protected static function __write_fixture(string $relative_path, string $contents): string
    {
        $absolute = static::__fixture_root() . '/' . $relative_path;

        ensure_directory(dirname($absolute));
        file_put_contents_safe($absolute, $contents);

        return $absolute;
    }

    /**
     * Where a concatenated artifact is written for these tests.
     */
    protected static function __output_path(string $name): string
    {
        return static::__fixture_root() . '/' . $name;
    }

    public static function setup()
    {
        ensure_directory(static::__fixture_root());
    }

    public static function teardown()
    {
        $root = static::__fixture_root();

        if (is_dir($root)) {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * CONCAT-01 - JavaScript files are joined in order, each behind its own banner, with a
     * decodable inline sourcemap naming every input.
     */
    public static function test_concat_js_joins_files_with_banners_and_an_inline_sourcemap()
    {
        $first = static::__write_fixture('first.js', "const concat_fixture_first = 1;\n");
        $second = static::__write_fixture('second.js', "const concat_fixture_second = 2;\n");
        $output = static::__output_path('joined.js');

        $result = Concatenator::concat_js(
            [
                ['path' => $first, 'source' => null],
                ['path' => $second, 'source' => null],
            ],
            $output
        );

        static::__assert_equals(2, $result['files'], 'the service reported a different file count');
        static::__assert_true($result['bytes'] > 0, 'the service reported an empty artifact');
        static::__assert_true(file_exists($output), 'no artifact was written');

        $joined = file_get_contents($output);

        // Order is the caller's order, and each file is announced.
        static::__assert_contains('/* === ' . relative_path($first) . ' === */', $joined);
        static::__assert_contains('/* === ' . relative_path($second) . ' === */', $joined);
        static::__assert_true(
            strpos($joined, 'concat_fixture_first') < strpos($joined, 'concat_fixture_second'),
            'the files were concatenated out of order'
        );

        // The inline sourcemap is real: it decodes, and it names both inputs.
        static::__assert_true(
            preg_match(
                '~//# sourceMappingURL=data:application/json;charset=utf-8;base64,([A-Za-z0-9+/=]+)~',
                $joined,
                $matches
            ) === 1,
            'the artifact carries no inline sourcemap'
        );

        $map = json_decode(base64_decode($matches[1]), true);

        static::__assert_equals(3, $map['version'], 'the sourcemap is not a version 3 map');
        static::__assert_equals(2, count($map['sources']), 'the sourcemap does not name both inputs');
        static::__assert_true($result['sourcemap_bytes'] > 0, 'the service reported no sourcemap');
    }

    /**
     * CONCAT-02 - A file read from one path but ATTRIBUTED to another (what a babel
     * transform produces) is banner-marked `(babel)` and mapped to the developer's own file,
     * never to the temp file the bytes came from.
     *
     * This replaced a `<babel path>::<original path>` string that used to be packed into a
     * single argv token; off argv the two paths travel as their own fields.
     */
    public static function test_a_transformed_file_is_attributed_to_its_original_source()
    {
        $original = static::__write_fixture('original.js', "class Concat_Fixture {}\n");
        $transformed = static::__write_fixture('transformed.js', "var Concat_Fixture = function () {};\n");
        $output = static::__output_path('attributed.js');

        Concatenator::concat_js(
            [['path' => $transformed, 'source' => $original]],
            $output
        );

        $joined = file_get_contents($output);

        static::__assert_contains('/* === ' . relative_path($original) . ' (babel) === */', $joined);

        // The bytes are the transformed ones...
        static::__assert_contains('var Concat_Fixture = function', $joined);

        // ...while the sourcemap points at the file the developer edits.
        preg_match(
            '~//# sourceMappingURL=data:application/json;charset=utf-8;base64,([A-Za-z0-9+/=]+)~',
            $joined,
            $matches
        );
        $map = json_decode(base64_decode($matches[1]), true);

        static::__assert_contains(relative_path($original), implode(' ', $map['sources']));
        static::__assert_true(
            strpos(implode(' ', $map['sources']), 'transformed.js') === false,
            'the sourcemap named the transformed temp file instead of the original source'
        );
    }

    /**
     * CONCAT-03 - CSS files are joined with banners and their own inline sourcemap comment.
     */
    public static function test_concat_css_joins_files_with_an_inline_sourcemap()
    {
        $first = static::__write_fixture('first.css', ".concat_fixture_first { color: red; }\n");
        $second = static::__write_fixture('second.css', ".concat_fixture_second { color: blue; }\n");
        $output = static::__output_path('joined.css');

        $result = Concatenator::concat_css(
            [
                ['path' => $first, 'source' => null],
                ['path' => $second, 'source' => null],
            ],
            $output
        );

        static::__assert_equals(2, $result['files'], 'the service reported a different file count');

        $joined = file_get_contents($output);

        static::__assert_contains('.concat_fixture_first', $joined);
        static::__assert_contains('.concat_fixture_second', $joined);
        static::__assert_contains('/* === ' . relative_path($first) . ' === */', $joined);
        static::__assert_contains('/*# sourceMappingURL=data:application/json;charset=utf-8;base64,', $joined);
    }

    /**
     * CONCAT-04 - THE REGRESSION TEST. A file list far past the single-argument ceiling
     * concatenates successfully.
     *
     * The arithmetic, so the number is not arbitrary: the fixture writes 1,500 files whose
     * absolute paths are each roughly 100 bytes once shell-quoted. The OLD construction
     * therefore assembled a command string of about 150,000 bytes - some 19,000 bytes past
     * the 131,072-byte MAX_ARG_STRLEN ceiling - and would have failed with
     * `posix_spawn() failed: Argument list too long` before node ever started. The test
     * asserts the equivalent argv length exceeds the ceiling (so it stays a real regression
     * test even if the fixture paths change length) and that the concatenation nevertheless
     * completes with every file present.
     */
    public static function test_a_file_list_past_the_argv_ceiling_still_concatenates()
    {
        $file_count = 1500;
        $files = [];
        $argv_bytes = 0;

        for ($index = 0; $index < $file_count; $index++) {
            // A padded name, so each path is realistically long rather than artificially short.
            $name = sprintf('bulk/concat_fixture_padded_module_%04d.js', $index);
            $path = static::__write_fixture($name, "var concat_bulk_{$index} = {$index};\n");

            $files[] = ['path' => $path, 'source' => null];

            // What the retired shell construction would have added for this file.
            $argv_bytes += strlen(escapeshellarg($path)) + 1;
        }

        static::__assert_true(
            $argv_bytes > ARG_MAX_SINGLE_BYTES,
            'the fixture is too small to reproduce the ceiling: ' . number_format($argv_bytes)
                . ' bytes of argv against a ' . number_format(ARG_MAX_SINGLE_BYTES) . '-byte limit'
        );

        $output = static::__output_path('bulk.js');
        $result = Concatenator::concat_js($files, $output);

        static::__assert_equals($file_count, $result['files'], 'not every file was concatenated');
        static::__assert_true(file_exists($output), 'no artifact was written');

        $joined = file_get_contents($output);

        // Spot-check the ends of the list rather than all 1,500.
        static::__assert_contains('var concat_bulk_0 = 0;', $joined);
        static::__assert_contains('var concat_bulk_1499 = 1499;', $joined);
        static::__assert_equals(
            $file_count,
            preg_match_all('~/\* === .+ === \*/~', $joined),
            'a banner is missing for at least one file'
        );
    }

    /**
     * CONCAT-05 - A sourcemap describing more generated lines than its file has is refused,
     * loudly, naming the offending file.
     *
     * Left unchecked, Mozilla's SourceNode materializes every generated line the map claims,
     * so each phantom line becomes a bare `undefined` identifier at the top level of the
     * bundle - which throws the moment the bundle executes, taking the whole bundle with it.
     */
    public static function test_a_sourcemap_that_overruns_its_file_is_refused()
    {
        $map = [
            'version' => 3,
            'file' => 'overrun.js',
            'sources' => ['overrun_source.js'],
            'sourcesContent' => ["var a = 1;\n"],
            'names' => [],
            // Five mapped generated lines for a one-line file.
            'mappings' => 'AAAA;AAAA;AAAA;AAAA;AAAA',
        ];

        $path = static::__write_fixture(
            'overrun.js',
            "var concat_overrun = 1;\n"
                . '//# sourceMappingURL=data:application/json;charset=utf-8;base64,'
                . base64_encode(json_encode($map)) . "\n"
        );

        $threw = false;
        $message = '';

        try {
            Concatenator::concat_js([['path' => $path, 'source' => null]], static::__output_path('overrun_out.js'));
        } catch (RuntimeException $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        static::__assert_true($threw, 'a malformed sourcemap was accepted');
        static::__assert_contains('Malformed sourcemap', $message);
        static::__assert_contains('overrun.js', $message);
    }

    /**
     * CONCAT-06 - A missing input file fails loudly and names the file.
     */
    public static function test_a_missing_input_file_is_named()
    {
        $missing = static::__fixture_root() . '/never_written.js';

        $threw = false;
        $message = '';

        try {
            Concatenator::concat_js([['path' => $missing, 'source' => null]], static::__output_path('missing_out.js'));
        } catch (RuntimeException $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        static::__assert_true($threw, 'a missing input file was accepted');
        static::__assert_contains('never_written.js', $message);
    }

    /**
     * CONCAT-07 - An empty file list is a compiler bug, not an empty artifact, and says so.
     */
    public static function test_an_empty_file_list_is_refused()
    {
        $threw = false;

        try {
            Concatenator::concat_js([], static::__output_path('empty_out.js'));
        } catch (RuntimeException $e) {
            $threw = true;
        }

        static::__assert_true($threw, 'an empty file list produced an artifact');
    }

    /**
     * CONCAT-08 - The node service answers its own health check, and is running after a
     * concatenation - i.e. concat spawned it on demand and left it serving.
     */
    public static function test_the_daemon_is_reachable_after_a_concatenation()
    {
        $path = static::__write_fixture('ping.js', "var concat_ping = 1;\n");

        Concatenator::concat_js([['path' => $path, 'source' => null]], static::__output_path('ping_out.js'));

        static::__assert_true(Rsx_Node_Service::ping(), 'the node service did not answer a ping');
    }

    /**
     * CONCAT-09 - THE MAP-FIDELITY REGRESSION TEST. A rule in the concatenated bundle maps
     * back to the file and line it actually came from.
     *
     * THE DEFECT THIS PINS. The CSS merge used to keep a hand-tracked line counter beside
     * the emitted text (born one line out of step with its own header) and remapped chunks
     * by probing originalPositionFor() at column 0 of every line (dropping any line whose
     * mappings start past column 0). Bundled rules then resolved to the WRONG SOURCE FILE
     * in the browser's devtools - a `.Section__header` rule mapped into a different
     * component's stylesheet entirely. The mapped-chunk assertion below fails under either
     * defect: the off-by-one shifts the generated line, and the column-0 probe cannot
     * reproduce the synthetic map's exact original lines.
     *
     * Two chunks: one carrying its own inline sourcemap (the sass-compile shape) whose
     * mappings deliberately point at lines 10 and 20 of a virtual original, and one plain
     * chunk (the identity branch). The final merged map is decoded segment by segment -
     * a sourcemap is base64-VLQ, not JSON, so the decoder below is what "read the map"
     * costs - and each selector's generated line must carry a mapping to the right
     * original source and line.
     */
    public static function test_concat_css_maps_rules_to_their_original_source_lines()
    {
        // The mapped chunk: two one-line rules, plus the inline map naming a virtual
        // original. "AASA;AAUA" decodes to: line 1 col 0 -> source 0 line 10 col 0,
        // line 2 col 0 -> source 0 line 20 col 0 (VLQ deltas 9 and +10, 0-based).
        $chunk_map = [
            'version' => 3,
            'sources' => ['virtual/original_a.scss'],
            'sourcesContent' => [str_repeat("\n", 25)],
            'names' => [],
            'mappings' => 'AASA;AAUA',
        ];
        $mapped_css = ".fixture_mapped_first { color: red; }\n"
            . ".fixture_mapped_second { color: blue; }\n"
            . '/*# sourceMappingURL=data:application/json;charset=utf-8;base64,'
            . base64_encode(json_encode($chunk_map)) . " */\n";

        $mapped = static::__write_fixture('mapped.css', $mapped_css);
        $plain = static::__write_fixture('plain.css', ".fixture_plain { color: green; }\n");
        $output = static::__output_path('map_fidelity.css');

        Concatenator::concat_css(
            [
                ['path' => $mapped, 'source' => null],
                ['path' => $plain, 'source' => null],
            ],
            $output
        );

        $joined = file_get_contents($output);

        if (!preg_match('/sourceMappingURL=data:application\/json;charset=utf-8;base64,([^ ]+) \*\//', $joined, $m)) {
            throw new RuntimeException('the concatenated bundle carries no inline sourcemap');
        }

        $map = json_decode(base64_decode($m[1]), true);
        $mappings = static::__decode_vlq_mappings($map['mappings']);
        $lines = explode("\n", $joined);

        // Each probe: the selector's 1-based generated line must carry a mapping to the
        // expected source (suffix match - the merge relativizes paths) at the expected
        // 1-based original line.
        $probes = [
            ['.fixture_mapped_first', 'virtual/original_a.scss', 10],
            ['.fixture_mapped_second', 'virtual/original_a.scss', 20],
            ['.fixture_plain', 'plain.css', 1],
        ];

        foreach ($probes as [$selector, $expected_source, $expected_line]) {
            $generated_line = null;
            foreach ($lines as $index => $line) {
                if (str_starts_with($line, $selector . ' ')) {
                    $generated_line = $index + 1;
                    break;
                }
            }
            static::__assert_true($generated_line !== null, "{$selector} is not in the bundle");

            $hit = null;
            foreach ($mappings as $mapping) {
                if ($mapping['generated_line'] === $generated_line && $mapping['source_index'] !== null) {
                    $hit = $mapping;
                    break;
                }
            }
            static::__assert_true($hit !== null, "{$selector} (generated line {$generated_line}) carries no mapping at all");

            $source = $map['sources'][$hit['source_index']];
            static::__assert_true(
                str_ends_with($source, $expected_source),
                "{$selector} maps to '{$source}', expected a path ending '{$expected_source}'"
            );
            static::__assert_equals(
                $expected_line,
                $hit['original_line'],
                "{$selector} maps to {$source}:{$hit['original_line']}, expected line {$expected_line}"
            );
        }
    }

    /**
     * Decode a sourcemap's `mappings` string into rows of
     * {generated_line (1-based), generated_column, source_index, original_line (1-based)}.
     *
     * Base64-VLQ per the sourcemap v3 spec: groups split by ';' (one per generated line),
     * segments by ',', each segment 1/4/5 delta-encoded fields. Source index, line and
     * column deltas carry across line boundaries; the generated column resets per line.
     */
    protected static function __decode_vlq_mappings(string $mappings): array
    {
        $base64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $char_values = array_flip(str_split($base64));

        $decode_segment = static function (string $segment) use ($char_values): array {
            $values = [];
            $value = 0;
            $shift = 0;
            foreach (str_split($segment) as $char) {
                $digit = $char_values[$char];
                $value += ($digit & 31) << $shift;
                if ($digit & 32) {
                    $shift += 5;
                } else {
                    $values[] = ($value & 1) ? -($value >> 1) : ($value >> 1);
                    $value = 0;
                    $shift = 0;
                }
            }
            return $values;
        };

        $rows = [];
        $source_index = 0;
        $original_line = 0;

        foreach (explode(';', $mappings) as $line_index => $group) {
            $generated_column = 0;
            if ($group === '') {
                continue;
            }
            foreach (explode(',', $group) as $segment) {
                $fields = $decode_segment($segment);
                $generated_column += $fields[0];
                $has_source = count($fields) >= 4;
                if ($has_source) {
                    $source_index += $fields[1];
                    $original_line += $fields[2];
                }
                $rows[] = [
                    'generated_line' => $line_index + 1,
                    'generated_column' => $generated_column,
                    'source_index' => $has_source ? $source_index : null,
                    'original_line' => $has_source ? $original_line + 1 : null,
                ];
            }
        }

        return $rows;
    }
}
