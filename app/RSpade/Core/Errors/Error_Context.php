<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Errors;

/**
 * Error_Context - everything an error page is told about the failure
 *
 * An application error page receives exactly this object, as $params['error'],
 * and has nothing else to read: the failing request is over, its controller
 * never ran, and the page renders anonymously. A page shows what the context
 * carries and nothing more.
 *
 * READONLY, because the funnel builds it once and the page only renders it.
 *
 * detail is the exception block (class, message, file, line, frames) and is
 * populated for a 500 only - and only for a caller Rsx_Diagnostics admits (a
 * developer, outside production), because an error page is fully inspectable
 * with curl. Every other 500 carries error_id instead: the reference under
 * which the full detail was logged, for the page to show.
 *
 * preview is true when the context was fabricated by a development browse of
 * /error/<code>, so a page can say so if it wants to; every other field is the
 * same shape a real failure produces.
 *
 * See: php artisan rsx:man error_pages
 */
#[Instantiatable]
class Error_Context
{
    /**
     * @param int $status The HTTP status the response will carry
     * @param string $title Short headline ("Page Not Found")
     * @param string $message One-sentence explanation, or the reason an endpoint gave
     * @param string $path The failing request path
     * @param string $method The failing request's HTTP method
     * @param string $realm Auth_Gates::REALM_STAFF or REALM_PORTAL
     * @param string $home_url The realm's home
     * @param bool $preview True when rendered from a development /error/<code> browse
     * @param array|null $detail Exception detail for a 500, developer callers only
     * @param string|null $error_id The log reference of a redacted 500's detail
     */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $message,
        public readonly string $path,
        public readonly string $method,
        public readonly string $realm,
        public readonly string $home_url,
        public readonly bool $preview,
        public readonly ?array $detail,
        public readonly ?string $error_id = null
    ) {
    }
}
