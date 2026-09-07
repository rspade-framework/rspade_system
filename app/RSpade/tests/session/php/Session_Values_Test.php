<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Session::put_value / get_value / forget_value - the session-scoped key/value store.
 *
 * The properties that matter are the ones a caller would otherwise have to assume: that a
 * READ never mints a session (asking a question must not create a row for every anonymous
 * visitor), that an EXPIRED value reads as absent the instant it expires rather than when a
 * prune task next runs, that values round-trip by type, and that the row dies with its
 * session via the FK cascade.
 */
class Session_Values_Test extends Rsx_Test_Abstract
{
    /**
     * A CLI run mints a TYPE_CLI session row on the first Session::get_session_id().
     */
    private static function __session_id(): int
    {
        return Session::get_session_id();
    }

    private static function __row_count(int $session_id): int
    {
        return DB::table('_session_values')->where('session_id', $session_id)->count();
    }

    // -------------------------------------------------------------------------
    // Reads never create a session
    // -------------------------------------------------------------------------

    public static function test_get_without_a_session_returns_the_default()
    {
        static::__reset_session();

        static::__assert_equals('fallback', Session::get_value('nothing_here', 'fallback'));
    }

    public static function test_get_without_a_session_creates_no_session_row()
    {
        static::__reset_session();

        $before = Session::count();
        Session::get_value('nothing_here');

        static::__assert_equals($before, Session::count());
    }

    public static function test_forget_without_a_session_creates_no_session_row()
    {
        static::__reset_session();

        $before = Session::count();
        Session::forget_value('nothing_here');

        static::__assert_equals($before, Session::count());
    }

    // -------------------------------------------------------------------------
    // Round trip
    // -------------------------------------------------------------------------

    public static function test_put_then_get_returns_the_value()
    {
        $id = static::__session_id();

        Session::put_value('greeting', 'hello');

        static::__assert_equals('hello', Session::get_value('greeting'));
        static::__assert_equals(1, static::__row_count($id));
    }

    public static function test_values_round_trip_by_type()
    {
        static::__session_id();

        Session::put_value('an_int', 42);
        Session::put_value('a_bool', false);
        Session::put_value('an_array', ['a' => 1, 'b' => [2, 3]]);

        static::__assert_true(Session::get_value('an_int') === 42, 'int stays an int');
        static::__assert_true(Session::get_value('a_bool') === false, 'false is not confused with unset');
        static::__assert_equals(['a' => 1, 'b' => [2, 3]], Session::get_value('an_array'));
    }

    public static function test_put_is_an_upsert_not_an_append()
    {
        $id = static::__session_id();

        Session::put_value('once', 'first');
        Session::put_value('once', 'second');

        static::__assert_equals('second', Session::get_value('once'));
        static::__assert_equals(1, static::__row_count($id));
    }

    public static function test_unset_key_returns_the_default()
    {
        static::__session_id();

        static::__assert_equals('fallback', Session::get_value('never_written', 'fallback'));
        static::__assert_null(Session::get_value('never_written'));
    }

    public static function test_forget_removes_the_value_and_its_row()
    {
        $id = static::__session_id();

        Session::put_value('temporary', 'x');
        Session::forget_value('temporary');

        static::__assert_null(Session::get_value('temporary'));
        static::__assert_equals(0, static::__row_count($id));
    }

    public static function test_forgetting_an_absent_key_is_not_an_error()
    {
        static::__session_id();

        Session::forget_value('was_never_there');

        static::__assert_true(true, 'no exception');
    }

    // -------------------------------------------------------------------------
    // Expiry
    // -------------------------------------------------------------------------

    public static function test_a_value_with_no_expiry_is_readable()
    {
        static::__session_id();

        Session::put_value('forever', 'still here', null);

        static::__assert_equals('still here', Session::get_value('forever'));
    }

    public static function test_a_future_expiry_is_readable()
    {
        static::__session_id();

        Session::put_value('soon', 'still here', Rsx_Time::add(Rsx_Time::now_iso(), 3600));

        static::__assert_equals('still here', Session::get_value('soon'));
    }

    public static function test_an_expired_value_reads_as_absent_without_a_prune()
    {
        static::__session_id();

        // Expired one hour ago. Nothing has swept it - the READ must not return it.
        Session::put_value('stale', 'gone', Rsx_Time::subtract(Rsx_Time::now_iso(), 3600));

        static::__assert_null(Session::get_value('stale'));
        static::__assert_equals('fallback', Session::get_value('stale', 'fallback'));
    }

    public static function test_an_expired_value_leaves_its_row_for_the_pruner()
    {
        $id = static::__session_id();

        Session::put_value('stale', 'gone', Rsx_Time::subtract(Rsx_Time::now_iso(), 3600));

        // Unreadable, but still on disk: correctness comes from the read filter, and the
        // task reclaims the space later.
        static::__assert_equals(1, static::__row_count($id));
    }

    // -------------------------------------------------------------------------
    // Lifetime
    // -------------------------------------------------------------------------

    public static function test_values_are_removed_when_the_session_row_is_deleted()
    {
        $id = static::__session_id();

        Session::put_value('doomed', 'x');
        static::__assert_equals(1, static::__row_count($id));

        // The FK is ON DELETE CASCADE, so ending the session reclaims its values with no
        // sweeper involved - this is what makes the store's lifetime intrinsic.
        DB::table('_sessions')->where('id', $id)->delete();

        static::__assert_equals(0, static::__row_count($id));
    }

    public static function test_one_session_cannot_read_another_sessions_value()
    {
        $id = static::__session_id();
        Session::put_value('mine', 'secret');

        $other = DB::table('_session_values')
            ->where('session_id', '!=', $id)
            ->where('value_key', 'mine')
            ->count();

        static::__assert_equals(0, $other, 'the value belongs to exactly one session');
    }
}
