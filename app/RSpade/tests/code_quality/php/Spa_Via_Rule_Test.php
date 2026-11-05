<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\JavaScript\SpaVia_CodeQualityRule;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for SPA-VIA-01 (SpaVia_CodeQualityRule) - the manifest-build FATALs that guard
 * the reserved breadcrumb-origin parameter `_via` (rsx:man breadcrumb_origins).
 *
 * Each FATAL is proved to fire, and each legal shape is proved NOT to: the pair declared
 * together, a class that only READS `constructor.breadcrumb_via` (the resolver does), a file
 * that mentions `_via` only in a string, and an ordinary route parameter. The JavaScript half
 * runs through the real node service (`quality.analyze_via`), not a stub.
 */
class Spa_Via_Rule_Test extends Rsx_Test_Abstract
{
    // Pure file inspection - no database access.
    protected static $use_database_transactions = false;

    /**
     * Write $source as a JS fixture and run the rule over it. Returns the FATAL message, or
     * null when the rule passed the file.
     */
    private static function __run_js(string $source): ?string
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/spa_via_rule_fixture_' . uniqid();
        $path = $dir . '/rsx/spa_via_probe.js';
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        try {
            return static::__run($path, $source, []);
        } finally {
            @unlink($path);
            @rmdir(dirname($path));
            @rmdir($dir);
        }
    }

    /**
     * Run the rule over a PHP file whose manifest metadata declares one route.
     */
    private static function __run_php_route(string $attribute, string $pattern): ?string
    {
        $metadata = [
            'public_static_methods' => [
                'index' => ['attributes' => [$attribute => [[$pattern]]]],
            ],
        ];

        return static::__run('rsx/app/probe/probe_controller.php', "<?php\n", $metadata);
    }

    private static function __run(string $path, string $source, array $metadata): ?string
    {
        $rule = new SpaVia_CodeQualityRule(new ViolationCollector());

        try {
            $rule->check($path, $source, $metadata);
        } catch (YoureDoingItWrongException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private static function __action(string $members, string $decorators = "@route('/probe/:id')"): string
    {
        return $decorators . "\n"
            . "class Probe_Action extends Spa_Action {\n"
            . $members
            . "}\n";
    }

    // =====================================================================
    // FATALs
    // =====================================================================

    public static function test_route_param_named_via_is_fatal()
    {
        $js = static::__run_js(static::__action('', "@route('/probe/:_via')"));
        static::__assert_not_null($js, '@route with a :_via segment is FATAL');
        static::__assert_contains(':_via', $js);

        $php = static::__run_php_route('Route', '/probe/:_via');
        static::__assert_not_null($php, '#[Route] with a :_via segment is FATAL');

        $portal = static::__run_php_route('Portal_Route', '/probe/:_via?');
        static::__assert_not_null($portal, '#[Portal_Route] with an optional :_via segment is FATAL');
    }

    public static function test_reading_args_via_is_fatal()
    {
        $forms = [
            'const x = this.args._via;',
            "const x = this.args['_via'];",
            'const x = args._via;',
            'const { _via } = this.args;',
        ];

        foreach ($forms as $statement) {
            $message = static::__run_js(static::__action("    on_create() {\n        {$statement}\n    }\n"));
            static::__assert_not_null($message, "reading _via from args is FATAL: {$statement}");
            static::__assert_contains('Spa.via()', (string) $message);
        }
    }

    public static function test_list_without_hook_is_fatal()
    {
        $message = static::__run_js(static::__action("    static breadcrumb_via = ['engagement'];\n"));

        static::__assert_not_null($message, 'breadcrumb_via without breadcrumb_parent_via() is FATAL');
        static::__assert_contains('without an instance breadcrumb_parent_via()', (string) $message);
    }

    public static function test_static_hook_does_not_satisfy_the_pair()
    {
        $message = static::__run_js(static::__action(
            "    static breadcrumb_via = ['engagement'];\n"
            . "    static async breadcrumb_parent_via(via) { return null; }\n"
        ));

        static::__assert_not_null($message, 'a STATIC breadcrumb_parent_via() is not the hook');
    }

    public static function test_hook_without_list_is_fatal()
    {
        $message = static::__run_js(static::__action("    async breadcrumb_parent_via(via) { return null; }\n"));

        static::__assert_not_null($message, 'breadcrumb_parent_via() without breadcrumb_via is FATAL');
        static::__assert_contains('without static breadcrumb_via', (string) $message);
    }

    public static function test_non_literal_list_is_fatal()
    {
        $message = static::__run_js(static::__action(
            "    static breadcrumb_via = Probe_Types.ALL;\n"
            . "    async breadcrumb_parent_via(via) { return null; }\n"
        ));

        static::__assert_not_null($message, 'a breadcrumb_via that is not an array literal of strings is FATAL');
        static::__assert_contains('not an array literal of strings', (string) $message);
    }

    public static function test_invalid_type_entry_is_fatal()
    {
        foreach (['Engagement', '1st', 'engagement.156', 'has-dash'] as $entry) {
            $message = static::__run_js(static::__action(
                "    static breadcrumb_via = ['engagement', '{$entry}'];\n"
                . "    async breadcrumb_parent_via(via) { return null; }\n"
            ));

            static::__assert_not_null($message, "breadcrumb_via entry '{$entry}' is FATAL");
            static::__assert_contains("'{$entry}'", (string) $message);
        }
    }

    // =====================================================================
    // Legal shapes
    // =====================================================================

    public static function test_pair_declared_together_is_clean()
    {
        $message = static::__run_js(static::__action(
            "    static breadcrumb_via = ['engagement', 'billing_dashboard'];\n"
            . "    async breadcrumb_parent_via(via) {\n"
            . "        await this.await_loaded();\n"
            . "        return via.id === '156' ? Rsx.Route('Probe_Action', via.id) + '#tab=billing' : null;\n"
            . "    }\n"
        ));

        static::__assert_null($message, 'the list and the hook declared together pass');
    }

    public static function test_reads_that_are_not_declarations_are_clean()
    {
        $source = "class Probe_Reader {\n"
            . "    static accepts(action) {\n"
            . "        const list = action.constructor.breadcrumb_via;\n"
            . "        const param = '_via';\n"
            . "        return Spa.via().length > 0 && list.includes(param) && Spa_Via.raw(location.search);\n"
            . "    }\n"
            . "}\n";

        static::__assert_null(static::__run_js($source), 'reading constructor.breadcrumb_via and a _via string literal is not a declaration');
    }

    public static function test_ordinary_route_param_is_clean()
    {
        static::__assert_null(static::__run_php_route('Route', '/probe/:via_id'), ':via_id is an ordinary parameter');
        static::__assert_null(static::__run_js(static::__action('', "@route('/probe/:my_via')")), ':my_via is an ordinary parameter');
    }
}
