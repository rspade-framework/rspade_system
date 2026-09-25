<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Errors\Php;

use Exception;
use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Debug\Rsx_Diagnostics;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Exceptions\Ajax_Exception_Handler;
use App\RSpade\Core\Exceptions\Api_Exception_Handler;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Exception detail is keyed on WHO IS ASKING (Rsx_Diagnostics::caller_sees_detail()),
 * never on the mode alone: the JSON channels (Ajax, the external API) and the Ajax
 * endpoint resolver give a remote caller one generic answer plus an error id.
 *
 * The console's ambient request is a loopback caller, which the predicate admits; a
 * remote caller is simulated by moving the ambient request's peer off the box.
 *
 * Behavior of record: php artisan rsx:man error_handling (CALLER-KEYED DETAIL).
 */
class Diagnostic_Detail_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The predicate: loopback yes, remote no, strict production never.
     */
    public static function test_predicate_admits_loopback_refuses_remote_and_production()
    {
        static::__with_mode(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_true(Rsx_Diagnostics::caller_sees_detail(), 'a loopback caller is a developer caller');

            static::__as_remote_caller(function () {
                static::__assert_false(Rsx_Diagnostics::caller_sees_detail(), 'an anonymous remote caller is not');
            });
        });

        static::__with_mode(Rsx::MODE_DEBUG, function () {
            static::__assert_true(Rsx_Diagnostics::caller_sees_detail(), 'debug mode admits a developer caller');
        });

        static::__with_mode(Rsx::MODE_PRODUCTION, function () {
            static::__assert_false(Rsx_Diagnostics::caller_sees_detail(), 'strict production admits nobody');
        });
    }

    /**
     * A forwarded chain naming a remote client is decisive, even when the peer is the
     * local proxy hop.
     */
    public static function test_a_forwarded_remote_client_is_not_a_developer()
    {
        static::__with_mode(Rsx::MODE_DEVELOPMENT, function () {
            $headers = request()->headers;
            $headers->set('X-Forwarded-For', '203.0.113.9, 127.0.0.1');

            try {
                static::__assert_false(Rsx_Diagnostics::caller_sees_detail());
            } finally {
                $headers->remove('X-Forwarded-For');
            }
        });
    }

    /**
     * The Ajax envelope: a remote caller gets the generic sentence and an error id; a
     * developer caller gets the file, line and message.
     */
    public static function test_ajax_handler_redacts_for_a_remote_caller()
    {
        // The handler answers the AJAX CHANNEL - the request's stored classification.
        Rsx_Request_Channel::classify(Request::create('/_ajax/X/y', 'POST'));

        try {
            static::__with_mode(Rsx::MODE_DEVELOPMENT, function () {
                $handler = new Ajax_Exception_Handler();
                $request = Request::create('/_ajax/X/y', 'POST');

                static::__as_remote_caller(function () use ($handler, $request) {
                    $payload = $handler->handle(new RuntimeException('probe ajax secret'), $request)->getData(true);

                    static::__assert_equals('An unexpected error occurred. Please try again.', $payload['error']['error']);
                    static::__assert_true(
                        (bool) preg_match('/^[0-9a-f]{16}$/', $payload['error']['error_id'] ?? ''),
                        'the redacted envelope carries an error id'
                    );
                    static::__assert_false(isset($payload['error']['file']), 'no file for a remote caller');
                    static::__assert_false(isset($payload['error']['backtrace']), 'no trace for a remote caller');
                });

                $payload = $handler->handle(new RuntimeException('probe ajax secret'), $request)->getData(true);
                static::__assert_equals('probe ajax secret', $payload['error']['error']);
                static::__assert_true(isset($payload['error']['file']), 'a developer caller sees the origin');
            });
        } finally {
            Rsx_Request_Channel::reset();
        }
    }

    /**
     * The external API: a remote caller gets "Internal server error" and an error id,
     * never the exception text or class.
     */
    public static function test_api_handler_redacts_for_a_remote_caller()
    {
        // The handler answers the API CHANNEL - the request's stored classification.
        Rsx_Request_Channel::classify(Request::create('/api/v1/x'));

        try {
            static::__with_mode(Rsx::MODE_DEVELOPMENT, function () {
                static::__as_remote_caller(function () {
                    $handler = new Api_Exception_Handler();
                    $payload = $handler->handle(new RuntimeException('probe api secret'), Request::create('/api/v1/x'))
                        ->getData(true);

                    static::__assert_equals('Internal server error', $payload['error']['message']);
                    static::__assert_true(isset($payload['error']['error_id']), 'the API error carries an error id');
                });
            });
        } finally {
            Rsx_Request_Channel::reset();
        }
    }

    /**
     * Every way an Ajax target can fail to be an endpoint - no such class, not a
     * controller, not an endpoint - answers a remote caller with ONE sentence, so the
     * channel is not a class-name oracle.
     */
    public static function test_not_an_endpoint_is_one_message_for_a_remote_caller()
    {
        static::__with_mode(Rsx::MODE_DEVELOPMENT, function () {
            static::__as_remote_caller(function () {
                $messages = [];
                foreach ([['Nonexistent_Probe_Class', 'x'], ['Rsx', 'get_mode'], ['File_Attachment_Controller', 'no_such_method']] as [$class, $method]) {
                    try {
                        Ajax::internal($class, $method);
                        static::__fail("{$class}::{$method} resolved as an Ajax endpoint");
                    } catch (Exception $e) {
                        $messages[] = $e->getMessage();
                    }
                }

                static::__assert_equals('Nonexistent_Probe_Class::x is not an Ajax endpoint', $messages[0]);
                static::__assert_equals('Rsx::get_mode is not an Ajax endpoint', $messages[1]);
                static::__assert_equals('File_Attachment_Controller::no_such_method is not an Ajax endpoint', $messages[2]);
            });

            try {
                Ajax::internal('Nonexistent_Probe_Class', 'x');
            } catch (Exception $e) {
                static::__assert_contains('Controller class not found', $e->getMessage());
            }
        });
    }

    /**
     * Run a closure as an anonymous caller from off the box.
     */
    private static function __as_remote_caller(callable $fn): void
    {
        $server = request()->server;
        $original = $server->get('REMOTE_ADDR');
        $server->set('REMOTE_ADDR', '203.0.113.9');

        try {
            $fn();
        } finally {
            $server->set('REMOTE_ADDR', $original);
        }
    }

    /**
     * Run a closure with the application mode forced, restoring it afterwards.
     */
    private static function __with_mode(string $mode, callable $fn): void
    {
        Rsx::_testing_set_mode($mode);

        try {
            $fn();
        } finally {
            Rsx::clear_mode_cache();
        }
    }
}
