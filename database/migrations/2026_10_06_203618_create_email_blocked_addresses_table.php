<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Every table carries a signed BIGINT id
     * primary key. All integers are signed. Migrations must be self-contained.
     *
     * Creates _email_blocked_addresses: the SITE's list of addresses no email of any
     * category may reach (Rsx_Mail::block_address()). Separate from _email_recipients,
     * which is the RECIPIENT's opt-out: a different author, a different lifetime, and
     * nothing the recipient can reach ever writes here.
     *
     * Adds to _email_queue:
     *   block_cause_id       why a Blocked row was blocked (1 recipient opt-out,
     *                        2 site block list); NULL on every other row
     *   withheld_recipients  the cc/bcc entries removed from the message before it went,
     *                        each with the field it was on and the reason
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE _email_blocked_addresses (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                email VARCHAR(255) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uk_email_blocked_addresses_site_email (site_id, email),
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            ALTER TABLE _email_queue
                ADD COLUMN block_cause_id BIGINT NULL DEFAULT NULL AFTER status_id,
                ADD COLUMN withheld_recipients JSON NULL DEFAULT NULL AFTER bcc
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
