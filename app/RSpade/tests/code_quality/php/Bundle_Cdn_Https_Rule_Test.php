<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Convention\BundleCdnHttps_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * BUNDLE-CDN-01: every cdn_assets entry a bundle declares is an absolute https:// URL.
 *
 * The rule's evaluate_definition() seam is driven over SYNTHETIC definitions, so no bundle
 * class is declared; check() itself is driven over the framework's real CDN bundles
 * (Jquery_Bundle, Bootstrap5_Bundle) to prove the shipped declarations pass.
 */
class Bundle_Cdn_Https_Rule_Test extends Rsx_Test_Abstract
{
    // Pure declaration analysis; no database.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'BUNDLE-CDN-01';

    private static function __evaluate(array $definition): array
    {
        $collector = new ViolationCollector();
        (new BundleCdnHttps_CodeQualityRule($collector))->evaluate_definition(
            base_path('rsx/theme/fixture_bundle.php'),
            "<?php\nreturn ['cdn_assets' => []];\n",
            'Fixture_Bundle',
            $definition
        );

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    public static function test_https_urls_pass()
    {
        $violations = static::__evaluate(['cdn_assets' => [
            'js' => [['url' => 'https://cdn.example.com/a.js', 'integrity' => 'sha384-x']],
            'css' => [['url' => 'https://cdn.example.com/a.css']],
        ]]);

        static::__assert_count(0, $violations);
        static::__assert_count(0, static::__evaluate(['include' => []]), 'no cdn_assets declares nothing to judge');
    }

    public static function test_every_other_scheme_is_refused()
    {
        foreach (['http://cdn.example.com/a.js', '//cdn.example.com/a.js', 'cdn.example.com/a.js', 'ftp://cdn.example.com/a.js', 'file:///tmp/a.js', 'HTTPS://cdn.example.com/a.js'] as $url) {
            $violations = static::__evaluate(['cdn_assets' => ['js' => [['url' => $url]]]]);

            static::__assert_count(1, $violations, "refused: {$url}");
            static::__assert_contains('not an https:// URL', $violations[0]->message);
            static::__assert_equals('critical', $violations[0]->severity);
        }
    }

    public static function test_an_entry_without_a_url_is_refused()
    {
        $violations = static::__evaluate(['cdn_assets' => ['css' => [['integrity' => 'sha384-x'], 'https://cdn.example.com/a.css']]]);

        static::__assert_count(2, $violations, 'a map with no url, and a bare string, each declare no url');
        static::__assert_contains("has no 'url'", $violations[0]->message);
    }

    public static function test_the_shipped_cdn_bundles_pass()
    {
        foreach (['Jquery_Bundle', 'Bootstrap5_Bundle'] as $class_name) {
            $fqcn = 'App\\RSpade\\Bundles\\' . $class_name;
            $file = base_path('app/RSpade/Bundles/' . $class_name . '.php');

            $collector = new ViolationCollector();
            (new BundleCdnHttps_CodeQualityRule($collector))->check(
                $file,
                file_get_contents($file),
                ['class' => $class_name, 'fqcn' => $fqcn]
            );

            static::__assert_count(0, $collector->get_by_rule(self::RULE_ID), "{$class_name} declares https only");
        }
    }
}
