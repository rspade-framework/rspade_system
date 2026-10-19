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
     * _two_factor_trusted_devices: a browser on which an identity passed a second factor,
     * recorded on the application's request (Rsx_Two_Factor::trust_this_device() /
     * Rsx_Portal_Two_Factor::trust_this_device()) and honoured until trusted_until.
     * identity_type is the identity model's type ref (Login_User_Model for staff,
     * Portal_User_Model for the portal), which keeps the two realms apart in one table.
     * earned_by is the kind of answer that passed (issued_code, totp, passkey,
     * recovery_code). One row per identity per browser: trusting again extends it.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE _two_factor_trusted_devices (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                identity_type BIGINT NOT NULL,
                identity_id BIGINT NOT NULL,
                device_id BIGINT NOT NULL,
                earned_by VARCHAR(20) NOT NULL,
                trusted_until TIMESTAMP(3) NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uniq_two_factor_trusted_device (identity_type, identity_id, device_id),
                KEY idx_two_factor_trusted_until (trusted_until),
                CONSTRAINT fk_two_factor_trusted_device FOREIGN KEY (device_id) REFERENCES _session_devices (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
