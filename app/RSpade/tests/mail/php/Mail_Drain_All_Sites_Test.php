<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Email_Recipient_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;
use App\RSpade\Tests\Mail\Php\Mail_Transport_Stub;

/**
 * Mail_Drain_All_Sites_Test - the outbound mail queue is drained for EVERY site.
 *
 * The queue is one table for the whole install and the drain is its only consumer, so a
 * worker's declared site must not narrow what it sees. Each test queues a message under a
 * SECOND site, then runs the drain from a process declaring site 1 - the shape of a worker
 * in any application whose CLI context names a tenant - and asserts the other tenant's row
 * was handled all the same, with its own site_id intact.
 *
 * Runs in the default per-test transaction; the second site is created inside it.
 */
class Mail_Drain_All_Sites_Test extends Rsx_Test_Abstract
{
    private const WORKER_SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::WORKER_SITE_ID);
    }

    private static function __second_site_id(): int
    {
        $site = new Site_Model();
        $site->slug = 'mail-drain-' . uniqid();
        $site->name = 'Mail Drain Second Site';
        $site->save();

        return (int) $site->id;
    }

    /**
     * Queue one fixture message as $site_id and hand back its row.
     */
    private static function __queue_as_site(int $site_id, string $note): Email_Queue_Model
    {
        static::__acting_as_site($site_id);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            return (new Mail_Notification_Fixture_Email($note))
                ->to('all_sites_' . uniqid() . '@example.com')
                ->send();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
            static::__acting_as_site(self::WORKER_SITE_ID);
        }
    }

    /**
     * The row as stored, read past the site scope (it belongs to another tenant).
     */
    private static function __stored(int $id): Email_Queue_Model
    {
        return Email_Queue_Model::without_site_scope(fn () => Email_Queue_Model::find($id));
    }

    public static function test_the_drain_sends_another_sites_message()
    {
        $other_site_id = static::__second_site_id();
        $row = static::__queue_as_site($other_site_id, 'Other site probe');

        static::__assert_equals($other_site_id, (int) $row->site_id, 'the row was queued under the second site');

        $stub = new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT);
        Rsx_Mail_Transport::$override_for_tests = $stub;

        try {
            $counts = Task::internal('Mail_Queue_Service', 'send_pending_queue');
        } finally {
            Rsx_Mail_Transport::$override_for_tests = null;
        }

        static::__assert_true(
            in_array('Other site probe', $stub->sent_subjects, true),
            'a worker declaring site 1 handed the second site\'s message to the transport'
        );
        static::__assert_greater_than(0, $counts['sent'], 'and counted it as sent');

        $stored = static::__stored((int) $row->id);
        static::__assert_equals(Email_Queue_Model::STATUS_SENT, (int) $stored->status_id, 'the row is SENT');
        static::__assert_equals($other_site_id, (int) $stored->site_id, 'and still belongs to its own site');

        $recipient = Email_Recipient_Model::without_site_scope(
            fn () => Email_Recipient_Model::where('site_id', $other_site_id)
                ->where('email', strtolower($stored->to_address))
                ->first()
        );
        static::__assert_not_null($recipient, 'the delivery is counted on a recipient row of the message\'s own site');
        static::__assert_equals(1, (int) $recipient->total_sent, 'exactly once');
    }

    public static function test_the_stranded_row_reclaim_reaches_another_site()
    {
        $other_site_id = static::__second_site_id();
        $row = static::__queue_as_site($other_site_id, 'Stranded probe');

        Email_Queue_Model::without_site_scope(function () use ($row) {
            Email_Queue_Model::where('id', $row->id)->update(['status_id' => Email_Queue_Model::STATUS_SENDING]);
        });

        $stub = new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT);
        Rsx_Mail_Transport::$override_for_tests = $stub;

        try {
            $counts = Task::internal('Mail_Queue_Service', 'send_pending_queue');
        } finally {
            Rsx_Mail_Transport::$override_for_tests = null;
        }

        static::__assert_greater_than(0, $counts['reclaimed'], 'the other site\'s stranded row was reclaimed');
        static::__assert_equals(
            Email_Queue_Model::STATUS_SENT,
            (int) static::__stored((int) $row->id)->status_id,
            'and sent on the same pass'
        );
    }

    public static function test_retention_prunes_another_sites_rows()
    {
        $other_site_id = static::__second_site_id();
        $row = static::__queue_as_site($other_site_id, 'Retention probe');

        Email_Queue_Model::without_site_scope(function () use ($row) {
            Email_Queue_Model::where('id', $row->id)->update([
                'status_id' => Email_Queue_Model::STATUS_SENT,
                'created_at' => now()->subDays(90),
            ]);
        });

        // The cleanup also prunes the development catcher's Maildir; point it at nothing so
        // this test never touches captured mail.
        $previous_maildir = config('rsx.mail.catcher_maildir');
        config(['rsx.mail.catcher_maildir' => Rsx_Project_Paths::tmp_path('no_such_catcher_' . uniqid())]);

        try {
            Task::internal('Mail_Queue_Service', 'cleanup', ['days' => 30]);
        } finally {
            config(['rsx.mail.catcher_maildir' => $previous_maildir]);
        }

        static::__assert_null(
            Email_Queue_Model::without_site_scope(fn () => Email_Queue_Model::find($row->id)),
            'a 90-day-old row of another site is gone after the retention pass'
        );
    }
}
