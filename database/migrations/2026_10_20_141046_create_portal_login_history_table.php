<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * _portal_login_history - the client portal's record of sign-in outcomes, the portal twin
 * of _login_history (App\RSpade\Core\Session\Portal_Login_History reads and writes it).
 *
 * Same columns as the staff table, keyed on the portal identity, with two differences:
 *
 *   - site_id is carried, because a portal user belongs to exactly one site and a portal
 *     sign-in happens on that site's portal; the staff table has no site to record (a staff
 *     sign-in authenticates the cross-site identity before any site is chosen).
 *   - portal_user_id is NOT NULL and cascades with its portal user, like every other
 *     portal-user-keyed system table (_portal_two_factor_credentials, _portal_sso_identities,
 *     _sessions.portal_user_id). Every row names an identity: only outcomes reached after the
 *     credentials were proven are rows, and a failure is an ephemeral counter, never a row.
 *
 * There are no location columns: where an address is lives on its _ip_addresses row.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _portal_login_history (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                portal_user_id BIGINT NOT NULL,
                site_id BIGINT NOT NULL,
                email_attempted VARCHAR(255) NOT NULL,
                ip_address_id BIGINT NULL,
                user_agent_id BIGINT NULL,
                status VARCHAR(32) NOT NULL,
                failure_reason VARCHAR(255) NULL,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),

                INDEX idx_portal_login_history_portal_user (portal_user_id),
                INDEX idx_portal_login_history_site (site_id),
                INDEX idx_portal_login_history_ip_address (ip_address_id),
                INDEX idx_portal_login_history_user_agent (user_agent_id),

                CONSTRAINT portal_login_history_portal_user_fk FOREIGN KEY (portal_user_id)
                    REFERENCES portal_users (id) ON DELETE CASCADE,
                CONSTRAINT portal_login_history_ip_address_fk FOREIGN KEY (ip_address_id)
                    REFERENCES _ip_addresses (id) ON DELETE RESTRICT,
                CONSTRAINT portal_login_history_user_agent_fk FOREIGN KEY (user_agent_id)
                    REFERENCES _user_agents (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
