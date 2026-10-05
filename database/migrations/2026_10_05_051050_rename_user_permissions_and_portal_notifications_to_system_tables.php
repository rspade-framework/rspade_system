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
     * A framework table that is reached only through a library interface, never queried
     * by an application as an ORM model, is a SYSTEM table and carries the underscore
     * prefix. Both of these are:
     *   user_permissions      - the ACL GRANT/DENY rows, read and written only through
     *                           User_Permission_Model::grant()/deny()/remove()/for_user()
     *                           and User_Model's permission resolution;
     *   portal_notifications  - written and read only through
     *                           Portal_Notification_Model::emit()/feed()/unread_count()/
     *                           mark_read()/mark_all_read().
     * RENAME TABLE keeps every column, index, foreign key and row.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            RENAME TABLE
                user_permissions TO _user_permissions,
                portal_notifications TO _portal_notifications
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
