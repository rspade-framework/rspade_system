<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The durable record of WHO DID SOMETHING, FROM WHERE.
 *
 * A _sessions row is operational state: it is rewritten as the browser signs in, switches
 * site and impersonates, replaced at every sign-in, deleted when a CLI process ends and
 * collected when idle. Nothing that must stay true about a past act can point at one.
 *
 * _audit_sessions holds one row per distinct ACTING CONTEXT - identity, tenant, address,
 * session row and user agent - minted only when something attributes an act to it
 * (Session::get_audit_session_id()). A row is immutable apart from last_seen_at.
 * session_row_id and device_id are correlation numbers with NO foreign key: grouping
 * audit rows by them never needs the session or device to still exist, which is what
 * leaves those tables free to be collected.
 *
 * _user_agents holds each distinct user-agent string once, in full, keyed by its hash.
 *
 * NEITHER TABLE IS EVER GARBAGE-COLLECTED. They are the audit trail's own memory; a
 * retention sweep that treated them like _sessions would erase the attribution of every
 * record that points here. Both grow with distinct contexts that actually logged
 * something, not with sessions or requests.
 *
 * _transactions.audit_session_id attributes every recorded unit of work (rsx:man
 * revisions) to its acting context.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _user_agents (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ua_hash CHAR(64) NOT NULL,
                user_agent TEXT NOT NULL,
                first_seen_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_user_agents_hash (ua_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE _audit_sessions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tuple_hash CHAR(64) NOT NULL,
                site_id BIGINT NULL,
                login_user_id BIGINT NULL,
                portal_user_id BIGINT NULL,
                portal_site_id BIGINT NULL,
                impersonator_login_user_id BIGINT NULL,
                impersonator_user_id BIGINT NULL,
                ip_address VARCHAR(45) NULL,
                session_row_id BIGINT NULL,
                user_agent_id BIGINT NULL,
                device_id BIGINT NULL,
                session_type_id BIGINT NOT NULL,
                first_seen_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                last_seen_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_audit_sessions_tuple (tuple_hash),
                KEY idx_audit_sessions_login_user (login_user_id, first_seen_at),
                KEY idx_audit_sessions_portal_user (portal_user_id, first_seen_at),
                KEY idx_audit_sessions_session_row (session_row_id),
                KEY idx_audit_sessions_device (device_id),
                KEY idx_audit_sessions_ip (ip_address, first_seen_at),
                CONSTRAINT audit_sessions_user_agent_fk FOREIGN KEY (user_agent_id) REFERENCES _user_agents (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            ALTER TABLE _transactions
                ADD COLUMN audit_session_id BIGINT NULL AFTER ip,
                ADD INDEX idx_transactions_audit_session (audit_session_id),
                ADD CONSTRAINT transactions_audit_session_fk FOREIGN KEY (audit_session_id) REFERENCES _audit_sessions (id) ON DELETE RESTRICT
        ");
    }
};
