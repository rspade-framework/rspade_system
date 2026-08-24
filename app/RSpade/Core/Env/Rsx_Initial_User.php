<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Env;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * THE INITIAL USER - the one account an application is born with.
 *
 * An RSpade application creates its first account exactly once, from one of four
 * places (the first-run setup screen, the RSPADE_DEFAULT_* post-migrate step, the
 * test baseline seed, or an operator calling this directly). Before this class each of
 * those wrote its own INSERTs, and every one of them produced a slightly different
 * row - which is why an application that wanted to say "the founder is an admin"
 * had nowhere to attach that but a migration hardcoding user id 1.
 *
 * THE ID IS ONE, AND IT IS ASSIGNED, NOT ASSUMED.
 *
 * Both halves of the account - the `login_users` credential and the `users` site
 * profile - are created with id 1. That is a CONTRACT an application may rely on,
 * so it is written explicitly rather than left to AUTO_INCREMENT: the counter can
 * already have advanced past 1 (a hard-deleted row leaves it there), and "the first
 * account happens to be id 1" is not the same statement as "the initial user IS id
 * 1". A row already occupying id 1 on either table is an impossible condition here,
 * not a case to handle - every caller has its own "does this application need an
 * initial user" check, and reaching this function means that check was wrong.
 *
 * THE EVENT IS THE EXTENSION POINT.
 *
 * On success this fires the action `user.initial.created` with both records, the
 * site and the path that created them. An application that wants supporting rows
 * for its founder - a group membership, admin ACLs, a default dashboard - registers
 * an #[OnEvent] handler in /rsx/handlers/ and gets them on every new install AND in
 * the test baseline. It does NOT write a migration that inserts rows keyed to user
 * id 1: a migration is a forward-only historical record, it runs once per database,
 * and it cannot know whether the account it is decorating exists yet.
 *
 * See: php artisan rsx:man initial_user
 */
class Rsx_Initial_User
{
    /**
     * The id both halves of the initial account are created with. The contract the
     * `user.initial.created` payload carries, and the reason it is a constant rather
     * than a literal repeated at four call sites.
     */
    public const INITIAL_USER_ID = 1;

    /**
     * The name the initial account gets when the caller does not supply one.
     *
     * users.first_name / last_name are nullable with NO database default, so an
     * account created without them has no name at all - and every screen that
     * prints a user then falls through to showing the raw email address, which is
     * how a founding account ends up displaying as "you@example.com" forever.
     *
     * Defaulted HERE rather than at each call site because there are three ways in
     * (the first-run screen, the RSPADE_DEFAULT_* post-migrate seed, and the test
     * baseline) and only the test baseline has any reason to name its own account.
     * A default per caller is a default that gets forgotten by the next caller.
     */
    public const DEFAULT_FIRST_NAME = 'William';

    public const DEFAULT_LAST_NAME = 'Adama';

    /**
     * The event fired once the initial user exists.
     *
     * THE SESSION REFLECTS THE NEW ACCOUNT'S SITE WHEN YOUR HANDLER RUNS.
     * Session::get_site_id() returns the founding account's site_id (site 1 on a
     * stock install) and Session::has_session() answers true, in EVERY source -
     * the first-run web screen, the post-migrate seed, and the test baseline
     * alike. Declared by Session::set_temporary_site_id() immediately before the
     * account is written, so a handler can create site-scoped records without
     * passing a site around or resolving one itself.
     *
     * Nothing was persisted to make that true: no session row exists, no cookie
     * was set, and any row that did exist is untouched. It is a declaration for
     * the remainder of this script.
     *
     * There is still NO LOGGED-IN USER - get_login_user_id() and get_user_id()
     * are null, and is_logged_in() is false. Nobody has signed in; the account
     * has only just come into existence. Use the payload's 'user' for authorship
     * rather than reading it from the session.
     */
    public const EVENT_CREATED = 'user.initial.created';

    /**
     * The four values `source` can carry in the event payload - which path created
     * the account. post_migrate is the RSPADE_DEFAULT_* seed that runs at the END of a
     * migrate, after the final normalize pass - not from a migration; see
     * create_from_env_if_needed(). A handler that must behave differently in a test baseline than on
     * a real first run reads this; most handlers ignore it.
     */
    public const SOURCE_FIRST_RUN = 'first_run';
    public const SOURCE_POST_MIGRATE = 'post_migrate';
    public const SOURCE_TEST_BASELINE = 'test_baseline';
    public const SOURCE_MANUAL = 'manual';

