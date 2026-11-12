<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Response\Rollback_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;

/**
 * Ajax endpoints for Site_Lock_Hold_Discipline_Test: a write exception recorded from a reader,
 * a manual handler and a writer, one left open, one around a write section, and a writer
 * answering an error by returning it or by throwing Rollback_Response. Every row is a framework
 * site-scoped _email_blocked_addresses row for $params['email'], on the session's site.
 * Indexed only under a test run.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Site_Lock_Exception_Fixture_Controller extends Rsx_Controller_Abstract
{
    /**
     * A reader recording under a write exception, then ($params['then']) returning, throwing,
     * or leaving a second exception open.
     */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_records(Request $request, array $params = [])
    {
        Site_Lock::write_exception('site lock test: record from a reader', fn () => static::__block((string) $params['email']));

        $state = static::state();

        if (($params['then'] ?? '') === 'throw') {
            throw new RuntimeException('reader_records threw after recording');
        }

        if (($params['then'] ?? '') === 'leak') {
            Site_Lock::acquire_write_exception('site lock test: never released');
        }

        return $state;
    }

    /** A #[Manual_Lock] handler holding nothing, recording under a write exception. */
    #[Ajax_Endpoint]
    #[Manual_Lock]
    #[Auth('public')]
    public static function manual_records(Request $request, array $params = [])
    {
        Site_Lock::write_exception('site lock test: record from a manual handler', fn () => static::__block((string) $params['email']));

        return static::state();
    }

    /** A writer recording under a write exception, then throwing. */
    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_records_then_throws(Request $request, array $params = [])
    {
        Site_Lock::write_exception('site lock test: record from a writer', fn () => static::__block((string) $params['email']));

        throw new RuntimeException('writer_records_then_throws threw after recording');
    }

    /** A reader opening a write section inside a write exception: still an upgrade. */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_sections_in_exception(Request $request, array $params = [])
    {
        return Site_Lock::write_exception('site lock test: not a lock', fn () => Site_Lock::write((int) Session::get_site_id(), fn () => true));
    }

    /** A reader reaching a writer in-process from inside a write exception: still an upgrade. */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_calls_writer_in_exception(Request $request, array $params = [])
    {
        return Site_Lock::write_exception('site lock test: not a lock', fn () => Ajax::internal('Site_Lock_Dispatch_Fixture_Controller', 'writer_probe'));
    }

    /**
     * A writer that writes, then answers a validation error: by RETURNING it ('return') or by
     * throwing Rollback_Response with the same value ('throw').
     */
    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_answers_error(Request $request, array $params = [])
    {
        static::__block((string) $params['email']);

        $answer = response_form_error('Row 2 is invalid', ['row_2' => 'Missing a name']);

        if (($params['how'] ?? '') === 'throw') {
            throw new Rollback_Response($answer);
        }

        return $answer;
    }

    /** A writer throwing Rollback_Response carrying an HTTP response: refused for an Ajax call. */
    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_rolls_back_with_http_response(Request $request, array $params = [])
    {
        throw new Rollback_Response(response()->json(['error' => 'not for Ajax'], 422));
    }

    /** The lock state the caller runs under, through the public queries. */
    public static function state(): array
    {
        return [
            'held_mode' => Site_Lock::held_mode(),
            'in_write_exception' => Site_Lock::in_write_exception(),
            'in_bypass' => Site_Lock::in_bypass(),
        ];
    }

    private static function __block(string $email): void
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'site lock hold discipline test';
        $row->save();
    }
}
