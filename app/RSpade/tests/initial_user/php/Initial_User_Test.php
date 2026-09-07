<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\InitialUser\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Env\Rsx_Initial_User;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\InitialUser\Php\Initial_User_Fixture_Handler;

/**
 * Rsx_Initial_User: the id-1 contract and the user.initial.created event.
 *
 * The test database ALREADY carries an initial user - the baseline account the runner
 * seeds through this very function - so every creating test first removes it inside its
 * own rolled-back transaction, which is also the only honest way to reach the state a
 * fresh install is in. The FK graph is ON DELETE CASCADE all the way down from both
 * tables, so deleting the two rows takes the dependents with it.
 *
 * test_baseline_carries_the_handler_rows is the one test that asserts against the
 * committed baseline rather than a state it built: it proves the event really fired
 * during provisioning, which is what lets an application's handler rows be assumed by
 * every other test in the suite.
 */
class Initial_User_Test extends Rsx_Test_Abstract
{
    public static function teardown(): void
    {
        Initial_User_Fixture_Handler::$recording = false;
        Initial_User_Fixture_Handler::reset();
    }

    /**
     * Remove the initial account, so the calling test faces the state a fresh install
     * is in. Raw deletes: `users` soft-deletes and is site-scoped, and neither of those
     * frees up id 1.
     */
    private static function __clear_initial_user(): void
    {
        DB::statement('DELETE FROM users WHERE id = ?', [Rsx_Initial_User::INITIAL_USER_ID]);
        DB::statement('DELETE FROM login_users WHERE id = ?', [Rsx_Initial_User::INITIAL_USER_ID]);
    }

    /**
     * One raw row, or null. Raw rather than the ORM throughout this class, which is the
     * case PHP-DB-01 names: the assertions are about what is ON DISK at a given id, and
     * `users` soft-deletes and is site-scoped - a model read would hide exactly the rows
     * these tests exist to see.
     */
    private static function __row(string $sql, array $bindings = []): ?object
    {
        $rows = DB::select($sql, $bindings);

        return $rows[0] ?? null;
    }

    /**
     * Advance the AUTO_INCREMENT counter on both tables past 1 without DDL - an ALTER
     * would commit the surrounding transaction. An inserted-then-deleted row leaves the
     * counter exactly where a hard-deleted prior account would.
     */
    private static function __advance_auto_increment(): void
    {
        DB::statement(
            'INSERT INTO login_users (email, password, is_activated, is_verified, created_at, updated_at)'
            . ' VALUES (?, ?, 0, 0, NOW(3), NOW(3))',
            ['auto-increment-probe@rspade.test', 'x']
        );
        $login_user_id = (int) static::__row('SELECT LAST_INSERT_ID() AS id')->id;

        DB::statement(
            'INSERT INTO users (login_user_id, site_id, email, is_enabled, created_at, updated_at)'
            . ' VALUES (?, 1, ?, 0, NOW(3), NOW(3))',
            [$login_user_id, 'auto-increment-probe@rspade.test']
        );
        $user_id = (int) static::__row('SELECT LAST_INSERT_ID() AS id')->id;

        static::__assert_greater_than(1, $login_user_id, 'the probe credential took an id above 1');
        static::__assert_greater_than(1, $user_id, 'the probe profile took an id above 1');

        DB::statement('DELETE FROM users WHERE id = ?', [$user_id]);
        DB::statement('DELETE FROM login_users WHERE id = ?', [$login_user_id]);
    }

    public static function test_create_assigns_id_one_when_auto_increment_has_advanced()
    {
        static::__clear_initial_user();
        static::__advance_auto_increment();

        $user = Rsx_Initial_User::create('founder@rspade.test', 'a-password-nobody-uses', [
            'site_id' => 1,
            'source' => Rsx_Initial_User::SOURCE_MANUAL,
        ]);

        static::__assert_instance_of(User_Model::class, $user, 'create() returns the site profile');
        static::__assert_equals(1, (int) $user->id, 'the site profile is id 1');
        static::__assert_equals(1, (int) $user->login_user_id, 'the profile points at credential 1');
        static::__assert_equals(1, (int) $user->site_id, 'the profile belongs to the requested site');

        // Read the rows back rather than trusting the in-memory models: an explicitly
        // valued AUTO_INCREMENT column does not move LAST_INSERT_ID(), and reading the id
        // back from there is exactly the bug this contract has to survive.
        static::__assert_not_empty(
            static::__row('SELECT id FROM login_users WHERE id = 1 AND email = ?', ['founder@rspade.test']),
            'the credential row really is id 1 in the database'
        );
        static::__assert_not_empty(
            static::__row('SELECT id FROM users WHERE id = 1 AND login_user_id = 1'),
            'the profile row really is id 1 in the database'
        );
    }

