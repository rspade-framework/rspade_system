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
     * The task queue's indexes follow its three real access paths.
     *
     * idx_tasks_claim (status, next_run_at, created_at) serves the worker claim, which is a
     * SELECT ... FOR UPDATE: status='pending' AND next_run_at IS NULL ordered by created_at
     * (tier 1) and status='pending' AND next_run_at <= now (tier 2). Without it the claim
     * walked the whole created_at index and locked rows of every status, completed history
     * included, so concurrent workers and dispatches serialized on those locks.
     *
     * idx_tasks_identity (class, method) serves every lookup of one task identity - the
     * #[Exclusive]/#[Debounce] pending-row check on dispatch, the debounce window, and the
     * scheduler's per-tick tracker lookup.
     *
     * idx_tasks_retention (status, completed_at) serves the retention prune.
     *
     * idx_status and idx_status_queue are prefixes of the claim index (queue is only ever
     * filtered together with class and method); idx_queue, idx_scheduled_for and
     * idx_worker_pid serve no predicate (scheduled_for appears only inside an
     * IS NULL OR <= disjunction). idx_next_run_at stays for the tracker scans.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _tasks
                DROP INDEX idx_status,
                DROP INDEX idx_status_queue,
                DROP INDEX idx_queue,
                DROP INDEX idx_scheduled_for,
                DROP INDEX idx_worker_pid,
                ADD INDEX idx_tasks_claim (status, next_run_at, created_at),
                ADD INDEX idx_tasks_identity (class, method),
                ADD INDEX idx_tasks_retention (status, completed_at)
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
