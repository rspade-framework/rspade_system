<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Proxority\Php;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use App\RSpade\Commands\Rsx\Proxority_Routes_Command;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Proxority\Proxority_Routes;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:proxority:routes - the whitelist Proxority refreshes (Proxority_Routes).
 *
 * Pinned: route patterns translate to the proxy's syntax (whole-segment params, optionals
 * expanded, a '*' ending the pattern as a prefix); portal rows appear under the portal
 * template's prefix, bare as well when custom domains are honoured and bare only under
 * 'only'; build() files each row under its surface's directive and always lists the build
 * artifacts and public files as unthrottled; the live answer carries the framework's file
 * routes as media; --json prints exactly the three lists and exits 0; and with maintenance
 * mode on disk it prints {"maintenance":true} and exits 3.
 *
 * The answer's consumer is system/app/RSpade/resource/docker/proxority/lib/classify.js (compile_whitelist()).
 */
class Proxority_Routes_Command_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function teardown()
    {
        Framework_Maintenance::$force_active_for_tests = null;
    }

    public static function test_route_patterns_translate_to_the_proxy_syntax()
    {
        $cases = [
            '/' => ['/'],
            '/about' => ['/about'],
            '/_download/:key' => ['/_download/:key'],
            '/_thumbnail/dynamic/:key/:type/:width/:height?' => [
                '/_thumbnail/dynamic/:key/:type/:width',
                '/_thumbnail/dynamic/:key/:type/:width/:height',
            ],
            '/a/:x?/b' => ['/a/b', '/a/:x/b'],
            '/files/:id.:ext' => ['/files/:param'],
            '/*' => ['/*'],
            '/docs/*' => ['/docs/*'],
            '/docs/v:n*' => ['/docs/v*'],
            '/a/b*c/d' => ['/a/b*'],
        ];

        foreach ($cases as $pattern => $expected) {
            $actual = Proxority_Routes::wire_patterns($pattern);
            sort($actual);
            sort($expected);
            static::__assert_equals($expected, $actual, $pattern);
        }
    }

    public static function test_portal_paths_follow_the_template_and_custom_domains()
    {
        static::__assert_equals(['/_portal'], Proxority_Routes::bases_for('/_portal', Rsx_Portal_Url::CUSTOM_DOMAINS_OFF));
        static::__assert_equals(['/_portal/:slug', ''], Proxority_Routes::bases_for('/_portal/:slug', Rsx_Portal_Url::CUSTOM_DOMAINS_OVERRIDE));
        static::__assert_equals([''], Proxority_Routes::bases_for('/_portal', Rsx_Portal_Url::CUSTOM_DOMAINS_ONLY));
        static::__assert_equals([''], Proxority_Routes::bases_for('', Rsx_Portal_Url::CUSTOM_DOMAINS_OFF), 'a portal at a host root');

        static::__assert_equals('/_portal', Proxority_Routes::join('/_portal', '/'));
        static::__assert_equals('/_portal/:slug/x/:id', Proxority_Routes::join('/_portal/:slug', '/x/:id'));
        static::__assert_equals('/x', Proxority_Routes::join('', '/x'));
    }

    public static function test_build_files_each_row_under_its_surface_directive()
    {
        $surfaces = [
            'Files::download' => ['throttle' => 'media'],
            'Spa::index' => ['throttle' => 'unthrottled'],
            'Api::status' => ['throttle' => 'priority'],
            'Plain::page' => [],
        ];

        $routes = [
            '/dl/:key' => ['pattern' => '/dl/:key', 'surface' => 'Files::download'],
            '/app/one' => ['pattern' => '/app/one', 'surface' => 'Spa::index'],
            '/app/two/:id' => ['pattern' => '/app/two/:id', 'surface' => 'Spa::index'],
            '/api/v1/status' => ['pattern' => '/api/v1/status', 'surface' => 'Api::status'],
            '/plain' => ['pattern' => '/plain', 'surface' => 'Plain::page'],
        ];

        $portal_routes = [
            '/dl/:key' => ['pattern' => '/dl/:key', 'surface' => 'Files::download'],
            '/' => ['pattern' => '/', 'surface' => 'Spa::index'],
        ];

        $answer = Proxority_Routes::build($routes, $portal_routes, $surfaces, ['/_portal/:slug'], ['/favicon.ico']);

        static::__assert_equals(['unthrottled', 'priority', 'media'], array_keys($answer));
        static::__assert_equals(['/_portal/:slug/dl/:key', '/dl/:key'], $answer['media']);
        static::__assert_equals(['/api/v1/status'], $answer['priority']);
        static::__assert_equals(
            ['/_compiled/*', '/_portal/:slug', '/_vendor/*', '/app/one', '/app/two/:id', '/favicon.ico'],
            $answer['unthrottled']
        );

        // A disabled portal (no bases) contributes nothing.
        $answer = Proxority_Routes::build([], $portal_routes, $surfaces, [], []);
        static::__assert_equals([], $answer['media']);
    }

    public static function test_the_live_answer_lists_the_framework_file_routes_as_media()
    {
        $answer = Proxority_Routes::answer();

        foreach (['/_download/:key', '/_download_zip/:key', '/_inline/:key', '/_preview/pdf/:key', '/_preview/sheet/:key',
            '/_thumbnail/preset/:key/:preset_name', '/_thumbnail/dynamic/:key/:type/:width',
            '/_thumbnail/dynamic/:key/:type/:width/:height', '/_upload', '/api/v1/files'] as $pattern) {
            static::__assert_true(in_array($pattern, $answer['media'], true), "{$pattern} is media");
        }

        static::__assert_true(in_array('/_compiled/*', $answer['unthrottled'], true));
        static::__assert_true(in_array('/_vendor/*', $answer['unthrottled'], true));

        // Every public file is an exact path; a PHP file never is.
        foreach ($answer['unthrottled'] as $pattern) {
            static::__assert_false(str_ends_with(strtolower($pattern), '.php'), "{$pattern} is not a PHP file");
        }

        // The portal twins, when the portal is on, sit under its prefix.
        if (Rsx_Portal_Url::is_enabled() && Rsx_Portal_Url::custom_domains() !== Rsx_Portal_Url::CUSTOM_DOMAINS_ONLY) {
            $prefix = Rsx_Portal_Url::template()['prefix'];
            static::__assert_true(in_array(Proxority_Routes::join($prefix, '/_download/:key'), $answer['media'], true), 'the portal download twin is media');
        }
    }

    /**
     * The proxy's own parser accepts the whole live answer: a pattern it refused would make
     * every refresh fail and leave the proxy on its previous (at boot, empty) list.
     */
    public static function test_the_proxy_parser_accepts_the_live_answer()
    {
        $script = 'const c = require(' . json_encode(base_path('app/RSpade/resource/docker/proxority/lib/classify.js')) . ');'
            . 'let input = ""; process.stdin.on("data", (d) => input += d);'
            . 'process.stdin.on("end", () => { c.compile_whitelist(JSON.parse(input)); console.log("[OK]"); });';

        $process = new Process(['node', '-e', $script]);
        // The node child only parses what it is handed; no bound on it (no-timeout mandate).
        $process->setTimeout(null);
        $process->setInput(json_encode(Proxority_Routes::answer()));
        $process->run();

        static::__assert_equals(0, $process->getExitCode(), 'compile_whitelist() refused the answer: ' . $process->getErrorOutput());
        static::__assert_equals('[OK]', trim($process->getOutput()));
    }

    public static function test_json_prints_exactly_the_three_lists_and_exits_zero()
    {
        Framework_Maintenance::$force_active_for_tests = false;

        $code = Artisan::call('rsx:proxority:routes', ['--json' => true]);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        static::__assert_equals(0, $code);
        static::__assert_true(is_array($decoded), 'the output is JSON: ' . substr($output, 0, 200));
        static::__assert_equals(['unthrottled', 'priority', 'media'], array_keys($decoded));
        static::__assert_equals(Proxority_Routes::answer(), $decoded);
    }

    public static function test_maintenance_mode_answers_maintenance_with_its_own_exit_code()
    {
        Framework_Maintenance::$force_active_for_tests = true;

        try {
            $code = Artisan::call('rsx:proxority:routes', ['--json' => true]);
            $output = trim(Artisan::output());
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
        }

        static::__assert_equals(Proxority_Routes_Command::EXIT_MAINTENANCE, $code);
        static::__assert_equals(3, $code);
        static::__assert_equals(['maintenance' => true], json_decode($output, true));
    }
}
