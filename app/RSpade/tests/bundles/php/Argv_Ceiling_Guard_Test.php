<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Bundles\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * exec_safe() refuses a command too long to spell, and says so in words.
 *
 * WHY THIS TEST LIVES IN THE BUNDLES CONCERN. The guard is framework-wide - it protects
 * every caller of exec_safe(), not the bundle pipeline in particular - but it exists
 * because of this concern's incident: the bundle concat command named every file in the
 * bundle on one argv element, crossed the 131072-byte MAX_ARG_STRLEN ceiling in a large
 * downstream application, and surfaced as a bare `posix_spawn() failed: Argument list too
 * long` naming helpers.php, with no byte count and no mention of bundles. An hour went into
 * the diagnosis while every route in that application returned 500. The concatenator no
 * longer touches argv at all (see Bundle_Concat_Service_Test, CONCAT-04); this guard is the
 * net under everything else, so it is catalogued beside the defect that motivated it.
 */
class Argv_Ceiling_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * ARGV-GUARD-01 - The ceiling is the kernel's, not a number somebody liked.
     *
     * MAX_ARG_STRLEN is fixed at 32 pages. This asserts the constant equals that, so a
     * future edit to a "nicer" number has to argue with the kernel rather than with taste.
     */
    public static function test_the_limit_is_the_kernel_single_argument_ceiling()
    {
        static::__assert_equals(131072, ARG_MAX_SINGLE_BYTES, 'the single-argument ceiling moved');
        static::__assert_equals(32 * 4096, ARG_MAX_SINGLE_BYTES, 'the ceiling is no longer 32 pages');
    }

    /**
     * ARGV-GUARD-02 - An over-limit command throws, naming the byte count, the limit, and
     * where the payload should go instead.
     */
    public static function test_an_over_limit_command_is_refused_legibly()
    {
        // One byte past the ceiling. The padding is a long argument to the no-op `:`
        // builtin rather than a trailing comment, because exec_safe() wraps what it is
        // given in `( ... ) 2>&1` and a comment would swallow the closing paren.
        $command = ': ' . str_repeat('x', ARG_MAX_SINGLE_BYTES + 1);

        $threw = false;
        $message = '';

        try {
            exec_safe($command);
        } catch (\RuntimeException $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        static::__assert_true($threw, 'an over-limit command was spawned anyway');

        // The three things whose absence cost an hour: how big, how big is allowed, and the
        // fact that ulimit/ARG_MAX are not the answer.
        static::__assert_contains(number_format(strlen($command)), $message);
        static::__assert_contains(number_format(ARG_MAX_SINGLE_BYTES), $message);
        static::__assert_contains('MAX_ARG_STRLEN', $message);
        static::__assert_contains('ulimit', $message);
    }

    /**
     * ARGV-GUARD-03 - A command just under the ceiling still runs.
     *
     * The guard must sit exactly at the OS limit: refusing anything smaller would break
     * working callers, and the ceiling is a cliff, so "just under" is a real operating point
     * rather than a curiosity.
     */
    public static function test_a_command_just_under_the_ceiling_still_runs()
    {
        $padding = ARG_MAX_SINGLE_BYTES - 4096;
        $command = 'printf argv_guard_ok; : ' . str_repeat('x', $padding);

        static::__assert_true(strlen($command) < ARG_MAX_SINGLE_BYTES, 'the fixture is not under the ceiling');

        $output = [];
        $return_var = 0;
        exec_safe($command, $output, $return_var);

        static::__assert_equals(0, $return_var, 'a legal command failed: ' . implode("\n", $output));
        static::__assert_contains('argv_guard_ok', implode("\n", $output));
    }

    /**
     * ARGV-GUARD-04 - An ordinary short command is untouched by the guard.
     */
    public static function test_an_ordinary_command_is_unaffected()
    {
        $output = [];
        $return_var = 0;

        exec_safe('printf argv_guard_plain', $output, $return_var);

        static::__assert_equals(0, $return_var, 'a plain command failed');
        static::__assert_contains('argv_guard_plain', implode("\n", $output));
    }
}
