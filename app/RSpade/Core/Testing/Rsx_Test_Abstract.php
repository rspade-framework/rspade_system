<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Testing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Detached_Processes;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;

/**
 * Rsx_Test_Abstract - Base class for RSX framework tests
 *
 * Provides simple test framework in the RSX spirit - no complex dependencies,
 * just straightforward testing with clear pass/fail results.
 *
 * Assertion / control / context methods are all prefixed with a double
 * underscore (framework-internal convention) and are protected. Test methods
 * call them via static::__assert_*(), static::__acting_as_*(), etc.
 *
 * Each test_* method is wrapped in a database transaction that is rolled back
 * when the method returns (see $use_database_transactions). setup() and
 * teardown() run OUTSIDE the per-test transaction, so fixtures created there
 * persist across the tests in the class. The test runner (rsx:test) swaps the
 * default connection to the test database for the whole run, so the developer
 * database is never touched.
 */
abstract class Rsx_Test_Abstract
{
    /**
     * Test results storage
     */
    protected static $results = [];
    protected static $current_test = null;

    /**
     * Whether to wrap each test_* method in a database transaction that is
     * rolled back afterwards. Set to false in a child class to opt out (for
     * tests that must commit, or that manage their own transactions).
     * @var bool
     */
    protected static $use_database_transactions = true;

    /**
     * When true, the test runner re-provisions the test database to a clean,
     * freshly-migrated baseline ONCE before this class runs (a "blank slate").
     *
     * Use for tests that exercise the build/migration procedure, or that commit
     * data they then read back. Such classes should normally also set
     * $use_database_transactions = false (they commit, so per-test rollback
     * isolation does not apply). The runner restores the clean baseline before
     * the next class, so transaction-based classes are never affected by a
     * reset class that committed data.
     * @var bool
     */
    protected static $requires_db_reset = false;

    /**
     * When true, this class runs ONLY when its group is named with --group, or when the
     * class itself is named as a specific test. A bare suite run - and every --filter over
     * one - skips it, and the runner says so rather than passing over it silently.
     *
     * FOR A CLASS THAT MUTATES THE BOX, not merely the test database. Everything else in
     * the suite is isolated by construction: a transaction that rolls back, a container
     * with its own database, a path override pointing the file subsystem at a scratch
     * tree. A class that switches RSX_MODE, rebuilds the real build tree or changes the
     * permissions of the source trees has none of that isolation - it acts on the one
     * shared thing the whole run depends on, and its cost is measured in minutes rather
     * than seconds. Opting it out of the default run is what makes both facts survivable:
     * the operator asks for it deliberately, knowing the box is theirs for the duration.
     *
     * It is NOT a way to park a slow or a flaky test. A test nobody ever names is a test
     * nobody runs.
     * @var bool
     */
    protected static $explicit_group_only = false;

    /**
     * When true, every test method in this class runs ACTING AS A BROWSER: a fresh browser
     * row is inserted (inside the test's transaction) and the process acts as it, through
     * __as_browser(). For a class whose subject belongs to a browser - session values, a
     * second-factor challenge parked between two calls, View as Client - because the test
     * process otherwise runs a virtual session, which has no browser and refuses
     * Session::put_value().
     *
     * @var bool
     */
    protected static $acts_as_browser = false;

    /**
     * The internal flag that marks a process as PART OF A TEST RUN: rsx:test declares it
     * on itself - in system/artisan, PRE-BOOT, because Manifest::init() runs during boot and
     * what it indexes depends on the answer - and Rsx_Artisan forwards it to every child it
     * spawns, so a migrate or a command a test runs in another mode is still recognisably
     * under the suite. Read it through suite_is_running().
     */
    public const TEST_RUN_FLAG = '--_test-run';

    /**
     * Is this process the test suite, or a descendant of it?
     *
     * TWO things consult it, and neither is security-relevant.
     *
     * 1. WHAT THE MANIFEST INDEXES. The test trees - app/RSpade/tests, app/RSpade/temp and
     *    rsx/tests - are scanned only under a test run (Manifest::scan_directories()). A
     *    fixture is real indexed source: a route, an Ajax surface, an #[Auth] naming a check.
     *    A served site must not carry one.
     * 2. An operator guardrail that would otherwise stop a test from running in the mode it
     *    is testing: APP_URL must be https outside development because an end user must not
     *    launch a production SITE over http - but a test-spawned debug-mode migrate on an
     *    http box is not a site launch.
     *
     * The web entrypoint never carries argv, so a served request is never "under test".
     */
    public static function suite_is_running(): bool
    {
        return \App\RSpade\Core\Console\Rsx_Internal_Flags::has(self::TEST_RUN_FLAG);
    }

