<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One session row per BROWSER, and the user sessions signed in on it.
 *
 * _sessions becomes the browser: its cookie and CSRF tokens (rotated IN PLACE at every
 * sign-in and sign-out), its session values, its type, its last use and two POINTERS - the
 * staff user session and the portal user session currently signed in on it (null = signed
 * out / guest). It absorbs _session_devices: the row IS the browser, so there is nothing
 * left for a device record to tie together.
 *
 * _user_sessions is "where you're signed in": one row per browser x realm x account x site
 * x impersonator. Its identity is immutable and its tuple_hash is unique, so the same
 * account signing in again on the same browser and site REUSES its row (sign_in_count
 * counts the times it became signed in); a different account, site or impersonator is a
 * different row. The sign-in limits are measured on it (signed_in_at, last_page_view_at)
 * and a sign-out stamps it (signed_out_at, sign_out_reason_id) instead of deleting
 * anything.
 *
 *   realm_id            1 staff, 2 portal, 3 api (Session::REALM_*)
 *   tuple_hash          sha256 of realm|session|login_user|site|portal_user|portal_site|
 *                       impersonator_login_user|impersonator_user (null = empty string)
 *   first_signed_in_at  when this tuple first became signed in
 *   signed_in_at        the current sign-in - the max_length_minutes clock, carried across
 *                       a site switch and into a staff impersonation
 *   started_at          when this row last became the CURRENT one (a sign-in, a site switch,
 *                       an impersonation's start)
 *   sign_out_reason_id  Session::SIGN_OUT_*
 *
 * The data carried over: every CLI, API and Playwright row and every inactive or superseded
 * row is deleted (their values, flash alerts and links cascade); of each device's rows only
 * the newest survives as that browser's row; each surviving row's staff and portal identity
 * becomes a signed-in user session it points at (a staff impersonation also gets the
 * impersonator's own sign-in, suspended behind it). Two-factor browser trust moves from the
 * device to the surviving browser row; trust whose browser did not survive is dropped. The
 * audit sessions' session_row_id / device_id correlation numbers become session_id (the
 * browser) and user_session_id.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _user_sessions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                session_id BIGINT NOT NULL,
                realm_id BIGINT NOT NULL,
                tuple_hash CHAR(64) NOT NULL,
                login_user_id BIGINT NULL,
                user_id BIGINT NULL,
                site_id BIGINT NULL,
                portal_user_id BIGINT NULL,
                portal_site_id BIGINT NULL,
                impersonator_login_user_id BIGINT NULL,
                impersonator_user_id BIGINT NULL,
                first_signed_in_at TIMESTAMP(3) NOT NULL,
                signed_in_at TIMESTAMP(3) NOT NULL,
                started_at TIMESTAMP(3) NOT NULL,
                last_page_view_at TIMESTAMP(3) NULL,
                last_active_at TIMESTAMP(3) NOT NULL,
                signed_out_at TIMESTAMP(3) NULL,
                sign_out_reason_id BIGINT NULL,
                sign_in_count BIGINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
                UNIQUE KEY uq_user_sessions_tuple (tuple_hash),
                KEY idx_user_sessions_session (session_id),
                KEY idx_user_sessions_login_user_recent (login_user_id, last_active_at),
                KEY idx_user_sessions_user_recent (user_id, last_active_at),
                KEY idx_user_sessions_portal_user_recent (portal_user_id, last_active_at),
                KEY idx_user_sessions_impersonator (impersonator_login_user_id),
                CONSTRAINT user_sessions_session_fk FOREIGN KEY (session_id) REFERENCES _sessions (id) ON DELETE CASCADE,
                CONSTRAINT user_sessions_portal_user_fk FOREIGN KEY (portal_user_id) REFERENCES portal_users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            ALTER TABLE _sessions
                ADD COLUMN api_key_id BIGINT NULL AFTER type_id,
                ADD COLUMN staff_user_session_id BIGINT NULL AFTER api_key_id,
                ADD COLUMN portal_user_session_id BIGINT NULL AFTER staff_user_session_id
        ");

        // -----------------------------------------------------------------
        // The rows that do not survive
        // -----------------------------------------------------------------

        // Machine rows (Playwright 2, API 3, CLI 4) and every row that is no longer a live
        // browser credential. Values, flash alerts and session links cascade.
        DB::statement("DELETE FROM _sessions WHERE type_id <> 1 OR active = 0 OR superseded_by_id IS NOT NULL");

        // One row per browser: of the rows sharing a device, the most recently used.
        DB::statement("
            DELETE older FROM _sessions older
            JOIN _sessions newer
                ON newer.device_id = older.device_id
                AND newer.id <> older.id
                AND (newer.last_active > older.last_active
                     OR (newer.last_active = older.last_active AND newer.id > older.id))
        ");

        // -----------------------------------------------------------------
        // User sessions from the identities the surviving rows carry
        // -----------------------------------------------------------------

        // A staff impersonation rode the impersonator's own sign-in: that sign-in becomes a
        // user session of its own, suspended behind the impersonation.
        DB::statement("
            INSERT INTO _user_sessions
                (session_id, realm_id, tuple_hash, login_user_id, user_id, site_id,
                 first_signed_in_at, signed_in_at, started_at, last_page_view_at, last_active_at, sign_in_count)
            SELECT
                s.id,
                1,
                SHA2(CONCAT_WS('|', 1, s.id, s.impersonator_login_user_id, IFNULL(s.site_id, 0), '', '', '', ''), 256),
                s.impersonator_login_user_id,
                (SELECT u.id FROM users u
                    WHERE u.login_user_id = s.impersonator_login_user_id AND u.site_id = IFNULL(s.site_id, 0) AND u.deleted_at IS NULL
                    LIMIT 1),
                IFNULL(s.site_id, 0),
                COALESCE(s.login_at, s.created_at, s.last_active),
                COALESCE(s.login_at, s.created_at, s.last_active),
                COALESCE(s.login_at, s.created_at, s.last_active),
                COALESCE(s.last_page_view_at, s.last_active),
                s.last_active,
                1
            FROM _sessions s
            WHERE s.login_user_id IS NOT NULL AND s.impersonator_login_user_id IS NOT NULL
        ");

        DB::statement("
            INSERT INTO _user_sessions
                (session_id, realm_id, tuple_hash, login_user_id, user_id, site_id, impersonator_login_user_id,
                 first_signed_in_at, signed_in_at, started_at, last_page_view_at, last_active_at, sign_in_count)
            SELECT
                s.id,
                1,
                SHA2(CONCAT_WS('|', 1, s.id, s.login_user_id, IFNULL(s.site_id, 0), '', '', IFNULL(s.impersonator_login_user_id, ''), ''), 256),
                s.login_user_id,
                (SELECT u.id FROM users u
                    WHERE u.login_user_id = s.login_user_id AND u.site_id = IFNULL(s.site_id, 0) AND u.deleted_at IS NULL
                    LIMIT 1),
                IFNULL(s.site_id, 0),
                s.impersonator_login_user_id,
                COALESCE(s.login_at, s.created_at, s.last_active),
                COALESCE(s.login_at, s.created_at, s.last_active),
                CASE WHEN s.impersonator_login_user_id IS NOT NULL
                     THEN COALESCE(s.impersonation_started_at, s.login_at, s.created_at, s.last_active)
                     ELSE COALESCE(s.login_at, s.created_at, s.last_active) END,
                COALESCE(s.last_page_view_at, s.last_active),
                s.last_active,
                1
            FROM _sessions s
            WHERE s.login_user_id IS NOT NULL
        ");

        DB::statement("
            INSERT INTO _user_sessions
                (session_id, realm_id, tuple_hash, portal_user_id, portal_site_id, impersonator_user_id,
                 first_signed_in_at, signed_in_at, started_at, last_page_view_at, last_active_at, sign_in_count)
            SELECT
                s.id,
                2,
                SHA2(CONCAT_WS('|', 2, s.id, '', '', s.portal_user_id, IFNULL(s.portal_site_id, ''), '', IFNULL(s.impersonator_user_id, '')), 256),
                s.portal_user_id,
                s.portal_site_id,
                s.impersonator_user_id,
                COALESCE(s.portal_login_at, s.created_at, s.last_active),
                COALESCE(s.portal_login_at, s.created_at, s.last_active),
                CASE WHEN s.impersonator_user_id IS NOT NULL
                     THEN COALESCE(s.impersonation_started_at, s.portal_login_at, s.created_at, s.last_active)
                     ELSE COALESCE(s.portal_login_at, s.created_at, s.last_active) END,
                COALESCE(s.portal_last_page_view_at, s.last_active),
                s.last_active,
                1
            FROM _sessions s
            WHERE s.portal_user_id IS NOT NULL
        ");

        // The browser points at what is CURRENT on it: the impersonation when there is one.
        DB::statement("
            UPDATE _sessions s
            JOIN _user_sessions u
                ON u.session_id = s.id
                AND u.realm_id = 1
                AND u.login_user_id = s.login_user_id
                AND u.impersonator_login_user_id <=> s.impersonator_login_user_id
            SET s.staff_user_session_id = u.id
        ");

        DB::statement("
            UPDATE _sessions s
            JOIN _user_sessions u ON u.session_id = s.id AND u.realm_id = 2
            SET s.portal_user_session_id = u.id
        ");

        // -----------------------------------------------------------------
        // Two-factor browser trust: from the device to the browser row
        // -----------------------------------------------------------------

        DB::statement("ALTER TABLE _two_factor_trusted_devices ADD COLUMN session_id BIGINT NULL AFTER device_id");

        DB::statement("
            UPDATE _two_factor_trusted_devices t
            JOIN _sessions s ON s.device_id = t.device_id
            SET t.session_id = s.id
        ");

        DB::statement("DELETE FROM _two_factor_trusted_devices WHERE session_id IS NULL");

        DB::statement("ALTER TABLE _two_factor_trusted_devices DROP FOREIGN KEY fk_two_factor_trusted_device");

        DB::statement("
            ALTER TABLE _two_factor_trusted_devices
                DROP INDEX uniq_two_factor_trusted_device,
                DROP INDEX fk_two_factor_trusted_device,
                DROP COLUMN device_id,
                ADD UNIQUE KEY uniq_two_factor_trusted_session (identity_type, identity_id, session_id),
                ADD KEY idx_two_factor_trusted_session (session_id),
                ADD CONSTRAINT fk_two_factor_trusted_session FOREIGN KEY (session_id) REFERENCES _sessions (id) ON DELETE CASCADE
        ");

        // -----------------------------------------------------------------
        // Audit sessions: session_row_id / device_id -> session_id / user_session_id
        // -----------------------------------------------------------------

        DB::statement("
            ALTER TABLE _audit_sessions
                ADD COLUMN session_id BIGINT NULL AFTER ip_address_id,
                ADD COLUMN user_session_id BIGINT NULL AFTER session_id
        ");

        DB::statement("
            UPDATE _audit_sessions a
            JOIN _sessions s ON s.device_id = a.device_id
            SET a.session_id = s.id
            WHERE a.device_id IS NOT NULL
        ");

        DB::statement("
            UPDATE _audit_sessions a
            JOIN _sessions s ON s.id = a.session_row_id
            SET a.session_id = s.id
            WHERE a.session_id IS NULL
        ");

        DB::statement("
            UPDATE _audit_sessions a
            JOIN _user_sessions u
                ON u.session_id = a.session_id
                AND u.realm_id = 1
                AND u.login_user_id = a.login_user_id
                AND u.site_id = IFNULL(a.site_id, 0)
                AND u.impersonator_login_user_id <=> a.impersonator_login_user_id
            SET a.user_session_id = u.id
            WHERE a.login_user_id IS NOT NULL
        ");

        DB::statement("
            UPDATE _audit_sessions a
            JOIN _user_sessions u
                ON u.session_id = a.session_id
                AND u.realm_id = 2
                AND u.portal_user_id = a.portal_user_id
                AND u.impersonator_user_id <=> a.impersonator_user_id
            SET a.user_session_id = u.id
            WHERE a.user_session_id IS NULL AND a.portal_user_id IS NOT NULL
        ");

        DB::statement("
            ALTER TABLE _audit_sessions
                DROP INDEX idx_audit_sessions_session_row,
                DROP INDEX idx_audit_sessions_device,
                DROP COLUMN session_row_id,
                DROP COLUMN device_id,
                ADD KEY idx_audit_sessions_session (session_id),
                ADD KEY idx_audit_sessions_user_session (user_session_id)
        ");

        // -----------------------------------------------------------------
        // _sessions loses what moved to _user_sessions, and its device
        // -----------------------------------------------------------------

        DB::statement("
            ALTER TABLE _sessions
                DROP FOREIGN KEY sessions_device_fk,
                DROP FOREIGN KEY sessions_superseded_by_fk,
                DROP FOREIGN KEY sessions_portal_user_fk
        ");

        DB::statement("
            ALTER TABLE _sessions
                DROP INDEX idx_sessions_user_active_recent,
                DROP INDEX idx_sessions_portal_user_recent,
                DROP INDEX idx_sessions_device,
                DROP INDEX sessions_superseded_by_fk,
                DROP COLUMN site_id,
                DROP COLUMN device_id,
                DROP COLUMN superseded_by_id,
                DROP COLUMN login_user_id,
                DROP COLUMN login_at,
                DROP COLUMN last_page_view_at,
                DROP COLUMN portal_user_id,
                DROP COLUMN portal_site_id,
                DROP COLUMN portal_login_at,
                DROP COLUMN portal_last_page_view_at,
                DROP COLUMN impersonator_login_user_id,
                DROP COLUMN impersonator_user_id,
                DROP COLUMN impersonation_started_at,
                ADD UNIQUE KEY uq_sessions_api_key (api_key_id),
                ADD KEY idx_sessions_staff_user_session (staff_user_session_id),
                ADD KEY idx_sessions_portal_user_session (portal_user_session_id),
                ADD CONSTRAINT sessions_api_key_fk FOREIGN KEY (api_key_id) REFERENCES _api_keys (id) ON DELETE CASCADE,
                ADD CONSTRAINT sessions_staff_user_session_fk FOREIGN KEY (staff_user_session_id) REFERENCES _user_sessions (id) ON DELETE SET NULL,
                ADD CONSTRAINT sessions_portal_user_session_fk FOREIGN KEY (portal_user_session_id) REFERENCES _user_sessions (id) ON DELETE SET NULL
        ");

        DB::statement("DROP TABLE _session_devices");
    }
};
