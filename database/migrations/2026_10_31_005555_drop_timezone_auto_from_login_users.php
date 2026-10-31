<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IMPORTANT: Use raw MySQL queries for clarity and auditability
     *
     * @return void
     */
    public function up()
    {
        // login_users.timezone NULL now MEANS automatic (the display zone follows each
        // browser's own, through the rsx_tz cookie), so the separate flag goes. An account
        // that was automatic keeps no zone of its own - whatever zone the flag let a browser
        // write there is discarded - and an empty string is no zone either.
        DB::statement("
            UPDATE login_users
            SET timezone = NULL
            WHERE timezone_auto = 1 OR timezone = ''
        ");

        DB::statement("
            ALTER TABLE login_users
            DROP COLUMN timezone_auto
        ");
    }
};
