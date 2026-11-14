<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\PHP\Php_Parser;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Php_Parser pairs braces: a '}' closing a string interpolation ("{$a}", "${a}") is told
 * from a '}' closing a block by the brace it pairs with, never by how far away an
 * interpolation opener is.
 *
 * Every fixture is a class in a scratch `rsx/` tree (so the structure validators judge it)
 * whose methods interpolate, followed by a static property and - after the class - one
 * global function. Scope is right exactly when the static property is recorded (class depth
 * restored after each method) and the global function is the ONLY structure violation
 * (file depth restored after the class); a method misread as a global function would add a
 * PHP-STRUCTURE-01 "Global function" violation of its own.
 */
class Php_Parser_Brace_Pairing_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Php_Parser::parse() of $source written at a scratch rsx/lib/ path */
    private static function __parse(string $source): array
    {
        $root = Rsx_Project_Paths::tmp_path('php_parser_braces_' . random_hash(8));
        $file = $root . '/rsx/lib/brace_probe.php';
        ensure_directory(dirname($file));
        file_put_contents($file, $source);

        try {
            return Php_Parser::parse($file);
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    /** A class around $methods, a static property after them, and a stray global function */
    private static function __fixture(string $methods): string
    {
        return "<?php\n"
            . "class Brace_Probe\n"
            . "{\n"
            . $methods
            . "\n"
            . "    public static \$after_methods = 1;\n"
            . "}\n"
            . "\n"
            . "function stray_global() {}\n";
    }

    /** Assert the scope facts every fixture must produce */
    private static function __assert_scope(array $parsed, string $case): void
    {
        static::__assert_equals('Brace_Probe', $parsed['class'] ?? null, "{$case}: the class is found");
        static::__assert_true(
            isset($parsed['static_properties']['after_methods']),
            "{$case}: the static property after the methods is recorded (class depth restored)"
        );

        $globals = array_map(fn ($v) => $v['function'] ?? null, $parsed['structure_violations'] ?? []);
        static::__assert_equals(
            ['stray_global'],
            $globals,
            "{$case}: only the function after the class is a global function (file depth restored)"
        );
    }

    public static function test_block_closed_right_after_an_interpolation()
    {
        $parsed = static::__parse(static::__fixture(<<<'PHP'
    public static function first($x, $a)
    {
        if ($x) { return "{$a}"; }
        return 1;
    }

    public static function second()
    {
        return 2;
    }
PHP));

        static::__assert_scope($parsed, 'if ($x) { return "{$a}"; }');
    }

    public static function test_interpolation_longer_than_ten_tokens()
    {
        $parsed = static::__parse(static::__fixture(<<<'PHP'
    public static function first($a)
    {
        return "{$a['b'][0]['c']['d']['e']} text";
    }

    public static function second()
    {
        return 2;
    }
PHP));

        static::__assert_scope($parsed, 'a long interpolation followed by a closing brace');
    }

    public static function test_dollar_brace_interpolation()
    {
        $parsed = static::__parse(static::__fixture(<<<'PHP'
    public static function first($name)
    {
        return "${name} text";
    }

    public static function second()
    {
        return 2;
    }
PHP));

        static::__assert_scope($parsed, '"${name}"');
    }

    public static function test_nested_interpolation()
    {
        $parsed = static::__parse(static::__fixture(<<<'PHP'
    public static function first($a, $c)
    {
        if ($a) { return "{$a->b("{$c->d['e']}")}"; }
        return "{$a->b("x{$c}y")} and {$c}";
    }

    public static function second()
    {
        return 2;
    }
PHP));

        static::__assert_scope($parsed, 'an interpolation nested inside an interpolation');
    }

    public static function test_heredoc_and_nowdoc()
    {
        $parsed = static::__parse(static::__fixture(<<<'PHP'
    public static function first($a, $name)
    {
        $here = <<<TEXT
            {$a['b'][0]['c']['d']['e']} and ${name} and {$a->f}
            TEXT;
        $now = <<<'TEXT'
            {$not} an { interpolation } }
            TEXT;
        if ($a) { return $here . $now; }
        return '';
    }

    public static function second()
    {
        return 2;
    }
PHP));

        static::__assert_scope($parsed, 'a heredoc and a nowdoc');
    }

    public static function test_interpolation_parses_like_concatenation()
    {
        $interpolated = static::__parse(static::__fixture(<<<'PHP'
    public static $before = 0;

    public static function first($x, $a, $name, $c)
    {
        if ($x) { return "{$a}"; }
        $long = "{$a['b'][0]['c']['d']['e']} text";
        $dollar = "${name} text";
        $nested = "{$a->b("{$c}")}";
        $here = <<<TEXT
            {$a['b'][0]['c']} ${name}
            TEXT;
        return $long . $dollar . $nested . $here;
    }

    protected static $between = [];

    public static function second()
    {
        if (true) { return "{$GLOBALS['x']['y']['z']}"; }
    }
PHP));

        $concatenated = static::__parse(static::__fixture(<<<'PHP'
    public static $before = 0;

    public static function first($x, $a, $name, $c)
    {
        if ($x) { return '' . $a; }
        $long = $a['b'][0]['c']['d']['e'] . ' text';
        $dollar = $name . ' text';
        $nested = $a->b('' . $c);
        $here = $a['b'][0]['c'] . ' ' . $name;
        return $long . $dollar . $nested . $here;
    }

    protected static $between = [];

    public static function second()
    {
        if (true) { return '' . $GLOBALS['x']['y']['z']; }
    }
PHP));

        static::__assert_scope($interpolated, 'the mixed interpolated file');
        static::__assert_scope($concatenated, 'the concatenated file');

        foreach (['class', 'namespace', 'extends', 'global_functions', 'global_constants', 'static_properties', 'structure_violations'] as $key) {
            static::__assert_equals(
                $concatenated[$key] ?? null,
                $interpolated[$key] ?? null,
                "'{$key}' is the same whether the file interpolates or concatenates"
            );
        }
    }
}
