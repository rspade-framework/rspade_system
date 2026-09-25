<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Migrations must be self-contained.
     *
     * The identity tables are read by primary key, by email, or by a composite that already
     * has its own index. What goes:
     *
     *   - single-column indexes on booleans and small enums (is_activated, is_verified,
     *     is_developer, status_id, is_enabled, role_id): low cardinality, never the access path;
     *   - deleted_at single-column indexes: the implicit deleted_at IS NULL matches nearly
     *     every row, so the optimizer never chooses them;
     *   - last_login, phone and the invite dates: display or residual-filter columns only;
     *   - portal_users.site_id / .email, user_permissions.user_id: prefixes of the
     *     (site_id, email) and (user_id, permission_id) UNIQUE keys, which the foreign keys
     *     bind to instead; user_permissions.permission_id and user_profiles.site_id are never
     *     a predicate on their own;
     *   - portal_notifications.site_id is the prefix of the recipient and unread indexes, and
     *     its type and subject indexes serve no runtime predicate.
     *
     * users.email becomes (email, site_id): email leading serves the unscoped lookups (a
     * sign-in invite match, the API key CLI), the trailing site_id the per-tenant duplicate
     * check.
     *
     * Every table keeps an index LEADING with created_at and one leading with updated_at
     * (migrate:normalize_schema guarantees both, detecting coverage by the leading column).
     * Where a migration-named index duplicated the one normalization added under the column's
     * own name, the named copy is the one dropped: the survivor is then the index
     * normalization re-creates on a database replayed from scratch, so both paths converge.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE login_users
                DROP INDEX idx_login_users_created_at,
                DROP INDEX idx_login_users_updated_at,
                DROP INDEX idx_login_users_is_activated,
                DROP INDEX idx_login_users_is_verified,
                DROP INDEX idx_login_users_is_developer,
                DROP INDEX idx_login_users_status_id,
                DROP INDEX idx_login_users_last_login,
                DROP INDEX idx_login_users_deleted_at
        ");

        DB::statement("
            ALTER TABLE users
                DROP INDEX idx_site_users_is_enabled,
                DROP INDEX idx_site_users_role_id,
                DROP INDEX idx_users_phone,
                DROP INDEX idx_users_invite_accepted_at,
                DROP INDEX idx_users_invite_expires_at,
                DROP INDEX idx_site_users_deleted_at,
                DROP INDEX idx_users_email,
                ADD INDEX idx_users_email_site (email, site_id)
        ");

        DB::statement("
            ALTER TABLE portal_users
                DROP INDEX idx_portal_users_created_at,
                DROP INDEX idx_portal_users_site_id,
                DROP INDEX idx_portal_users_email,
                DROP INDEX idx_portal_users_is_verified,
                DROP INDEX idx_portal_users_status_id,
                DROP INDEX idx_portal_users_last_login,
                DROP INDEX idx_portal_users_deleted_at
        ");

        DB::statement("
            ALTER TABLE sites
                DROP INDEX idx_sites_is_enabled,
                DROP INDEX idx_sites_deleted_at
        ");

        DB::statement("
            ALTER TABLE user_permissions
                DROP INDEX idx_user_id,
                DROP INDEX idx_permission_id
        ");

        DB::statement("
            ALTER TABLE user_profiles DROP INDEX idx_site_id
        ");

        DB::statement("
            ALTER TABLE portal_notifications
                DROP INDEX idx_portal_notifications_site_id,
                DROP INDEX idx_portal_notifications_type,
                DROP INDEX idx_portal_notifications_subject
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