    /**
     * The staff site id the test process booted with (see run()); restored at every
     * class boundary so one class's __reset_session() cannot re-scope the classes that follow.
     *
     * @var int|null
     */
    private static $__boot_site_id = null;

    /**
     * The CLI IDENTITY the test process booted with, captured beside $__boot_site_id and
     * restored with it at every class boundary.
     *
     * @var int|null
     */
    private static $__boot_login_user_id = null;

    /** @var int|null */
    private static $__boot_user_id = null;

    /** @var int How many run() calls are on the stack (a test may call run() on another class). */
    private static $__run_depth = 0;

    /** @var bool True once the boot context above has been captured. */
    private static $__boot_captured = false;

    /**
     * The path-owner override set the test process booted with (see run()).
     *
     * The run's FILE-SUBSYSTEM ISOLATION is one of these overrides: Rsx_Test_Command points
     * Rsx_Project_Paths' files root at tmp/test-storage for the whole run, so a test-database
     * attachment delete can never unlink a developer blob (backlog B-38). A class that
     * redirects a root of its own and ends with a bare _clear_overrides() drops that
     * isolation for every class that runs after it in the same process - the same shape of
     * leak the boot session context has, and restored in the same place.
     *
     * @var array<string,string>
     */
    private static $__boot_path_overrides = [];

    /**
     * Runner-facing accessor for the per-class reset flag.
     * @return bool
     */
    public static function requires_db_reset(): bool
    {
        return static::$requires_db_reset;
    }

    /**
     * Runner-facing accessor for the per-class explicit-selection flag.
     * @return bool
     */
    public static function explicit_group_only(): bool
    {
        return static::$explicit_group_only;
    }

    /**
     * Initialize the test class
     * Called before running tests
     */
    #[Replaceable]
    public static function setup()
    {
        // Override in child classes for test setup
    }
    
    /**
     * Clean up after tests
     * Called after all tests complete
     */
    #[Replaceable]
    public static function teardown()
    {
        // Override in child classes for cleanup
    }
    
    /**
     * Run all test methods in this class
     *
     * $results / $current_test are declared on THIS abstract base, so late static binding
     * gives every subclass the SAME storage. A test that runs another test class inside one
     * of its test_* methods would therefore wipe the caller's results. Both statics are
     * saved on entry and restored on exit so a nested run() is fully isolated; the helpers
     * that record into them (__pass/__fail/__skip) keep working unchanged.
     *
     * @return array Test results
     */
    public static function run()
    {
        $outer_results = static::$results;
        $outer_current_test = static::$current_test;

        static::$results = [];
        static::$current_test = null;

        // The staff site this process BOOTED with. The template's Main::init() declares the
        // tenant (rsx/main.php: Session::set_site_id(1)) before any test runs, so every
        // site-scoped query in the suite is answered against that site - until a test calls
        // __reset_session(), which nulls the CLI override and leaves get_site_id() at 0 for
        // every class that runs after it in the same process. Site-1 rows then vanish from
        // queries that never asked for a site at all (Preview_Sample_Import_Test after the
        // Portal_Session classes). Remember the boot site once, restore it between classes.
        if (!self::$__boot_captured) {
            self::$__boot_captured = true;
            self::$__boot_site_id = (int) Session::get_site_id();
            self::$__boot_login_user_id = Session::get_login_user_id();
            self::$__boot_user_id = Session::get_user_id();
            self::$__boot_path_overrides = Rsx_Project_Paths::_overrides();
        }

        self::$__run_depth++;

        try {
            return static::_run_tests();
        } finally {
            self::$__run_depth--;
            static::$results = $outer_results;
            static::$current_test = $outer_current_test;

            try {
                // A detached process this class started (a task worker) ends HERE, not during
                // the next class: the boundary waits until every one has exited. Only at the
                // outermost run() - a nested run() is part of the calling test, whose own class
                // boundary contains everything that test started.
                if (self::$__run_depth === 0) {
                    Rsx_Test_Detached_Processes::contain();
                }
            } finally {
                static::__restore_class_boundary();
            }
        }
    }

