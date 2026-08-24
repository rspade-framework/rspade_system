<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * HISTORICAL NO-OP - this migration no longer creates anything.
     *
     * It used to insert the first credential from RSPADE_DEFAULT_EMAIL /
     * RSPADE_DEFAULT_PASSWORD, into the `users` table of the day (the table a later
     * migration renames to `login_users`). Its sibling, create_test_user_record,
     * inserted the site profile half two months of schema history later.
     *
     * Two halves created at two points in history could never satisfy the contract the
     * framework now guarantees - that the initial user is id 1 on BOTH tables, created
     * by one function, announcing itself with one event. And the creation is not a
     * migration's job at all: it runs model code and application handler code, which
     * only work against the CURRENT schema, while a migration must replay identically
     * forever from a fixed point in history.
     *
     * So the account is created AFTER the migration chain instead - a post-migrate step
     * in Maint_Migrate, calling Rsx_Initial_User::create_from_env_if_needed(), run once
     * the final normalize pass has put the schema at the tip.
     *
     * The body is emptied rather than the file deleted: this migration has a row in the
     * migrations table of every existing install, and it must keep replaying as a no-op
     * for a database built from scratch. Nothing is lost - a database that already ran
     * the old body still has its account, and one replaying from zero gets the same
     * account from the post-migrate step at the end of the very same run.
     *
     * See: php artisan rsx:man initial_user
     *
     * @return void
     */
    public function up()
    {
        // Intentionally empty. See the docblock.
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
