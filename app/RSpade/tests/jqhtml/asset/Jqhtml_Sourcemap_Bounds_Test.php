<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Jqhtml\Asset;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Integrations\Jqhtml\JqhtmlWebpackCompiler;

/**
 * A compiled template's sourcemap must never describe a generated line the file does not have.
 *
 * The jqhtml compiler builds its mappings from the SOURCE: one segment for every source line.
 * A Define whose body renders nothing compiles to a ONE-LINE render function, so a template
 * carrying a long <%-- --%> header produces far more source lines than code lines - and the
 * map then claims generated lines that do not exist.
 *
 * That is not cosmetic. Bundle concatenation runs the map through Mozilla's
 * SourceNode.fromStringWithSourceMap, which materializes every generated line the map names;
 * each phantom line arrives in the bundle as a bare `undefined` identifier at top level, and
 * the whole bundle throws the moment it executes.
 *
 * Two halves are proved here: the compiler emits a bounded map, and the concatenator REFUSES
 * an unbounded one instead of silently emitting the phantoms.
 */
class Jqhtml_Sourcemap_Bounds_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    protected const FIXTURE_DIR = 'app/RSpade/tests/jqhtml/asset/resource/sourcemap_fixture-temp';

    /**
     * A template whose comment header dwarfs its (empty) body - the shape that overran the map.
     */
    protected static function __fixture_template(): string
    {
        $comment_lines = [];
        for ($i = 1; $i <= 20; $i++) {
            $comment_lines[] = "Comment line {$i} - the header is deliberately longer than the generated code.";
        }

        return "<%--\n" . implode("\n", $comment_lines) . "\n--%>\n"
            . "<Define:Rsx_Sourcemap_Fixture_Temp tag=\"div\" class=\"Rsx_Sourcemap_Fixture_Temp\"></Define:Rsx_Sourcemap_Fixture_Temp>\n";
    }

    protected static function __fixture_path(): string
    {
        return base_path(static::FIXTURE_DIR) . '/Rsx_Sourcemap_Fixture_Temp.jqhtml';
    }

    public static function setup()
    {
        $dir = base_path(static::FIXTURE_DIR);
        ensure_directory($dir);
        file_put_contents(static::__fixture_path(), static::__fixture_template());
    }

    public static function teardown()
    {
        $dir = base_path(static::FIXTURE_DIR);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    /**
     * The number of generated lines the map describes, i.e. the index of the last mapping
     * segment that actually carries a mapping.
     */
    protected static function __mapped_line_count(string $mappings): int
    {
        $segments = explode(';', $mappings);
        $mapped = 0;

        foreach ($segments as $index => $segment) {
            if ($segment !== '') {
                $mapped = $index + 1;
            }
        }

        return $mapped;
    }

    protected static function __compile_fixture(): array
    {
        $compiler = new JqhtmlWebpackCompiler();
        $compiled = $compiler->compile_file(static::__fixture_path());

        $matches = [];
        $found = preg_match('/sourceMappingURL=data:application\/json;charset=utf-8;base64,([A-Za-z0-9+\/=]+)/', $compiled, $matches);

        if (!$found) {
            static::__fail('Compiled fixture carries no inline sourcemap');
        }

        $map = json_decode(base64_decode($matches[1]), true);
        $code = preg_replace('/\n?\/\/# sourceMappingURL=[^\n]*/', '', $compiled);

        return [$compiled, $code, $map];
    }

    /**
     * The map must not name more generated lines than the compiled file has.
     */
    public static function test_empty_body_template_sourcemap_is_bounded_by_its_output()
    {
        [, $code, $map] = static::__compile_fixture();

        $code_lines = substr_count(rtrim($code, "\n"), "\n") + 1;
        $mapped_lines = static::__mapped_line_count($map['mappings']);

        static::__assert_true(
            $mapped_lines <= $code_lines,
            "Sourcemap describes {$mapped_lines} generated lines but the compiled file has {$code_lines}"
        );
    }

    /**
     * End to end: concatenating that compiled template must not produce a bare `undefined`.
     */
    public static function test_compiled_empty_body_template_concatenates_without_phantom_identifiers()
    {
        [$compiled] = static::__compile_fixture();

        $input = base_path(static::FIXTURE_DIR) . '/compiled-temp.js';
        file_put_contents($input, $compiled);

        $output = base_path(static::FIXTURE_DIR) . '/concatenated-temp.js';
        $result = static::__run_concat([$input], $output);

        static::__assert_equals(0, $result['exit'], 'concat-js failed: ' . $result['output']);

        $concatenated = file_get_contents($output);

        static::__assert_false(
            (bool) preg_match('/^\s*(?:undefined)+\s*$/m', $concatenated),
            'Concatenated output contains bare undefined identifiers'
        );
    }

    /**
     * A map that overruns its file is REFUSED, naming the file - never silently concatenated.
     */
    public static function test_concat_refuses_a_sourcemap_that_overruns_its_file()
    {
        $code = "var a_temp = 1;\nvar b_temp = 2;\n";

        $map = [
            'version' => 3,
            'sources' => ['overrun-temp.js'],
            'sourcesContent' => [$code],
            'mappings' => implode(';', array_fill(0, 40, 'AAAA')),
            'names' => [],
        ];

        $input = base_path(static::FIXTURE_DIR) . '/overrun-temp.js';
        file_put_contents(
            $input,
            $code . '//# sourceMappingURL=data:application/json;charset=utf-8;base64,' . base64_encode(json_encode($map)) . "\n"
        );

        $output = base_path(static::FIXTURE_DIR) . '/overrun_out-temp.js';
        $result = static::__run_concat([$input], $output);

        static::__assert_true($result['exit'] !== 0, 'concat-js accepted a sourcemap that overruns its file');
        static::__assert_contains('Malformed sourcemap', $result['output']);
        static::__assert_contains('overrun-temp.js', $result['output']);
    }

    /**
     * Run the bundle concatenator the way BundleCompiler runs it.
     *
     * That is over the concat RPC daemon, so a rejection arrives as a thrown exception
     * rather than a non-zero exit code; the shape returned here keeps the two callers
     * above reading the same way.
     */
    protected static function __run_concat(array $inputs, string $output_file): array
    {
        $files = [];
        foreach ($inputs as $input) {
            $files[] = ['path' => $input, 'source' => null];
        }

        try {
            $result = \App\RSpade\Core\Bundle\Concatenator::concat_js($files, $output_file);
        } catch (\Throwable $e) {
            return ['exit' => 1, 'output' => $e->getMessage()];
        }

        return ['exit' => 0, 'output' => 'concatenated ' . $result['files'] . ' file(s)'];
    }
}
