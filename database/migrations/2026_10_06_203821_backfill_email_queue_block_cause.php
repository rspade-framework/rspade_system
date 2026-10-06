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
     * Every Blocked _email_queue row written before block_cause_id existed was blocked by
     * the recipient's opt-out - the only rule there was - so each is stamped cause 1
     * (BLOCK_CAUSE_OPTED_OUT), and resend keeps treating it as an opt-out.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("UPDATE _email_queue SET block_cause_id = 1 WHERE status_id = 5 AND block_cause_id IS NULL");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
