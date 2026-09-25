<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Debug;

use Illuminate\Support\Facades\Log;
use Throwable;
use App\RSpade\Core\Debug\Dev_Auth_Token;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Diagnostics - who may see exception detail, and the reference everyone else gets.
 *
 * Exception detail (class, message, file, line, trace, SQL) describes the deployment: its
 * paths, its schema, its hostnames. So it is keyed on WHO IS ASKING, never on the mode
 * alone - a development-mode RSpade site may be serving the public right now, and "not
 * production" is not a security predicate.
 *
 * caller_sees_detail() is the ONE predicate every error channel asks (the web page and
 * Ignition, the Ajax envelope and the batch envelope, the external API, Error_Screens).
 * It is true only in development or debug mode AND for a caller that is one of:
 *
 *   - a signed-in developer (Session::is_developer(), login_users.is_developer);
 *   - a request carrying a VALID rsx:debug dev-auth credential (Dev_Auth_Token);
 *   - a loopback caller (is_loopback_ip(): the peer AND every forwarded hop are local).
 *
 * Strict production answers false for everybody.
 *
 * Everyone else gets the channel's generic answer plus an ERROR ID, and the full detail
 * goes to the log under that id (report_redacted()), so a user's "it said ref 7f3c..." is
 * one grep away from the trace.
 */
class Rsx_Diagnostics
{
    /**
     * May the caller of the current request see exception detail?
     *
     * @return bool
     */
    public static function caller_sees_detail(): bool
    {
        if (Rsx::is_production() && !Rsx::is_debug()) {
            return false;
        }

        // Cheapest first, and the two header checks touch no database: this runs inside
        // exception handlers, where the database may be the thing that failed.
        if (is_loopback_ip()) {
            return true;
        }

        if (static::__has_valid_dev_auth()) {
            return true;
        }

        return static::__session_is_developer();
    }

    /**
     * Log the full detail of an exception under a fresh error id and return the id.
     *
     * The id is what the caller is shown instead of the detail. The log line carries the
     * exception itself, so the trace is attached exactly as Laravel's own report would.
     *
     * @param Throwable $e
     * @param string $channel Which channel redacted it ('web', 'ajax', 'ajax_batch', 'api')
     * @return string The error id
     */
    public static function report_redacted(Throwable $e, string $channel): string
    {
        $error_id = bin2hex(random_bytes(8));

        Log::error(sprintf(
            '[error_id=%s] %s exception redacted for the caller: %s: %s at %s:%d',
            $error_id,
            $channel,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ), ['exception' => $e]);

        return $error_id;
    }

    /**
     * True when the request carries an rsx:debug dev-auth credential that verifies.
     *
     * Staff credentials sign the request URI; portal credentials sign it with the portal
     * prefix normalized off. A credential that is present but fails is simply not proof.
     *
     * @return bool
     */
    protected static function __has_valid_dev_auth(): bool
    {
        $request = request();
        $token = $request->header('X-Dev-Auth-Token');
        if (!$token) {
            return false;
        }

        $exp = $request->header('X-Dev-Auth-Exp');
        $uri = $request->getRequestUri();

        $staff_user_id = $request->header('X-Dev-Auth-User-Id');
        if ($staff_user_id && ctype_digit((string) $staff_user_id)) {
            return Dev_Auth_Token::verify($uri, (int) $staff_user_id, false, $exp, $token) === null;
        }

        $portal_user_id = $request->header('X-Dev-Auth-Portal-User-Id');
        if ($portal_user_id && ctype_digit((string) $portal_user_id)) {
            $portal_uri = Rsx_Portal::strip_prefix($uri);

            return Dev_Auth_Token::verify($portal_uri, (int) $portal_user_id, true, $exp, $token) === null;
        }

        return false;
    }

    /**
     * Is the session's login identity a developer?
     *
     * Reading the session touches the database, and this runs while an exception is being
     * rendered - quite possibly one the database raised. A read that fails proves nothing,
     * so it answers false: detail is withheld, which is the safe direction.
     *
     * @return bool
     */
    protected static function __session_is_developer(): bool
    {
        try {
            return Session::is_developer();
        } catch (Throwable $e) {
            return false;
        }
    }
}
