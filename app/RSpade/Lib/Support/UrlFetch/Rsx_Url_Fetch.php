<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Support\UrlFetch;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use App\RSpade\Core\Files\Rsx_File_Name;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Lib\Support\UrlFetch\Rsx_Url_Fetch_Exception;
use App\RSpade\Lib\Support\UrlFetch\Rsx_Url_Fetch_Result;

/**
 * Rsx_Url_Fetch - download a URL somebody else chose, without letting them aim this server
 * at itself.
 *
 * A URL that comes from a user (an import, a "fetch from link" field, a webhook tester, an
 * avatar by URL) is a request this SERVER makes on their behalf, from inside its network:
 * http://127.0.0.1:6379/ reaches the local Redis, http://169.254.169.254/ a cloud provider's
 * metadata service and its credentials, http://10.0.0.5/ a neighbour no outsider can reach.
 * That is server-side request forgery (SSRF). to_temp_file() refuses those destinations and
 * makes the refusal stick:
 *
 *   - Only http and https.
 *   - The host is resolved (A and AAAA) and EVERY address is classified; one loopback,
 *     unspecified, private (RFC 1918, IPv6 ULA fc00::/7), link-local (169.254/16 - the
 *     metadata range - and fe80::/10), carrier-grade NAT (100.64/10), multicast, broadcast or
 *     reserved address refuses the fetch, as does an IPv6 form embedding one of those (an
 *     IPv4-mapped ::ffff:a.b.c.d, NAT64 64:ff9b::, 6to4 2002::). address_class() is the one
 *     classifier.
 *   - The connection goes to the CHECKED address and nowhere else (curl CURLOPT_CONNECT_TO
 *     with an empty host, which applies whatever host curl itself parses from the URL), so a
 *     second DNS answer between the check and the connect cannot move the target, and a URL
 *     spelled so that two parsers disagree about its host still reaches only the address that
 *     was checked. The Host header, TLS SNI and certificate verification use the URL's own
 *     host name. Proxies from the environment are switched off, because a proxy would make
 *     the connection somewhere else.
 *   - Redirects are followed by this class, one hop at a time, and every hop is checked as
 *     the first was - the classic bypass is a public URL answering 302 to a private one.
 *   - The body streams to a temp file in constant memory. $max_bytes is enforced up front
 *     against Content-Length and again WHILE the body arrives (a server may send no length,
 *     or lie); exceeding it aborts the transfer, deletes the file and throws.
 *
 * $allow_private = true skips the destination policy (still http/https, still streamed and
 * capped) for a caller whose URL is not user-supplied and legitimately points inward - an
 * internal service, a test fixture server. Never pass it for a URL a user typed.
 *
 * THE ONE LIMIT ON TIME is the connect wait, rsx.url_fetch.connect_timeout_seconds: how long
 * the other party has to accept a connection at all. It is an external party that may never
 * answer, and expiry is a loud error naming the URL. There is deliberately no limit on how
 * long the transfer takes - a slow server sending a large file is not a fault - and the
 * max_bytes cap is what keeps a hostile server from filling the disk.
 *
 * THE TEMP FILE is the caller's to move or delete; whatever is still at its path when the
 * process ends is deleted then (one shutdown hook, registered on the first fetch). A caller
 * that moves it away leaves nothing to delete. A long-running process (a task worker) deletes
 * it itself when done - Rsx_Url_Fetch_Result::delete() - rather than wait for its exit.
 *
 * Contract: rsx:man url_fetch.
 */
class Rsx_Url_Fetch
{
    /**
     * The most redirects followed for one fetch. A LOOP BOUND, not a timeout: a redirect chain
     * that does not end (A -> B -> A) would otherwise never finish, and no legitimate download
     * link needs more than a handful of hops (a shortener, an http->https upgrade, a CDN
     * handoff). Five is the conventional browser-free client figure; a chain longer than that
     * is refused with an error naming the last URL.
     */
    public const MAX_REDIRECTS = 5;

    /**
     * The default byte cap: 1 GB. A deliberately LIBERAL default so that no legitimate file
     * is refused out of the box - every caller should pass the size its feature actually
     * needs (an avatar: a few MB; an import: what the import format can sensibly reach).
     */
    public const DEFAULT_MAX_BYTES = 1073741824;

    /** The HTTP statuses followed as redirects (each carries a Location). */
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** Every temp file this process created, deleted at shutdown if still present. */
    private static array $__created_files = [];

    private static bool $__shutdown_registered = false;

