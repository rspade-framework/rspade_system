<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Throttle\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Debug\Browser_Log_Budget;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The browser log sinks are bounded: a per-address quota (stricter for a caller who is not
 * signed in), spent by browser errors per UNIQUE entry - an exact entry is admitted once per
 * address per 30 minutes - past which nothing is written; at most 20 entries per call; every
 * field cut; nothing a browser sends can start a line that reads as a log entry of its own;
 * and a counter store that cannot answer writes nothing (fail closed).
 *
 * The quota is driven against a sink name of the test's own, so the counters it spends are
 * nobody else's; the endpoint tests reset the real sink's quota first (every CLI call comes
 * from one address) and send strings unique to the run, since the dedup memory outlives it.
 */
class Browser_Log_Budget_Test extends Rsx_Test_Abstract
{
    /** @var array<int, array{level: string, message: string}> */
    private static array $captured = [];

    private static bool $listening = false;

    private static ?bool $log_browser_errors = null;

    public static function setup(): void
    {
        static::$log_browser_errors = (bool) config('rsx.log_browser_errors');

        if (!static::$listening) {
            Log::listen(function ($message) {
                static::$captured[] = ['level' => $message->level, 'message' => $message->message];
            });
            static::$listening = true;
        }
    }

    public static function teardown(): void
    {
        config(['rsx.log_browser_errors' => static::$log_browser_errors]);
        Browser_Log_Budget::_reset(Browser_Log_Budget::SINK_ERRORS);
    }

    /** Nobody signed in: the anonymous tier. */
    private static function __anonymous(): void
    {
        static::__reset_session();
    }

    /** Post $errors to the browser-error sink and answer the lines it logged. */
    private static function __post_errors(array $errors): array
    {
        config(['rsx.log_browser_errors' => true]);
        static::$captured = [];

        $request = Request::create('/_ajax/Debugger_Controller/log_browser_errors', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['errors' => $errors]));
        Debugger::log_browser_errors($request);

