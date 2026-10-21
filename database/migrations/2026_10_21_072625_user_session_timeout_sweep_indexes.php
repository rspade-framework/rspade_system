<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index coverage for the hourly sign-in limit sweep (Session_Cleanup_Service::cleanup_sessions,
 * through Session::_end_timed_out_user_sessions()).
 *
 * The sweep signs out the user sessions still signed in past a realm's sign-in limit or idle
 * limit, one clock at a time:
 *
 *   realm_id = ? AND signed_out_at IS NULL AND signed_in_at < cutoff
 *   realm_id = ? AND signed_out_at IS NULL AND last_page_view_at < cutoff
 *
 * Each predicate is a range on the third column of its own index, so the set read is exactly
 * the signed-in user sessions past that clock, however many signed-out rows the table holds.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE _user_sessions
                ADD KEY idx_user_sessions_length_sweep (realm_id, signed_out_at, signed_in_at),
                ADD KEY idx_user_sessions_idle_sweep (realm_id, signed_out_at, last_page_view_at)
        ");
    }
};
