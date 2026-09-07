<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Bundle\Minifier;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Proves the console_debug strip wiring end-to-end at the minifier boundary: the
 * strip_console_debug flag rides the RPC request (Minifier::minify_js third arg,
 * driven in the compiler by Manifest::_should_strip_console_debug()), and the
 * minify server adds Terser pure_funcs ['console_debug'] ONLY when the request
 * asks for it. Same server binary, same input - the flag is the only difference.
 *
 * This is the unit-level companion to the E2E grep (a strict-prod bundle contains
 * zero console_debug call sites). Runs the real node/Terser RPC server, so it is an
 * asset test; the server is force-restarted before and stopped after.
 *
 * No DB.
 */
class Minify_Strip_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // A call the optimizer must NOT be able to remove on its own (has a live effect
    // via window), sitting next to a console_debug call that pure_funcs should drop.
    private const SAMPLE_JS = <<<'JS'
    function demo(x) {
        console_debug('TEST', 'hello world', x);
        window.__demo_kept = x + 1;
        return window.__demo_kept;
    }
    demo(41);
    JS;

    public static function setup()
    {
        // Ensure the code currently on disk is what is actually serving.
        Minifier::force_restart();
    }

    public static function teardown()
    {
        Rsx_Node_Service::stop(force: true);
    }

    public static function test_strip_flag_removes_console_debug_call_site()
    {
        $out = Minifier::minify_js(self::SAMPLE_JS, 'strip_on.js', true);

        static::__assert_false(
            str_contains($out, 'console_debug'),
            "with strip flag ON, console_debug( call site must be gone. Output: {$out}"
        );
        // The load-bearing effect survives (pure_funcs only targets console_debug).
        static::__assert_contains('__demo_kept', $out, 'the real side-effecting code must survive stripping');
    }

    public static function test_no_strip_flag_keeps_console_debug_call_site()
    {
        $out = Minifier::minify_js(self::SAMPLE_JS, 'strip_off.js', false);

        static::__assert_contains(
            'console_debug',
            $out,
            'without the strip flag (debug builds), console_debug( must be retained'
        );
    }
}