    /**
     * The process-global state a class may have changed, put back at its boundary.
     */
    private static function __restore_class_boundary(): void
    {
        // A CLASS boundary is always the boot CONTEXT - site AND identity. Deliberately
        // NOT per test: a class may create its own site in setup() (once per class) and
        // scope every test to it - Realtime_User_Refresh_Test does exactly that - so
        // restoring between tests would silently re-scope the class's own fixtures out
        // of view. Between classes there is no such expectation, and that is where the
        // leak did damage.
        static::__restore_boot_context();

        // The path overrides are process-global and belong to the RUN. A class may
        // redirect a root inside its own body; between classes the run's set is what
        // holds, file-subsystem isolation included.
        Rsx_Project_Paths::_restore_overrides(self::$__boot_path_overrides);

        // Task::spawn_workers(true) is a per-CLASS opt-in; the next class starts from
        // enqueue-only again.
        \App\RSpade\Core\Task\Task::spawn_workers(false);
    }

    /**
     * Re-establish the staff site AND CLI identity the process booted with (see run()).
     *
     * WHY THE IDENTITY IS RESTORED TOO. Session::_testing_reset() runs after every test
     * and deliberately PRESERVES the CLI declaration ($_cli_site_id / $_cli_login_user_id
     * / $_cli_user_id), because setup() runs once per CLASS and is where a suite declares
     * it - clearing it per test would land every later fixture on site 0. That contract is
     * right within a class and wrong between them: nothing else ever cleared the identity,
     * so a class that called __acting_as_user() handed its user to every class that ran
     * after it in the same process. A later class asserting an ANONYMOUS caller then saw
     * somebody signed in, and the failure moved between classes as container scheduling
     * changed (Realtime_Token_Test::test_connection_token_returns_valid_signed_shape_when_anonymous).
     *
     * A no-op when the context already matches, and when nothing was ever captured.
     */
    private static function __restore_boot_context(): void
    {
        if (!self::$__boot_captured) {
            return;
        }

        $site_matches = (int) Session::get_site_id() === (int) self::$__boot_site_id;
        $login_matches = Session::get_login_user_id() === self::$__boot_login_user_id;
        $user_matches = Session::get_user_id() === self::$__boot_user_id;

        if ($site_matches && $login_matches && $user_matches) {
            return;
        }

        // One call sets all three - declaring a context, minting nothing.
        Session::impersonate(
            (int) self::$__boot_site_id,
            self::$__boot_login_user_id,
            self::$__boot_user_id
        );
    }

