<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Session lifetimes, sign-in rotation and the device record.
 *
 * _session_devices is the long-lived record of ONE BROWSER that has signed in. A
 * _sessions row is now the browser's credential for one sign-in: every sign-in mints a
 * new row (and token) and the row the browser arrived with is superseded, so the
 * sequence of rows a browser goes through is tied together by device_id.
 *
 * On _sessions:
 *   device_id                 the browser this row belongs to (null until it signs in)
 *   superseded_by_id          the row that replaced this one at a sign-in
 *   login_at                  when the STAFF identity on the row signed in
 *   portal_login_at           when the PORTAL identity on the row signed in
 *   last_page_view_at         the staff identity's last page view (a rendered page or
 *                             an SPA navigation - never an Ajax call)
 *   portal_last_page_view_at  the same, for the portal identity
 *
 * login_at / last_page_view_at are what rsx.sessions.staff.max_length_minutes and
 * max_idle_minutes are measured from (portal_* for rsx.sessions.portal). Sessions that
 * are signed in today are stamped from what is known about them: the row's creation as
 * the sign-in, its last activity as the last page view.
 *
 * last_seen on users, login_users and portal_users is the account's last page view,
 * stamped together with the session's.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _session_devices (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            ALTER TABLE _sessions
                ADD COLUMN device_id BIGINT NULL AFTER type_id,
                ADD COLUMN superseded_by_id BIGINT NULL AFTER device_id,
                ADD COLUMN login_at TIMESTAMP(3) NULL AFTER login_user_id,
                ADD COLUMN last_page_view_at TIMESTAMP(3) NULL AFTER login_at,
                ADD COLUMN portal_login_at TIMESTAMP(3) NULL AFTER portal_site_id,
                ADD COLUMN portal_last_page_view_at TIMESTAMP(3) NULL AFTER portal_login_at,
                ADD INDEX idx_sessions_device (device_id),
                ADD CONSTRAINT sessions_device_fk FOREIGN KEY (device_id) REFERENCES _session_devices (id) ON DELETE SET NULL,
                ADD CONSTRAINT sessions_superseded_by_fk FOREIGN KEY (superseded_by_id) REFERENCES _sessions (id) ON DELETE SET NULL
        ");

        DB::statement("
            UPDATE _sessions
            SET login_at = created_at, last_page_view_at = last_active
            WHERE login_user_id IS NOT NULL
        ");

        DB::statement("
            UPDATE _sessions
            SET portal_login_at = created_at, portal_last_page_view_at = last_active
            WHERE portal_user_id IS NOT NULL
        ");

        DB::statement("ALTER TABLE users ADD COLUMN last_seen TIMESTAMP(3) NULL");
        DB::statement("ALTER TABLE login_users ADD COLUMN last_seen TIMESTAMP(3) NULL AFTER last_login");
        DB::statement("ALTER TABLE portal_users ADD COLUMN last_seen TIMESTAMP(3) NULL AFTER last_login");
    }
};
