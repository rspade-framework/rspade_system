<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every recorded client IP address becomes a reference into _ip_addresses.
 *
 * _ip_addresses holds one row per address: the address itself plus geocode fields (city,
 * state, country, latitude, longitude), all nullable and filled by a geocoder the
 * framework does not ship yet. Every system table that recorded an address now holds the
 * id of that row instead of a copy of the string:
 *
 *     _sessions.ip_address                    -> ip_address_id
 *     _login_history.ip_address               -> ip_address_id
 *     _audit_sessions.ip_address              -> ip_address_id
 *     _file_attachments.created_by_ip_address -> created_by_ip_address_id
 *     _api_request_log.ip                     -> ip_address_id
 *     _transactions.ip                        -> ip_address_id
 *
 * The addresses already recorded are moved across as they were stored. 'CLI', which a
 * command's session row and some login-history rows carried in place of an address,
 * becomes no address at all, as does an empty string.
 *
 * Readers get the address back through the framework (App\RSpade\Core\Session\Ip_Address,
 * the session listings, Login_History, Audit_Session::join()).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _ip_addresses (
                id BIGINT NOT NULL AUTO_INCREMENT,
                ip_address VARCHAR(45) NOT NULL,
                city VARCHAR(100) NULL,
                state VARCHAR(100) NULL,
                country VARCHAR(100) NULL,
                latitude DOUBLE NULL,
                longitude DOUBLE NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ip_addresses_ip_address (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $columns = [
            '_sessions' => ['ip_address', 'ip_address_id'],
            '_login_history' => ['ip_address', 'ip_address_id'],
            '_audit_sessions' => ['ip_address', 'ip_address_id'],
            '_file_attachments' => ['created_by_ip_address', 'created_by_ip_address_id'],
            '_api_request_log' => ['ip', 'ip_address_id'],
            '_transactions' => ['ip', 'ip_address_id'],
        ];

        foreach ($columns as $table => [$old, $new]) {
            DB::statement("
                INSERT IGNORE INTO _ip_addresses (ip_address)
                SELECT DISTINCT {$old}
                FROM {$table}
                WHERE {$old} IS NOT NULL AND {$old} <> '' AND {$old} <> 'CLI'
            ");

            DB::statement("ALTER TABLE {$table} ADD COLUMN {$new} BIGINT NULL AFTER {$old}");

            DB::statement("
                UPDATE {$table} t
                JOIN _ip_addresses a ON a.ip_address = t.{$old}
                SET t.{$new} = a.id
                WHERE t.{$old} IS NOT NULL AND t.{$old} <> '' AND t.{$old} <> 'CLI'
            ");
        }

        // _audit_sessions is searched by address over time; its index keeps that shape.
        DB::statement('
            ALTER TABLE _audit_sessions
                DROP INDEX idx_audit_sessions_ip,
                DROP COLUMN ip_address,
                ADD INDEX idx_audit_sessions_ip (ip_address_id, first_seen_at),
                ADD CONSTRAINT audit_sessions_ip_address_fk FOREIGN KEY (ip_address_id) REFERENCES _ip_addresses (id) ON DELETE RESTRICT
        ');

        unset($columns['_audit_sessions']);

        foreach ($columns as $table => [$old, $new]) {
            $key = ltrim($table, '_');
            $name = preg_replace('/_id$/', '', $new);

            DB::statement("
                ALTER TABLE {$table}
                    DROP COLUMN {$old},
                    ADD INDEX idx_{$key}_{$name} ({$new}),
                    ADD CONSTRAINT {$key}_{$name}_fk FOREIGN KEY ({$new}) REFERENCES _ip_addresses (id) ON DELETE RESTRICT
            ");
        }
    }
};
