<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Cli;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;

/**
 * Mail_Queue_Commands_Cli_Test - rsx:mail:queue, rsx:mail:show, rsx:mail:resend.
 *
 * These three are what an operator has instead of a database client when mail did not
 * arrive, so what is under test is their ANSWERS: the summary that says whether the queue
 * is moving, the detail that says what happened to one message, and the reset that puts a
 * finished message back.
 *
 * RUN IN-PROCESS, VIA Artisan::call(), per tests/CLAUDE.md's preference for the cli kind.
 * A spawned artisan would read the DEVELOPER's database and report on rows this test
 * never wrote.
 *
 * THE BLOCKED CASE IS THE ONE THAT MATTERS MOST. Blocked is a consent record - the
 * recipient asked not to receive this - so a resend that quietly overrode it would make
 * the unsubscribe link a lie. --force must be required, and refusal must be non-zero.
 */
class Mail_Queue_Commands_Cli_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * @return array{0: int, 1: string} exit code and captured output
     */
    private static function __run(string $command, array $arguments = []): array
    {
        static::__acting_as_site(self::SITE_ID);

        $exit_code = Artisan::call($command, $arguments);

        return [$exit_code, Artisan::output()];
    }

    /**
     * Queue one fixture message, past the dev-host gate (not this class's subject).
     */
    private static function __queue(string $note, string $address): Email_Queue_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            return (new Mail_Notification_Fixture_Email($note))->to($address)->send();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }
    }

    // =========================================================================
    // rsx:mail:queue
    // =========================================================================

    public static function test_the_summary_counts_every_status_and_names_the_mode()
    {
        static::__queue('Summary probe', 'summary_' . uniqid() . '@example.com');

        [$exit_code, $output] = static::__run('rsx:mail:queue', ['--json' => true]);

        static::__assert_equals(0, $exit_code, 'a summary is not a failure');

        $payload = json_decode(trim($output), true);

        static::__assert_equals(JSON_ERROR_NONE, json_last_error(), '--json prints parseable JSON: ' . $output);
        static::__assert_array_has_key('delivery', $payload, 'the mode is reported first - it decides what the rest means');
        static::__assert_array_has_key('pending', $payload['counts'], 'every status has a count');
        static::__assert_array_has_key('suppressed', $payload['counts'], 'including the ones that are not failures');
        static::__assert_greater_than(0, $payload['counts']['pending'], 'the message just queued is counted');
        static::__assert_not_null($payload['oldest_pending'], 'and something waiting has an age');
    }

    public static function test_a_recipient_filter_finds_the_message_by_address()
    {
        $address = 'findme_' . uniqid() . '@example.com';
        $row = static::__queue('Findable probe', $address);

        [$exit_code, $output] = static::__run('rsx:mail:queue', [
            '--recipient' => $address,
            '--json' => true,
        ]);

        static::__assert_equals(0, $exit_code, 'the listing ran');

        $payload = json_decode(trim($output), true);

        static::__assert_count(1, $payload, 'exactly the one message matching that address');
        static::__assert_equals((int) $row->id, $payload[0]['id'], 'and it is the row that was queued');
        static::__assert_equals('Pending', $payload[0]['status'], 'reported with its status');
    }

    public static function test_a_status_filter_lists_only_that_status()
    {
        static::__queue('Status probe', 'status_' . uniqid() . '@example.com');

        [, $output] = static::__run('rsx:mail:queue', ['--status' => 'pending', '--json' => true]);

        $payload = json_decode(trim($output), true);

        static::__assert_greater_than(0, count($payload), 'there is at least the row just queued');

        foreach ($payload as $row) {
            static::__assert_equals('Pending', $row['status'], 'and nothing else got in');
        }
    }

    public static function test_an_unknown_status_is_refused_with_the_vocabulary()
    {
        [$exit_code, $output] = static::__run('rsx:mail:queue', ['--status' => 'nearly']);

        static::__assert_equals(1, $exit_code, 'a status that does not exist is an error');
        static::__assert_contains('suppressed', $output, 'and the answer lists what does exist');
    }

    // =========================================================================
    // rsx:mail:show
    // =========================================================================

    public static function test_show_reports_the_whole_row_and_the_body_lengths()
    {
        $address = 'shown_' . uniqid() . '@example.com';
        $row = static::__queue('Shown probe', $address);

        [$exit_code, $output] = static::__run('rsx:mail:show', ['id' => $row->id, '--json' => true]);

        static::__assert_equals(0, $exit_code, 'the row exists, so this succeeds');

        $payload = json_decode(trim($output), true);

        static::__assert_equals((int) $row->id, $payload['id'], 'the row asked for');
        static::__assert_equals($address, $payload['to_address'], 'its recipient');
        static::__assert_equals('Pending', $payload['status'], 'its status');
        static::__assert_array_has_key('template_data', $payload, 'the frozen data the template will render');
        static::__assert_array_has_key('attachments', $payload, 'and its attachments, even when there are none');
        static::__assert_array_has_key(
            'rendered_html_length',
            $payload,
            'the bodies are reported by LENGTH so a terminal report stays readable'
        );
        static::__assert_array_has_key('rendered_html', $payload, 'and --json still carries them in full');
    }

    public static function test_show_refuses_an_id_that_does_not_exist()
    {
        [$exit_code, $output] = static::__run('rsx:mail:show', ['id' => 999999999]);

        static::__assert_equals(1, $exit_code, 'a missing row is an error, not an empty report');
        static::__assert_contains('999999999', $output, 'and the message names what was asked for');
    }

    // =========================================================================
    // rsx:mail:resend
    // =========================================================================

    public static function test_resend_resets_a_failed_row_to_pending()
    {
        $row = static::__queue('Resend probe', 'resend_' . uniqid() . '@example.com');
        $row->mark_failed('something broke');

        static::__assert_equals(Email_Queue_Model::STATUS_FAILED, (int) $row->fresh()->status_id, 'failed first');

        [$exit_code, $output] = static::__run('rsx:mail:resend', ['id' => $row->id]);

        static::__assert_equals(0, $exit_code, 'resending a finished row is the ordinary case');
        static::__assert_contains('Pending', $output, 'and the new state is printed');

        $row = $row->fresh();

        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) $row->status_id, 'it is queued again');
        static::__assert_equals(0, (int) $row->attempt_count, 'with its retry budget restored');
        static::__assert_null($row->last_error, 'and the old failure cleared');
        static::__assert_not_null(
            $row->next_attempt_at,
            'due NOW rather than nulled - a nulled column would age from created_at and re-stale immediately'
        );
    }

    public static function test_resend_does_nothing_to_a_row_the_queue_already_has()
    {
        $row = static::__queue('Pending probe', 'pending_' . uniqid() . '@example.com');

        [$exit_code, $output] = static::__run('rsx:mail:resend', ['id' => $row->id]);

        static::__assert_equals(0, $exit_code, 'nothing to do is not a failure');
        static::__assert_contains('already', $output, 'and it says so');
        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $row->fresh()->status_id,
            'the row was not touched'
        );
    }

    public static function test_resending_a_blocked_row_requires_force()
    {
        $address = 'blocked_' . uniqid() . '@example.com';
        $row = static::__queue('Blocked probe', $address);
        $row->status_id = Email_Queue_Model::STATUS_BLOCKED;
        $row->save();

        [$exit_code, $output] = static::__run('rsx:mail:resend', ['id' => $row->id]);

        static::__assert_equals(1, $exit_code, 'overriding somebody\'s opt-out is refused by default');
        static::__assert_contains('unsubscribed', $output, 'and the refusal says why');
        static::__assert_contains('--force', $output, 'and how to mean it on purpose');
        static::__assert_equals(
            Email_Queue_Model::STATUS_BLOCKED,
            (int) $row->fresh()->status_id,
            'the row is untouched'
        );

        [$forced_exit, ] = static::__run('rsx:mail:resend', ['id' => $row->id, '--force' => true]);

        static::__assert_equals(0, $forced_exit, 'with --force it proceeds');
        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $row->fresh()->status_id,
            'and the message is queued again'
        );
    }

    public static function test_resend_refuses_an_id_that_does_not_exist()
    {
        [$exit_code, $output] = static::__run('rsx:mail:resend', ['id' => 999999999]);

        static::__assert_equals(1, $exit_code, 'a missing row is an error');
        static::__assert_contains('999999999', $output, 'and the message names what was asked for');
    }
}
