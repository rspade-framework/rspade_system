<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

/**
 * Fixture handler that makes ONE document misbehave on purpose, inside the document worker's
 * child process - which is the only place a test cannot reach with a config override or a
 * callable. It sits on the real document.extract_text chain, so the child command
 * (rsx:documents:process_one) meets it exactly as it would meet an application's handler.
 *
 * MARKER GUARD: it acts only on a blob whose bytes BEGIN with one of the markers below, and
 * declines (null) for everything else. No real document starts with these strings, and the test
 * tree is in the manifest only while rsx:test runs.
 *
 *   MARKER_FAIL    answers the chain's "failed" contract: an extraction that fails at once,
 *                  every time.
 *   MARKER_SLOW    never returns, in PHP code - the shape the stage bound stops.
 *   MARKER_WEDGE   starts a grandchild process (`sleep`), records its pid, and blocks in one
 *                  native call waiting for it - the shape the stage bound CANNOT stop, because
 *                  its signal handler only runs between PHP statements. Only the parent's kill
 *                  ends it.
 *   MARKER_CRASH   ends the process on the spot with exit status 3, recording nothing.
 */
class Document_Worker_Fixture_Handler
{
    public const MARKER_FAIL = 'RSX_TEST_WORKER_FAIL';
    public const MARKER_SLOW = 'RSX_TEST_WORKER_SLOW';
    public const MARKER_WEDGE = 'RSX_TEST_WORKER_WEDGE';
    public const MARKER_CRASH = 'RSX_TEST_WORKER_CRASH';

    #[OnEvent('document.extract_text', priority: 4)]
    public static function intercept($data)
    {
        $path = (string) ($data['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $head = (string) file_get_contents($path, false, null, 0, 32);

        if (str_starts_with($head, self::MARKER_FAIL)) {
            return ['status' => 'failed', 'error' => 'fixture forced failure'];
        }

        if (str_starts_with($head, self::MARKER_SLOW)) {
            // PHP statements, forever: an alarm's handler runs between any two of them.
            while (true) {
                usleep(20000);
            }
        }

        if (str_starts_with($head, self::MARKER_WEDGE)) {
            $pipes = [];
            $grandchild = proc_open(['sleep', '3600'], [], $pipes);
            file_put_contents(static::wedge_pid_file((int) $data['storage']->id), (string) proc_get_status($grandchild)['pid']);

            // One native wait, restarted by the kernel when a signal interrupts it: no PHP
            // statement runs until the grandchild exits, so no signal handler does either.
            proc_close($grandchild);

            return ['status' => 'failed', 'error' => 'the wedge ended by itself'];
        }

        if (str_starts_with($head, self::MARKER_CRASH)) {
            exit(3);
        }

        return null;
    }

    /**
     * Where the wedge records its grandchild's pid for the test to read.
     *
     * @param int $storage_id
     * @return string
     */
    public static function wedge_pid_file(int $storage_id): string
    {
        return sys_get_temp_dir() . '/rsx_test_worker_wedge_' . $storage_id . '.pid';
    }
}