        return array_values(array_filter(static::$captured, fn ($line) => str_contains($line['message'], '[BROWSER_ERROR]')));
    }

    /**
     * blb-01: an anonymous caller gets 30 calls in ten minutes; the 31st writes nothing.
     */
    public static function test_an_anonymous_caller_is_cut_off_after_its_window_limit()
    {
        static::__anonymous();
        $sink = 'test_' . uniqid();

        for ($call = 1; $call <= 30; $call++) {
            static::__assert_true(Browser_Log_Budget::spend($sink), "anonymous call {$call} is within the budget");
        }

        static::__assert_false(Browser_Log_Budget::spend($sink), 'the 31st call in ten minutes is not');
        Browser_Log_Budget::_reset($sink);
    }

    /**
     * blb-02: a signed-in caller gets 60, counted apart from the anonymous budget.
     */
    public static function test_a_signed_in_caller_has_the_larger_budget()
    {
        static::__anonymous();
        $sink = 'test_' . uniqid();

        for ($call = 1; $call <= 30; $call++) {
            Browser_Log_Budget::spend($sink);
        }
        static::__assert_false(Browser_Log_Budget::spend($sink), 'the anonymous budget is spent');

        static::__acting_as_user(1);

        for ($call = 1; $call <= 60; $call++) {
            static::__assert_true(Browser_Log_Budget::spend($sink), "signed-in call {$call} is within its own budget");
        }
        static::__assert_false(Browser_Log_Budget::spend($sink), 'the 61st signed-in call in ten minutes is not');

        Browser_Log_Budget::_reset($sink);
    }

    /**
     * blb-03: past the budget the sink answers as accepted and writes nothing at all.
     */
    public static function test_past_the_budget_nothing_is_logged()
    {
        static::__anonymous();
        Browser_Log_Budget::_reset(Browser_Log_Budget::SINK_ERRORS);
        for ($call = 1; $call <= 30; $call++) {
            Browser_Log_Budget::spend(Browser_Log_Budget::SINK_ERRORS);
        }

        static::__assert_count(0, static::__post_errors([['message' => 'over budget ' . uniqid()]]), 'silently dropped');
    }

    /**
     * blb-06: an exact entry is written once per address per 30 minutes, and a repeat spends
     * no slot of the quota.
     */
    public static function test_an_exact_entry_is_written_once_and_a_repeat_spends_nothing()
    {
        static::__anonymous();
        Browser_Log_Budget::_reset(Browser_Log_Budget::SINK_ERRORS);

        $error = ['type' => 'error', 'message' => 'the same error ' . uniqid()];

        static::__assert_count(1, static::__post_errors([$error, $error]), 'the second copy in one call is dropped');
        static::__assert_count(0, static::__post_errors([$error]), 'and so is a later call carrying it');

        // 28 more spends reach the anonymous 30-per-ten-minutes limit only if the repeats spent
        // nothing: one slot was spent above, so the 29th spend here is the 30th slot.
        for ($call = 1; $call <= 28; $call++) {
            Browser_Log_Budget::spend(Browser_Log_Budget::SINK_ERRORS);
        }
        static::__assert_true(Browser_Log_Budget::spend(Browser_Log_Budget::SINK_ERRORS), 'the 30th slot is still free: the repeats spent none');
        static::__assert_false(Browser_Log_Budget::spend(Browser_Log_Budget::SINK_ERRORS), 'and the 31st is past the limit');
    }

    /**
     * blb-07: when the counter store cannot answer (redis down, maintenance), nothing is
     * written - the sinks fail closed.
     */
    public static function test_a_counter_store_that_cannot_answer_writes_nothing()
    {
        static::__anonymous();

        \App\RSpade\Core\Framework\Framework_Maintenance::$force_active_for_tests = true;
        try {
            static::__assert_false(Browser_Log_Budget::spend('test_' . uniqid()), 'no quota without the store');
            static::__assert_false(Browser_Log_Budget::admit('test_' . uniqid(), 'an entry'), 'no admission without the store');
            static::__assert_count(0, static::__post_errors([['message' => 'store down ' . uniqid()]]), 'nothing is logged');
        } finally {
            \App\RSpade\Core\Framework\Framework_Maintenance::$force_active_for_tests = null;
        }
    }

    /**
     * blb-04: at most 20 entries per call, and one line counts the rest.
     */
    public static function test_entries_past_twenty_are_counted_not_written()
    {
        static::__anonymous();
        Browser_Log_Budget::_reset(Browser_Log_Budget::SINK_ERRORS);

        $run = uniqid();
        $errors = [];
        for ($index = 0; $index < 25; $index++) {
            $errors[] = ['type' => 'error', 'message' => "error {$index} {$run}"];
        }

        $lines = static::__post_errors($errors);

        static::__assert_count(21, $lines, 'twenty entries and one count line');
        static::__assert_contains('5 more error(s)', $lines[20]['message']);
    }

    /**
     * blb-05: nothing a browser sends can forge a log entry, and every field is cut.
     */
    public static function test_a_report_cannot_forge_a_log_line_and_is_cut()
    {
        static::__anonymous();
        Browser_Log_Budget::_reset(Browser_Log_Budget::SINK_ERRORS);

        $forged = "real message\n[2026-10-31 12:00:00] production.ERROR: forged entry";
        $lines = static::__post_errors([[
            'type' => 'error',
            'message' => $forged . ' ' . uniqid() . str_repeat('x', 3000),
            'stack' => "at one\n[2026-10-31 12:00:01] production.CRITICAL: forged too\nat two",
            'userAgent' => str_repeat('u', 1000),
            'url' => "https://example.test/\r\n[2026-10-31] fake",
        ]]);

        static::__assert_count(1, $lines);
        $message = $lines[0]['message'];

        foreach (explode("\n", $message) as $index => $line) {
            if ($index > 0) {
                static::__assert_false(str_starts_with($line, '['), 'no line after the first begins a log entry: ' . $line);
            }
        }

        static::__assert_contains('real message [2026-10-31 12:00:00] production.ERROR: forged entry', $message, 'the newline became a space');
        static::__assert_contains('    [2026-10-31 12:00:01] production.CRITICAL: forged too', $message, 'a stack line is indented');
        static::__assert_contains('...(truncated)', $message);
        static::__assert_true(mb_strlen($message) < 2000 + 8000 + 300 + 2 * 500 + 200, 'every field is bounded');
    }
}
