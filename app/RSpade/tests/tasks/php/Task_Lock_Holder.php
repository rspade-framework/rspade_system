<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
/**
 * Test helper: ANOTHER PROCESS holding a named RsxLocks write lock.
 *
 * RsxLocks is re-entrant within a process, so "this identity is already running somewhere
 * else" cannot be staged by the test process taking the lock itself - its own later attempt
 * would simply succeed. The holder is an ordinary child PHP process (Symfony Process) that
 * boots the framework on the TEST database (the lock name is scoped by the database, exactly
 * as the test's own locks are), takes the lock, writes a ready file and then:
 *
 *   'hold'              keeps the lock until release() (a line on its stdin) or until its
 *                       stdin closes;
 *   'release_on_waiter' releases it the moment the daemon shows a writer queued behind it,
 *                       first writing the release moment (microtime) to its ready file's
 *                       '.released' twin - so a test can prove the waiter ran after it.
 *
 * Every wait here is a bounded poll on a process this test does not control (the house bound,
 * tests/CLAUDE.md), and a failure names the condition never reached.
 */
class Task_Lock_Holder
{
    /** 100 ms polls: 120 s, the house bound. */
    private const POLLS = 1200;

    /**
     * Start a holder of $lock_name and return once it holds it.
     *
     * @return array{process: Process, input: InputStream, ready_file: string, released_file: string}
     */
    public static function start(string $lock_name, string $mode): array
    {
        $helper_path = Rsx_Project_Paths::tmp_path('rsxtest_task_lock_holder.php');
        ensure_directory(dirname($helper_path));
        file_put_contents($helper_path, static::__source());

        $ready_file = Rsx_Project_Paths::tmp_path('rsxtest_task_lock_holder_ready_' . getmypid() . '_' . uniqid());
        $released_file = $ready_file . '.released';

        $input = new InputStream();
        $process = new Process(['php', $helper_path, $mode, $ready_file, $lock_name]);
        $process->setInput($input);
        $process->setTimeout(null);
        $process->start();

        for ($poll = 0; $poll < self::POLLS; $poll++) {
            if (is_file($ready_file)) {
                return ['process' => $process, 'input' => $input, 'ready_file' => $ready_file, 'released_file' => $released_file];
            }
            if (!$process->isRunning()) {
                break;
            }
            usleep(100000);
        }

        throw new RuntimeException('the lock holder never reported holding ' . $lock_name
            . ' (exit ' . var_export($process->isRunning() ? null : $process->getExitCode(), true) . '): '
            . trim($process->getErrorOutput() . ' ' . $process->getOutput()));
    }

    /** Tell a 'hold' holder to release and exit, and wait until it has. */
    public static function release(array $holder): void
    {
        if ($holder['process']->isRunning()) {
            $holder['input']->write("release\n");
        }
        static::stop($holder);
    }

    /** Wait for the holder to exit (closing its stdin, which ends a 'hold'), and clean up. */
    public static function stop(array $holder): void
    {
        $holder['input']->close();

        for ($poll = 0; $poll < self::POLLS && $holder['process']->isRunning(); $poll++) {
            usleep(100000);
        }
        if ($holder['process']->isRunning()) {
            $holder['process']->signal(SIGKILL);
            while ($holder['process']->isRunning()) {
                usleep(10000);
            }

            throw new RuntimeException('the lock holder never exited after its stdin closed');
        }

        @unlink($holder['ready_file']);
        @unlink($holder['released_file']);
    }

    /** The microtime the 'release_on_waiter' holder released at, or null when it has not. */
    public static function released_at(array $holder): ?float
    {
        return is_file($holder['released_file']) ? (float) file_get_contents($holder['released_file']) : null;
    }

    private static function __source(): string
    {
        $autoload = var_export(base_path('vendor/autoload.php'), true);
        $bootstrap = var_export(base_path('bootstrap/app.php'), true);

        return <<<PHP
<?php
require {$autoload};
\$app = require {$bootstrap};
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\RSpade\Core\Locks\RsxLocks;

// The lock name is scoped by the current database, and a freshly-booted process defaults to
// the dev database: point it at the test database, as the test-suite process itself is.
config(['database.default' => 'test']);
Illuminate\Support\Facades\DB::purge('test');

[\$script, \$mode, \$ready_file, \$lock_name] = \$argv;

\$token = RsxLocks::named_write_lock(\$lock_name);
file_put_contents(\$ready_file, (string) getmypid());

\$stdin = [STDIN];
while (true) {
    if (\$mode === 'release_on_waiter' && RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, \$lock_name)['writers_waiting'] > 0) {
        file_put_contents(\$ready_file . '.released', (string) microtime(true));
        RsxLocks::release_lock(\$token);
        exit(0);
    }

    // A line on stdin is the parent's release; EOF means the parent is gone.
    \$read = \$stdin;
    \$write = null;
    \$except = null;
    if (stream_select(\$read, \$write, \$except, 0, 50000) > 0) {
        fgets(STDIN);
        RsxLocks::release_lock(\$token);
        exit(0);
    }
}
PHP;
    }
}
