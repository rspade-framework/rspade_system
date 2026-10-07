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
     * primary key. All integers are signed. Migrations must be self-contained.
     *
     * Rebuilds the task tables around ONE ROW PER RUN.
     *
     * _tasks held one row per one-time run and one TRACKER row per #[Schedule], reused for
     * every run of that schedule, so a schedule had no run history; logs lived in one TEXT
     * column rewritten whole on every line. Its rows are run history and queued work, not
     * application data, and the old shape maps onto the new one only lossily - so the table is
     * dropped and recreated rather than altered column by column. Pending work in it is lost;
     * a framework update runs inside maintenance mode, where no worker is claiming any.
     *
     *   _task_schedules     one row per declared #[Schedule]: its cadence and run statistics
     *   _tasks              one row per run (dispatched, scheduled or inline), with the
     *                       lifecycle, the worker that ran it and the small live reports
     *   _task_reports       the larger reports, one row per (task, kind): state JSON, state
     *                       list, completion summary
     *   _task_output        stdout / stderr / operator lines, one row per line
     *   _task_messages      messages the task emitted for its watchers
     *   _task_attachments   named files a task produced, pinning blobs in _file_storage
     *   _task_kill_requests force stops and force kills, carried out by kill workers
     *
     * Every index serves a named query: the worker claim, the identity lookup behind
     * #[Exclusive] / #[Debounce], the active and history grids, the retention sweeps, and the
     * per-task cursor reads of output and messages.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('DROP TABLE _tasks');

        DB::statement("
            CREATE TABLE _task_schedules (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                class VARCHAR(255) NOT NULL,
                method VARCHAR(255) NOT NULL,
                cron_expression VARCHAR(120) NOT NULL,
                next_run_at TIMESTAMP(3) NOT NULL,
                last_task_id BIGINT NULL DEFAULT NULL,
                last_success_at TIMESTAMP(3) NULL DEFAULT NULL,
                last_error_at TIMESTAMP(3) NULL DEFAULT NULL,
                last_error TEXT NULL,
                consecutive_failures BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uk_task_schedules_identity (class, method),
                INDEX idx_task_schedules_due (next_run_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE _tasks (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                class VARCHAR(255) NOT NULL,
                method VARCHAR(255) NOT NULL,
                params JSON NULL,
                params_hash CHAR(64) NOT NULL,
                origin_id BIGINT NOT NULL,
                schedule_id BIGINT NULL DEFAULT NULL,
                pool_id BIGINT NULL DEFAULT NULL,
                site_id BIGINT NULL DEFAULT NULL,
                dispatched_by_id BIGINT NULL DEFAULT NULL,
                dispatched_by_type BIGINT NULL DEFAULT NULL,
                status_id BIGINT NOT NULL DEFAULT 1,
                status_reason TEXT NULL,
                error TEXT NULL,
                return_code BIGINT NULL DEFAULT NULL,
                scheduled_for TIMESTAMP(3) NULL DEFAULT NULL,
                started_at TIMESTAMP(3) NULL DEFAULT NULL,
                completed_at TIMESTAMP(3) NULL DEFAULT NULL,
                stop_requested_at TIMESTAMP(3) NULL DEFAULT NULL,
                abandon_count BIGINT NOT NULL DEFAULT 0,
                timeout BIGINT NULL DEFAULT NULL,
                status_text VARCHAR(1000) NULL DEFAULT NULL,
                progress_percent DECIMAL(5,2) NULL DEFAULT NULL,
                progress_done BIGINT NULL DEFAULT NULL,
                progress_total BIGINT NULL DEFAULT NULL,
                eta_at TIMESTAMP(3) NULL DEFAULT NULL,
                last_heartbeat_at TIMESTAMP(3) NULL DEFAULT NULL,
                last_report_at TIMESTAMP(3) NULL DEFAULT NULL,
                worker_pid BIGINT NULL DEFAULT NULL,
                worker_id BIGINT NULL DEFAULT NULL,
                worker_generation BIGINT NULL DEFAULT NULL,
                worker_host VARCHAR(255) NULL DEFAULT NULL,
                output_truncated_at TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                INDEX idx_tasks_claim (status_id, scheduled_for, id),
                INDEX idx_tasks_identity (class, method, params_hash, status_id),
                INDEX idx_tasks_active (status_id, started_at),
                INDEX idx_tasks_completed (completed_at),
                INDEX idx_tasks_truncation (output_truncated_at, completed_at),
                INDEX idx_tasks_site (site_id, status_id),
                INDEX idx_tasks_dispatched_by (dispatched_by_type, dispatched_by_id),
                INDEX idx_tasks_schedule (schedule_id, id),
                CONSTRAINT fk_tasks_schedule FOREIGN KEY (schedule_id) REFERENCES _task_schedules(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE _task_reports (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT NOT NULL,
                kind_id BIGINT NOT NULL,
                body MEDIUMTEXT NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uk_task_reports_kind (task_id, kind_id),
                CONSTRAINT fk_task_reports_task FOREIGN KEY (task_id) REFERENCES _tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE _task_output (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT NOT NULL,
                stream_id BIGINT NOT NULL,
                line TEXT NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                INDEX idx_task_output_cursor (task_id, id),
                CONSTRAINT fk_task_output_task FOREIGN KEY (task_id) REFERENCES _tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE _task_messages (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                INDEX idx_task_messages_cursor (task_id, id),
                CONSTRAINT fk_task_messages_task FOREIGN KEY (task_id) REFERENCES _tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE _task_attachments (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT NOT NULL,
                name VARCHAR(255) NOT NULL,
                file_storage_id BIGINT NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(255) NOT NULL,
                size BIGINT NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uk_task_attachments_name (task_id, name),
                INDEX idx_task_attachments_storage (file_storage_id),
                CONSTRAINT fk_task_attachments_task FOREIGN KEY (task_id) REFERENCES _tasks(id) ON DELETE CASCADE,
                CONSTRAINT fk_task_attachments_storage FOREIGN KEY (file_storage_id) REFERENCES _file_storage(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE _task_kill_requests (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT NOT NULL,
                host VARCHAR(255) NOT NULL,
                target_pid BIGINT NOT NULL,
                mode_id BIGINT NOT NULL,
                status_id BIGINT NOT NULL DEFAULT 1,
                kill_after_at TIMESTAMP(3) NOT NULL,
                explanation TEXT NOT NULL,
                outcome VARCHAR(255) NULL DEFAULT NULL,
                requested_by_id BIGINT NULL DEFAULT NULL,
                requested_by_type BIGINT NULL DEFAULT NULL,
                worker_pid BIGINT NULL DEFAULT NULL,
                worker_id BIGINT NULL DEFAULT NULL,
                worker_generation BIGINT NULL DEFAULT NULL,
                completed_at TIMESTAMP(3) NULL DEFAULT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                INDEX idx_task_kill_requests_claim (host, status_id, kill_after_at),
                INDEX idx_task_kill_requests_task (task_id, status_id),
                CONSTRAINT fk_task_kill_requests_task FOREIGN KEY (task_id) REFERENCES _tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
