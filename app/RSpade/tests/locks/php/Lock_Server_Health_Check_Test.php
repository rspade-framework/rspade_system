<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Locks\Lockd_Connection;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Lockd_Client::lock_server() - the #[Health_Check('Lock Server')] probe - across its four
 * outcomes: a daemon answering ping (OK), nothing listening (FAIL naming the supervisor
 * program), a port that accepts but never answers (FAIL: wedged), and a raised maintenance
 * flag (INFO, without probing at all).
 *
 * The endpoint is pointed by setting the probe's override property directly, NOT through
 * Lockd_Client::_use_endpoint(): that closes the process's lock connection, and the
 * connection is the lock - closing it would release whatever this process holds.
 */
class Lock_Server_Health_Check_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function teardown()
    {
        Framework_Maintenance::$force_active_for_tests = null;
    }

    /** Run the probe against $endpoint (null = the configured daemon). */
    private static function __probe(?array $endpoint): array
    {
        $property = new \ReflectionProperty(Lockd_Client::class, 'endpoint_override');
        $previous = $property->getValue();
        $property->setValue(null, $endpoint);

        try {
            return Lockd_Client::lock_server();
        } finally {
            $property->setValue(null, $previous);
        }
    }

    /** A loopback port nothing is listening on: bound by the OS, then released. */
    private static function __free_port(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        return $port;
    }

    public static function test_a_daemon_answering_ping_is_ok()
    {
        $result = static::__probe(Lockd_Connection::default_endpoint());

        static::__assert_equals('OK', $result['status'], json_encode($result));
        static::__assert_contains('answering on', $result['detail']);
    }

    public static function test_nothing_listening_is_a_fail_naming_the_supervisor_program()
    {
        $port = static::__free_port();
        $result = static::__probe(['host' => '127.0.0.1', 'port' => $port]);

        static::__assert_equals('FAIL', $result['status'], json_encode($result));
        static::__assert_contains('not listening on 127.0.0.1:' . $port, $result['detail']);
        static::__assert_contains('[program:rsx-lockd]', $result['remediation'] ?? '');
    }

    public static function test_a_port_that_accepts_but_never_answers_is_a_fail()
    {
        // The kernel completes the handshake from the listen backlog; nothing here ever
        // accepts or writes, which is exactly a wedged daemon or a squatter on its port.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

        try {
            $result = static::__probe(['host' => '127.0.0.1', 'port' => $port]);
        } finally {
            fclose($server);
        }

        static::__assert_equals('FAIL', $result['status'], json_encode($result));
        static::__assert_contains('did not answer a ping', $result['detail']);
        static::__assert_contains('supervisorctl restart rsx-lockd', $result['remediation'] ?? '');
    }

    public static function test_a_raised_maintenance_flag_is_info_without_probing()
    {
        Framework_Maintenance::$force_active_for_tests = true;

        try {
            // A dead endpoint: under maintenance the probe must not even look.
            $result = static::__probe(['host' => '127.0.0.1', 'port' => static::__free_port()]);
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
        }

        static::__assert_equals('INFO', $result['status'], json_encode($result));
        static::__assert_contains('stopped on purpose', $result['detail']);
    }
}
