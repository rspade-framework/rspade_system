<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Mail_Transport_Unavailable_Exception;
use App\RSpade\Core\Mail\Rsx_Mail_Test_Email;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Mail_Catcher_Delivery_Test - the whole chain, with nothing stubbed.
 *
 * Every other test in this concern replaces something: the transport is a stub, or the
 * builder is called directly, or the row is written by hand. This one replaces nothing.
 * A real message goes over a real SMTP conversation to the development catcher, and the
 * assertion is made against THE FILE THE CATCHER WROTE - the bytes a recipient would
 * have received.
 *
 * That is the only test here that can catch a defect living in the seam: a transport
 * DSN that is subtly wrong, a header the mailer drops on the way out, an envelope
 * recipient that does not match the To. Everything upstream of the socket can be
 * perfect while the message still never arrives.
 *
 * IT NEVER SKIPS. If the catcher is not listening, this FAILS and names the supervisor
 * program to start - a green suite that quietly stopped proving delivery is worse than
 * a red one, because nobody looks at it again.
 *
 * The transport host is PINNED here rather than inherited: this install's MAIL_HOST is
 * whatever an operator configured, and this test's subject is the catcher.
 */
class Mail_Catcher_Delivery_Test extends Rsx_Test_Abstract
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
     * The Maildir the development catcher writes into.
     */
    private static function __maildir(): string
    {
        return rtrim((string) config('rsx.mail.catcher_maildir'), '/');
    }

    /**
     * Every captured message file, newest last.
     *
     * @return array<int,string>
     */
    private static function __captured_files(): array
    {
        $files = [];

        foreach (['new', 'cur'] as $subdir) {
            $path = static::__maildir() . '/' . $subdir;

            if (!is_dir($path)) {
                continue;
            }

            foreach (scandir($path) as $entry) {
                $file = $path . '/' . $entry;

                if ($entry !== '.' && $entry !== '..' && is_file($file)) {
                    $files[$file] = filemtime($file);
                }
            }
        }

        asort($files);

        return array_keys($files);
    }

    /**
     * The captured file carrying this queue row's X-RSX-Email-Id, read back in full.
     *
     * Matching on the header rather than on "the newest file" is deliberate: this box
     * runs a development catcher that other work also writes into, and a test that
     * asserted against whatever landed last would pass or fail on somebody else's
     * message.
     */
    private static function __captured_message_for(int $email_id, array $before): ?string
    {
        $needle = 'X-RSX-Email-Id: ' . $email_id;

        foreach (array_reverse(static::__captured_files()) as $file) {
            if (in_array($file, $before, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            if (str_contains($contents, $needle)) {
                return $contents;
            }
        }

        return null;
    }

    /**
     * Send one real message to $address through the real transport, and return the row.
     */
    private static function __deliver(string $address): Email_Queue_Model
    {
        static::__acting_as_site(self::SITE_ID);

        // NOTHING is stubbed here - the override must be off even if a previous class
        // left it set, or this test would silently prove nothing.
        Rsx_Mail_Transport::$override_for_tests = null;

        $previous = config('rsx.mail.delivery');

        // THE MODE IS THE PINNING. In 'aiosmtpd' the transport is 127.0.0.1:1025 with no
        // encryption and no auth by definition - rsx.mail.transport.* is not consulted -
        // so naming the mode says everything the old six-key block said, and says it the
        // way an install says it. A test that pinned the host under 'live' would also be
        // arming the .dev.-hostname recipient gate, which is not this class's subject.
        config(['rsx.mail.delivery' => 'aiosmtpd']);

        try {
            // aiosmtpd mode reaches nobody but the catcher, so the .dev.-hostname
            // recipient gate does not apply and the address is delivered as written.
            $record = (new Rsx_Mail_Test_Email())->to($address)->send();

            try {
                Task::internal('Mail_Queue_Service', 'send_pending_queue');
            } catch (Mail_Transport_Unavailable_Exception $e) {
                static::__fail(
                    'The development mail catcher is not accepting connections on '
                    . self::CATCHER_HOST . ':' . self::CATCHER_PORT . '. Start it - supervisor '
                    . self::CATCHER_PROGRAM . ' - and run this again. It is not skipped: a suite '
                    . 'that stops proving delivery is a suite nobody can trust. (' . $e->getMessage() . ')'
                );
            }

            return $record->fresh();
        } finally {
            config([
                'rsx.mail.delivery' => $previous,
            ]);
        }
    }

    // =========================================================================

    /**
     * THE GREETING IS THE FIRST THING THE MODE PROMISES.
     *
     * aiosmtpd mode trusts 127.0.0.1:1025 to be the local catcher, and the only evidence
     * available before a message is handed over is what the server says about itself. If
     * something else ever takes that port, this fails here rather than in the Maildir
     * assertions below - which would otherwise fail for a reason that reads like a bug in
     * the builder.
     */
    public static function test_the_catcher_advertises_itself_as_aiosmtpd()
    {
        $banner = Rsx_Mail_Transport::probe_banner();

        static::__assert_not_empty(
            $banner,
            'the catcher answered on ' . self::CATCHER_HOST . ':' . self::CATCHER_PORT
            . ' - supervisor ' . self::CATCHER_PROGRAM
        );

        static::__assert_true(
            stripos($banner, 'aiosmtpd') !== false,
            'the greeting says aiosmtpd (got: ' . $banner . ') - see system/bin/mail_catcher.py'
        );

        static::__assert_null(
            static::__banner_error(),
            'so aiosmtpd mode trusts the connection'
        );
    }

    /**
     * The banner verdict, asked in the mode that asks it.
     */
    private static function __banner_error(): ?string
    {
        $previous = config('rsx.mail.delivery');
        config(['rsx.mail.delivery' => 'aiosmtpd']);

        try {
            return Rsx_Mail_Transport::aiosmtpd_banner_error();
        } finally {
            config(['rsx.mail.delivery' => $previous]);
        }
    }

    public static function test_a_real_send_lands_in_the_catcher_maildir()
    {
        static::__assert_true(
            is_dir(static::__maildir()),
            'the catcher Maildir (' . static::__maildir() . ') exists - supervisor ' . self::CATCHER_PROGRAM
        );

        $address = 'catcher_' . uniqid() . '@example.com';
        $before = static::__captured_files();

        $row = static::__deliver($address);

        static::__assert_equals(
            Email_Queue_Model::STATUS_SENT,
            (int) $row->status_id,
            'the transport accepted the message'
        );
        static::__assert_not_empty($row->message_id_header, 'and the row records its Message-ID');

        $captured = static::__captured_message_for((int) $row->id, $before);

        static::__assert_not_null(
            $captured,
            'the catcher wrote a file carrying X-RSX-Email-Id: ' . $row->id
                . ' - if this is null the SMTP conversation succeeded but nothing was filed'
        );

        static::__assert_contains(
            'X-RcptTo: ' . $address,
            $captured,
            'the envelope recipient the catcher saw is the address the row was addressed to'
        );
        static::__assert_contains(
            'X-RSX-Email-Id: ' . $row->id,
            $captured,
            'and the message names its queue row, which is how a bounce is traced back'
        );
    }

    /**
     * The captured bytes are a real multipart message with both readings and a subject -
     * not merely something that made it through the socket.
     */
    public static function test_the_captured_message_is_a_complete_multipart_email()
    {
        $address = 'catcher_shape_' . uniqid() . '@example.com';
        $before = static::__captured_files();

        $row = static::__deliver($address);
        $captured = static::__captured_message_for((int) $row->id, $before);

        static::__assert_not_null($captured, 'the message was captured');

        static::__assert_contains('Subject: RSpade mail test', $captured, 'the subject travelled');
        static::__assert_contains('To: ' . $address, $captured, 'and the To header');
        static::__assert_contains('Content-Type: multipart/alternative', $captured, 'both readings were offered');
        static::__assert_contains('Content-Type: text/plain', $captured, 'a text part is present');
        static::__assert_contains('Content-Type: text/html', $captured, 'and an html part');
        static::__assert_contains('Message-ID:', $captured, 'the Message-ID the builder minted rode along');
    }
}