    /**
     * Download $url to a temp file under the destination policy above.
     *
     * @param string $url An http or https URL
     * @param int $max_bytes The most bytes the body may have (default 1 GB - tune it per use)
     * @param bool $allow_private Skip the destination policy (never for a user's URL)
     * @return Rsx_Url_Fetch_Result The temp file path, the response's content type, a safe
     *   suggested file name, the final URL and the size
     * @throws Rsx_Url_Fetch_Exception Every refusal and failure, with a message naming the URL
     */
    public static function to_temp_file(string $url, int $max_bytes = self::DEFAULT_MAX_BYTES, bool $allow_private = false): Rsx_Url_Fetch_Result
    {
        if ($max_bytes < 1) {
            throw new \InvalidArgumentException("Rsx_Url_Fetch: max_bytes must be at least 1, got {$max_bytes}");
        }

        $path = static::__new_temp_file();

        try {
            $current = static::__parse($url);

            for ($hop = 0; ; $hop++) {
                $addresses = static::check_destination($current, $allow_private);
                $response = static::__request($current, $addresses, $path, $max_bytes);

                if (in_array($response['status'], self::REDIRECT_STATUSES, true)) {
                    if ($response['location'] === null) {
                        throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::HTTP_STATUS, "Fetching {$current} failed: HTTP {$response['status']} redirect with no Location header");
                    }
                    if ($hop >= self::MAX_REDIRECTS) {
                        throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::TOO_MANY_REDIRECTS, "Fetching {$url} followed " . self::MAX_REDIRECTS . " redirects without reaching the file (last: {$current})");
                    }
                    $current = static::__parse((string) UriResolver::resolve(new Uri($current), new Uri($response['location'])));
                    continue;
                }

                if ($response['status'] < 200 || $response['status'] > 299) {
                    throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::HTTP_STATUS, "Fetching {$current} failed: HTTP {$response['status']}", $response['status']);
                }

                clearstatcache(true, $path);

