<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Models\Email_Blocked_Address_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Locks\Php\Site_Lock_Exempt_Fixture_Model;
use App\RSpade\Tests\Locks\Php\Site_Lock_Fixture_Service;
use App\RSpade\Tests\Locks\Php\Site_Lock_Unreasoned_Exempt_Fixture_Model;

/**
 * Site_Lock - the per-site write section, the read section, the bypass, the enforcement of
 * the site write lock on site-scoped writes, the hard rules and the hold-time warnings.
 *
 * Everything here runs in ONE process against the running rsx-lockd; cross-process
 * contention (writers serialized, a writer waiting for readers) is the dispatcher wiring's
 * subject and needs real processes. The site-scoped model written is the framework's
 * Email_Blocked_Address_Model (_email_blocked_addresses), on baseline site 1.
 *
 * Each test runs inside the harness's per-test transaction, which the harness registers as
 * the tolerated baseline for rule 1 - so a write section opened here is not "inside a
 * foreign transaction", and test_a_site_lock_inside_a_foreign_transaction_fatals proves a
 * transaction of the test's own still is.
 */
class Site_Lock_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;
    private const OTHER_SITE_ID = 987653;

    /** The hold-time thresholds as configured, restored after every test that changes them. */
    private static array $configured_thresholds = [];

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);

        static::$configured_thresholds = [
            'rsx.locks.warn_write_hold_seconds' => config('rsx.locks.warn_write_hold_seconds'),
            'rsx.locks.warn_read_hold_seconds' => config('rsx.locks.warn_read_hold_seconds'),
        ];
    }

    // =============================================================================
    // Enforcement
    // =============================================================================

    public static function test_an_unlocked_site_scoped_write_inside_a_window_fatals()
    {
        $email = static::__email();

        static::__in_window(function () use ($email) {
            static::__assert_throws(RuntimeException::class, fn () => static::__block($email), 'without site 1\'s write lock');
        });

        static::__assert_false(static::__exists($email), 'the refused write wrote nothing');
    }

    public static function test_an_unlocked_write_outside_any_window_is_not_checked()
    {
        $email = static::__email();

        static::__block($email);

        static::__assert_true(static::__exists($email), 'fixture-style code outside every window writes freely');
    }

    public static function test_a_write_inside_a_write_section_succeeds_and_releases()
    {
        $email = static::__email();

        static::__in_window(function () use ($email) {
            Site_Lock::write(self::SITE_ID, fn () => static::__block($email));
        });

        static::__assert_true(static::__exists($email), 'the section wrote the row');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'no site lock is held after the section');
    }

    public static function test_a_raw_bulk_delete_is_enforced()
    {
        $email = static::__email();
        static::__block($email);

        static::__in_window(function () use ($email) {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Email_Blocked_Address_Model::where('email', $email)->delete(),
                'delete on site 1'
            );

            Site_Lock::write(self::SITE_ID, fn () => Email_Blocked_Address_Model::where('email', $email)->delete());
        });

        static::__assert_false(static::__exists($email), 'the bulk delete inside the section ran');
    }

    // =============================================================================
    // The write section
    // =============================================================================

    public static function test_a_nested_write_section_reenters_without_a_new_transaction()
    {
        $levels = Site_Lock::write(self::SITE_ID, function () {
            $outer = DB::transactionLevel();
            $inner = Site_Lock::write(self::SITE_ID, fn () => DB::transactionLevel());

            return [$outer, $inner];
        });

        static::__assert_equals($levels[0], $levels[1], 'the nested section opened no transaction of its own');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the reentrant release left nothing held');
    }

    public static function test_a_throw_rolls_the_section_back_and_releases_the_lock()
    {
        $email = static::__email();
        $level = DB::transactionLevel();

        static::__assert_throws(RuntimeException::class, function () use ($email) {
            Site_Lock::write(self::SITE_ID, function () use ($email) {
                static::__block($email);

                throw new RuntimeException('abandon the section');
            });
        }, 'abandon the section');

        static::__assert_false(static::__exists($email), 'the write was rolled back');
        static::__assert_equals($level, DB::transactionLevel(), 'the section left no transaction open');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the lock was released after the rollback');
    }

    public static function test_a_section_that_leaves_its_own_transaction_open_is_rolled_back_and_refused()
    {
        $email = static::__email();
        $level = DB::transactionLevel();

        static::__assert_throws(RuntimeException::class, function () use ($email) {
            Site_Lock::write(self::SITE_ID, function () use ($email) {
                static::__block($email);
                DB::beginTransaction();
            });
        }, 'still open');

        static::__assert_false(static::__exists($email), 'nothing half-done was committed');
        static::__assert_equals($level, DB::transactionLevel(), 'every level was closed');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the lock was released');
    }

    public static function test_releasing_a_write_lock_with_its_transaction_open_rolls_back_and_throws()
    {
        $level = DB::transactionLevel();
        $token = Site_Lock::_acquire(self::SITE_ID, Site_Lock::MODE_WRITE, 'rule 3 test');
        Site_Lock::_begin_transaction($token);

        static::__assert_throws(RuntimeException::class, fn () => Site_Lock::_release($token), 'rule 3');

        static::__assert_equals($level, DB::transactionLevel(), 'the transaction was rolled back');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'and the lock released');
    }

    // =============================================================================
    // Read sections
    // =============================================================================

    public static function test_a_read_inside_a_write_section_runs_under_the_write_lock()
    {
        $answer = Site_Lock::write(self::SITE_ID, fn () => Site_Lock::read(self::SITE_ID, fn () => [
            'value' => 'read',
            'write_held' => static::__holds(RsxLocks::WRITE_LOCK),
        ]));

        static::__assert_equals('read', $answer['value'], 'the read section ran');
        static::__assert_true($answer['write_held'], 'under the write lock it already held');
    }

    public static function test_a_write_inside_a_read_section_fatals()
    {
        Site_Lock::read(self::SITE_ID, function () {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Site_Lock::write(self::SITE_ID, fn () => null),
                'cannot turn into a write lock'
            );
        });

        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the read lock was released');
    }

    // =============================================================================
    // Bypass
    // =============================================================================

    public static function test_a_bypass_permits_writes_opens_no_transaction_and_closes_after()
    {
        $email = static::__email();
        $level = DB::transactionLevel();

        static::__in_window(function () use ($email, $level) {
            $inside = Site_Lock::bypass(self::SITE_ID, 'site lock test', function () use ($email) {
                static::__block($email);

                return DB::transactionLevel();
            });

            static::__assert_equals($level, $inside, 'a bypass opens no transaction');
            static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'and takes no lock');

            static::__assert_throws(
                RuntimeException::class,
                fn () => static::__block(static::__email()),
                'without site 1\'s write lock'
            );
        });

        static::__assert_true(static::__exists($email), 'the bypassed write landed');
    }

    public static function test_a_bypass_covers_only_its_site()
    {
        static::__in_window(function () {
            Site_Lock::bypass(self::OTHER_SITE_ID, 'site lock test', function () {
                static::__assert_throws(RuntimeException::class, fn () => static::__block(static::__email()), 'on site 1');
            });
        });
    }

    public static function test_a_bypass_requires_a_reason()
    {
        static::__assert_throws(RuntimeException::class, fn () => Site_Lock::bypass(self::SITE_ID, '   ', fn () => null), 'requires a reason');
        static::__assert_throws(RuntimeException::class, fn () => Site_Lock::bypass_all_sites('', fn () => null), 'requires a reason');
    }

    // =============================================================================
    // Hard rules
    // =============================================================================

    public static function test_locking_another_site_while_holding_one_fatals()
    {
        Site_Lock::write(self::SITE_ID, function () {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Site_Lock::read(self::OTHER_SITE_ID, fn () => null),
                'rule 5'
            );
        });
    }

    public static function test_a_site_lock_inside_a_foreign_transaction_fatals()
    {
        DB::beginTransaction();

        try {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Site_Lock::write(self::SITE_ID, fn () => null),
                'rule 1'
            );
            static::__assert_throws(RuntimeException::class, fn () => RsxLocks::site_read_lock(self::SITE_ID), 'rule 1');
        } finally {
            DB::rollBack();
        }

        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'nothing was acquired');
    }

    public static function test_the_harness_transaction_is_a_tolerated_baseline()
    {
        static::__assert_greater_than(0, DB::transactionLevel(), 'the harness opened its per-test transaction');

        static::__assert_equals('ran', Site_Lock::write(self::SITE_ID, fn () => 'ran'), 'a section opens inside it');
    }

    public static function test_changing_the_site_under_a_lock_or_in_a_bypass_fatals()
    {
        Site_Lock::read(self::SITE_ID, function () {
            static::__assert_throws(RuntimeException::class, fn () => Session::set_temporary_site_id(self::SITE_ID), 'refused');
            static::__assert_throws(RuntimeException::class, fn () => Session::set_site_id(self::SITE_ID), 'refused');
            static::__assert_throws(RuntimeException::class, fn () => Session::clear_temporary_site_id(), 'refused');
        });

        Site_Lock::bypass(self::SITE_ID, 'site lock test', function () {
            static::__assert_throws(RuntimeException::class, fn () => Session::set_temporary_site_id(self::SITE_ID), 'inside a Site_Lock bypass');
        });

        // Between sections it is permitted again.
        Session::set_site_id(self::SITE_ID);
        static::__assert_equals(self::SITE_ID, (int) Session::get_site_id(), 'the site is settable outside every section');
    }

    public static function test_a_request_window_refuses_a_site_change_and_a_task_window_does_not()
    {
        Site_Lock::_enforce('Site_Lock_Test handler', function () {
            static::__assert_throws(RuntimeException::class, fn () => Session::set_site_id(self::SITE_ID), 'RsxAuth::enter_site');
            static::__assert_throws(RuntimeException::class, fn () => Session::set_temporary_site_id(self::SITE_ID), 'Site_Lock_Test handler');
            static::__assert_throws(RuntimeException::class, fn () => Session::impersonate(self::SITE_ID, null, null), 'refused');
        });

        static::__in_window(function () {
            Session::set_temporary_site_id(self::SITE_ID);
            Session::clear_temporary_site_id();
        });

        static::__assert_false(Site_Lock::_in_request_window(), 'the request window closed with the handler');
    }

    // =============================================================================
    // Exempt models
    // =============================================================================

    public static function test_an_exempt_model_is_never_checked()
    {
        $exempt = new class extends Site_Lock_Exempt_Fixture_Model {
        };
        $plain = new Email_Blocked_Address_Model();

        static::__in_window(function () use ($exempt, $plain) {
            Site_Lock::_check_model_write($exempt, self::SITE_ID, 'save');

            static::__assert_throws(
                RuntimeException::class,
                fn () => Site_Lock::_check_model_write($plain, self::SITE_ID, 'save'),
                'without site 1\'s write lock'
            );
        });
    }

    public static function test_a_site_zero_row_needs_site_zeros_write_lock()
    {
        $email = static::__email();

        static::__acting_as_site(0);

        try {
            static::__in_window(function () use ($email) {
                static::__assert_throws(RuntimeException::class, fn () => static::__block($email), 'without site 0\'s write lock');
                static::__assert_false(Email_Blocked_Address_Model::where('email', $email)->exists(), 'the refused site-0 write wrote nothing');

                Site_Lock::write(0, function () use ($email) {
                    static::__assert_true(RsxLocks::holds(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . '0', RsxLocks::WRITE_LOCK), 'site 0 has a real write lock');
                    static::__block($email);
                });

                static::__assert_throws(
                    RuntimeException::class,
                    fn () => Email_Blocked_Address_Model::where('email', $email)->delete(),
                    'bulk delete on site 0'
                );
            });

            $row = Email_Blocked_Address_Model::where('email', $email)->first();
            static::__assert_not_null($row, 'the site-0 write inside Site_Lock::write(0, ...) committed');
            static::__assert_equals(0, (int) $row->site_id, 'as a row of site 0');
        } finally {
            static::__acting_as_site(self::SITE_ID);
        }

        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'nothing held after the section');
    }

    public static function test_a_request_on_site_zero_takes_site_zeros_lock()
    {
        $seen = Site_Lock::_run_request('writer', 'Site_Lock_Test writer on site 0', fn () => [
            'held' => Site_Lock::_held(),
            'level' => DB::transactionLevel(),
        ], 0);

        static::__assert_equals(0, $seen['held']['site_id'] ?? null, 'a writer with no tenant locks site 0');
        static::__assert_equals(Site_Lock::MODE_WRITE, $seen['held']['mode'] ?? null, 'for write');
        static::__assert_equals(DB::transactionLevel() + 1, $seen['level'], 'inside its own transaction');

        $seen = Site_Lock::_run_request('reader', 'Site_Lock_Test reader on site 0', fn () => Site_Lock::_held(), 0);

        static::__assert_equals([0, Site_Lock::MODE_READ], [$seen['site_id'] ?? null, $seen['mode'] ?? null], 'a reader with no tenant holds site 0\'s read lock');
        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'both released');
    }

    public static function test_an_exemption_requires_a_reason()
    {
        static::__assert_throws(RuntimeException::class, fn () => Site_Lock::_is_exempt(Site_Lock_Unreasoned_Exempt_Fixture_Model::class), 'gives no reason');
    }

    // =============================================================================
    // Task bodies
    // =============================================================================

    public static function test_a_task_body_write_without_a_section_fatals()
    {
        $email = static::__email();

        static::__assert_throws(
            RuntimeException::class,
            fn () => static::__run_task_method(Site_Lock_Fixture_Service::class, 'write_unlocked', ['email' => $email]),
            'Site_Lock_Fixture_Service::write_unlocked'
        );

        static::__assert_false(static::__exists($email), 'nothing was written');
        static::__assert_false(Site_Lock::_is_enforcing(), 'the window closed with the task');
    }

    public static function test_a_task_body_write_inside_a_section_passes()
    {
        $email = static::__email();

        static::__run_task_method(Site_Lock_Fixture_Service::class, 'write_in_section', ['email' => $email, 'site_id' => self::SITE_ID]);

        static::__assert_true(static::__exists($email), 'the sectioned write landed');
    }

    public static function test_a_transaction_a_task_left_open_is_rolled_back_and_reported()
    {
        $level = DB::transactionLevel();
        $warnings = static::__capture_warnings(
            fn () => static::__run_task_method(Site_Lock_Fixture_Service::class, 'leak_transaction')
        );

        $leak_warnings = array_values(array_filter($warnings, fn (string $line) => str_contains($line, 'still open')));

        static::__assert_equals($level, DB::transactionLevel(), 'the runner closed the leaked transaction');
        static::__assert_count(1, $leak_warnings, 'and logged one warning about it');
        static::__assert_contains('leak_transaction', $leak_warnings[0], 'naming the task');
    }

    // =============================================================================
    // Hold-time warnings
    // =============================================================================

    public static function test_a_write_held_past_its_threshold_warns()
    {
        static::__thresholds(['rsx.locks.warn_write_hold_seconds' => 0.05]);

        $warnings = static::__capture_warnings(fn () => Site_Lock::write(self::SITE_ID, fn () => usleep(150000)));

        static::__assert_count(1, $warnings, 'one hold warning');
        static::__assert_contains('WRITE lock on site 1', $warnings[0], 'naming the mode and the site');
        static::__assert_contains('waited', $warnings[0], 'and the time waited for the grant');
    }

    public static function test_a_hold_within_its_threshold_is_silent()
    {
        static::__thresholds(['rsx.locks.warn_write_hold_seconds' => 30, 'rsx.locks.warn_read_hold_seconds' => 30]);

        $warnings = static::__capture_warnings(function () {
            Site_Lock::write(self::SITE_ID, fn () => null);
            Site_Lock::read(self::SITE_ID, fn () => null);
        });

        static::__assert_count(0, $warnings, 'no warning below the thresholds');
    }

    public static function test_a_zero_threshold_disables_the_warning()
    {
        static::__thresholds(['rsx.locks.warn_read_hold_seconds' => 0]);

        $warnings = static::__capture_warnings(fn () => Site_Lock::read(self::SITE_ID, fn () => usleep(50000)));

        static::__assert_count(0, $warnings, '0 disables the read hold warning');
    }

    // =============================================================================
    // No upgrade: one wording everywhere
    // =============================================================================

    public static function test_every_no_upgrade_refusal_says_take_the_write_lock_first()
    {
        $refusals = [];

        $refusals['raw site_write_lock under a raw read'] = static::__refusal(function () {
            $read = RsxLocks::site_read_lock(self::SITE_ID);

            try {
                RsxLocks::site_write_lock(self::SITE_ID);
            } finally {
                RsxLocks::release_lock($read);
            }
        });

        $refusals['Site_Lock::write() inside Site_Lock::read()'] = static::__refusal(
            fn () => Site_Lock::read(self::SITE_ID, fn () => Site_Lock::write(self::SITE_ID, fn () => null))
        );

        $refusals['RsxLocks::upgrade_lock() of a site read'] = static::__refusal(function () {
            $read = RsxLocks::site_read_lock(self::SITE_ID);

            try {
                RsxLocks::upgrade_lock($read);
            } finally {
                RsxLocks::release_lock($read);
            }
        });

        foreach ($refusals as $where => $message) {
            static::__assert_contains('cannot turn into a write lock without being released first', $message, "{$where}: says a read lock is never upgraded");
            static::__assert_contains('deadlock', $message, "{$where}: says why");
            static::__assert_contains('the READ part of the algorithm must be inside the write lock', $message, "{$where}: names the remedy");
            static::__assert_contains('A read inside a write section needs no separate lock', $message, "{$where}: and that the read needs no lock of its own");
        }

        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'nothing held after the refusals');
    }

    // =============================================================================
    // Nesting is counted (raw API, closure API, and the two mixed)
    // =============================================================================

    public static function test_nested_raw_read_locks_need_as_many_releases()
    {
        $first = RsxLocks::site_read_lock(self::SITE_ID);
        $second = RsxLocks::site_read_lock(self::SITE_ID);
        $third = RsxLocks::site_read_lock(self::SITE_ID);

        static::__assert_equals(3, static::__count(RsxLocks::READ_LOCK), 'three acquisitions, one hold counted three times');

        RsxLocks::release_lock($third);
        RsxLocks::release_lock($second);

        static::__assert_equals(1, static::__count(RsxLocks::READ_LOCK), 'two releases leave one');
        static::__assert_true(static::__holds(RsxLocks::READ_LOCK), 'the real read lock is still held');

        RsxLocks::release_lock($first);

        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the last release lets it go');
    }

    public static function test_nested_raw_write_locks_need_as_many_releases()
    {
        $outer = RsxLocks::site_write_lock(self::SITE_ID);
        $inner = RsxLocks::site_write_lock(self::SITE_ID);

        static::__assert_equals(2, static::__count(RsxLocks::WRITE_LOCK), 'two acquisitions counted');

        RsxLocks::release_lock($inner);

        static::__assert_equals(1, static::__count(RsxLocks::WRITE_LOCK), 'one release leaves one');
        static::__assert_true(static::__holds(RsxLocks::WRITE_LOCK), 'the real write lock is still held');

        RsxLocks::release_lock($outer);

        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the last release lets it go');
    }

    public static function test_a_raw_read_inside_a_raw_write_nests_on_the_write_hold()
    {
        $write = RsxLocks::site_write_lock(self::SITE_ID);
        $read = RsxLocks::site_read_lock(self::SITE_ID);

        static::__assert_equals($write, $read, 'the read is granted as a nested hold of the WRITE lock');
        static::__assert_equals(2, static::__count(RsxLocks::WRITE_LOCK), 'counted on the write hold');
        static::__assert_equals(0, static::__count(RsxLocks::READ_LOCK), 'no separate read hold exists');

        RsxLocks::release_lock($read);

        static::__assert_true(static::__holds(RsxLocks::WRITE_LOCK), 'releasing the read does not release the write lock');
        static::__assert_equals(1, static::__count(RsxLocks::WRITE_LOCK));

        RsxLocks::release_lock($write);

        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'the write release lets it go');
    }

    public static function test_a_child_function_releasing_its_own_hold_never_releases_the_callers()
    {
        $child = function (string $type): void {
            $token = $type === RsxLocks::WRITE_LOCK ? RsxLocks::site_write_lock(self::SITE_ID) : RsxLocks::site_read_lock(self::SITE_ID);
            RsxLocks::release_lock($token);
        };

        $caller_read = RsxLocks::site_read_lock(self::SITE_ID);
        $child(RsxLocks::READ_LOCK);
        static::__assert_equals(1, static::__count(RsxLocks::READ_LOCK), 'a child read under the caller\'s read leaves the caller\'s hold');
        RsxLocks::release_lock($caller_read);

        $caller_write = RsxLocks::site_write_lock(self::SITE_ID);
        $child(RsxLocks::WRITE_LOCK);
        $child(RsxLocks::READ_LOCK);
        static::__assert_equals(1, static::__count(RsxLocks::WRITE_LOCK), 'child write and read under the caller\'s write leave the caller\'s hold');
        RsxLocks::release_lock($caller_write);

        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'nothing held after the callers release');
    }

    public static function test_nested_closure_sections_count_and_unwind()
    {
        $seen = Site_Lock::read(self::SITE_ID, function () {
            $inner = Site_Lock::read(self::SITE_ID, fn () => static::__count(RsxLocks::READ_LOCK));

            return ['inner' => $inner, 'after_inner' => static::__count(RsxLocks::READ_LOCK)];
        });

        static::__assert_equals(1, $seen['inner'], 'a read section inside a read section runs under the one hold');
        static::__assert_equals(1, $seen['after_inner'], 'and leaves the outer read held');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'released once the outer section ends');

        $seen = Site_Lock::write(self::SITE_ID, function () {
            $level = DB::transactionLevel();
            $inner_write = Site_Lock::write(self::SITE_ID, fn () => static::__count(RsxLocks::WRITE_LOCK));
            $inner_read = Site_Lock::read(self::SITE_ID, fn () => static::__holds(RsxLocks::WRITE_LOCK));

            return [
                'inner_write' => $inner_write,
                'inner_read' => $inner_read,
                'after' => static::__count(RsxLocks::WRITE_LOCK),
                'transaction_kept' => DB::transactionLevel() === $level,
            ];
        });

        static::__assert_equals(1, $seen['inner_write'], 'a write section inside a write section runs under the one hold');
        static::__assert_true($seen['inner_read'], 'a read section inside a write section runs under the write hold');
        static::__assert_equals(1, $seen['after'], 'neither inner section released the write lock');
        static::__assert_true($seen['transaction_kept'], 'nor touched the section\'s transaction');
        static::__assert_false(static::__holds(RsxLocks::READ_LOCK), 'released once the outer section ends');
    }

    public static function test_raw_holds_inside_a_write_section_leave_the_section_holding()
    {
        $email = static::__email();

        static::__in_window(function () use ($email) {
            Site_Lock::write(self::SITE_ID, function () use ($email) {
                // A library taking and releasing the raw locks inside the section.
                RsxLocks::release_lock(RsxLocks::site_write_lock(self::SITE_ID));
                RsxLocks::release_lock(RsxLocks::site_read_lock(self::SITE_ID));

                static::__assert_equals(1, static::__count(RsxLocks::WRITE_LOCK), 'the section still holds its write lock');

                // ...so a write after the library returns still has write authority.
                static::__block($email);
            });
        });

        static::__assert_true(static::__exists($email), 'the section wrote and committed');
        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'nothing held after the section');
    }

    public static function test_a_closure_section_inside_a_raw_hold_leaves_the_raw_hold()
    {
        $raw = RsxLocks::site_read_lock(self::SITE_ID);

        Site_Lock::read(self::SITE_ID, fn () => null);

        static::__assert_equals(1, static::__count(RsxLocks::READ_LOCK), 'the read section did not release the raw read');

        RsxLocks::release_lock($raw);

        $raw = RsxLocks::site_write_lock(self::SITE_ID);

        Site_Lock::write(self::SITE_ID, fn () => null);
        Site_Lock::read(self::SITE_ID, fn () => null);

        static::__assert_equals(1, static::__count(RsxLocks::WRITE_LOCK), 'neither section released the raw write');

        RsxLocks::release_lock($raw);

        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'nothing held after the raw release');
    }

    // =============================================================================
    // Application hooks (Site_Lock::_run_hook()): unlocked, enforced, clean hand-over
    // =============================================================================

    public static function test_a_hook_runs_unlocked_inside_an_enforcement_window()
    {
        $seen = Site_Lock::_run_hook('Site_Lock_Test::pre_dispatch()', fn () => [
            'enforcing' => Site_Lock::_is_enforcing(),
            'holds' => RsxLocks::site_lock_hold_counts(),
            'held' => Site_Lock::_held(),
        ]);

        static::__assert_true($seen['enforcing'], 'the hook runs inside an enforcement window');
        static::__assert_equals([], $seen['holds'], 'with no site lock');
        static::__assert_null($seen['held'], 'and no boundary lock');
        static::__assert_false(Site_Lock::_is_enforcing(), 'the window closes with the hook');
    }

    public static function test_an_unlocked_write_in_a_hook_fatals_and_a_section_bypass_or_exempt_model_passes()
    {
        $email = static::__email();

        $e = static::__assert_throws(
            RuntimeException::class,
            fn () => Site_Lock::_run_hook('Site_Lock_Test::pre_dispatch()', fn () => static::__block($email)),
            'without site 1\'s write lock'
        );
        static::__assert_contains('Site_Lock_Test::pre_dispatch()', $e->getMessage(), 'the refusal names the hook');
        static::__assert_false(static::__exists($email), 'the refused write wrote nothing');

        $section = static::__email();
        Site_Lock::_run_hook('Site_Lock_Test::pre_dispatch()', fn () => Site_Lock::write(self::SITE_ID, fn () => static::__block($section)));
        static::__assert_true(static::__exists($section), 'a write section in a hook writes');

        $bypassed = static::__email();
        Site_Lock::_run_hook('Site_Lock_Test::init()', fn () => Site_Lock::bypass(self::SITE_ID, 'site lock test bookkeeping', fn () => static::__block($bypassed)));
        static::__assert_true(static::__exists($bypassed), 'a bypass in a hook writes');

        $exempt = new class extends Site_Lock_Exempt_Fixture_Model {
        };
        Site_Lock::_run_hook('Site_Lock_Test::pre_dispatch()', fn () => Site_Lock::_check_model_write($exempt, self::SITE_ID, 'save'));
        static::__assert_true(true, 'an exempt model passes the write check in a hook with no lock');

        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'every hook handed over holding nothing');
    }

    public static function test_a_hook_that_leaks_a_site_lock_fails_and_the_lock_is_released()
    {
        $e = static::__assert_throws(
            RuntimeException::class,
            fn () => Site_Lock::_run_hook('Site_Lock_Test::pre_dispatch()', function () {
                RsxLocks::site_write_lock(self::SITE_ID);
                RsxLocks::site_write_lock(self::SITE_ID);
            }),
            'Site_Lock_Test::pre_dispatch() returned still holding the WRITE lock on site 1 (2 holds)'
        );

        static::__assert_contains('must hand over clean', $e->getMessage());
        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'both leaked holds were released');
    }

    public static function test_a_hook_that_leaks_a_transaction_fails_and_it_is_rolled_back()
    {
        $email = static::__email();
        $level = DB::transactionLevel();

        static::__assert_throws(
            RuntimeException::class,
            fn () => Site_Lock::_run_hook('Site_Lock_Test::init()', function () use ($email) {
                DB::beginTransaction();
                static::__block_unchecked($email);
            }),
            'database transaction(s) still open'
        );

        static::__assert_equals($level, DB::transactionLevel(), 'the leaked transaction was rolled back');
        static::__assert_false(static::__exists($email), 'and what it wrote with it');
    }

    public static function test_a_hook_that_throws_keeps_its_exception_and_is_cleaned_up()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => Site_Lock::_run_hook('Site_Lock_Test::pre_dispatch()', function () {
                RsxLocks::site_read_lock(self::SITE_ID);

                throw new RuntimeException('the hook denied the request');
            }),
            'the hook denied the request'
        );

        static::__assert_equals([], RsxLocks::site_lock_hold_counts(), 'the leaked hold was released anyway');
        static::__assert_false(Site_Lock::_is_enforcing(), 'and the window closed');
    }

    public static function test_a_hook_running_under_an_outer_hold_hands_back_exactly_that_hold()
    {
        Site_Lock::write(self::SITE_ID, function () {
            Site_Lock::_run_hook('Site_Lock_Test::pre_dispatch()', function () {
                RsxLocks::release_lock(RsxLocks::site_write_lock(self::SITE_ID));
                Site_Lock::read(self::SITE_ID, fn () => null);
            });

            static::__assert_equals(1, static::__count(RsxLocks::WRITE_LOCK), 'the outer section\'s hold is intact after a balanced hook');
        });
    }

    public static function teardown()
    {
        config(static::$configured_thresholds);

        foreach ([0, self::SITE_ID, self::OTHER_SITE_ID] as $site_id) {
            RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . $site_id);
        }
    }

    // =============================================================================
    // Helpers
    // =============================================================================

    /**
     * Set hold-time thresholds for one test: every key starts from its configured value, so
     * a threshold one test lowered never reaches the next.
     */
    private static function __thresholds(array $values): void
    {
        config(array_merge(static::$configured_thresholds, $values));
    }

    private static function __in_window(callable $work): void
    {
        Site_Lock::_enter_enforcement('Site_Lock_Test window');

        try {
            $work();
        } finally {
            Site_Lock::_leave_enforcement();
        }
    }

    private static function __email(): string
    {
        return 'site-lock-' . bin2hex(random_bytes(6)) . '@test.rspade.test';
    }

    private static function __block(string $email): void
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'site lock test';
        $row->save();
    }

    private static function __exists(string $email): bool
    {
        return Email_Blocked_Address_Model::where('email', $email)->exists();
    }

    private static function __holds(string $type): bool
    {
        return RsxLocks::holds(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . self::SITE_ID, $type);
    }

    /** How many acquisitions this process holds of the test site's $type lock identity. */
    private static function __count(string $type): int
    {
        return RsxLocks::site_lock_hold_counts()[self::SITE_ID . ':' . $type]['count'] ?? 0;
    }

    /** The message of the RuntimeException $work throws (the test fails if it throws none). */
    private static function __refusal(callable $work): string
    {
        return static::__assert_throws(RuntimeException::class, $work)->getMessage();
    }

    /** A write with enforcement suspended: the row a leaked transaction carries. */
    private static function __block_unchecked(string $email): void
    {
        Site_Lock::bypass(self::SITE_ID, 'site lock test fixture', fn () => static::__block($email));
    }

    /**
     * The WARNING lines logged while $work ran.
     *
     * @return string[]
     */
    private static function __capture_warnings(callable $work): array
    {
        $warnings = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });

        try {
            $work();
        } finally {
            Event::forget(MessageLogged::class);
        }

        return $warnings;
    }
}
