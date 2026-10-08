<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * _sessions.login_requirements held the requirements a signed-in identity had not yet met.
 * Nothing reads it: a signed-in identity is signed in on every surface, and what a person
 * still owes after sign-in (enrolling a factor, an onboarding step) is the application's own
 * redirect, not session state the framework conceals an identity behind.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _sessions DROP COLUMN login_requirements");
    }
};
