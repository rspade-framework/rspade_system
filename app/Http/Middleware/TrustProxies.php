<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * The ONE client-address resolver: after this middleware, $request->ip() is the client.
 *
 * X-Forwarded-For is honoured only when the immediate peer is a trusted proxy, and is then
 * walked right to left, skipping trusted hops - so the entries a client wrote itself (always
 * to the LEFT of what the first trusted proxy appended) are never the answer. Loopback is
 * always trusted (the framework's own nginx -> FPC proxy -> nginx hops); anything in front of
 * nginx is declared in config('rsx.http.trusted_proxies').
 *
 * ONLY X-Forwarded-For is trusted. X-Forwarded-Host/-Port/-Prefix would let whatever a client
 * sent through a loopback hop rewrite $request->getHost(), and the application's host is the
 * HTTP_HOST nginx received, checked against APP_URL / PORTAL_URL.
 *
 * See config/rsx.php ('http' => 'trusted_proxies').
 */
class TrustProxies extends Middleware
{
    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR;

    /**
     * Loopback plus the operator's declared proxies.
     *
     * @return array<int, string>
     */
    protected function proxies()
    {
        return array_values(array_merge(['127.0.0.1', '::1'], (array) config('rsx.http.trusted_proxies', [])));
    }
}
