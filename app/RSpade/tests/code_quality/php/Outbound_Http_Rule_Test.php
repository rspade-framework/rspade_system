<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\OutboundHttp_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for PHP-OUTBOUND-HTTP-01 (OutboundHttp_CodeQualityRule).
 *
 * The rule asks application code making an outbound HTTP request to use Rsx_Url_Fetch for a
 * URL a user can supply, or to mark a fixed trusted destination. Fixtures are written under
 * a throwaway directory whose path carries an `rsx/` segment (the rule judges only
 * application code by path) and the rule is driven directly. The banned spellings are
 * ASSEMBLED from pieces so that this file is not itself a list of violations.
 */
class Outbound_Http_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'PHP-OUTBOUND-HTTP-01';

    /** @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation> */
    private static function __run(string $body, string $tree = 'rsx'): array
    {
        $source = "<?php\n\nclass Outbound_Probe\n{\n    public static function go(\$url, \$path)\n    {\n"
            . $body
            . "    }\n}\n";

        $dir = Rsx_Project_Paths::tmp_path() . '/outbound_http_fixture_' . uniqid();
        $path = $dir . '/' . $tree . '/lib/outbound_probe.php';
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new OutboundHttp_CodeQualityRule($collector);
        $rule->check($path, $source);
        $violations = $collector->get_by_rule(self::RULE_ID);

        @unlink($path);
        @rmdir(dirname($path));
        @rmdir(dirname($path, 2));
        @rmdir($dir);

        return $violations;
    }

    public static function test_http_facade_calls_are_flagged()
    {
        $facade = 'Ht' . 'tp';
        static::__assert_count(1, static::__run("        return {$facade}::get(\$url);\n"), 'Http::get($x)');
        static::__assert_count(1, static::__run("        return {$facade}::timeout(5)->post(\$url, []);\n"), 'a chain starting at the facade');
        static::__assert_count(1, static::__run("        return \\Illuminate\\Support\\Facades\\{$facade}::get(\$url);\n"), 'the fully qualified facade');
    }

    public static function test_guzzle_curl_and_get_headers_are_flagged()
    {
        $guzzle = 'Guzzle' . 'Http\\Client';
        static::__assert_count(1, static::__run("        \$c = new \\{$guzzle}();\n"), 'new GuzzleHttp\\Client');
        static::__assert_count(2, static::__run("        \$h = " . 'curl_' . "init(\$url);\n        " . 'curl_' . "exec(\$h);\n"), 'curl_init + curl_exec');
        static::__assert_count(1, static::__run("        return " . 'get_' . "headers('/local');\n"), 'get_headers always');
    }

    public static function test_file_functions_flag_urls_and_unknown_arguments()
    {
        $read = 'file_get_' . 'contents';
        static::__assert_count(1, static::__run("        return {$read}('ht' . 'tp://example.com');\n"), 'a concatenation of unknown parts');
        static::__assert_count(1, static::__run("        return {$read}('https://example.com/x');\n"), 'an https literal');
        static::__assert_count(1, static::__run("        return {$read}(\$url);\n"), 'a bare variable');
        static::__assert_count(1, static::__run("        return " . 'read' . "file(\$url);\n"), 'readfile of a variable');
    }

    public static function test_local_paths_are_not_flagged()
    {
        $read = 'file_get_' . 'contents';
        static::__assert_count(0, static::__run("        return {$read}('/etc/hostname');\n"), 'a local literal');
        static::__assert_count(0, static::__run("        \$h = fopen('php://memory', 'r+');\n"), 'php://memory');
        static::__assert_count(0, static::__run("        return {$read}(__DIR__ . '/resource/data.json');\n"), '__DIR__');
        static::__assert_count(0, static::__run("        return {$read}(base_path('rsx/x.json'));\n"), 'base_path()');
        static::__assert_count(0, static::__run("        return {$read}(\$file->getPathname());\n"), 'SplFileInfo path');
        static::__assert_count(0, static::__run("        return \$date->copy();\n"), 'a method named copy');
        static::__assert_count(0, static::__run("        // " . 'Ht' . "tp::get(\$url) in a comment\n        return 'Http::get(\$url)';\n"), 'comment and string');
    }

    public static function test_marker_with_reason_exempts_and_bare_marker_does_not()
    {
        $facade = 'Ht' . 'tp';
        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        static::__assert_count(0, static::__run("        // {$marker} payment provider API, fixed host\n        return {$facade}::get('https://api.example.com/');\n"), 'marker in the comment above');
        static::__assert_count(0, static::__run("        return {$facade}::get('https://api.example.com/'); // {$marker} fixed host\n"), 'marker on the line');
        static::__assert_count(1, static::__run("        // {$marker}\n        return {$facade}::get(\$url);\n"), 'a bare marker exempts nothing');
        static::__assert_count(1, static::__run("        // {$marker} fixed host\n        \$x = 1;\n        return {$facade}::get(\$url);\n"), 'a marker separated by code does not reach the call');
    }

    public static function test_framework_code_is_not_judged()
    {
        $facade = 'Ht' . 'tp';
        static::__assert_count(0, static::__run("        return {$facade}::get(\$url);\n", 'app/RSpade/Lib'), 'outside rsx/');
    }
}
