<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * _api_keys.is_temporary: set at mint by rsx:api:key:temp, reported on api_key.created /
     * api_key.revoked (Api_Key_Event).
     *
     * @return void
     */
    public function up()
    {
        // A short-lived key minted for one run of a script (rsx:api:key:temp) is marked, so the
        // key events can report it without anybody comparing names.
        DB::statement("ALTER TABLE _api_keys ADD COLUMN is_temporary TINYINT(1) NOT NULL DEFAULT 0 AFTER read_only");

        // A key rsx:api:key:temp minted before this column existed is known by the name it gives
        // every key (Api_Key_Temp_Command::KEY_NAME).
        DB::statement("UPDATE _api_keys SET is_temporary = 1 WHERE name = 'Temporary (CLI)'");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
