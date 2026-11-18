<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Support\UrlFetch;

/**
 * Rsx_Url_Fetch refused or failed a fetch. The message names the URL and the reason and is
 * written to be shown to the person who supplied the URL; $reason is one of the constants
 * below, for code that answers each differently, and $http_status is set for HTTP_STATUS.
 *
 * A fetch of a user's URL is input that can legitimately fail, so this is the exception a
 * caller catches (and turns into a field error); everything else is left to bubble.
 */
#[Instantiatable]
class Rsx_Url_Fetch_Exception extends \RuntimeException
{
    /** Not an http(s) URL with a host. */
    public const INVALID_URL = 'invalid_url';

    /** The host resolves to no address. */
    public const UNRESOLVED_HOST = 'unresolved_host';

    /** The host resolves to a non-public address (loopback, private, link-local, ...). */
    public const REFUSED_DESTINATION = 'refused_destination';

    /** The server answered a non-2xx status, or a redirect with no Location. */
    public const HTTP_STATUS = 'http_status';

    /** More redirects than Rsx_Url_Fetch::MAX_REDIRECTS. */
    public const TOO_MANY_REDIRECTS = 'too_many_redirects';

    /** Content-Length, or the bytes received, exceed max_bytes. */
    public const TOO_LARGE = 'too_large';

    /** No connection within rsx.url_fetch.connect_timeout_seconds. */
    public const CONNECT = 'connect';

    /** Any other transfer failure (refused connection, TLS, reset). */
    public const TRANSFER = 'transfer';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $http_status = null,
    ) {
        parent::__construct($message);
    }
}
