<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The client address: the TrustProxies middleware is the ONE resolver, and
 * Session::get_client_ip() (the login and second-factor throttles, login history,
 * session rows) answers its $request->ip().
 *
 * X-Forwarded-For counts only through a TRUSTED peer (loopback, plus
 * config('rsx.http.trusted_proxies')) and is walked right to left, so the entries a client
 * wrote itself never become the answer - which is what keeps a rotated header from minting
 * a fresh throttle bucket per guess.
 *
 * Session::get_client_ip() answers null in CLI by design, so the resolver is driven here
 * directly: each case runs the real middleware over a synthetic request. The middleware sets
 * Symfony's PROCESS-WIDE trusted-proxy state, which is restored after every case.
 *
 * Pure request logic, no DB.
 */
class Client_Ip_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The resolved client address for a request from $peer carrying $forwarded_for, with
     * optional rsx.http.trusted_proxies and extra headers.
     */
    private static function __resolve(string $peer, ?string $forwarded_for, array $trusted = [], array $server = []): Request
    {
        $saved_proxies = Request::getTrustedProxies();
        $saved_headers = Request::getTrustedHeaderSet();
        $saved_config = config('rsx.http.trusted_proxies');

        config(['rsx.http.trusted_proxies' => $trusted]);

        $server['REMOTE_ADDR'] = $peer;
        if ($forwarded_for !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwarded_for;
        }

        $request = Request::create('http://app.example.test/login', 'POST', [], [], [], $server);

        try {
            (new TrustProxies())->handle($request, fn ($handled) => $handled);

            // Resolve while the middleware's trust is in force, then hand back a request whose
            // answers are already computed.
            $request->attributes->set('resolved_ip', $request->ip());
            $request->attributes->set('resolved_host', $request->getHost());
        } finally {
            Request::setTrustedProxies($saved_proxies, $saved_headers);
            config(['rsx.http.trusted_proxies' => $saved_config]);
        }

        return $request;
    }

    private static function __ip(string $peer, ?string $forwarded_for, array $trusted = []): string
    {
        return (string) static::__resolve($peer, $forwarded_for, $trusted)->attributes->get('resolved_ip');
    }

    /**
     * An untrusted peer IS the client; its X-Forwarded-For is ignored entirely (nginx's
     * fastcgi path, where the peer is the browser itself).
     */
    public static function test_an_untrusted_peer_is_the_client_whatever_it_forwards()
    {
        static::__assert_equals('203.0.113.5', static::__ip('203.0.113.5', null));
        static::__assert_equals('203.0.113.5', static::__ip('203.0.113.5', '6.6.6.6'), 'a forged header from the client is ignored');
        static::__assert_equals('203.0.113.5', static::__ip('203.0.113.5', '6.6.6.6, 127.0.0.1'), 'a forged loopback hop is ignored too');
    }

    /**
     * Through a trusted (loopback) peer, the RIGHTMOST untrusted entry is the client: nginx
     * APPENDS the address it saw, so anything the client wrote sits to its left.
     */
    public static function test_a_trusted_peer_yields_the_rightmost_untrusted_hop()
    {
        static::__assert_equals('198.51.100.7', static::__ip('127.0.0.1', '198.51.100.7'), 'the FPC path: nginx appended the client');
        static::__assert_equals('198.51.100.7', static::__ip('127.0.0.1', '6.6.6.6, 198.51.100.7'), 'a spoofed leading entry is skipped');
        static::__assert_equals('198.51.100.7', static::__ip('127.0.0.1', '6.6.6.6, 198.51.100.7, ::1'), 'trusted hops are skipped');
        static::__assert_equals('198.51.100.7', static::__ip('::1', '198.51.100.7'), 'IPv6 loopback is trusted');
    }

    /**
     * A device in front of nginx is not trusted until it is declared; declared, its hop is
     * skipped as well, so the address IT appended becomes the answer.
     */
    public static function test_a_declared_proxy_is_skipped_and_an_undeclared_one_is_the_client()
    {
        $chain = '6.6.6.6, 198.51.100.7, 10.1.2.3';

        static::__assert_equals('10.1.2.3', static::__ip('127.0.0.1', $chain), 'an undeclared load balancer is the client');
        static::__assert_equals('198.51.100.7', static::__ip('127.0.0.1', $chain, ['10.0.0.0/8']), 'declared by CIDR, it is skipped');
        static::__assert_equals('198.51.100.7', static::__ip('127.0.0.1', $chain, ['10.1.2.3']), 'declared by address, it is skipped');
    }

    /**
     * Only X-Forwarded-For is trusted: a client cannot rewrite the host through a loopback
     * hop with X-Forwarded-Host.
     */
    public static function test_forwarded_host_is_never_trusted()
    {
        $request = static::__resolve('127.0.0.1', '198.51.100.7', [], ['HTTP_X_FORWARDED_HOST' => 'evil.example']);

        static::__assert_equals('app.example.test', $request->attributes->get('resolved_host'));
    }
}
