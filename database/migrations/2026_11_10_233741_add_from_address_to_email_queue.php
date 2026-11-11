<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A queued email may name its own From (Rsx_Email_Abstract::from()).
     *
     * Both columns are frozen at enqueue, as reply_to / reply_to_name are, so the drain
     * sends what the row says: a sender setting the application changes after a message
     * was queued does not rewrite that message. NULL from_address means the configured
     * sender (mail.from.address); NULL from_name with an address set means the configured
     * display name (mail.from.name, else rsx.name).
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _email_queue
                ADD COLUMN from_address VARCHAR(255) NULL DEFAULT NULL AFTER category_id,
                ADD COLUMN from_name VARCHAR(255) NULL DEFAULT NULL AFTER from_address
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
