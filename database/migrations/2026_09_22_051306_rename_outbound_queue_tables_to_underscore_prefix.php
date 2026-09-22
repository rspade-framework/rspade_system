<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the five outbound-queue tables to the system-table underscore prefix.
 *
 * email_queue, email_recipients, email_attachments, sms_queue and sms_recipients are
 * the storage behind Rsx_Mail and Rsx_Sms: the framework creates the rows, the drain
 * claims and updates them, the retention sweep deletes them. No application code
 * writes them, and the one place an application reads them - the system email admin
 * screens - goes through Email_Queue_Model / Email_Recipient_Model like any other
 * model, so the table name is not part of anything an application states.
 *
 * That makes them system tables by the same test as _file_storage, _file_attachments
 * and _sessions: framework-managed, framework-written, an implementation detail behind
 * a facade. The underscore prefix says so, exempts them from the ORM requirement the
 * quality checker places on application tables, and takes them out of the framework's
 * contract on application-owned tables (Schema_Contract), which exists for tables an
 * application may alter.
 *
 * MySQL carries indexes and foreign keys across a RENAME, so the FK from
 * _email_attachments to _email_queue and to _file_storage, and the site foreign keys
 * on the two queues, survive as they are.
 *
 * See: php artisan rsx:man database_schema_architecture
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("RENAME TABLE email_queue TO _email_queue");
        DB::statement("RENAME TABLE email_recipients TO _email_recipients");
        DB::statement("RENAME TABLE email_attachments TO _email_attachments");
        DB::statement("RENAME TABLE sms_queue TO _sms_queue");
        DB::statement("RENAME TABLE sms_recipients TO _sms_recipients");
    }
};
