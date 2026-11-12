<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The #[On_Build] trigger's memory: ONE row (id 1) holding the manifest build key the
 * scheduler tick last dispatched the #[On_Build] tasks for, and when it did.
 *
 * The tick (rsx:task:process, Task_On_Build::dispatch_for_build()) compares the running
 * build key with this one; when they differ it dispatches every #[On_Build] task once and
 * writes the new key here. No row = no build was ever dispatched for, so the first tick on a
 * fresh database dispatches. Stored in the database so the fact is shared by every host that
 * shares it and survives restarts (rsx:man tasks, ON BUILD).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _task_on_build (
                id BIGINT NOT NULL PRIMARY KEY,
                build_key VARCHAR(64) NOT NULL,
                dispatched_at TIMESTAMP(3) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
