<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use ReflectionMethod;
use RuntimeException;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Debug\Playwright_Exception_Handler;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The two rsx:debug DISCLOSURE paths, and the loopback question they both ask.
 *
 * Both are opened by an UNSIGNED header, which proves nothing - anybody on the network
 * can send one:
 *
 *   X-Playwright-Test           Playwright_Exception_Handler returns the exception
 *                               message, file, line and a ten-frame stack trace as
 *                               plain text.
 *   X-Playwright-Console-Debug  Debugger forces console_debug output into a web
 *                               response that configuration has switched OFF.
 *
 * Neither may answer anyone but the local harness, so each requires development mode
 * AND is_loopback_ip(). Security here is designed for DEVELOPMENT: a development-mode
 * site may be serving the public right now, so "it is only reachable in dev" is not a
 * mitigation.
 *
 * is_loopback_ip() itself is tested here because these paths are its only consumers:
 * the peer must be loopback AND every address a proxy declared must be loopback too.
 * A reverse proxy makes REMOTE_ADDR its own address, so the forwarded chain is the only
 * statement about where a request came from.
 */
class Playwright_Disclosure_Gate_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function teardown()
    {
        // Never leave the container without a request: code paths all over the
        // framework call request() and fatal on an unbound one.
        app()->instance('request', Request::create('/'));
        Rsx::clear_mode_cache();
    }

    /**
     * Bind a request with the given peer address and headers as the current request.
     */
    private static function __bind_request(string $remote_addr, array $headers = []): Request
    {
        $server = ['REMOTE_ADDR' => $remote_addr];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create('/_test/dispatch/throw', 'GET', [], [], [], $server);
        app()->instance('request', $request);

        return $request;
    }

    // -----------------------------------------------------------------------------
    // is_loopback_ip()
    // -----------------------------------------------------------------------------

    public static function test_a_direct_loopback_peer_is_loopback()
    {
        self::__bind_request('127.0.0.1');
        self::__assert_true(is_loopback_ip(), '127.0.0.1 with no forwarding is local');

        self::__bind_request('::1');
        self::__assert_true(is_loopback_ip(), '::1 with no forwarding is local');
    }

    public static function test_a_remote_peer_is_not_loopback()
    {
        self::__bind_request('203.0.113.7');
        self::__assert_false(is_loopback_ip(), 'a remote peer is never local');
    }

    public static function test_a_proxied_local_request_is_still_loopback()
    {
        // The normal deployment: nginx in front, so REMOTE_ADDR is the proxy and the
        // forwarded chain names the real client - here, the local harness.
        self::__bind_request('127.0.0.1', [
            'X-Forwarded-For' => '::1',
            'X-Real-IP' => '::1',
            'X-Forwarded-Proto' => 'http',
        ]);
        self::__assert_true(is_loopback_ip(), 'a proxy declaring a loopback client is local');
    }

    public static function test_a_proxied_remote_request_is_not_loopback()
    {
        // The attack this gate exists for: a request from the internet arriving through
        // the same proxy, so REMOTE_ADDR is 127.0.0.1 and only the chain gives it away.
        self::__bind_request('127.0.0.1', ['X-Forwarded-For' => '203.0.113.7']);
        self::__assert_false(is_loopback_ip(), 'a forwarded remote client is not local');

        self::__bind_request('127.0.0.1', ['X-Forwarded-For' => '203.0.113.7, ::1']);
        self::__assert_false(is_loopback_ip(), 'ANY remote hop in the chain disqualifies');

        self::__bind_request('127.0.0.1', ['X-Real-IP' => '203.0.113.7']);
        self::__assert_false(is_loopback_ip(), 'X-Real-IP is read too');
    }

    public static function test_forwarding_without_an_address_is_refused()
    {
        // Forwarded by something that would not say by whom: fail closed.
        self::__bind_request('127.0.0.1', ['X-Forwarded-Host' => 'example.com']);
        self::__assert_false(is_loopback_ip(), 'a forwarded request with no declared client is refused');
    }

    // -----------------------------------------------------------------------------
    // The plain-text stack trace
    // -----------------------------------------------------------------------------

    public static function test_the_trace_handler_answers_the_local_harness()
    {
        $request = self::__bind_request('127.0.0.1', ['X-Playwright-Test' => '1']);

        $response = (new Playwright_Exception_Handler())->handle(
            new RuntimeException('disclosure_gate_probe'),
            $request
        );

        self::__assert_not_null($response, 'the local harness still gets its plain-text trace');
        self::__assert_contains('disclosure_gate_probe', $response->getContent());
        self::__assert_contains('Stack Trace', $response->getContent());
    }

    public static function test_the_trace_handler_refuses_a_non_loopback_caller()
    {
        $request = self::__bind_request('203.0.113.7', ['X-Playwright-Test' => '1']);

        self::__assert_null(
            (new Playwright_Exception_Handler())->handle(new RuntimeException('disclosure_gate_probe'), $request),
            'a remote caller sending X-Playwright-Test gets no trace'
        );
    }

    public static function test_the_trace_handler_refuses_a_forwarded_remote_caller()
    {
        $request = self::__bind_request('127.0.0.1', [
            'X-Playwright-Test' => '1',
            'X-Forwarded-For' => '203.0.113.7',
        ]);

        self::__assert_null(
            (new Playwright_Exception_Handler())->handle(new RuntimeException('disclosure_gate_probe'), $request),
            'the header alone is never the gate'
        );
    }

    public static function test_the_trace_handler_is_development_only()
    {
        $request = self::__bind_request('127.0.0.1', ['X-Playwright-Test' => '1']);

        // A sealed debug build reports app()->environment() === 'local', which is why
        // the old gate was live there.
        Rsx::_testing_set_mode(Rsx::MODE_DEBUG);
        $response = (new Playwright_Exception_Handler())->handle(new RuntimeException('probe'), $request);
        Rsx::clear_mode_cache();

        self::__assert_null($response, 'a sealed debug box discloses no trace');
    }

    // -----------------------------------------------------------------------------
    // The console-debug header override
    // -----------------------------------------------------------------------------

    /**
     * The header branch is a private predicate on Debugger; reaching it directly is the
     * only way to assert it without depending on the whole console_debug output stack.
     */
    private static function __console_debug_header_allowed(string $remote_addr, array $headers): bool
    {
        self::__bind_request($remote_addr, $headers);

        // The predicate reads $_SERVER (it runs in contexts with no bound request), so
        // the superglobal is what has to carry the header.
        $previous = $_SERVER['HTTP_X_PLAYWRIGHT_CONSOLE_DEBUG'] ?? null;
        if (isset($headers['X-Playwright-Console-Debug'])) {
            $_SERVER['HTTP_X_PLAYWRIGHT_CONSOLE_DEBUG'] = $headers['X-Playwright-Console-Debug'];
        } else {
            unset($_SERVER['HTTP_X_PLAYWRIGHT_CONSOLE_DEBUG']);
        }

        $method = new ReflectionMethod(Debugger::class, '__playwright_console_debug_header_allowed');
        $method->setAccessible(true);
        $allowed = $method->invoke(null);

        if ($previous === null) {
            unset($_SERVER['HTTP_X_PLAYWRIGHT_CONSOLE_DEBUG']);
        } else {
            $_SERVER['HTTP_X_PLAYWRIGHT_CONSOLE_DEBUG'] = $previous;
        }

        return $allowed;
    }

    public static function test_console_debug_header_answers_the_local_harness()
    {
        self::__assert_true(
            self::__console_debug_header_allowed('127.0.0.1', ['X-Playwright-Console-Debug' => '1']),
            'the local harness keeps its console_debug override'
        );
    }

    public static function test_console_debug_header_refuses_a_non_loopback_caller()
    {
        self::__assert_false(
            self::__console_debug_header_allowed('203.0.113.7', ['X-Playwright-Console-Debug' => '1']),
            'a remote caller cannot force console_debug into the response'
        );

        self::__assert_false(
            self::__console_debug_header_allowed('127.0.0.1', [
                'X-Playwright-Console-Debug' => '1',
                'X-Forwarded-For' => '203.0.113.7',
            ]),
            'nor through the reverse proxy'
        );
    }

    public static function test_console_debug_header_is_development_only()
    {
        Rsx::_testing_set_mode(Rsx::MODE_DEBUG);
        $allowed = self::__console_debug_header_allowed('127.0.0.1', ['X-Playwright-Console-Debug' => '1']);
        Rsx::clear_mode_cache();

        self::__assert_false($allowed, 'a sealed debug box refuses the override');
    }
}
