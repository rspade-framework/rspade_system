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
     * Permission 1 was an installation-wide permission held by every site's founder role.
     * Permissions are per site, and authority over every site is a developer's, so it is
     * retired: no role grants it, and an explicit grant or deny row for it on any user is
     * removed. The id is not reused.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("DELETE FROM _user_permissions WHERE permission_id = 1");
    }
    
    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
