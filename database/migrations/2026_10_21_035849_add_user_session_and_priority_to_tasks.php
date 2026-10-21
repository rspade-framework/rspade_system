<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two columns on _tasks for binding a run to the sign-in that started it and for the
 * worker's claim order.
 *
 *   user_session_id  the _user_sessions row a dispatched run was started from (a
 *                    correlation number like audit_session_id: no foreign key, the run row
 *                    is history and must keep it whatever happens to the sign-in)
 *   priority         the claim tier, lower first: 0 = an on-demand #[Run_As_Dispatcher]
 *                    run, 1 = any other on-demand run, 2 = a scheduled run. Stamped by the
 *                    writer at insert; the default 1 is what an insert that states no tier
 *                    gets.
 *
 * idx_tasks_claim_priority serves a claim that orders by tier, then due time, then age.
 * Existing scheduled rows are stamped 2; everything else keeps the default.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE _tasks
                ADD COLUMN user_session_id BIGINT NULL AFTER audit_session_id,
                ADD COLUMN priority BIGINT NOT NULL DEFAULT 1 AFTER status_id,
                ADD KEY idx_tasks_claim_priority (status_id, priority, scheduled_for, id),
                ADD KEY idx_tasks_user_session (user_session_id)
        ");

        DB::statement("UPDATE _tasks SET priority = 2 WHERE origin_id = 2");
    }
};
