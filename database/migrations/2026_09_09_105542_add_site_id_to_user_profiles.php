<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Site-scope user_profiles.
     *
     * user_profiles is a 1:1 satellite of users, and users is site-scoped
     * (User_Model_Abstract extends Rsx_Site_Actor_Model_Abstract, users.site_id is
     * NOT NULL). The satellite was not, so User_Profile_Model_Abstract extended
     * Rsx_Model_Abstract and a profile row carried no tenant of its own: a query
     * against the table was cross-tenant with nothing filtering it, and the only
     * thing keeping reads honest was that callers happened to arrive through the
     * scoped parent. A framework model that an application is expected to extend
     * should not be the one place the tenant boundary stops.
     *
     * The backfill takes each profile's site from its owning user, which is the only
     * correct answer - a profile belongs to exactly the tenant its user does. It runs
     * BEFORE the NOT NULL constraint so no row is ever asked to satisfy it with a
     * placeholder, and an orphan profile (no matching user) would fail the ALTER
     * loudly rather than being silently parked in site 0.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE user_profiles ADD COLUMN site_id BIGINT NULL AFTER user_id");

        DB::statement(
            "UPDATE user_profiles p
                JOIN users u ON u.id = p.user_id
                SET p.site_id = u.site_id"
        );

        DB::statement("ALTER TABLE user_profiles MODIFY COLUMN site_id BIGINT NOT NULL");
        DB::statement("ALTER TABLE user_profiles ADD INDEX idx_site_id (site_id)");
    }
};
