<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     * ✅ DB::statement("ALTER TABLE email_queue ADD COLUMN new_field VARCHAR(255)")
     * ❌ Schema::table() with Blueprint
     * 
     * Migrations must be self-contained - no Model/Service references
     *
     * The two indexes serve the history of one address, per tenant, newest first
     * (Rsx_Mail::recent_to_address()). The queue is kept for a long time, and without them a
     * rarely mailed address scans the site's whole queue back through created_at:
     *
     *   WHERE site_id = ? AND dev_original_to = ? ORDER BY created_at DESC, id DESC
     *   WHERE site_id = ? AND to_address = ?      ORDER BY created_at DESC, id DESC
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _email_queue
                ADD INDEX idx_email_queue_recipient_history (site_id, to_address, created_at),
                ADD INDEX idx_email_queue_original_recipient_history (site_id, dev_original_to, created_at)
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
