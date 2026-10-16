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
 * Proves the console_debug and console.log strip wiring end-to-end at the minifier
 * boundary.
 *
 * console_debug: the
 * strip_console_debug flag rides the RPC request (Minifier::minify_js third arg,
 * driven in the compiler by Manifest::_should_strip_console_debug()), and the
 * minify server adds Terser pure_funcs ['console_debug'] ONLY when the request
 * asks for it. Same server binary, same input - the flag is the only difference.
 *
 * console.log: the strip_console_log flag (Minifier::minify_js fourth arg, set by the
 * compiler for a bundle's APP output only) adds 'console.log' to pure_funcs, which drops
 * the call and keeps a side-effecting argument; console.error / warn survive, and without
 * the flag (the vendor output) console.log survives too.
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

    // console.log calls beside the console methods that must survive, one with a
    // side-effecting argument whose effect must survive the dropped call.
    private const CONSOLE_JS = <<<'JS'
    window.__log_counter = 0;
    function report(x) {
        console.log('dropped-log-marker', x);
        console.log(window.__log_counter++);
        console.error('kept-error-marker', x);
        console.warn('kept-warn-marker', x);
        return window.__log_counter;
    }
    report(7);
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

    public static function test_strip_console_log_drops_log_and_keeps_error_warn_and_side_effects()
    {
        $out = Minifier::minify_js(self::CONSOLE_JS, 'log_strip_on.js', true, true);

        static::__assert_false(
            str_contains($out, 'console.log'),
            "with strip_console_log ON, every console.log( call site must be gone. Output: {$out}"
        );
        static::__assert_false(str_contains($out, 'dropped-log-marker'), "the dropped call's argument must go with it. Output: {$out}");
        static::__assert_contains('console.error', $out, 'console.error must survive');
        static::__assert_contains('kept-error-marker', $out, 'console.error keeps its arguments');
        static::__assert_contains('console.warn', $out, 'console.warn must survive');
        static::__assert_contains('kept-warn-marker', $out, 'console.warn keeps its arguments');
        static::__assert_true(
            str_contains($out, '__log_counter++') || str_contains($out, '++window.__log_counter'),
            "the side-effecting argument of a dropped console.log must survive. Output: {$out}"
        );
    }

    public static function test_no_strip_console_log_keeps_log_as_the_vendor_output_does()
    {
        $out = Minifier::minify_js(self::CONSOLE_JS, 'log_strip_off.js', true, false);

        static::__assert_contains('console.log', $out, 'without strip_console_log (the vendor output), console.log( must be retained');
        static::__assert_contains('dropped-log-marker', $out, 'the retained console.log keeps its arguments');
    }
}
