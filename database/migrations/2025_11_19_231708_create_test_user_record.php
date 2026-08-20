<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates a default site and user record for the test user.
     *
     * Assumptions:
     * - Running in fresh/reset database
     * - login_users table has record with id=1 (created by prior migration)
     * - sites table is empty
     * - users table is empty
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

        // Create user record for test user and associate with site
        DB::statement("
            INSERT INTO users (
                id,
                login_user_id,
                site_id,
                email,
                first_name,
                last_name,
                created_at,
                updated_at
            ) VALUES (
                1,
                1,
                1,
                'test@example.com',
                'Test',
                'User',
                NOW(3),
                NOW(3)
            )
        ");
    }
};
