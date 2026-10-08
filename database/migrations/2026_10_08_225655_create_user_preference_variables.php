<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * User preference variables: a key/value store on each of the three user records, for a
 * small per-person fact that is not worth a column - a step skipped, a prompt dismissed,
 * "do not show this again". Read through get_variable() / set_variable() / forget_variable()
 * / has_variable() on User_Model, Login_User_Model and Portal_User_Model.
 *
 * One table per record rather than one polymorphic table: the foreign key is real, so a
 * hard delete of the record reclaims its rows by cascade, and a soft delete (which keeps the
 * row) keeps them. UNIQUE(owner, variable_key) makes a write an upsert. The value is JSON.
 * There is no expiry column: a recorded decision does not expire.
 */
return new class extends Migration
{
    public function up()
    {
        foreach ([
            ['_user_variables', 'user_id', 'users', 'user_variables_user_fk', 'uniq_user_variable'],
            ['_login_user_variables', 'login_user_id', 'login_users', 'login_user_variables_login_user_fk', 'uniq_login_user_variable'],
            ['_portal_user_variables', 'portal_user_id', 'portal_users', 'portal_user_variables_portal_user_fk', 'uniq_portal_user_variable'],
        ] as [$table, $column, $owner_table, $constraint, $unique]) {
            DB::statement("
                CREATE TABLE {$table} (
                    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    {$column} BIGINT NOT NULL,
                    variable_key VARCHAR(191) NOT NULL,
                    value LONGTEXT NULL,
                    created_at TIMESTAMP NULL DEFAULT NULL,
                    updated_at TIMESTAMP NULL DEFAULT NULL,
                    UNIQUE KEY {$unique} ({$column}, variable_key),
                    CONSTRAINT {$constraint}
                        FOREIGN KEY ({$column}) REFERENCES {$owner_table} (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
};