    /**
     * Create the initial user - credential plus site profile, both id 1 - and fire
     * `user.initial.created`.
     *
     * Written through the models, so the framework's own save() path runs: audit
     * stamping, write effects, and every hook a credential or profile record is
     * entitled to. The explicit id is assigned by turning OFF the instance's
     * incrementing flag for the insert; Eloquent otherwise reads the id back from
     * LAST_INSERT_ID(), which MySQL does not advance for an explicitly-valued
     * AUTO_INCREMENT column, and would overwrite the id in memory with a stale one.
     *
     * @param string $email    the login email; also the profile email
     * @param string $password the PLAIN password - hashed here, so no caller hashes it twice
     * @param array  $options  site_id, role_id, first_name, last_name, source, connection
     *                          (first_name/last_name default to DEFAULT_FIRST_NAME /
     *                          DEFAULT_LAST_NAME - the columns are nullable, but a
     *                          nameless account renders as its email address)
     * @return User_Model the site profile (id 1); its credential is $user->login_user
     */
    public static function create(string $email, string $password, array $options = []): User_Model
    {
        $connection_name = $options['connection'] ?? null;
        $source = (string) ($options['source'] ?? self::SOURCE_MANUAL);
        $site_id = array_key_exists('site_id', $options)
            ? (int) $options['site_id']
            : self::resolve_site_id($connection_name);

        self::__assert_no_initial_user($connection_name);

        // DECLARE the tenant for the rest of this script, before anything is written.
        //
        // The account being created IS the first account, so nobody is signed in and
        // there is no session to read a tenant from - in a first-run web request the
        // visitor has no cookie, and in the migrate/test CLI path there is no cookie
        // to have. Without this, site-scoped code reached from here (the model writes
        // below, and every user.initial.created handler) sees site_id 0 and
        // has_session() false, and either scopes to nothing or refuses outright.
        //
        // set_temporary_site_id() is what makes that safe to fix in ONE call for both
        // modes: it writes no session row, emits no cookie, and touches no existing
        // row - a browser that somehow does have a session keeps its own tenant. It is
        // NOT cleared afterwards, deliberately: the declaration is meant to hold for
        // the remainder of the script, which is the request or command that just
        // created the founding account.
        Session::set_temporary_site_id($site_id);

        // Every field assigned explicitly - never mass assignment, so what this
        // writes is visible here rather than inferred from an array.
        $login_user = new Login_User_Model();
        if ($connection_name !== null) {
            $login_user->setConnection($connection_name);
        }
        $login_user->incrementing = false;
        $login_user->id = self::INITIAL_USER_ID;
        $login_user->email = $email;
        $login_user->password = Hash::make($password);
        $login_user->is_activated = 1;
        $login_user->is_verified = 1;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();
        $login_user->incrementing = true;

        $user = new User_Model();
        if ($connection_name !== null) {
            $user->setConnection($connection_name);
        }
        $user->incrementing = false;
        $user->id = self::INITIAL_USER_ID;
        $user->login_user_id = $login_user->id;
        $user->site_id = $site_id;
        $user->email = $email;
        $user->first_name = $options['first_name'] ?? self::DEFAULT_FIRST_NAME;
        $user->last_name = $options['last_name'] ?? self::DEFAULT_LAST_NAME;
        $user->role_id = array_key_exists('role_id', $options) ? $options['role_id'] : null;
        $user->is_enabled = 1;
        $user->save();
        $user->incrementing = true;

        // Handlers run INLINE, in whatever request or command created the account.
        Rsx::trigger_action(self::EVENT_CREATED, [
            'user' => $user,
            'login_user' => $login_user,
            'site_id' => $site_id,
            'source' => $source,
        ]);

        return $user;
    }

    /**
     * Create the initial user from RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD, if
     * this database still needs one. The POST-MIGRATE step - see Maint_Migrate.
     *
     * WHY THIS IS NOT A MIGRATION. A migration runs at ONE FIXED POINT in schema
     * history and must replay identically forever. This creation runs MODEL code and,
     * through user.initial.created, APPLICATION HANDLER code - both of which are
     * current code that only works against the CURRENT schema. Put that in a migration
     * and a from-scratch replay executes today's models against a months-old table
     * shape, which is the exact coupling migrations are forbidden to have. Running it
     * after the final normalize pass instead means the schema is at the tip by
     * construction, every time, with no ordering to reason about.
     *
     * The credentials are REQUIRED and blank is fatal: RSpade ships no default
     * credential, because a framework with one is a framework where every install in
     * the world shares it. Two contexts tolerate blank and simply create nothing:
     *
     *   DEVELOPMENT - the first-run setup screen offers to create this account in the
     *   browser instead, which is a far better introduction than a login form with no
     *   way past it.
     *
     *   THE TEST DATABASE - a test run provisions a schema, not a person. The test
     *   runner seeds its own baseline identity through create() straight afterwards.
     *
     * @return User_Model|null the account created, or null when none was needed
     * @throws \RuntimeException when the credentials are blank anywhere else
     */
    public static function create_from_env_if_needed(): ?User_Model
    {
        // Already has its initial user - every existing install. Nothing to do, and NOT
        // an error: the id-1 assertion inside create() is for callers that never checked.
        if (!self::is_needed()) {
            return null;
        }

        $email = trim((string) env('RSPADE_DEFAULT_EMAIL', ''));
        $password = (string) env('RSPADE_DEFAULT_PASSWORD', '');

        if ($email === '' || $password === '') {
            $connection = (string) config('database.default');
            $current_database = (string) config('database.connections.' . $connection . '.database');
            $test_database = (string) env('DB_TEST_DATABASE', 'rspade_test');

            $is_development = Rsx::is_development();
            $is_test_database = $current_database !== '' && $current_database === $test_database;

            if ($is_development || $is_test_database) {
                return null;
            }

            $missing = [];
            if ($email === '') {
                $missing[] = 'RSPADE_DEFAULT_EMAIL';
            }
            if ($password === '') {
                $missing[] = 'RSPADE_DEFAULT_PASSWORD';
            }

            throw new \RuntimeException(
                'Cannot create the first user: ' . implode(' and ', $missing) . ' '
                . (count($missing) === 1 ? 'is' : 'are') . ' not set in .env.'
                . "\n\n"
                . "  These are the credentials of the account you will log in with, and RSpade\n"
                . "  deliberately ships no default for them - a shared, published password is\n"
                . "  not a starting point. Set both in .env and run migrate again:\n\n"
                . "      RSPADE_DEFAULT_EMAIL=you@example.com\n"
                . "      RSPADE_DEFAULT_PASSWORD=<a password you choose>\n\n"
                . '  See .env.README for the full description of every .env value.'
            );
        }

        return self::create($email, $password, [
            'source' => self::SOURCE_POST_MIGRATE,
        ]);
    }

