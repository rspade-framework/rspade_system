<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Auth\Password_Change;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Portal\Portal_User_Model;

/**
 * EVERY PASSWORD CHANGE IS REPORTED, ONCE, AFTER IT COMMITS.
 *
 * Saving a new password on an EXISTING identity through its model fires
 * login_user.password.changed (staff) / portal_user.password.changed (portal) with
 * {identity, cause, actor, audit_session_id, ip_address}. The cause is what set_password()
 * stated, 'unknown' when nothing did; creating an identity, or a save that leaves the password
 * alone, fires nothing; inside a transaction the event waits for the commit and a rollback
 * drops it; an unknown cause string throws before anything is assigned.
 *
 * The event is registered with afterCommit(), which only a REAL outermost commit runs, so the
 * class opts out of the per-test transaction and starts from a clean baseline. Handlers are
 * replaced by a recorder (Event_Registry::_set_test_handlers()), so the application's own
 * handlers never run here.
 */
class Password_Change_Event_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    private const SITE_ID = 1;

    /** @var array<int, array{0: string, 1: array}> [event, payload] in the order fired */
    private static array $fired = [];

    /**
     * Start recording from nothing: setup() and teardown() run once per CLASS, so every test
     * opens with this.
     */
    private static function __record(): void
    {
        static::$fired = [];

        foreach (['login_user.password.changed', 'portal_user.password.changed'] as $event) {
            Event_Registry::_set_test_handlers($event, [function ($data) use ($event) {
                static::$fired[] = [$event, $data];
            }]);
        }
    }

    public static function teardown(): void
    {
        Event_Registry::_clear_test_handlers();
        static::__reset_session();
    }

    private static function __login_user(string $tag): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'password_change_event_' . $tag . '_' . uniqid() . '@example.com';
        $login_user->set_password('the-first-password');
        $login_user->is_activated = 1;
        $login_user->is_verified = 1;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * pce-01: a stated cause is reported once, with the identity; nobody acting in a command.
     */
    public static function test_a_change_fires_once_with_its_cause()
    {
        static::__record();
        $login_user = static::__login_user('cause');
        static::__assert_count(0, static::$fired, 'creating an identity with a password is not a change');

        $login_user->set_password('a-second-password', Password_Change::CAUSE_ADMIN);
        $login_user->save();

        static::__assert_count(1, static::$fired, 'one change, one event');
        [$event, $data] = static::$fired[0];
        static::__assert_equals('login_user.password.changed', $event);
        static::__assert_equals(Password_Change::CAUSE_ADMIN, $data['cause']);
        static::__assert_equals((int) $login_user->id, (int) $data['identity']->id);
        static::__assert_null($data['actor'], 'nobody is signed in to a command');
        static::__assert_null($data['audit_session_id'], 'a command has no audit session');
        static::__assert_null($data['ip_address'], 'a command has no client address');

        // The stated cause belongs to that one save.
        $login_user->password = Login_User_Model::hash_password('a-third-password');
        $login_user->save();
        static::__assert_equals(Password_Change::CAUSE_UNKNOWN, static::$fired[1][1]['cause'], 'a later save does not inherit the cause');
    }

    /**
     * pce-02: no stated cause reports 'unknown'; a save that leaves the password alone fires nothing.
     */
    public static function test_no_stated_cause_reports_unknown_and_other_saves_fire_nothing()
    {
        static::__record();
        $login_user = static::__login_user('unknown');

        $login_user->timezone = 'America/Chicago';
        $login_user->save();
        static::__assert_count(0, static::$fired, 'a save that does not change the password fires nothing');

        $login_user->set_password('no-cause-stated');
        $login_user->save();

        static::__assert_count(1, static::$fired);
        static::__assert_equals(Password_Change::CAUSE_UNKNOWN, static::$fired[0][1]['cause']);
    }

    /**
     * pce-03: inside a transaction the event waits for the commit; a rollback drops it.
     */
    public static function test_the_event_fires_after_the_commit_and_never_on_a_rollback()
    {
        static::__record();
        $login_user = static::__login_user('commit');

        DB::transaction(function () use ($login_user) {
            $login_user->set_password('inside-a-transaction', Password_Change::CAUSE_SELF);
            $login_user->save();

            static::__assert_count(0, static::$fired, 'nothing fires before the commit');
        });

        static::__assert_count(1, static::$fired, 'fired once the transaction committed');
        static::__assert_equals(Password_Change::CAUSE_SELF, static::$fired[0][1]['cause']);

        $fresh = Login_User_Model::find($login_user->id);
        DB::beginTransaction();
        $fresh->set_password('rolled-back', Password_Change::CAUSE_SELF);
        $fresh->save();
        DB::rollBack();

        static::__assert_count(1, static::$fired, 'a rolled-back change fires nothing');
    }

    /**
     * pce-04: the actor is the signed-in identity that made the change.
     */
    public static function test_the_actor_is_the_signed_in_identity()
    {
        static::__record();
        $administrator = static::__login_user('admin');
        $target = static::__login_user('target');

        Session::set_login_user_id((int) $administrator->id, false);

        $target->set_password('set-by-somebody-else', Password_Change::CAUSE_ADMIN);
        $target->save();

        static::__assert_count(1, static::$fired);
        static::__assert_instance_of(Login_User_Model::class, static::$fired[0][1]['actor']);
        static::__assert_equals((int) $administrator->id, (int) static::$fired[0][1]['actor']->id);
    }

    /**
     * pce-05: a cause outside the vocabulary throws, and nothing is assigned.
     */
    public static function test_an_unknown_cause_throws()
    {
        static::__record();
        $login_user = static::__login_user('bad');
        $before = $login_user->password;

        static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => $login_user->set_password('whatever', 'because'),
            "Unknown password change cause 'because'"
        );

        static::__assert_equals($before, $login_user->password, 'the password was not assigned');
    }

    /**
     * pce-06: rsx:users:password:set states 'cli'.
     */
    public static function test_the_command_states_cli()
    {
        static::__record();
        $login_user = static::__login_user('cli');

        $code = Artisan::call('rsx:users:password:set', [
            '--user' => (string) $login_user->id,
            '--password' => 'set-from-the-command-line',
            '--json' => true,
        ]);

        static::__assert_equals(0, $code, Artisan::output());
        static::__assert_count(1, static::$fired);
        static::__assert_equals(Password_Change::CAUSE_CLI, static::$fired[0][1]['cause']);
    }

    /**
     * pce-07: the portal twin.
     */
    public static function test_the_portal_identity_fires_its_own_event()
    {
        static::__record();
        $portal_user = null;

        Session::set_temporary_site_id(self::SITE_ID);
        try {
            Site_Lock::write(self::SITE_ID, function () use (&$portal_user) {
                $portal_user = new Portal_User_Model();
                $portal_user->site_id = self::SITE_ID;
                $portal_user->email = 'password_change_event_portal_' . uniqid() . '@example.com';
                $portal_user->set_password('the-first-password');
                $portal_user->is_verified = true;
                $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
                $portal_user->save();
            });

            static::__assert_count(0, static::$fired, 'creating a portal user is not a change');

            Site_Lock::write(self::SITE_ID, function () use ($portal_user) {
                $portal_user->set_password('a-new-portal-password', Password_Change::CAUSE_RESET_LINK);
                $portal_user->save();

                static::__assert_count(0, static::$fired, 'the write section has not committed yet');
            });
        } finally {
            Session::clear_temporary_site_id();
        }

        static::__assert_count(1, static::$fired);
        static::__assert_equals('portal_user.password.changed', static::$fired[0][0]);
        static::__assert_equals(Password_Change::CAUSE_RESET_LINK, static::$fired[0][1]['cause']);
        static::__assert_equals((int) $portal_user->id, (int) static::$fired[0][1]['identity']->id);
    }
}
