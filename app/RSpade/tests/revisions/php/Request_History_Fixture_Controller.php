<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;

/**
 * Ajax endpoints for Request_History_Test: writers that write one framework site-scoped row
 * (_email_blocked_addresses, the session's site) per run, a writer that writes nothing, a
 * reader, #[Manual_Lock] endpoints, and writers that fail after writing - by throwing, or by
 * returning a coded result. Each counts its runs in $runs, so a test can tell a replay (no
 * run) from an execution. Indexed only under a test run.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Request_History_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** action => times its body ran. */
    public static array $runs = [];

    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function block(Request $request, array $params = [])
    {
        static::__count('block');
        static::__block((string) $params['email']);

        if (!empty($params['throw'])) {
            throw new RuntimeException('block threw after writing');
        }

        if (!empty($params['refuse'])) {
            return response_form_error('Refused after writing', ['email' => 'Refused']);
        }

        return ['email' => $params['email'], 'run' => static::$runs['block'], 'empty' => new \stdClass()];
    }

    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function block_elsewhere(Request $request, array $params = [])
    {
        static::__count('block_elsewhere');
        static::__block((string) $params['email']);

        return ['email' => $params['email']];
    }

    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writes_nothing(Request $request, array $params = [])
    {
        static::__count('writes_nothing');

        return ['ok' => true];
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function read(Request $request, array $params = [])
    {
        static::__count('read');

        return ['seen' => array_keys($params)];
    }

    /** A #[Manual_Lock] writer: its raw site write lock makes it a writer request. */
    #[Ajax_Endpoint]
    #[Manual_Lock]
    #[Auth('public')]
    public static function manual_block(Request $request, array $params = [])
    {
        static::__count('manual_block');

        $token = RsxLocks::site_write_lock((int) Session::get_site_id());

        try {
            static::__block((string) $params['email']);
        } finally {
            RsxLocks::release_lock($token);
        }

        return ['email' => $params['email']];
    }

    /** A #[Manual_Lock] endpoint that takes no lock: not a writer request. */
    #[Ajax_Endpoint]
    #[Manual_Lock]
    #[Auth('public')]
    public static function manual_reads(Request $request, array $params = [])
    {
        static::__count('manual_reads');

        return ['ok' => true];
    }

    private static function __count(string $action): void
    {
        static::$runs[$action] = (static::$runs[$action] ?? 0) + 1;
    }

    private static function __block(string $email): void
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'request history test';
        $row->save();
    }
}