    /**
     * True when this database has no credential records at all - i.e. the
     * application still needs its initial user.
     *
     * The check is `login_users`, not `users`: credentials and site profiles are
     * different tables, and asking the wrong one answers a different question.
     *
     * @param string|null $connection_name
     * @return bool
     */
    public static function is_needed(?string $connection_name = null): bool
    {
        return self::__select($connection_name, 'SELECT id FROM login_users LIMIT 1') === [];
    }

    /**
     * The site the initial profile belongs to: the configured default when it
     * exists, otherwise the lowest-numbered real site there is.
     *
     * A profile row must belong to a site - sign-in looks up the enabled User_Model
     * rows for an identity, and an account belonging to no site is reported as
     * "inactive", which is a confusing way to say "there was nowhere to put you".
     * So no site at all is fatal here rather than a credential nobody can use.
     *
     * Site 0 ("Default") is deliberately excluded: it is the sessionless-write FK
     * target, not a tenant anybody signs into.
     *
     * @param string|null $connection_name
     * @return int
     */
    public static function resolve_site_id(?string $connection_name = null): int
    {
        $site_id = (int) config('multi-tenant.default_site_id', 1);

        if (self::__select($connection_name, 'SELECT id FROM sites WHERE id = ?', [$site_id]) !== []) {
            return $site_id;
        }

        $sites = self::__select($connection_name, 'SELECT id FROM sites WHERE id > 0 ORDER BY id LIMIT 1');
        $first_site = $sites[0] ?? null;

        if ($first_site === null) {
            throw new \RuntimeException(
                'Cannot create the initial user: this database has no site for the account to belong to. '
                . 'Run migrations first - the framework seeds the default site.'
            );
        }

        return (int) $first_site->id;
    }

    /**
     * The impossible-condition assertion behind the id-1 contract.
     *
     * Raw table queries rather than the models, deliberately: `users` soft-deletes
     * and is site-scoped, so a model query can report "no row" about an id that is
     * very much occupied.
     *
     * @param string|null $connection_name
     * @return void
     */
    private static function __assert_no_initial_user(?string $connection_name): void
    {
        foreach (['login_users', 'users'] as $table) {
            $existing = self::__select(
                $connection_name,
                'SELECT id FROM ' . $table . ' WHERE id = ?',
                [self::INITIAL_USER_ID]
            );

            if ($existing !== []) {
                shouldnt_happen(
                    'Refusing to create the initial user: ' . $table . ' already has a row with id '
                    . self::INITIAL_USER_ID . '. This application already has its initial user, and the '
                    . "caller's own setup check should have prevented this call."
                );
            }
        }
    }

    /**
     * A raw SELECT on the named connection, or on the default one.
     *
     * Raw rather than the ORM on purpose, and this is the case PHP-DB-01 names: these
     * three questions are about rows the models would hide from us. `users` soft-deletes
     * and carries the site global scope, so a model read can report "no row" about an id
     * that is very much occupied - which is the one answer this class must never get
     * wrong.
     *
     * @param string|null $connection_name
     * @param string $sql
     * @param array $bindings
     * @return array<int, object>
     */
    private static function __select(?string $connection_name, string $sql, array $bindings = []): array
    {
        return $connection_name === null
            ? DB::select($sql, $bindings)
            : DB::connection($connection_name)->select($sql, $bindings);
    }
}
