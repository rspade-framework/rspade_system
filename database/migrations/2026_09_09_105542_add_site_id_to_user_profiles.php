<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * IT IS GUARDED ON THE COLUMN ALREADY EXISTING, which is the one place this framework
     * accepts a conditional in a migration (owner instruction 2026-09-09; the same shape as
     * 2026_08_09_112232_add_deleted_at_to_actor_tables). The reason is specific rather than
     * defensive: an application may have site-scoped `user_profiles` in its OWN migration
     * before the framework did - one such report is what prompted this change - and on that
     * database the ALTER below is a duplicate-column error on the next `migrate`. The guard is
     * not a guess about the schema; it is the framework declining to fight an application that
     * already reached the same end state.
     *
     * It deliberately guards the WHOLE step, not each statement. A database that already has
     * the column has its own backfill and its own index, and re-running either against data
     * this migration did not create is a worse outcome than skipping.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('user_profiles', 'site_id')) {
            return;
        }

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
