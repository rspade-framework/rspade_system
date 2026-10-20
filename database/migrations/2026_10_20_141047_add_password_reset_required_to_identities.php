<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * password_reset_required on both sign-in identities: login_users (staff) and portal_users
 * (client portal).
 *
 * A state of the identity that refuses a PASSWORD sign-in until the password has been
 * changed. Set by the application (or rsx:users:force_password_reset), cleared by the
 * framework whenever the password actually changes. The credential verifiers
 * (RsxAuth::verify_credentials(), Portal_Auth::verify_credentials()) throw
 * Auth_Password_Reset_Required_Exception for a flagged identity once its password has
 * matched. See rsx:man session.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE login_users
                ADD COLUMN password_reset_required TINYINT(1) NOT NULL DEFAULT 0
        ");

        DB::statement("
            ALTER TABLE portal_users
                ADD COLUMN password_reset_required TINYINT(1) NOT NULL DEFAULT 0
        ");
    }
};
