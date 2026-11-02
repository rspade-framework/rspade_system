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
     * The record's own updated_at as this revision's write left it. A form that loaded the
     * record at updated_at T was looking at every revision whose value is at or before T and
     * at none after it, which created_at cannot say: a revision's row is stamped a moment
     * after the record's. NULL on revisions recorded before this column.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _revisions
                ADD COLUMN record_updated_at TIMESTAMP(3) NULL DEFAULT NULL AFTER operation_id
        ");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
