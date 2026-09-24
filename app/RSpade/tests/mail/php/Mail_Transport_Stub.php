<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A transport that answers however a test needs it to.
 *
 * WHY A STUB IS THE ONLY WAY IN. The drain constructs its own transport - that is the
 * point, nobody hands it one - so a test that needs to watch the loop react to a
 * particular SMTP outcome has no other seam than Rsx_Mail_Transport::$override_for_tests.
 * Every test that sets it clears it in a finally.
 *
 * The three modes are the three outcomes the runner distinguishes, and it MUST
 * distinguish them:
 *
 *   ACCEPT          the message went. Terminal, success.
 *   SERVER_ERROR    the server answered with an error FOR THIS MESSAGE. The connection
 *                   is fine; this message gets its own retry clock and eventually FAILS.
 *   UNREACHABLE     the connection itself failed. Nothing was rejected, so the message
 *                   keeps its retry budget and the whole drain dies loudly instead.
 *
 * UNREACHABLE_ONCE is the fourth, and it exists for the one behaviour that is invisible
 * from the other three: the drain reconnects and retries THE SAME MESSAGE once before
 * giving up, so a mail host that blinked costs one reconnect rather than a queue-wide
 * outage. *
 * THROW is the fifth: every send throws the exception the test handed in. It is how the
 * drain's reading of a NON-SMTP failure is tested - an API transport's HTTP client lets
 * its own exception escape, and the drain has to sort it into one of the two classes.
 */
#[Instantiatable]
class Mail_Transport_Stub implements TransportInterface
{
    /** The transport accepts everything. */
    public const MODE_ACCEPT = 'accept';

    /** Every message is answered with an SMTP error reply. */
    public const MODE_SERVER_ERROR = 'server_error';

    /** The connection always fails. */
    public const MODE_UNREACHABLE = 'unreachable';

    /** The FIRST send fails at the connection level; everything after it is accepted. */
    public const MODE_UNREACHABLE_ONCE = 'unreachable_once';

    /** Every send throws the exception given to the constructor. */
    public const MODE_THROW = 'throw';

    /** The reply this stub gives in MODE_SERVER_ERROR. */
    public const SERVER_REPLY = 'Expected response code "250" but got code "550", '
        . 'with message "550 5.1.1 <nobody@example.com>: Recipient address rejected".';

    /** How many times send() was called, across every mode. */
    public int $send_count = 0;

    /** The subject of every message this stub was asked to send, in order. */
    public array $sent_subjects = [];

    /** Every message this stub ACCEPTED, in order. */
    public array $sent_messages = [];

    public function __construct(private string $mode = self::MODE_ACCEPT, private ?\Throwable $failure = null)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->send_count++;

        if (method_exists($message, 'getSubject')) {
            $this->sent_subjects[] = (string) $message->getSubject();
        }

        if ($this->mode === self::MODE_THROW) {
            throw $this->failure;
        }

        if ($this->mode === self::MODE_SERVER_ERROR) {
            throw new UnexpectedResponseException(self::SERVER_REPLY);
        }

        if ($this->mode === self::MODE_UNREACHABLE
            || ($this->mode === self::MODE_UNREACHABLE_ONCE && $this->send_count === 1)
        ) {
            throw new TransportException('Connection could not be established with host "stub": stubbed outage');
        }

        $this->sent_messages[] = $message;

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'stub://' . $this->mode;
    }
}
