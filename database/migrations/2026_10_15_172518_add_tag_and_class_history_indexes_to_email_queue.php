<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Migrations must be self-contained.
     *
     * `tag` is the application-defined kind of message a send carried, read once from the
     * email class's tag() hook at enqueue and frozen on the row (NULL when the class
     * declares none). It tells apart the kinds of message one email class sends.
     *
     * The two indexes serve the history of one email, per tenant, newest first:
     *
     *   WHERE site_id = ? AND email_class = ?             ORDER BY created_at DESC, id DESC
     *   WHERE site_id = ? AND email_class = ? AND tag = ? ORDER BY created_at DESC, id DESC
     *
     * The first cannot be served by the second (tag sits between email_class and
     * created_at), so each shape has its own.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _email_queue
                ADD COLUMN tag VARCHAR(64) NULL DEFAULT NULL AFTER email_class,
                ADD INDEX idx_email_queue_class_history (site_id, email_class, created_at),
                ADD INDEX idx_email_queue_class_tag_history (site_id, email_class, tag, created_at)
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