                return new Rsx_Url_Fetch_Result(
                    $path,
                    $response['content_type'],
                    static::__suggested_filename($current, $response['content_disposition']),
                    $current,
                    (int) filesize($path)
                );
            }
        } catch (\Throwable $e) {
            if (file_exists($path)) {
                unlink($path);
            }
            throw $e;
        }
    }

    /**
     * Check one URL's destination and answer the addresses it may be connected to.
     *
     * Resolves the host (an IP literal is its own answer) and classifies every address;
     * throws when there is none, or - unless $allow_private - when any is not a public
     * address. The first address answered is the one to_temp_file() connects to.
     *
     * @return string[] The checked addresses
     * @throws Rsx_Url_Fetch_Exception
     */
    public static function check_destination(string $url, bool $allow_private = false): array
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '') {
            throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::INVALID_URL, "Cannot fetch {$url}: it names no host");
        }

        $addresses = static::__resolve($host);
        if ($addresses === []) {
            throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::UNRESOLVED_HOST, "Cannot fetch {$url}: the host {$host} does not resolve to any address");
        }

        if (!$allow_private) {
            foreach ($addresses as $address) {
                $class = static::address_class($address);
                if ($class !== null) {
                    throw new Rsx_Url_Fetch_Exception(
                        Rsx_Url_Fetch_Exception::REFUSED_DESTINATION,
                        "Refused to fetch {$url}: the host {$host} resolves to {$address}, a {$class} address. "
                        . 'Only public internet addresses may be fetched for a URL that can come from a user.'
                    );
                }
            }
        }

        return $addresses;
    }

    /**
     * What kind of non-public address $ip is, or null for a public one.
     *
     * Answers one of: 'loopback', 'unspecified', 'private', 'link-local', 'carrier-grade NAT',
     * 'multicast', 'broadcast', 'reserved'. An IPv6 address that embeds an IPv4 address
     * (IPv4-mapped ::ffff:0:0/96, NAT64 64:ff9b::/96, 6to4 2002::/16) is classified by the
     * address it embeds. Something that is not an IP address at all answers 'reserved'.
     */
    public static function address_class(string $ip): ?string
    {
        $ip = trim($ip, '[]');
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return 'reserved';
        }

        if (strlen($binary) === 4) {
            return static::__ipv4_class($binary);
        }

        // IPv6. Forms that carry an IPv4 address answer for that address.
        foreach ([['::ffff:0:0', 96], ['64:ff9b::', 96]] as [$prefix, $bits]) {
            if (static::__in_prefix($binary, $prefix, $bits)) {
                return static::__ipv4_class(substr($binary, 12, 4));
            }
        }
        if (static::__in_prefix($binary, '2002::', 16)) {
            return static::__ipv4_class(substr($binary, 2, 4));
        }

        $ranges = [
            ['::1', 128, 'loopback'],
            ['::', 128, 'unspecified'],
            ['::', 96, 'reserved'],            // IPv4-compatible (deprecated)
            ['fc00::', 7, 'private'],          // unique local
            ['fec0::', 10, 'private'],         // site-local (deprecated)
            ['fe80::', 10, 'link-local'],
            ['ff00::', 8, 'multicast'],
            ['100::', 64, 'reserved'],         // discard-only
            ['2001::', 32, 'reserved'],        // Teredo: the IPv4 endpoint is obscured
            ['2001:db8::', 32, 'reserved'],    // documentation
        ];
        foreach ($ranges as [$prefix, $bits, $class]) {
            if (static::__in_prefix($binary, $prefix, $bits)) {
                return $class;
            }
        }

        return null;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private static function __ipv4_class(string $binary): ?string
    {
        $ip = inet_ntop($binary);
        if ($ip === '255.255.255.255') {
            return 'broadcast';
        }

        $ranges = [
            ['0.0.0.0', 8, 'unspecified'],
            ['10.0.0.0', 8, 'private'],
            ['100.64.0.0', 10, 'carrier-grade NAT'],
            ['127.0.0.0', 8, 'loopback'],
            ['169.254.0.0', 16, 'link-local'],
            ['172.16.0.0', 12, 'private'],
            ['192.0.0.0', 24, 'reserved'],     // IETF protocol assignments
            ['192.0.2.0', 24, 'reserved'],     // documentation
            ['192.88.99.0', 24, 'reserved'],   // 6to4 relay anycast
            ['192.168.0.0', 16, 'private'],
            ['198.18.0.0', 15, 'reserved'],    // benchmarking
            ['198.51.100.0', 24, 'reserved'],  // documentation
            ['203.0.113.0', 24, 'reserved'],   // documentation
            ['224.0.0.0', 4, 'multicast'],
            ['240.0.0.0', 4, 'reserved'],
        ];
        foreach ($ranges as [$prefix, $bits, $class]) {
            if (static::__in_prefix($binary, $prefix, $bits)) {
                return $class;
            }
        }

        return null;
    }

    /** Is the packed address $binary inside $prefix/$bits (same family)? */
    private static function __in_prefix(string $binary, string $prefix, int $bits): bool
    {
        $network = inet_pton($prefix);
        if (strlen($network) !== strlen($binary)) {
            return false;
        }

        $whole = intdiv($bits, 8);
        if (substr($binary, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($binary[$whole]) & $mask) === (ord($network[$whole]) & $mask);
    }

    /**
     * Every address $host resolves to: itself when it is an IP literal, else the system
     * resolver's IPv4 answers (hosts file included) plus the DNS AAAA records. Only an
     * address listed here is ever connected to, so an address a lookup misses is one the
     * fetch cannot reach - never one that escapes the check.
     *
     * @return string[]
     */
    private static function __resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = $ipv4;
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /** Normalise $url and refuse anything but http(s) with a host. */
    private static function __parse(string $url): string
    {
        try {
            $uri = new Uri(trim($url));
        } catch (\InvalidArgumentException $e) {
            throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::INVALID_URL, "Cannot fetch {$url}: it is not a valid URL");
        }

        $scheme = strtolower($uri->getScheme());
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::INVALID_URL, "Cannot fetch {$url}: only http and https URLs may be fetched");
        }
        if ($uri->getHost() === '') {
            throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::INVALID_URL, "Cannot fetch {$url}: it names no host");
        }

        // The fragment is the browser's, never sent.
        return (string) $uri->withFragment('');
    }

    /**
     * One GET of $url, connected to $addresses[0], the body streamed into $path.
     *
     * @return array{status: int, location: ?string, content_type: ?string, content_disposition: ?string}
     */
    private static function __request(string $url, array $addresses, string $path, int $max_bytes): array
    {
        $connect_timeout = (int) config('rsx.url_fetch.connect_timeout_seconds');
        $port = parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'https:') ? 443 : 80);
        $target = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Rsx_Url_Fetch could not open its temp file {$path} for writing");
        }

        $state = [
            'status' => 0,
            'headers' => [],
            'written' => 0,
            'over_size' => false,
            'declared_too_large' => null,
            'not_a_body' => false,
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_CONNECT_TO => ['::' . $target . ':' . $port],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // The one sanctioned bound (rsx.url_fetch.connect_timeout_seconds, written down in
            // system/config/rsx.php): the wait for the remote party to accept a connection at
            // all. No CURLOPT_TIMEOUT or low-speed limit - the transfer runs to completion and
            // max_bytes is the safety net.
            CURLOPT_CONNECTTIMEOUT => $connect_timeout,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$state, $max_bytes) {
                $trimmed = trim($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $matches)) {
                    $state['status'] = (int) $matches[1];
                    $state['headers'] = [];

                    return strlen($line);
                }
                if (str_contains($trimmed, ':')) {
                    [$name, $value] = array_map('trim', explode(':', $trimmed, 2));
                    $state['headers'][strtolower($name)] = $value;
                    if (strtolower($name) === 'content-length'
                        && $state['status'] >= 200 && $state['status'] <= 299
                        && ctype_digit($value) && (int) $value > $max_bytes) {
                        $state['declared_too_large'] = (int) $value;

                        return -1;
                    }
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$state, $handle, $max_bytes) {
                // A redirect's or an error's body is never the file.
                if ($state['status'] < 200 || $state['status'] > 299) {
                    $state['not_a_body'] = true;

                    return -1;
                }
                $length = strlen($chunk);
                if ($state['written'] + $length > $max_bytes) {
                    $state['over_size'] = true;

                    return -1;
                }
                if (fwrite($handle, $chunk) !== $length) {
                    return -1;
                }
                $state['written'] += $length;

                return $length;
            },
        ]);

        curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        fclose($handle);

        if ($state['declared_too_large'] !== null) {
            throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::TOO_LARGE, "Refused to fetch {$url}: the server declares {$state['declared_too_large']} bytes, more than the {$max_bytes} allowed");
        }
        if ($state['over_size']) {
            throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::TOO_LARGE, "Stopped fetching {$url}: the body passed the {$max_bytes} bytes allowed");
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT && $state['status'] === 0) {
            throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::CONNECT, "Fetching {$url} failed: {$addresses[0]} did not accept a connection within {$connect_timeout} seconds (rsx.url_fetch.connect_timeout_seconds)");
        }
        if ($errno !== 0 && !($errno === CURLE_WRITE_ERROR && $state['not_a_body'])) {
            throw new Rsx_Url_Fetch_Exception(Rsx_Url_Fetch_Exception::TRANSFER, "Fetching {$url} failed: {$error}");
        }

        return [
            'status' => $state['status'],
            'location' => $state['headers']['location'] ?? null,
            'content_type' => $state['headers']['content-type'] ?? null,
            'content_disposition' => $state['headers']['content-disposition'] ?? null,
        ];
    }

    /**
     * A name for the downloaded file: the server's Content-Disposition filename when it gives
     * one, else the last segment of the final URL's path, else "download" - always through
     * Rsx_File_Name::safe().
     */
    private static function __suggested_filename(string $url, ?string $disposition): string
    {
        $name = null;
        if ($disposition !== null) {
            if (preg_match("/filename\\*\\s*=\\s*[^']*'[^']*'([^;]+)/i", $disposition, $matches)) {
                $name = rawurldecode(trim($matches[1]));
            } elseif (preg_match('/filename\s*=\s*"([^"]*)"/i', $disposition, $matches)
                || preg_match('/filename\s*=\s*([^;]+)/i', $disposition, $matches)) {
                $name = trim($matches[1]);
            }
        }

        if ($name === null || $name === '') {
            $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        }

        // A path segment or a header may still carry directory parts.
        $name = basename(str_replace('\\', '/', $name));

        return Rsx_File_Name::safe($name, 'download');
    }

    /** A new, empty, uniquely-named temp file, registered for deletion at process end. */
    private static function __new_temp_file(): string
    {
        $dir = Rsx_Project_Paths::url_fetch_dir();
        ensure_directory($dir);

        $path = $dir . '/' . bin2hex(random_bytes(16)) . '.part';
        touch($path);

        self::$__created_files[] = $path;
        if (!self::$__shutdown_registered) {
            self::$__shutdown_registered = true;
            register_shutdown_function([static::class, '_delete_leftover_temp_files']);
        }

        return $path;
    }

    /**
     * Shutdown hook: delete every temp file this process created that is still at its path.
     * A file the caller moved or deleted is simply gone and needs nothing.
     */
    public static function _delete_leftover_temp_files(): void
    {
        foreach (self::$__created_files as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        self::$__created_files = [];
    }
}
