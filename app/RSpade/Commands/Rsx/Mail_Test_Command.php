<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Mail\Mail_Transport_Unavailable_Exception;
use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Mail\Rsx_Mail_Test_Email;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Task\Task;
use Illuminate\Console\Command;

/**
 * rsx:mail:test - send one real message through the configured transport and report
 * exactly what happened to it.
 *
 * This is the smoke test for the whole chain: enqueue -> claim -> render -> build ->
 * transport, with nothing stubbed and nothing skipped. Every other way of asking "does
 * mail work here" answers a smaller question - the health check proves a port is open,
 * a unit test proves the builder builds - and this one answers the whole question.
 *
 * THE DRAIN RUNS SYNCHRONOUSLY, IN THIS PROCESS. Enqueueing already dispatched a
 * background drain (as every send does; #[Exclusive] coalesces them), but a detached
 * worker's outcome is not this command's to report. Task::internal() runs the drain here
 * and the row is read back AFTER it returns, so the status printed is the status the
 * send actually reached.
 *
 * A transport that cannot be reached is REPORTED, not stack-traced: the drain throws
 * Mail_Transport_Unavailable_Exception, which is not a bug to debug but the answer to
 * the question that was asked - and the message stays PENDING for the next sweep.
 *
 * THE DELIVERY MODE IS THE FIRST THING PRINTED, because it decides what the rest of the
 * report can possibly say. In 'disabled' the drain is not even run: the queue is frozen,
 * the row stays PENDING, and that is the honest answer rather than a failure - exit 0.
 * In 'suppressed' the row ends SUPPRESSED, which is also a success: the message was
 * built and recorded exactly as configured.
 *
 * See: php artisan rsx:man email
 */
class Mail_Test_Command extends Command
{
    protected $signature = 'rsx:mail:test
                            {address : Recipient of the test message}
                            {--email= : An Rsx_Email_Abstract subclass to send its sample() instead of the framework probe}
                            {--json : Machine-readable JSON output}';

    protected $description = 'Send one test email through the configured transport and report what happened to it';

    public function handle(): int
    {
        $address = (string) $this->argument('address');
        $json = (bool) $this->option('json');

        $email = $this->__resolve_email();

        if ($email === null) {
            return 1;
        }

        $mode = Rsx_Mail_Transport::delivery_mode();

        $record = $email->to($address)->send();

        $transport_error = null;

        // Running the drain in 'disabled' would prove nothing: it returns immediately by
        // design. Skipping it keeps the report honest about what this box does with mail.
        if ($mode !== Rsx_Mail_Transport::MODE_DISABLED) {
            try {
                Task::internal('Mail_Queue_Service', 'send_pending_queue');
            } catch (Mail_Transport_Unavailable_Exception $e) {
                // The one expected failure: the mail host did not answer, twice, with a
                // fresh connection in between. The queue is intact and this is the report.
                $transport_error = $e->getMessage();
            }
        }

        $record = Email_Queue_Model::find($record->id);

        $status_id = (int) $record->status_id;
        $succeeded = $status_id === Email_Queue_Model::STATUS_SENT
            || $status_id === Email_Queue_Model::STATUS_SUPPRESSED
            || ($mode === Rsx_Mail_Transport::MODE_DISABLED
                && $status_id === Email_Queue_Model::STATUS_PENDING);

        $payload = [
            'delivery' => $mode,
            'transport' => Rsx_Mail_Transport::describe(),
            'email_class' => $record->email_class,
            'to_address' => $record->to_address,
            'queue_id' => (int) $record->id,
            'status' => $record->status_id__label,
            'message_id_header' => $record->message_id_header,
            'attempt_count' => (int) $record->attempt_count,
            'last_error' => $record->last_error,
            'transport_response' => $this->__first_lines($record->transport_response, 3),
            'transport_unavailable' => $transport_error,
            'captured_file' => $this->__newest_captured_file(),
        ];

        if ($json) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $succeeded ? 0 : 1;
        }

        $this->__render($payload, $succeeded);

