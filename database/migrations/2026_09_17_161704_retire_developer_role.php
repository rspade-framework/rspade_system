<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Developer role (users.role_id 100) retires.
 *
 * Being a developer is not a rank in a site's role hierarchy: it says nothing about what
 * a person may do with that site's records, and a role could only ever say it for one
 * membership at a time. It is now login_users.is_developer, a property of the identity.
 *
 * So this migration moves the two facts apart. Every identity that held the Developer
 * role anywhere becomes a developer; every user row that held it becomes a Root Admin
 * (200), which is what the role was actually being used for - the top of the hierarchy.
 *
 * Forward-only and deterministic: after it runs, no users.role_id is 100, and
 * User_Model::$enums['role_id'] no longer declares one.
 */
return new class extends Migration
{
    public function up()
    {
        // The identity behind every Developer membership becomes a developer.
        DB::statement("
            UPDATE login_users l
            JOIN users u ON u.login_user_id = l.id
            SET l.is_developer = 1
            WHERE u.role_id = 100
        ");

        // The membership itself becomes what it was standing in for: the top role.
        DB::statement("
            UPDATE users
            SET role_id = 200
            WHERE role_id = 100
        ");
    }
};
