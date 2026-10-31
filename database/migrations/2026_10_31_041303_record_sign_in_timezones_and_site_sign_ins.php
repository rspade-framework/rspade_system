<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The display zone in effect is recorded beside every sign-in and every acting context, and a
 * staff sign-in ENTERING A SITE gets a history of its own.
 *
 * timezone / timezone_source (on _audit_sessions, _login_history, _portal_login_history and
 * the new table): the IANA zone Rsx_Time resolved for that request, and where it came from -
 * 'explicit' (login_users.timezone), 'browser' (the rsx_tz cookie), 'site' or 'server'. A row
 * written before this migration has neither, and NULL says exactly that: not recorded.
 *
 * _site_sign_ins: one row each time a staff sign-in enters a site - bound to it at sign-in,
 * moved to it from another site, or an impersonation begun on it. _login_history stays the
 * record of the login IDENTITY signing in, which happens before any site is chosen; this is
 * the record a site's own screens read. Pruned on the same retention window as the login
 * history (rsx.sessions.login_history_retention_days). user_session_id is a correlation
 * number, with no foreign key, like _audit_sessions'.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE _audit_sessions
                ADD COLUMN timezone VARCHAR(64) NULL,
                ADD COLUMN timezone_source VARCHAR(16) NULL
        ");

        DB::statement("
            ALTER TABLE _login_history
                ADD COLUMN timezone VARCHAR(64) NULL,
                ADD COLUMN timezone_source VARCHAR(16) NULL
        ");

        DB::statement("
            ALTER TABLE _portal_login_history
                ADD COLUMN timezone VARCHAR(64) NULL,
                ADD COLUMN timezone_source VARCHAR(16) NULL
        ");

        DB::statement("
            CREATE TABLE _site_sign_ins (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                user_id BIGINT NULL,
                login_user_id BIGINT NOT NULL,
                impersonator_login_user_id BIGINT NULL,
                user_session_id BIGINT NOT NULL,
                entry_type VARCHAR(16) NOT NULL,
                ip_address_id BIGINT NULL,
                user_agent_id BIGINT NULL,
                timezone VARCHAR(64) NOT NULL,
                timezone_source VARCHAR(16) NOT NULL,
                created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY idx_site_sign_ins_user (user_id, created_at),
                KEY idx_site_sign_ins_site (site_id, created_at),
                KEY idx_site_sign_ins_login_user (login_user_id, created_at),
                KEY idx_site_sign_ins_created (created_at),
                CONSTRAINT site_sign_ins_ip_address_fk FOREIGN KEY (ip_address_id) REFERENCES _ip_addresses (id) ON DELETE RESTRICT,
                CONSTRAINT site_sign_ins_user_agent_fk FOREIGN KEY (user_agent_id) REFERENCES _user_agents (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
