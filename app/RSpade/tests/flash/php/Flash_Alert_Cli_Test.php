<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Flash\Php;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Flash\Flash_Alert;
use App\RSpade\Lib\Flash\Flash_Alert_Model;

/**
 * Tests for the CLI contract (owner ruling 2026-08-09).
 *
 * In console context a flash alert does not create a session, does not write the
 * database and does not read it. The message goes to STDERR, and warning/error
 * additionally reach the Laravel log.
 *
 * This guard predates CLI sessions and used to be a consequence of there being no
 * session to attach to. A CLI process now mints a real _sessions row on demand, so
 * the guard is deliberate POLICY: a flash alert is a message for a browser that is
 * about to render, and a command has an operator reading its output right now.
 *
 * A PHP test IS a console process, so the public writers can be called directly
 * here - this is the one part of the flash surface a PHP test exercises end to end.
 * STDERR itself cannot be captured in-process (fwrite(STDERR, ...) goes to the
 * runner's terminal), so the line the writer emits is asserted through the pure
 * formatter that produces it.
 */
class Flash_Alert_Cli_Test extends Rsx_Test_Abstract
{
    private static function __format(string $message, int $type_id): string
    {
        $reflection = new ReflectionMethod(Flash_Alert::class, '_format_cli_line');
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, [$message, $type_id]);
    }

    /**
     * Run a callback with the log listening, and return the [level, message] pairs
     * it produced. Log::listen is the house pattern (see Result_Set_Tripwire_Test).
     */
    private static function __capture_log(callable $callback): array
    {
        $captured = [];

        Event::listen(MessageLogged::class, function ($event) use (&$captured) {
            $captured[] = [$event->level, $event->message];
        });

        $callback();

        Event::forget(MessageLogged::class);

        return $captured;
    }

    // =====================================================================

    public static function test_cli_writers_persist_nothing()
    {
        $before = (int) Flash_Alert_Model::query()->count();

        Flash_Alert::success('cli success');
        Flash_Alert::info('cli info');
        Flash_Alert::warning('cli warning');
        Flash_Alert::error('cli error');

        static::__assert_equals(
            $before,
            (int) Flash_Alert_Model::query()->count(),
            'no flash row is written in console context'
        );
    }

    public static function test_cli_writers_mint_no_session()
    {
        static::__reset_session();

        $before = (int) Session::query()->count();

        Flash_Alert::success('cli success');
        Flash_Alert::error('cli error');

        static::__assert_equals(
            $before,
            (int) Session::query()->count(),
            'writing a flash alert never mints a CLI session row'
        );
        static::__assert_false(Session::has_session(), 'and never activates the session facade');
    }

    public static function test_get_pending_messages_is_empty_in_cli()
    {
        // A row that WOULD be returned if the read touched the database at all.
        static::__assert_equals(
            [],
            Flash_Alert::get_pending_messages(),
            'the console read returns nothing and never queries'
        );
    }

    public static function test_error_and_warning_reach_the_laravel_log()
    {
        $captured = static::__capture_log(function () {
            Flash_Alert::error('cli logged error');
            Flash_Alert::warning('cli logged warning');
        });

        $levels = [];
        foreach ($captured as $entry) {
            if ($entry[1] === 'cli logged error' || $entry[1] === 'cli logged warning') {
                $levels[$entry[1]] = $entry[0];
            }
        }

        static::__assert_equals('error', $levels['cli logged error'] ?? null, 'error maps to Log::error');
        static::__assert_equals('warning', $levels['cli logged warning'] ?? null, 'warning maps to Log::warning');
    }

    public static function test_success_and_info_are_terminal_only()
    {
        $captured = static::__capture_log(function () {
            Flash_Alert::success('cli quiet success');
            Flash_Alert::info('cli quiet info');
        });

        foreach ($captured as $entry) {
            static::__assert_true(
                $entry[1] !== 'cli quiet success' && $entry[1] !== 'cli quiet info',
                'success/info are not logged - "Saved!" in a log file is noise'
            );
        }
    }

    public static function test_the_console_line_is_plain_ascii_and_labelled()
    {
        static::__assert_equals(
            '[FLASH:SUCCESS] all good',
            static::__format('all good', Flash_Alert_Model::TYPE_SUCCESS)
        );
        static::__assert_equals(
            '[FLASH:ERROR] it broke',
            static::__format('it broke', Flash_Alert_Model::TYPE_ERROR)
        );
        static::__assert_equals(
            '[FLASH:INFO] fyi',
            static::__format('fyi', Flash_Alert_Model::TYPE_INFO)
        );
        static::__assert_equals(
            '[FLASH:WARNING] careful',
            static::__format('careful', Flash_Alert_Model::TYPE_WARNING)
        );
    }
}
