<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A task's QUEUE REPORT becomes one row per item.
     *
     * The queue was a JSON list in _task_reports (kind 2), rewritten whole on every change -
     * so a task sliding a window of a hundred items forward by one rewrote all hundred, and
     * could not afford to report at the rate the report is useful at. _task_queue holds one
     * row per item, ordered by its auto-increment id: a push is an INSERT at the tail, a pop
     * a DELETE at the head, and nothing else moves.
     *
     * _tasks.has_queue records that a run's queue has held an item, so an emptied queue still
     * reads as a queue that is empty rather than as a report the run never made.
     *
     * THE STORED LISTS ARE DELETED, not converted: they are the remains of finished runs, and
     * a running task republishes its queue through the new calls.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('DELETE FROM _task_reports WHERE kind_id = 2');

        DB::statement("
            CREATE TABLE _task_queue (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT NOT NULL,
                body LONGTEXT NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                INDEX idx_task_queue_order (task_id, id),
                CONSTRAINT fk_task_queue_task FOREIGN KEY (task_id) REFERENCES _tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement('ALTER TABLE _tasks ADD COLUMN has_queue TINYINT(1) NOT NULL DEFAULT 0 AFTER last_report_at');
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