        return $succeeded ? 0 : 1;
    }

    /**
     * The email instance to send: the framework's own probe, or the sample() of a class
     * the operator named. Returns null after printing why the named class is unusable.
     */
    private function __resolve_email(): ?Rsx_Email_Abstract
    {
        $class_name = trim((string) $this->option('email'));

        if ($class_name === '') {
            return new Rsx_Mail_Test_Email();
        }

        $manifest = Manifest::get_full_manifest();
        $emails = $manifest['data']['emails'] ?? [];

        if (!isset($emails[$class_name])) {
            $known = array_keys($emails);
            sort($known);

            $this->error("[ERROR] '{$class_name}' is not an email class.");
            $this->line('Known email classes: ' . ($known === [] ? '(none)' : implode(', ', $known)));

            return null;
        }

        if (!Manifest::php_is_subclass_of($class_name, Rsx_Email_Abstract::class)) {
            $this->error("[ERROR] '{$class_name}' does not extend Rsx_Email_Abstract.");

            return null;
        }

        $fqcn = $emails[$class_name]['class'];

        return $fqcn::sample();
    }

    /**
     * Print the report a human reads.
     */
    private function __render(array $payload, bool $succeeded): void
    {
        $this->line('Delivery mode:  ' . $payload['delivery']);
        $this->line('Transport:      ' . $payload['transport']);
        $this->line('Email class:    ' . $payload['email_class']);
        $this->line('Recipient:      ' . $payload['to_address']);
        $this->line('Queue row:      #' . $payload['queue_id']);
        $this->line('Status:         ' . $payload['status']);
        $this->line('Attempts:       ' . $payload['attempt_count']);

        if ($payload['message_id_header']) {
            $this->line('Message-ID:     ' . $payload['message_id_header']);
        }

        if ($payload['last_error']) {
            $this->line('Last error:     ' . $payload['last_error']);
        }

        if ($payload['transport_response']) {
            $this->line('Transport says:');
            foreach (explode("\n", $payload['transport_response']) as $line) {
                $this->line('  ' . $line);
            }
        }

        if ($payload['transport_unavailable']) {
            $this->line('');
            $this->error('[ERROR] ' . $payload['transport_unavailable']);
            $this->line('The message is still PENDING and will be retried by the next sweep.');
        }

        if ($payload['captured_file']) {
            $this->line('');
            $this->line('Captured by the development mail catcher:');
            $this->line('  cat ' . $payload['captured_file']);
        }

        $this->line('');

        if ($payload['delivery'] === Rsx_Mail_Transport::MODE_DISABLED) {
            $this->line('[OK] ' . $payload['status']
                . ' - mail delivery is disabled, so the queue was not drained.');
            $this->line('The message is queued and will send when MAIL_DELIVERY is not "disabled".');
            return;
        }

        if ($succeeded) {
            $this->line('[OK] ' . $payload['status']);
            return;
        }

        $this->error('[ERROR] The message did not send (' . $payload['status'] . ').');
    }

    /**
     * The first $count lines of the SMTP conversation, or null.
     */
    private function __first_lines(?string $text, int $count): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $lines = preg_split('/\R/', trim($text));

        return implode("\n", array_slice($lines, 0, $count));
    }

    /**
     * The newest file the development catcher has written, so a developer can read the
     * message that was just sent. Null on any host that has no catcher.
     */
    private function __newest_captured_file(): ?string
    {
        $maildir = config('rsx.mail.catcher_maildir');

        if (!$maildir || !is_dir($maildir)) {
            return null;
        }

        $newest = null;
        $newest_time = 0;

        foreach (['new', 'cur'] as $subdir) {
            $path = rtrim($maildir, '/') . '/' . $subdir;

            if (!is_dir($path)) {
                continue;
            }

            foreach (scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $file = $path . '/' . $entry;

                if (!is_file($file)) {
                    continue;
                }

                $time = filemtime($file);

                if ($time >= $newest_time) {
                    $newest_time = $time;
                    $newest = $file;
                }
            }
        }

        return $newest;
    }
}