    public static function test_creating_a_second_initial_user_is_impossible()
    {
        // The baseline account is present - this is the caller-forgot-to-check case.
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Rsx_Initial_User::create('second@rspade.test', 'a-password-nobody-uses', ['site_id' => 1]);
            },
            'already has a row with id 1'
        );
    }

    public static function test_is_needed_reports_whether_the_account_exists()
    {
        static::__assert_false(Rsx_Initial_User::is_needed(), 'the baseline database has its initial user');

        static::__clear_initial_user();

        static::__assert_true(Rsx_Initial_User::is_needed(), 'with no credential at all, one is needed');
    }

    public static function test_event_fires_with_the_documented_payload()
    {
        static::__clear_initial_user();

        Initial_User_Fixture_Handler::reset();
        Initial_User_Fixture_Handler::$recording = true;

        $user = Rsx_Initial_User::create('event@rspade.test', 'a-password-nobody-uses', [
            'site_id' => 1,
            'source' => Rsx_Initial_User::SOURCE_FIRST_RUN,
        ]);

        Initial_User_Fixture_Handler::$recording = false;
        $recorded = Initial_User_Fixture_Handler::$recorded;

        static::__assert_count(1, $recorded, 'user.initial.created fires exactly once');

        $payload = $recorded[0];
        static::__assert_equals($user->id, $payload['user']->id, 'the payload carries the user that was created');
        static::__assert_equals(1, (int) $payload['user']->id, 'the user in the payload is id 1');
        static::__assert_equals(1, (int) $payload['login_user']->id, 'the credential in the payload is id 1');
        static::__assert_equals(
            'event@rspade.test',
            $payload['login_user']->email,
            'the credential in the payload is the one just created'
        );
        static::__assert_equals(1, $payload['site_id'], 'the payload names the site');
        static::__assert_equals(
            Rsx_Initial_User::SOURCE_FIRST_RUN,
            $payload['source'],
            'the payload names the path that created the account'
        );
    }

    public static function test_application_handlers_run_on_creation()
    {
        static::__clear_initial_user();

        $user = Rsx_Initial_User::create('handled@rspade.test', 'a-password-nobody-uses', [
            'site_id' => 1,
            'source' => Rsx_Initial_User::SOURCE_MANUAL,
        ]);

        // The reference application's own handler (/rsx/handlers/Initial_User_Handlers.php)
        // makes the founder a root admin and puts them in the Administrators group. That it
        // ran here is the proof that a handler in /rsx/handlers/ is discovered and fired.
        $stored = static::__row('SELECT role_id FROM users WHERE id = ?', [$user->id]);
        static::__assert_equals(
            User_Model::ROLE_ROOT_ADMIN,
            (int) $stored->role_id,
            'the application handler assigned the founder its top role'
        );

        // Newest first: the committed baseline carries an Administrators group of its own
        // (the same handler ran when the runner seeded it), and the one this test is about
        // is the one just created.
        $group = static::__row(
            'SELECT id FROM user_groups WHERE site_id = 1 AND name = ? AND deleted_at IS NULL'
            . ' ORDER BY id DESC LIMIT 1',
            ['Administrators']
        );

        static::__assert_not_empty($group, 'the application handler created the Administrators group');
        static::__assert_not_empty(
            static::__row(
                'SELECT id FROM user_group_members WHERE user_group_id = ? AND user_id = ?',
                [$group->id, $user->id]
            ),
            'the founder is a member of that group'
        );
    }

    public static function test_baseline_carries_the_handler_rows()
    {
        // Committed state, not built by this test: the runner seeded the baseline account
        // through Rsx_Initial_User, so the event fired during provisioning and the
        // application's handler rows are part of every test's starting database.
        $group = static::__row(
            'SELECT id FROM user_groups WHERE name = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            ['Administrators']
        );

        static::__assert_not_empty($group, 'the test baseline carries the Administrators group');
        static::__assert_not_empty(
            static::__row(
                'SELECT id FROM user_group_members WHERE user_group_id = ? AND user_id = ?',
                [$group->id, Rsx_Initial_User::INITIAL_USER_ID]
            ),
            'the baseline user is a member of it'
        );
    }

    public static function test_env_seed_is_a_no_op_once_an_account_exists()
    {
        // The post-migrate step's first question, and the one that makes running it at the
        // end of EVERY migrate harmless: this database already has its initial user.
        static::__assert_null(
            Rsx_Initial_User::create_from_env_if_needed(),
            'the env seed creates nothing when the account already exists'
        );
    }

    public static function test_env_seed_runs_its_whole_body_on_an_empty_database()
    {
        static::__clear_initial_user();

        // Deliberately asserted BOTH ways: whether this creates an account depends on
        // RSPADE_DEFAULT_* being configured on the box running the suite, which no test may
        // depend on. What IS asserted is that the function executes end to end and that any
        // account it does produce obeys the id contract - the branch selection itself
        // (blank credentials are fatal outside development and the test database) is
        // environment-dependent and catalogued as IU-09.
        $user = Rsx_Initial_User::create_from_env_if_needed();

        if ($user === null) {
            static::__assert_true(
                Rsx_Initial_User::is_needed(),
                'declining to seed leaves the database still needing an initial user'
            );

            return;
        }

        static::__assert_equals(1, (int) $user->id, 'an env-seeded account is id 1 like any other');
    }

    public static function test_a_caller_chosen_role_survives_the_handlers()
    {
        static::__clear_initial_user();

        $user = Rsx_Initial_User::create('roled@rspade.test', 'a-password-nobody-uses', [
            'site_id' => 1,
            'role_id' => User_Model::ROLE_DEVELOPER,
            'source' => Rsx_Initial_User::SOURCE_TEST_BASELINE,
        ]);

        // The reference handler only fills a role in when the caller left it unset - which
        // is what makes the test baseline (ROLE_DEVELOPER, outranking ROLE_ROOT_ADMIN)
        // survive its own creation.
        $stored = static::__row('SELECT role_id FROM users WHERE id = ?', [$user->id]);
        static::__assert_equals(
            User_Model::ROLE_DEVELOPER,
            (int) $stored->role_id,
            'an explicitly chosen role is not overruled by a handler'
        );
    }

    /**
     * create() stores a caller-supplied name. The columns are nullable with no
     * default, so an account created without one has NO name and every screen that
     * prints a user falls through to the raw email address.
     */
    public static function test_a_caller_supplied_name_is_stored()
    {
        static::__clear_initial_user();

        $user = Rsx_Initial_User::create('named@rspade.test', 'a-password-nobody-uses', [
            'site_id' => 1,
            'source' => Rsx_Initial_User::SOURCE_TEST_BASELINE,
            'first_name' => 'William',
            'last_name' => 'Adama',
        ]);

        $stored = static::__row('SELECT first_name, last_name FROM users WHERE id = ?', [$user->id]);
        static::__assert_equals('William', $stored->first_name);
        static::__assert_equals('Adama', $stored->last_name);
    }

    /**
     * AN ACCOUNT IS NEVER NAMELESS, whoever created it and whatever they passed.
     *
     * This is the case nothing else can reach: the first-run screen runs only
     * against a database with no account at all, and the RSPADE_DEFAULT_* seed only
     * on a fresh migrate - so neither is exercised by an ordinary test run or an
     * ordinary day of development. Both now rely on create() defaulting the name,
     * which is what this asserts, by creating an account the way they do (no name
     * in the options) and reading the columns back.
     *
     * The symptom it guards against is quiet rather than loud: the columns are
     * nullable, nothing throws, and get_printed_name() falls through to the email -
     * so a nameless founding account simply displays as its own email address on
     * every screen, forever, until somebody notices.
     */
    public static function test_an_account_created_without_a_name_gets_the_default()
    {
        static::__clear_initial_user();

        $user = Rsx_Initial_User::create('nameless@rspade.test', 'a-password-nobody-uses', [
            'site_id' => 1,
            'source' => Rsx_Initial_User::SOURCE_POST_MIGRATE,
        ]);

        $stored = static::__row('SELECT first_name, last_name FROM users WHERE id = ?', [$user->id]);

        static::__assert_equals(Rsx_Initial_User::DEFAULT_FIRST_NAME, $stored->first_name);
        static::__assert_equals(Rsx_Initial_User::DEFAULT_LAST_NAME, $stored->last_name);
        static::__assert_not_empty($stored->first_name);
        static::__assert_not_empty($stored->last_name);
    }
}
