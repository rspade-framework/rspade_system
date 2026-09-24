<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use GuzzleHttp\Psr7\Request as Psr7_Request;
use GuzzleHttp\Psr7\Response as Psr7_Response;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use App\RSpade\Core\Mail\Mail_Transport_Unavailable_Exception;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;
use App\RSpade\Tests\Mail\Php\Mail_Transport_Stub;

/**
 * Mail_Laravel_Mailer_Test - 'live' delivery goes through whatever Laravel has configured.
 *
 * The queue builds the message - rendering, CSS, inline images, headers - and hands the
 * finished MIME to the Symfony transport Laravel's MailManager builds for MAIL_MAILER. So a
 * transport a composer package registers with Mail::extend() (a Microsoft Graph one, say)
 * carries the queue with nothing framework-side to write. That is proved here with a
 * transport registered exactly the way such a package registers one.
 *
 * The second half is the part a package cannot be trusted to get right: an API transport
 * fails with ITS HTTP client's exceptions, and the drain must still sort each failure into
 * "this message was refused" (retry clock, FAILED at the cap) or "the service is
 * unavailable" (row back to PENDING, drain dies). A refusal misread as an outage is a
 * poisoned message that stops the queue forever; the reverse burns every message's
 * retries during a blip.
 */
class Mail_Laravel_Mailer_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const ONE_PIXEL_PNG =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** The transport the registered driver built, and the config it was handed. */
    private static ?Mail_Transport_Stub $built_transport = null;
    private static array $built_config = [];

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * Queue one fixture message past the dev-host gate and return its row.
     */
    private static function __queue(?callable $configure = null): Email_Queue_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            $email = new Mail_Notification_Fixture_Email('Laravel mailer probe');
            $email->to('laravel_mailer_' . uniqid() . '@example.com');

            if ($configure !== null) {
                $configure($email);
            }

            return $email->send();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }
    }

    /**
     * Run $fn with 'live' delivery through a mailer named 'fake-graph', whose transport
     * a Mail::extend() registration builds - the shape a transport package ships.
     */
    private static function __with_registered_mailer(callable $fn): mixed
    {
        Mail::extend('fake-graph', function (array $config) {
            self::$built_config = $config;
            self::$built_transport = new Mail_Transport_Stub();

            return self::$built_transport;
        });

        $previous = [
            config('rsx.mail.delivery'),
            config('mail.default'),
            config('mail.mailers.fake-graph'),
            config('rsx.mail.dev_site.domain_whitelist'),
        ];

        config([
            'rsx.mail.delivery' => 'live',
            'mail.default' => 'fake-graph',
            'mail.mailers.fake-graph' => ['transport' => 'fake-graph', 'tenant_id' => 'tenant-probe'],
            'rsx.mail.dev_site.domain_whitelist' => 'example.com',
        ]);

        Rsx_Mail_Transport::$override_for_tests = null;

        try {
            return $fn();
        } finally {
            config([
                'rsx.mail.delivery' => $previous[0],
                'mail.default' => $previous[1],
                'mail.mailers.fake-graph' => $previous[2],
                'rsx.mail.dev_site.domain_whitelist' => $previous[3],
            ]);
            self::$built_transport = null;
            self::$built_config = [];
        }
    }

    // =========================================================================
    // A REGISTERED TRANSPORT CARRIES THE QUEUE
    // =========================================================================

    /**
     * The mailer MAIL_MAILER names is built by Laravel from its config, the finished
     * message reaches it, and the row records it SENT through that mailer.
     */
    public static function test_a_transport_registered_with_mail_extend_carries_the_queue()
    {
        static::__with_registered_mailer(function () {
            $row = static::__queue();

            Task::internal('Mail_Queue_Service', 'send_pending_queue');

            $row = Email_Queue_Model::find($row->id);

            static::__assert_not_null(self::$built_transport, 'Laravel built the registered transport');
            static::__assert_equals('tenant-probe', self::$built_config['tenant_id'] ?? null, 'from the mailer\'s own config');
            static::__assert_equals(Email_Queue_Model::STATUS_SENT, (int) $row->status_id, 'the row is SENT');
            static::__assert_equals('fake-graph', $row->transport, 'through the mailer MAIL_MAILER names');

            $subjects = array_map(fn ($message) => (string) $message->getSubject(), self::$built_transport->sent_messages);
            static::__assert_true(in_array($row->subject, $subjects, true), 'and the transport received that message');
        });
    }

    /**
     * An inline image is part of the message the queue BUILT, so it reaches a registered
     * transport intact: the html's cid: reference names the image part's Content-ID.
     */
    public static function test_an_inline_image_reaches_the_registered_transport_intact()
    {
        $png = Rsx_Project_Paths::tmp_path('laravel_mailer_probe_' . uniqid() . '.png');
        file_put_contents_safe($png, base64_decode(self::ONE_PIXEL_PNG));

        try {
            static::__with_registered_mailer(function () use ($png) {
                $row = static::__queue(function ($email) use ($png) {
                    $email->show_image = true;
                    $email->embed('fixture_image', $png);
                });

                Task::internal('Mail_Queue_Service', 'send_pending_queue');

                $message = null;
                foreach (self::$built_transport->sent_messages as $candidate) {
                    if ((string) $candidate->getSubject() === $row->subject) {
                        $message = $candidate;
                    }
                }

                static::__assert_true($message instanceof Email, 'the transport received the built message');

                $raw = $message->toString();
                preg_match('/src=3D"cid:([^"]+)"|src="cid:([^"]+)"/', quoted_printable_decode($raw), $reference);
                preg_match('/^Content-ID: <([^>]+)>/mi', $raw, $part_id);

                $referenced = ($reference[1] ?? '') !== '' ? $reference[1] : ($reference[2] ?? '');

                static::__assert_not_empty($referenced, 'the html references its image by content id');
                static::__assert_equals($part_id[1] ?? null, $referenced, 'and that id is the image part\'s Content-ID');
                static::__assert_contains('Content-Disposition: inline', $raw, 'carried inline, not as an attachment');
            });
        } finally {
            @unlink($png);
        }
    }

    /**
     * The health row CONSTRUCTS a transport it cannot probe over TCP: a registered one is
     * OK, and one whose driver nobody registered FAILs naming the fix, before a single
     * message is queued against it.
     */
    public static function test_the_health_row_constructs_the_transport()
    {
        static::__with_registered_mailer(function () {
            $row = static::__transport_row();
            static::__assert_equals('OK', $row['status'], 'a registered transport is constructed: ' . $row['detail']);

            config([
                'mail.default' => 'unregistered-probe',
                'mail.mailers.unregistered-probe' => ['transport' => 'unregistered-probe'],
            ]);

            $row = static::__transport_row();
            static::__assert_equals('FAIL', $row['status'], 'an unregistered driver fails the row');
            static::__assert_contains('rsx.integrations.providers', $row['remediation'] ?? '', 'naming where a provider is registered');
        });
    }

    private static function __transport_row(): array
    {
        foreach (Rsx_Mail_Transport::mail_health() as $row) {
            if ($row['label'] === 'Mail transport') {
                return $row;
            }
        }

        return static::__fail('mail_health() returned no Mail transport row');
    }

    // =========================================================================
    // AN API TRANSPORT'S FAILURES ARE SORTED
    // =========================================================================

    /**
     * Drain one queued row through a transport throwing $failure, returning the row after.
     */
    private static function __drain_throwing(\Throwable $failure, bool $expect_outage): Email_Queue_Model
    {
        $row = static::__queue();

        Rsx_Mail_Transport::$override_for_tests = new Mail_Transport_Stub(Mail_Transport_Stub::MODE_THROW, $failure);

        try {
            if ($expect_outage) {
                static::__assert_throws(
                    Mail_Transport_Unavailable_Exception::class,
                    fn () => Task::internal('Mail_Queue_Service', 'send_pending_queue')
                );
            } else {
                Task::internal('Mail_Queue_Service', 'send_pending_queue');
            }
        } finally {
            Rsx_Mail_Transport::$override_for_tests = null;
        }

        return Email_Queue_Model::find($row->id);
    }

    private static function __graph_request(): Psr7_Request
    {
        return new Psr7_Request('POST', 'https://graph.example.com/v1.0/users/sender/sendMail');
    }

    public static function test_an_http_4xx_is_a_refusal_of_this_message()
    {
        $failure = new ClientException('400 Bad Request: invalid recipient', static::__graph_request(), new Psr7_Response(400));
        $row = static::__drain_throwing($failure, false);

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id, 'the row waits for its retry');
        static::__assert_equals(1, (int) $row->attempt_count, 'with the attempt COUNTED');
        static::__assert_not_null($row->next_attempt_at, 'on its own clock');
        static::__assert_contains('HTTP 400', (string) $row->last_error, 'and the status recorded');
    }

    public static function test_an_http_5xx_is_an_outage()
    {
        $failure = new ServerException('503 Service Unavailable', static::__graph_request(), new Psr7_Response(503));
        $row = static::__drain_throwing($failure, true);

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id, 'the row is back in the queue');
        static::__assert_equals(0, (int) $row->attempt_count, 'with no attempt spent');
    }

    public static function test_a_rate_limit_is_an_outage_not_a_refusal()
    {
        $failure = new ClientException('429 Too Many Requests', static::__graph_request(), new Psr7_Response(429));
        $row = static::__drain_throwing($failure, true);

        static::__assert_equals(0, (int) $row->attempt_count, 'a 429 spends no attempt');
    }

    public static function test_a_connection_failure_is_an_outage()
    {
        $failure = new ConnectException('Could not resolve host: graph.example.com', static::__graph_request());
        $row = static::__drain_throwing($failure, true);

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id, 'the row is back in the queue');
        static::__assert_equals(0, (int) $row->attempt_count, 'with no attempt spent');
    }

    /**
     * A package that wraps its client's exception in a Symfony one is read through the
     * chain: the 4xx underneath still makes it a refusal, not the outage the outer type
     * alone would say.
     */
    public static function test_a_status_is_read_through_a_wrapping_exception()
    {
        $inner = new ClientException('422 Unprocessable: attachment rejected', static::__graph_request(), new Psr7_Response(422));
        $row = static::__drain_throwing(new TransportException('Graph send failed', 0, $inner), false);

        static::__assert_equals(1, (int) $row->attempt_count, 'the wrapped 4xx is a refusal');
        static::__assert_contains('HTTP 422', (string) $row->last_error, 'and its status is recorded');
    }

    /**
     * An exception that says nothing about HTTP or connections cannot be told apart, so it
     * is bounded by the retry clock rather than allowed to stop the queue.
     */
    public static function test_an_unrecognised_exception_is_a_refusal()
    {
        $row = static::__drain_throwing(new \RuntimeException('token endpoint returned garbage'), false);

        static::__assert_equals(1, (int) $row->attempt_count, 'the attempt is counted');
        static::__assert_contains('token endpoint returned garbage', (string) $row->last_error, 'and the reason recorded');
    }
}