    /**
     * The run() body, split out so run() can own the save/restore of the shared statics.
     *
     * @return array Test results
     */
    private static function _run_tests()
    {
        // setup() starts at the same request boundary every test method ends at. The runner
        // re-provisions the baseline between a $requires_db_reset class and the next one, and
        // Rsx_Initial_User::create() leaves a script-scoped tenant declaration
        // (Session::set_temporary_site_id()) standing. That declaration outranks the CLI
        // tier, so a setup() that called __acting_as_site() for a site of its own still saved
        // its fixtures into the baseline site, and the class failed only when it ran after a
        // re-provision (Realtime_User_Refresh_Test). The CLI identity declaration survives
        // this reset, as it does between test methods. Outermost run() only: a nested run()
        // is part of the calling test, whose request is still in progress.
        if (self::$__run_depth === 1) {
            Session::_testing_reset();
        }

        // Call setup
        static::setup();

        // Get all methods starting with 'test_'
        $reflection = new \ReflectionClass(static::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        
        foreach ($methods as $method) {
            if (strpos($method->getName(), 'test_') === 0) {
                static::$current_test = $method->getName();

                // One test is one unit of work for revision history. The runner is a single
                // process for the whole suite, so without this every revision any test
                // recorded would be filed under the first one's transaction.
                \App\RSpade\Core\Revisions\Revision::_reset_request_state('test', static::class . '::' . $method->getName());

                $use_transaction = static::$use_database_transactions;

                if ($use_transaction) {
                    DB::beginTransaction();
                }

                try {
                    if (static::$acts_as_browser) {
                        static::__as_browser();
                    }

                    // Run the test
                    $method->invoke(null);

                    // If we got here without an exception, test passed
                    if (!isset(static::$results[static::$current_test])) {
                        static::$results[static::$current_test] = [
                            'status' => 'passed',
                            'message' => 'Test completed successfully',
                        ];
                    }
                } catch (\Throwable $e) {
                    // __skip() throws a sentinel exception - record as skipped, not failed
                    if (strpos($e->getMessage(), '__SKIP__:') === 0) {
                        static::$results[static::$current_test] = [
                            'status' => 'skipped',
                            'message' => substr($e->getMessage(), strlen('__SKIP__:')),
                        ];
                    } else {
                        static::$results[static::$current_test] = [
                            'status' => 'failed',
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ];
                    }
                } finally {
                    // Roll back the per-test transaction so each test is isolated.
                    // Rolled back even on failure - never leave a dangling transaction.
                    if ($use_transaction) {
                        DB::rollBack();
                    }

                    // Return Session to virgin, pre-init state: the browser the test acted
                    // as, the API identity tier, the loader flags and every resolved
                    // cache. A test is one request; this is the request boundary a web server
                    // would have provided by ending the process. None of it is a DB row, so
                    // the rollback above cannot undo it. The CLI identity DECLARATION
                    // deliberately survives - it belongs to the class, not to the test.
                    Session::_testing_reset();

                    // Clear the portal facade's process-global CLI state (declared site,
                    // portal user, impersonator). It is a static, not a DB row, so the
                    // transaction rollback above does not touch it and a declaration made
                    // by one test would otherwise be borrowed by every later test - in
                    // this class AND in every class that runs after it. Each test declares
                    // its own portal site (setup() runs once per CLASS, so a declaration
                    // there would not survive this reset).
                    Portal_Session::_testing_reset();

                    // Forget which identities passed a second factor "in this request": a
                    // test is a request, and a verification left standing would let a later
                    // test mint browser trust without one.
                    \App\RSpade\Core\TwoFactor\Rsx_Two_Factor::_reset_request_state();

                    // Clear the request classification (channel and staff/portal realm). A
                    // test that declared a portal request and threw before restoring it
                    // would otherwise leave every later model write stamped with the
                    // portal's site, in this class and in every class after it.
                    \App\RSpade\Core\Portal\Rsx_Portal::_clear_cache();

                    // Clear the Turnstile per-request validation latch so a controller
                    // call in one test cannot satisfy the completeness guard for the next.
                    Rsx_Turnstile::_reset_request_state();

                    // Clear every revision static, suppression included: a test that threw
                    // inside Revision::without() must not leave recording off for the next.
                    \App\RSpade\Core\Revisions\Revision::_testing_reset();

                    // Forget a browser timezone cookie a test stood in for, and the zone
                    // resolved from it: the next test is another request, from no browser.
                    \App\RSpade\Core\Time\Rsx_Time::_testing_reset();
                }
            }
        }

        // Call teardown
        static::teardown();

        return static::$results;
    }
    
    /**
     * Assert that a condition is true
     * 
     * @param bool $condition
     * @param string $message
     */
    protected static function __assert_true($condition, $message = 'Assertion failed')
    {
        if (!$condition) {
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that a condition is false
     * 
     * @param bool $condition
     * @param string $message
     */
    protected static function __assert_false($condition, $message = 'Assertion failed')
    {
        static::__assert_true(!$condition, $message);
    }
    
    /**
     * Assert that two values are equal
     * 
     * @param mixed $expected
     * @param mixed $actual
     * @param string $message
     */
    protected static function __assert_equals($expected, $actual, $message = null)
    {
        if ($expected !== $actual) {
            $message = $message ?: "Expected '" . var_export($expected, true) . 
                                   "' but got '" . var_export($actual, true) . "'";
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that two values are not equal
     * 
     * @param mixed $expected
     * @param mixed $actual
     * @param string $message
     */
    protected static function __assert_not_equals($expected, $actual, $message = null)
    {
        if ($expected === $actual) {
            $message = $message ?: "Values should not be equal: '" . var_export($expected, true) . "'";
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that a string contains a substring
     * 
     * @param string $needle
     * @param string $haystack
     * @param string $message
     */
    protected static function __assert_contains($needle, $haystack, $message = null)
    {
        if (strpos($haystack, $needle) === false) {
            $message = $message ?: "String does not contain '{$needle}'";
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that an array has a key
     * 
     * @param string $key
     * @param array $array
     * @param string $message
     */
    protected static function __assert_array_has_key($key, $array, $message = null)
    {
        if (!array_key_exists($key, $array)) {
            $message = $message ?: "Array does not have key '{$key}'";
            throw new \Exception($message);
        }
    }
    
    /**
     * Assert that a value is null
     * 
     * @param mixed $value
     * @param string $message
     */
    protected static function __assert_null($value, $message = 'Value should be null')
    {
        static::__assert_true($value === null, $message);
    }
    
    /**
     * Assert that a value is not null
     * 
     * @param mixed $value
     * @param string $message
     */
    protected static function __assert_not_null($value, $message = 'Value should not be null')
    {
        static::__assert_true($value !== null, $message);
    }
    
    /**
     * Assert that calling $fn throws an exception of the given class.
     *
     * The runner marks a test passed when it does NOT throw, so there is no way
     * to assert "this should throw" without capturing the throw locally. This
     * method does exactly that: it catches \Throwable (so Errors and custom
     * exceptions are both covered), verifies the class and optional message
     * substring, and returns the caught exception for further inspection.
     *
     * @param string $exception_class Expected class (or parent class) of the throwable
     * @param callable $fn Callable that is expected to throw
     * @param string|null $message_contains Optional substring the message must contain
     * @return \Throwable The caught exception
     */
    protected static function __assert_throws(string $exception_class, callable $fn, ?string $message_contains = null): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!($e instanceof $exception_class)) {
                throw new \Exception(
                    "Expected exception of type '{$exception_class}' but got '" . get_class($e) .
                    "' with message: " . $e->getMessage()
                );
            }

            if ($message_contains !== null && strpos($e->getMessage(), $message_contains) === false) {
                throw new \Exception(
                    "Exception message '" . $e->getMessage() . "' does not contain '{$message_contains}'"
                );
            }

            return $e;
        }

        throw new \Exception("Expected exception of type '{$exception_class}' but none was thrown");
    }

    /**
     * Assert that a countable (array or Countable) has the expected count
     *
     * @param int $expected
     * @param array|\Countable $countable
     * @param string|null $message
     */
    protected static function __assert_count($expected, $countable, $message = null)
    {
        if (!is_array($countable) && !($countable instanceof \Countable)) {
            throw new \Exception($message ?: 'Value passed to __assert_count is not countable');
        }

        $actual = count($countable);
        if ($actual !== $expected) {
            throw new \Exception($message ?: "Expected count {$expected} but got {$actual}");
        }
    }

    /**
     * Assert that $actual is strictly greater than $threshold
     *
     * @param int|float $threshold
     * @param int|float $actual
     * @param string|null $message
     */
    protected static function __assert_greater_than($threshold, $actual, $message = null)
    {
        if (!($actual > $threshold)) {
            throw new \Exception($message ?: "Expected value greater than {$threshold} but got {$actual}");
        }
    }

    /**
     * Assert that $actual is strictly less than $threshold
     *
     * @param int|float $threshold
     * @param int|float $actual
     * @param string|null $message
     */
    protected static function __assert_less_than($threshold, $actual, $message = null)
    {
        if (!($actual < $threshold)) {
            throw new \Exception($message ?: "Expected value less than {$threshold} but got {$actual}");
        }
    }

    /**
     * Assert that $object is an instance of $class
     *
     * @param string $class
     * @param mixed $object
     * @param string|null $message
     */
    protected static function __assert_instance_of(string $class, $object, $message = null)
    {
        if (!($object instanceof $class)) {
            $actual = is_object($object) ? get_class($object) : gettype($object);
            throw new \Exception($message ?: "Expected instance of '{$class}' but got '{$actual}'");
        }
    }

    /**
     * Assert that a value is empty (null, false, 0, '', '0', empty array)
     *
     * @param mixed $value
     * @param string|null $message
     */
    protected static function __assert_empty($value, $message = null)
    {
        if (!empty($value)) {
            throw new \Exception($message ?: 'Value should be empty: ' . var_export($value, true));
        }
    }

    /**
     * Assert that a value is not empty
     *
     * @param mixed $value
     * @param string|null $message
     */
    protected static function __assert_not_empty($value, $message = null)
    {
        if (empty($value)) {
            throw new \Exception($message ?: 'Value should not be empty');
        }
    }

    /**
     * Assert that two floats are equal within a tolerance (epsilon)
     *
     * @param float $expected
     * @param float $actual
     * @param float $epsilon Maximum allowed absolute difference
     * @param string|null $message
     */
    protected static function __assert_equals_approx($expected, $actual, $epsilon = 0.005, $message = null)
    {
        if (abs($expected - $actual) > $epsilon) {
            throw new \Exception(
                $message ?: "Expected {$expected} (+/- {$epsilon}) but got {$actual}"
            );
        }
    }

    // =========================================================================
    // SESSION / SITE / USER CONTEXT (headless impersonation)
    //
    // A test process runs a VIRTUAL session: no browser, no session row, and
    // Session::get_session_id() answers null. Site-scoped models read
    // Session::get_site_id() / get_user() and audit code reads Session::get_user_id(),
    // so these helpers declare the context as static overrides, writing nothing.
    // __reset_session() clears them. A test whose subject belongs to a browser acts as
    // one: __as_browser(), or $acts_as_browser for the whole class.
    // =========================================================================

    /**
     * Act as a BROWSER for the rest of this test: insert a browser row (no sign-in on it)
     * and make Session::get_session_id(), has_session(), the session values and
     * get_session() answer it. Identity is still the virtual session's - declare it with
     * __acting_as_user() as usual. Call it again for a second, different browser.
     *
     * @param array $options __insert_session_fixture()'s options
     * @return int The browser row's id
     */
    protected static function __as_browser(array $options = []): int
    {
        $session_id = static::__insert_session_fixture($options)['id'];

        Session::$_browser_session_id_for_tests = $session_id;

        return $session_id;
    }

    /**
     * Impersonate a site for the current test. Session::get_site_id() (and the
     * site scope on site-scoped models) will return this site id.
     *
     * @param int $site_id
     */
    protected static function __acting_as_site(int $site_id)
    {
        Session::set_site_id($site_id);
    }

    /**
     * Impersonate a site-specific user for the current test. Resolves the
     * User_Model (bypassing the site scope), then sets the site, login identity
     * and user id so every Session getter is coherent and audit attribution
     * (Session::get_user_id()) returns this user.
     *
     * @param int $user_id users.id (site-specific user)
     */
    protected static function __acting_as_user(int $user_id)
    {
        $user = User_Model::without_site_scope(function () use ($user_id) {
            return User_Model::find($user_id);
        });

        if (!$user) {
            shouldnt_happen("__acting_as_user: no User_Model with id {$user_id} in the test database");
        }

        Session::impersonate((int)$user->site_id, (int)$user->login_user_id, (int)$user->id);
    }

    /**
     * Clear all impersonated site/user/login context for the current test.
     */
    protected static function __reset_session()
    {
        Session::reset_impersonation();
    }

    /**
     * Insert a browser session row and the user sessions signed in on it, for a test of
     * something that reads session rows (listings, terminations, lifetimes, cleanup). A CLI
     * test cannot sign in through the web path, so the rows are written directly, in the
     * shape the web path writes them.
     *
     * $options (all optional):
     *   type_id, active, last_active, created_at (Rsx_Time-parseable), token - the row's
     *                first_signed_in_at is its earliest user session's (null with none: a guest)
     *   staff        [login_user_id, site_id, impersonator_login_user_id, signed_in_at,
     *                 last_page_view_at, last_active_at, started_at, signed_out_at,
     *                 sign_out_reason_id] - the user session the staff pointer names (unless
     *                 signed_out_at is given, which leaves the pointer null)
     *   staff_own    the same keys: a SIGNED-IN staff user session the pointer does not name -
     *                the impersonator's own sign-in behind a staff impersonation
     *   portal       [portal_user_id, portal_site_id, impersonator_user_id, ...timing] - the
     *                portal pointer's user session
     *
     * @param array $options
     * @return array{id: int, token: string, staff_user_session_id: ?int, staff_own_user_session_id: ?int, portal_user_session_id: ?int}
     */
    protected static function __insert_session_fixture(array $options = []): array
    {
        $as_db = fn ($value) => $value === null ? null : \App\RSpade\Core\Time\Rsx_Time::to_database($value);
        $now = now()->format('Y-m-d H:i:s.v');
        $token = $options['token'] ?? bin2hex(random_bytes(32));

        $session_id = (int) DB::table('_sessions')->insertGetId([
            'session_token' => $token,
            'csrf_token' => bin2hex(random_bytes(32)),
            'active' => (int) ($options['active'] ?? 1),
            'type_id' => $options['type_id'] ?? Session::TYPE_WEB,
            'version' => 1,
            'ip_address_id' => \App\RSpade\Core\Session\Ip_Address::id('10.0.0.9'),
            'user_agent_id' => \App\RSpade\Core\Session\Audit_Session::user_agent_id('test-browser'),
            'last_active' => $as_db($options['last_active'] ?? null) ?? $now,
            'created_at' => $as_db($options['created_at'] ?? null) ?? $now,
            'updated_at' => $now,
        ]);

        $insert = function (int $realm_id, array $spec) use ($session_id, $as_db, $now): int {
            $identity = $realm_id === Session::REALM_STAFF
                ? [
                    'login_user_id' => (int) $spec['login_user_id'],
                    'site_id' => (int) ($spec['site_id'] ?? 0),
                    'impersonator_login_user_id' => $spec['impersonator_login_user_id'] ?? null,
                ]
                : [
                    'portal_user_id' => (int) $spec['portal_user_id'],
                    'portal_site_id' => $spec['portal_site_id'] ?? null,
                    'impersonator_user_id' => $spec['impersonator_user_id'] ?? null,
                ];

            $signed_in_at = $as_db($spec['signed_in_at'] ?? null) ?? $now;

            return (int) DB::table('_user_sessions')->insertGetId(array_merge($identity, [
                'session_id' => $session_id,
                'realm_id' => $realm_id,
                'tuple_hash' => Session::_user_session_tuple_hash($session_id, $realm_id, $identity),
                'first_signed_in_at' => $signed_in_at,
                'signed_in_at' => $signed_in_at,
                'started_at' => $as_db($spec['started_at'] ?? null) ?? $signed_in_at,
                'last_page_view_at' => $as_db($spec['last_page_view_at'] ?? null) ?? $now,
                'last_active_at' => $as_db($spec['last_active_at'] ?? null) ?? $now,
                'signed_out_at' => $as_db($spec['signed_out_at'] ?? null),
                'sign_out_reason_id' => $spec['sign_out_reason_id'] ?? null,
            ]));
        };

        $out = [
            'id' => $session_id,
            'token' => $token,
            'staff_user_session_id' => null,
            'staff_own_user_session_id' => null,
            'portal_user_session_id' => null,
        ];

        if (isset($options['staff_own'])) {
            $out['staff_own_user_session_id'] = $insert(Session::REALM_STAFF, $options['staff_own']);
        }

        foreach (['staff' => Session::REALM_STAFF, 'portal' => Session::REALM_PORTAL] as $key => $realm_id) {
            if (!isset($options[$key])) {
                continue;
            }

            $user_session_id = $insert($realm_id, $options[$key]);
            $out[$key . '_user_session_id'] = $user_session_id;

            if (!isset($options[$key]['signed_out_at'])) {
                DB::table('_sessions')->where('id', $session_id)->update([$key . '_user_session_id' => $user_session_id]);
            }
        }

        // The browser's first sign-in, as Session stamps it: a row with no user session is a
        // guest (first_signed_in_at null).
        $first_signed_in_at = DB::table('_user_sessions')->where('session_id', $session_id)->min('first_signed_in_at');

        if ($first_signed_in_at !== null) {
            DB::table('_sessions')->where('id', $session_id)->update(['first_signed_in_at' => $first_signed_in_at]);
        }

        return $out;
    }

    /**
     * Call a #[Task] method directly, as a real inline run, and return the state it reported.
     *
     * The run gets its _tasks row (origin Inline, RUNNING in this process) and the method is
     * called with that row's Task_Instance - no pre_task(), no run lock, no output capture, so
     * the method under test is exercised exactly as written. A throw leaves the method's
     * exception to the caller. On return the held reports are written and the run is settled
     * by the method's return value, then the state object it reported with $task->state() is
     * returned (null when it reported none).
     *
     * The method runs in the test's own context, like any inline run: a #[Run_As_Dispatcher]
     * task runs as the identity the test declared with __acting_as_user() (and is refused
     * when the test declared nobody).
     *
     * @param string $class Fully-qualified service class
     * @param string $method The static #[Task] method
     * @param array $params The $params the method receives
     * @return mixed The run's state() report
     */
    protected static function __run_task_method(string $class, string $method, array $params = [])
    {
        $task_id = \App\RSpade\Core\Task\Task_Runner::insert_row(
            $class,
            $method,
            $params,
            \App\RSpade\Core\Task\Task_Run_Model::ORIGIN_INLINE,
            \App\RSpade\Core\Task\Task_Runner::running_fields()
        );
        $task = \App\RSpade\Core\Task\Task_Instance::find($task_id);

        $value = $class::$method($task, $params);

        \App\RSpade\Core\Task\Task_Runner::settle($task, \App\RSpade\Core\Task\Task_Run_Outcome::from_return($value));

        return \App\RSpade\Core\Task\Task_Run_Model::find($task_id)->state();
    }

    /**
     * Make a test HTTP request
     *
     * @param string $url
     * @param string $method
     * @param array $data
     * @return \Illuminate\Http\Client\Response
     */
    protected static function __request($url, $method = 'GET', $data = [])
    {
        $full_url = 'http://localhost' . $url;
        
        switch (strtoupper($method)) {
            case 'POST':
                return Http::post($full_url, $data);
            case 'GET':
                return Http::get($full_url, $data);
            default:
                throw new \Exception("Unsupported HTTP method: {$method}");
        }
    }
    
    /**
     * Mark current test as passed with optional message
     * 
     * @param string $message
     */
    protected static function __pass($message = 'Test passed')
    {
        static::$results[static::$current_test] = [
            'status' => 'passed',
            'message' => $message
        ];
    }
    
    /**
     * Mark current test as failed
     * 
     * @param string $message
     */
    protected static function __fail($message = 'Test failed')
    {
        throw new \Exception($message);
    }
    
    /**
     * Skip current test
     * 
     * @param string $reason
     */
    protected static function __skip($reason = 'Test skipped')
    {
        static::$results[static::$current_test] = [
            'status' => 'skipped',
            'message' => $reason
        ];
        
        // Throw special exception to stop test execution but not mark as failed
        throw new \Exception('__SKIP__:' . $reason);
    }

    /**
     * The most privileged role id declared by this application's User_Model.
     *
     * The role contract is NOT integer ordering: administration authority is the
     * explicit `can_admin_roles` whitelist each role declares in $enums (read by
     * User_Model::can_admin_role()). "Most privileged" is therefore the role that can
     * administer the most other roles; ties break on the lowest id, and an application
     * whose roles declare no administration relation at all yields its lowest id.
     *
     * This is the ONE derivation - the test runner seeds the baseline user with it, and
     * a test needing a strong identity asks for it here rather than naming a ROLE_*
     * constant that an application's own User_Model need not declare.
     *
     * @return int
     */
    public static function most_privileged_role_id(): int
    {
        $roles = User_Model::role_id__enum();

        $best_id = null;
        $best_count = -1;

        foreach ($roles as $role_id => $definition) {
            $role_id = (int) $role_id;
            $count = count($definition['can_admin_roles'] ?? []);

            if ($count > $best_count || ($count === $best_count && $role_id < $best_id)) {
                $best_id = $role_id;
                $best_count = $count;
            }
        }

        if ($best_id === null) {
            shouldnt_happen('User_Model declares no role_id enum, so no role can be derived.');
        }

        return $best_id;
    }

    /**
     * A [superior, subordinate, peer] role triple derived from this application's roles.
     *
     * - superior: a role whose `can_admin_roles` list is non-empty (the most privileged
     *   one, so it administers as many roles as this application declares).
     * - subordinate: a role BELOW the superior that the superior may administer (its own
     *   role, when the whitelist names it, is never chosen as the subordinate).
     * - peer: the superior again - a second holder of the same role. Whether the
     *   superior may administer a peer is whatever its own whitelist says
     *   (`role_can_admin_peer()`), so a test asserts that answer rather than assuming it.
     *
     * An application whose roles declare no administration relation cannot express the
     * scenario at all, so the test skips rather than failing.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function role_triple(): array
    {
        $superior = static::most_privileged_role_id();
        $roles = User_Model::role_id__enum();
        $below = array_values(array_filter(
            array_map('intval', $roles[$superior]['can_admin_roles'] ?? []),
            fn (int $role_id) => $role_id !== $superior
        ));

        if (empty($below)) {
            static::__skip('User_Model declares no role that administers another role, so role administration cannot be exercised in this application.');
        }

        return [$superior, $below[0], $superior];
    }

    /**
     * Does this application's whitelist let a role administer a second holder of the
     * SAME role? True when the superior role from role_triple() names itself in its own
     * `can_admin_roles` (equal-or-lower administration), false when it names only roles
     * below it.
     */
    public static function role_can_admin_peer(): bool
    {
        $superior = static::most_privileged_role_id();
        $roles = User_Model::role_id__enum();

        return in_array($superior, array_map('intval', $roles[$superior]['can_admin_roles'] ?? []), true);
    }

    /**
     * GRANT a user every permission this application's catalogue declares
     * (User_Model::permission_definitions()).
     *
     * For a test whose subject is framework machinery reached THROUGH an identity - an
     * external API call, an endpoint's gates - rather than any one permission. The
     * application decides what such an identity needs (its Main::pre_dispatch() may refuse a
     * bearer key whose user lacks a permission no role grants, and its endpoints declare
     * their own gates), and a framework test may not name that permission. A user holding
     * the whole catalogue satisfies any permission-based policy. Rows are ordinary
     * User_Permission_Model writes, so they roll back with the per-test transaction.
     */
    public static function grant_every_permission(int $user_id): void
    {
        foreach (array_keys(User_Model::permission_definitions()) as $permission_id) {
            User_Permission_Model::grant($user_id, (int) $permission_id);
        }
    }
}