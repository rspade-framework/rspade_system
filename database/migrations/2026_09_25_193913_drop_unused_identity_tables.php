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
     * Drops three tables no framework or application code reads or writes:
     *
     *   user_verifications - verification codes; email verification, password reset and the
     *                        second factor all live elsewhere (login_users, the reset tokens,
     *                        _two_factor_credentials).
     *   user_invites       - staff invitations; an invitation is users.invite_code.
     *   ip_addresses       - an IP geocoding cache nothing populates or queries.
     *
     * Their models (User_Verification_Model, User_Invite_Model, Ip_Address_Model) are gone
     * with them. user_invites is the only one holding foreign keys, all outbound, so the drop
     * order is free.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            DROP TABLE user_verifications
        ");

        DB::statement("
            DROP TABLE user_invites
        ");

        DB::statement("
            DROP TABLE ip_addresses
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
