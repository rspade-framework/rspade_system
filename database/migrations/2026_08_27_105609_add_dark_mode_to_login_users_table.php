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
     * primary key. All integers are signed; TINYINT(1) is reserved for booleans.
     * Migrations must be self-contained.
     *
     * The per-identity theme preference, beside the other one (timezone). It lives on
     * login_users rather than users because it describes the PERSON, not their
     * membership of a site: the same human on three sites wants one answer, and a
     * per-site column would give them three that silently disagree.
     *
     * An enum column, so BIGINT rather than VARCHAR/ENUM - see Login_User_Model::$enums
     * for the constants. Defaults to AUTO (2): a user who has never expressed a
     * preference follows their operating system, which is the modern expectation and
     * the least surprising thing to do to somebody who never asked for either theme.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE login_users
            ADD COLUMN dark_mode BIGINT NOT NULL DEFAULT 2
            AFTER timezone_auto
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
