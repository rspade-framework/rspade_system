<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index coverage for the session sweeps and lookups, the browser's first sign-in, and
 * realm-tagged session values.
 *
 * _sessions
 *   - (active, last_active): the daily maintenance sweep that makes a browser unused for
 *     rsx.sessions.unused_session_days inactive reads active rows by last_active.
 *   - first_signed_in_at: when ANY account first signed in on this browser, in any realm.
 *     NULL means a guest that never signed in - the only kind of browser the hourly guest
 *     cleanup deletes. Backfilled from the browser's user sessions.
 *   - (first_signed_in_at, last_active): the guest cleanup's predicate,
 *     first_signed_in_at IS NULL AND last_active < cutoff.
 *   - The two pointer indexes widen to (pointer, type_id): the rsx:debug harness finds its
 *     reusable row by joining a user session to the browser whose pointer names it AND whose
 *     type is Playwright, and both halves of that join are then one index probe.
 *
 * _audit_sessions
 *   - (user_session_id, last_seen_at) replaces (user_session_id): the audit sessions of one
 *     user session, most recent first.
 *
 * _session_values
 *   - realm_id: whose a value is - 1 staff, 2 portal (the _user_sessions.realm_id
 *     vocabulary), 4 guest (the browser's own, kept through every sign-out). Signing a realm
 *     out of a browser clears that realm's values and no other's.
 *   - UNIQUE (session_id, realm_id, value_key) replaces (session_id, value_key): one key may
 *     hold a value in each realm, and clearing a realm is a prefix range.
 *   - Existing values are classified by the framework key namespaces that write them:
 *     two_factor.*, sso.*, sys.* and apidocs.* are staff; portal_two_factor.* and
 *     portal_sso.* are portal; anything else is guest.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE _sessions
                ADD COLUMN first_signed_in_at TIMESTAMP(3) NULL AFTER last_active,
                ADD KEY idx_sessions_active_last_active (active, last_active),
                ADD KEY idx_sessions_guest_expiry (first_signed_in_at, last_active),
                ADD KEY idx_sessions_staff_user_session_type (staff_user_session_id, type_id),
                ADD KEY idx_sessions_portal_user_session_type (portal_user_session_id, type_id)
        ");

        DB::statement("
            ALTER TABLE _sessions
                DROP KEY idx_sessions_staff_user_session,
                DROP KEY idx_sessions_portal_user_session
        ");

        DB::statement("
            UPDATE _sessions s
            JOIN (
                SELECT session_id, MIN(first_signed_in_at) AS first_signed_in_at
                FROM _user_sessions
                GROUP BY session_id
            ) u ON u.session_id = s.id
            SET s.first_signed_in_at = u.first_signed_in_at
        ");

        DB::statement("
            ALTER TABLE _audit_sessions
                ADD KEY idx_audit_sessions_user_session_recent (user_session_id, last_seen_at),
                DROP KEY idx_audit_sessions_user_session
        ");

        DB::statement("ALTER TABLE _session_values ADD COLUMN realm_id BIGINT NULL AFTER session_id");

        DB::statement("
            UPDATE _session_values
            SET realm_id = CASE
                WHEN value_key LIKE 'portal\\_two\\_factor.%' OR value_key LIKE 'portal\\_sso.%' THEN 2
                WHEN value_key LIKE 'two\\_factor.%' OR value_key LIKE 'sso.%'
                    OR value_key LIKE 'sys.%' OR value_key LIKE 'apidocs.%' THEN 1
                ELSE 4
            END
        ");

        DB::statement("
            ALTER TABLE _session_values
                MODIFY COLUMN realm_id BIGINT NOT NULL,
                ADD UNIQUE KEY uniq_session_value_realm (session_id, realm_id, value_key),
                DROP KEY uniq_session_value
        ");
    }
};
