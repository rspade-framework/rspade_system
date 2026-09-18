<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * login_users.is_developer marks an identity as a developer of this installation.
 *
 * It is a property of the PERSON, not of a site membership, so it lives on the login
 * identity and travels with them across every site they belong to.
 *
 * Nothing in the framework ever writes it: no UI, no endpoint, no configuration key and
 * no environment variable. A developer is made by a person, by hand, in the database:
 *
 *     UPDATE login_users SET is_developer = 1 WHERE email = 'someone@example.com';
 *
 * That is deliberate. Whoever holds a database client on the production box already has
 * de facto developer access to everything the application can reach, and being able to
 * open one is the competence bar for holding the flag at all. Any lesser channel would
 * hand the flag to people who could not otherwise have taken it.
 *
 * DEFAULT 0, so every existing identity keeps exactly the access it has today. The index
 * exists because the flag is a filter ("which identities are developers"), never a lookup
 * key.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE login_users
            ADD COLUMN is_developer TINYINT(1) NOT NULL DEFAULT 0
        ");

        DB::statement("
            ALTER TABLE login_users
            ADD INDEX idx_login_users_is_developer (is_developer)
        ");
    }
};
