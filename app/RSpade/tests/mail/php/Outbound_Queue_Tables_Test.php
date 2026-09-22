<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Email_Attachment_Model;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Email_Recipient_Model;
use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Models\Sms_Recipient_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;

/**
 * Outbound_Queue_Tables_Test - the five outbound-queue tables are SYSTEM tables.
 *
 * The mail and SMS queues are framework storage: the framework writes every row, the
 * drain claims and updates them, the retention sweep deletes them, and an application
 * reaches them only through the models. They carry the `_` prefix that says so, and
 * this class pins both halves of that - the prefixed name is what exists in the
 * database, and the bare name does not, so nothing can be reading a table the framework
 * stopped writing.
 *
 * The cascade is pinned with it, because the prefix arrived by a RENAME and a rename
 * that dropped the foreign key would leave an attachment row pointing at a message that
 * is gone - bytes pinned against disposal forever, by a row nothing can reach.
 */
class Outbound_Queue_Tables_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * The five tables, model-declared name first so the model and the schema are
     * checked against each other rather than against a literal written twice.
     */
    private const MODELS = [
        Email_Queue_Model::class,
        Email_Recipient_Model::class,
        Email_Attachment_Model::class,
        Sms_Queue_Model::class,
        Sms_Recipient_Model::class,
    ];

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __table_exists(string $table): bool
    {
        $rows = DB::select(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return count($rows) === 1;
    }

    // =========================================================================
    // THE NAMES
    // =========================================================================

    public static function test_every_queue_model_declares_a_system_table()
    {
        foreach (self::MODELS as $model_class) {
            $table = (new $model_class())->getTable();

            static::__assert_true(
                str_starts_with($table, '_'),
                $model_class . ' declares a system table (' . $table . ')'
            );
        }
    }

    public static function test_the_prefixed_tables_exist()
    {
        foreach (self::MODELS as $model_class) {
            $table = (new $model_class())->getTable();

            static::__assert_true(
                static::__table_exists($table),
                $table . ' exists in this database'
            );
        }
    }

    public static function test_the_bare_tables_are_gone()
    {
        foreach (self::MODELS as $model_class) {
            $table = (new $model_class())->getTable();
            $bare = ltrim($table, '_');

            static::__assert_false(
                static::__table_exists($bare),
                $bare . ' no longer exists - nothing may still be reading it'
            );
        }
    }

    // =========================================================================
    // THE CASCADE
    // =========================================================================

    /**
     * Deleting a queued message takes its attachment rows with it. The retention sweep
     * deletes messages and relies on this; an attachment that outlived its message would
     * pin its blob against File_Disposal_Service for good.
     */
    public static function test_deleting_a_queued_message_cascades_to_its_attachments()
    {
        static::__acting_as_site(self::SITE_ID);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            $record = (new Mail_Notification_Fixture_Email())
                ->to('cascade_' . uniqid() . '@example.com')
                ->attach_bytes("id,name\n1,Ada\n", 'people.csv', 'text/csv')
                ->send();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }

        $queue_id = (int) $record->id;
        $attachment = Email_Attachment_Model::where('email_queue_id', $queue_id)->first();

        static::__assert_not_null($attachment, 'the message was queued with an attachment row');

        $attachment_id = (int) $attachment->id;

        Email_Queue_Model::find($queue_id)->delete();

        static::__assert_null(
            Email_Queue_Model::find($queue_id),
            'the message is gone'
        );
        static::__assert_null(
            Email_Attachment_Model::find($attachment_id),
            'and the foreign key took its attachment row with it'
        );
    }
}
