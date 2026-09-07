<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Cli;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Mail_Test_Command_Cli_Test - `php artisan rsx:mail:test`.
 *
 * The command is the operator's answer to "does mail work on this box", so what is
 * under test is its REPORT: the exit code, the machine-readable payload, and the path to
 * the captured message a developer is told to `cat`. Every other test in this concern
 * asks a smaller question; the command's job is to ask the whole one and answer honestly.
 *
 * RUN IN-PROCESS, VIA Artisan::call(), per tests/CLAUDE.md's preference for the cli kind.
 * That is not merely convenient here, it is the only correct choice: a spawned artisan
 * would boot against the DEVELOPER's database and the developer's MAIL_HOST, so it would
 * queue a real row outside the test database and prove nothing about the configuration
 * the test set up. In-process, config() pins the transport at the loopback catcher and
 * the row lands in the test database like every other row this concern writes.
 */
class Mail_Test_Command_Cli_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const CATCHER_HOST = '127.0.0.1';
    private const CATCHER_PORT = 1025;

    /** What an operator has to start when this test fails for the ordinary reason. */
    private const CATCHER_PROGRAM = '[program:mail-catcher]';

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * Run the command with the transport pinned at the development catcher.
     *
     * The host is pinned rather than inherited: this install's MAIL_HOST is whatever an
     * operator configured, and a test that sent wherever that points would be a test that
     * mails strangers.
     *
     * @return array{0: int, 1: string} exit code and captured output
     */
    private static function __run(array $arguments): array
    {
        static::__acting_as_site(self::SITE_ID);

        // Nothing is stubbed: this command's whole value is that it uses the real chain.
        Rsx_Mail_Transport::$override_for_tests = null;

        $previous = config('rsx.mail.delivery');

        // THE MODE IS THE PINNING. In 'aiosmtpd' the transport is 127.0.0.1:1025 with no
        // encryption and no auth by definition - rsx.mail.transport.* is not consulted -
        // so naming the mode says everything the old six-key block said, and says it the
        // way an install says it. A test that pinned the host under 'live' would also be
        // arming the .dev.-hostname recipient gate, which is not this class's subject.
        config(['rsx.mail.delivery' => 'aiosmtpd']);

        try {
            $exit_code = Artisan::call('rsx:mail:test', $arguments);

            return [$exit_code, Artisan::output()];
        } finally {
            config([
                'rsx.mail.delivery' => $previous,
            ]);
        }
    }

    // =========================================================================

    public static function test_a_send_to_the_catcher_reports_sent_as_json()
    {
        $address = 'cli_' . uniqid() . '@example.com';

        [$exit_code, $output] = static::__run(['address' => $address, '--json' => true]);

        static::__assert_equals(
            0,
            $exit_code,
            'exit 0 means the message reached a terminal state an operator asked for. '
                . 'A non-zero exit here usually means the development mail catcher is not running - '
                . 'supervisor ' . self::CATCHER_PROGRAM . '. Output: ' . $output
        );

        $payload = json_decode(trim($output), true);

        static::__assert_equals(JSON_ERROR_NONE, json_last_error(), '--json prints parseable JSON: ' . $output);

        static::__assert_equals('Sent', $payload['status'], 'the message was accepted by the transport');
        static::__assert_equals($address, $payload['to_address'], 'and addressed where the operator said');
        static::__assert_equals('Rsx_Mail_Test_Email', $payload['email_class'], 'the framework probe is the default message');
        static::__assert_equals('aiosmtpd', $payload['delivery'], 'delivery mode is reported');
        static::__assert_equals(1, $payload['attempt_count'], 'one attempt, and it worked');
        static::__assert_not_empty($payload['message_id_header'], 'the Message-ID is reported so it can be traced');
        static::__assert_null($payload['transport_unavailable'], 'the transport was reachable');

        static::__assert_not_empty(
            $payload['captured_file'],
            'the report names the file the development catcher wrote'
        );
        static::__assert_true(
            is_file($payload['captured_file']),
            'and that file exists, so `cat ' . $payload['captured_file'] . '` works as printed'
        );
    }

    public static function test_the_reported_queue_row_is_the_row_that_was_sent()
    {
        [$exit_code, $output] = static::__run([
            'address' => 'cli_row_' . uniqid() . '@example.com',
            '--json' => true,
        ]);

        static::__assert_equals(0, $exit_code, 'the send succeeded: ' . $output);

        $payload = json_decode(trim($output), true);
        $row = Email_Queue_Model::find($payload['queue_id']);

        static::__assert_not_null($row, 'the reported queue id names a real row');
        static::__assert_equals(
            Email_Queue_Model::STATUS_SENT,
            (int) $row->status_id,
            'and the status printed is the status the row actually reached - the command '
                . 'drains synchronously and re-reads the row AFTER, rather than reporting a '
                . 'detached worker\'s outcome it cannot see'
        );
    }

    /**
     * The human report is what an operator actually reads, so it says the same things.
     */
    public static function test_the_human_report_names_the_transport_and_the_captured_file()
    {
        [$exit_code, $output] = static::__run(['address' => 'cli_human_' . uniqid() . '@example.com']);

        static::__assert_equals(0, $exit_code, 'the send succeeded: ' . $output);

        static::__assert_contains('Delivery mode:', $output, 'the report names the delivery mode');
        static::__assert_contains(
            'smtp ' . self::CATCHER_HOST . ':' . self::CATCHER_PORT,
            $output,
            'and the transport it used, so nobody has to guess where the mail went'
        );
        static::__assert_contains('Status:', $output, 'and the outcome');
        static::__assert_contains('[OK] Sent', $output, 'ending in the one line that answers the question');
        static::__assert_contains('cat ', $output, 'plus the command that shows the captured message');
    }

    /**
     * A name that is not an email class is an operator typo, and the useful answer is the
     * list of names that WOULD have worked - not a stack trace.
     */
    public static function test_an_unknown_email_class_exits_one_and_lists_the_known_ones()
    {
        [$exit_code, $output] = static::__run([
            'address' => 'cli_unknown_' . uniqid() . '@example.com',
            '--email' => 'Not_An_Email',
        ]);

        static::__assert_equals(1, $exit_code, 'an unusable --email is a failure');
        static::__assert_contains("'Not_An_Email' is not an email class", $output, 'and says so plainly');
        static::__assert_contains('Known email classes:', $output, 'then lists what would have worked');
        static::__assert_contains('Rsx_Mail_Test_Email', $output, 'including the framework probe');
    }

    /**
     * Nothing is queued when the class cannot be resolved: the command decides what it is
     * sending BEFORE it enqueues, so a typo leaves no row behind to confuse anybody.
     */
    public static function test_an_unknown_email_class_queues_nothing()
    {
        $address = 'cli_nothing_' . uniqid() . '@example.com';

        static::__run(['address' => $address, '--email' => 'Not_An_Email']);

        static::__assert_equals(
            0,
            Email_Queue_Model::where('to_address', $address)->count(),
            'a refused send writes no queue row'
        );
    }

    /**
     * `--email=<Class>` sends that class's sample(). It is how an operator reviews a real
     * application email without triggering the business event that would produce one.
     */
    public static function test_naming_an_email_class_sends_that_class_sample()
    {
        [$exit_code, $output] = static::__run([
            'address' => 'cli_sample_' . uniqid() . '@example.com',
            '--email' => 'Rsx_Mail_Test_Email',
            '--json' => true,
        ]);

        static::__assert_equals(0, $exit_code, 'the named class sent: ' . $output);

        $payload = json_decode(trim($output), true);

        static::__assert_equals('Rsx_Mail_Test_Email', $payload['email_class'], 'the named class is what was sent');
        static::__assert_equals('Sent', $payload['status'], 'and it reached the transport');
    }

    /**
     * Suppressed is a SETTING somebody chose, not a fault - so the command reports it and
     * exits 0. An operator who suppressed delivery and then got exit 1 would go looking
     * for a broken mail host that is working fine.
     */
    public static function test_suppressed_delivery_is_reported_and_still_exits_zero()
    {
        $previous = config('rsx.mail.delivery');

        try {
            [$exit_code, $output] = static::__run_suppressed('cli_suppressed_' . uniqid() . '@example.com');

            static::__assert_equals(0, $exit_code, 'a deliberate setting is not a failure: ' . $output);

            $payload = json_decode(trim($output), true);

            static::__assert_equals('Suppressed', $payload['status'], 'the status says what happened');
            static::__assert_equals('suppressed', $payload['delivery'], 'and the mode says why');
        } finally {
            config(['rsx.mail.delivery' => $previous]);
        }
    }

    /**
     * __run() pins delivery to live; this pins it back after, so the suppressed case gets
     * the same transport setup with the one setting that is under test changed.
     *
     * @return array{0: int, 1: string}
     */
    private static function __run_suppressed(string $address): array
    {
        static::__acting_as_site(self::SITE_ID);

        Rsx_Mail_Transport::$override_for_tests = null;

        $previous = [
            config('rsx.mail.delivery'),
            config('rsx.mail.transport.host'),
            config('rsx.mail.transport.port'),
        ];

        config([
            'rsx.mail.delivery' => 'suppressed',
            'rsx.mail.transport.host' => self::CATCHER_HOST,
            'rsx.mail.transport.port' => self::CATCHER_PORT,
        ]);

        try {
            $exit_code = Artisan::call('rsx:mail:test', ['address' => $address, '--json' => true]);

            return [$exit_code, Artisan::output()];
        } finally {
            config([
                'rsx.mail.delivery' => $previous[0],
                'rsx.mail.transport.host' => $previous[1],
                'rsx.mail.transport.port' => $previous[2],
            ]);
        }
    }
}
