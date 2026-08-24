<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the default site (id 1).
     *
     * Assumptions:
     * - Running in a fresh/reset database
     * - sites table is empty
     *
     * THE USER HALF USED TO LIVE HERE. This migration also inserted the site profile
     * for login_user 1 - the second of two account halves created at two different
     * points in schema history, neither able to guarantee the id. The whole account is
     * now created AFTER the migration chain, by a post-migrate step in Maint_Migrate
     * calling Rsx_Initial_User, which assigns id 1 to both halves and fires
     * user.initial.created - not from a migration, because it runs model and
     * application handler code that only works against the current schema. An
     * application that wants rows keyed to its founder registers a handler for that
     * event instead of a migration like this one.
     *
     * The site insert stays: it is schema-shaped seed data every install needs, and the
     * initial user's profile needs a site to belong to.
     *
     * See: php artisan rsx:man initial_user
     *
     * @return void
     */
    public function up()
    {
        // Create default site
        DB::statement("
            INSERT INTO sites (
                id,
                slug,
                name,
                is_enabled,
                created_at,
                updated_at
            ) VALUES (
                1,
                'test',
                'Test Site',
                1,
                NOW(3),
                NOW(3)
            )
        ");
    }
};
