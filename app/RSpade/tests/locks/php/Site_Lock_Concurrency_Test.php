<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * The request boundary across real processes (SLK-17): every dispatcher runs a handler
 * through Site_Lock::_run_request(), and here separate PHP processes do exactly that against
 * one site of the running rsx-lockd:
 *
 *   - a writer waits for an open reader;
 *   - a reader that arrives behind a waiting writer is held back by it, though only a
 *     reader holds the lock;
 *   - two writers never hold the site together - the second runs only after the first
 *     (and the reader queued between them) released.
 *
 * Each helper is a detached process that takes its boundary, appends "<tag> granted" to a
 * shared order file, then holds until this test creates its release file, appends
 * "<tag> released" and exits. The test reads the daemon's own stats (writer_active,
 * readers_active, queue_length) to know who holds and who waits - order and state, never
 * speed. No database row is involved: the helpers' handlers write only the order file.
 */
class Site_Lock_Concurrency_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** A site id no other test locks. */
    private const SITE_ID = 987655;

    public static function test_writers_serialize_wait_for_readers_and_hold_back_a_later_reader()
    {
        $dir = Rsx_Project_Paths::tmp_path('rsxtest_site_lock_concurrency_' . getmypid());
        ensure_directory($dir);
        $order_file = $dir . '/order';
        $helper_path = $dir . '/helper.php';
        file_put_contents($order_file, '');
        file_put_contents($helper_path, static::__helper_source());

        $pids = [];
        $own = Site_Lock::_acquire(self::SITE_ID, Site_Lock::MODE_READ, 'Site_Lock_Concurrency_Test reader');
        $own_released = false;

        try {
            // A writer arrives while this process reads: it waits.
            $pids[] = static::__spawn($helper_path, 'writer', 'W1', $order_file, $dir);
            static::__poll_until(fn () => static::__stats()['queue_length'] === 1, 'the first writer queued behind the open reader');

            // A reader arrives behind the waiting writer: held back, though only a reader holds.
            $pids[] = static::__spawn($helper_path, 'reader', 'R2', $order_file, $dir);
            static::__poll_until(fn () => static::__stats()['queue_length'] === 2, 'the later reader queued behind the waiting writer');

            // A second writer queues last.
            $pids[] = static::__spawn($helper_path, 'writer', 'W3', $order_file, $dir);
            static::__poll_until(fn () => static::__stats()['queue_length'] === 3, 'the second writer queued');

            static::__assert_equals('', file_get_contents($order_file), 'nobody was granted while this process read');

            Site_Lock::_release($own);
            $own_released = true;

            static::__poll_until(fn () => static::__lines($order_file) === ['W1 granted'], 'the first writer was granted once the reader closed');
            $stats = static::__stats();
            static::__assert_true($stats['writer_active'], 'the first writer holds the site');
            static::__assert_equals(0, $stats['readers_active'], 'no reader runs beside it');
            static::__assert_equals(2, $stats['queue_length'], 'the reader and the second writer wait');

            touch($dir . '/W1.release');
            static::__poll_until(fn () => static::__lines($order_file) === ['W1 granted', 'W1 released', 'R2 granted'], 'the reader ran after the first writer');
            $stats = static::__stats();
            static::__assert_false($stats['writer_active'], 'no writer holds while the reader runs');
            static::__assert_equals(1, $stats['readers_active']);
            static::__assert_equals(1, $stats['queue_length'], 'the second writer still waits');

            touch($dir . '/R2.release');
            static::__poll_until(fn () => static::__lines($order_file) === ['W1 granted', 'W1 released', 'R2 granted', 'R2 released', 'W3 granted'], 'the second writer ran last');

            touch($dir . '/W3.release');
            static::__poll_until(fn () => count(static::__lines($order_file)) === 6, 'the second writer released');
            static::__poll_until(fn () => !static::__stats()['writer_active'] && static::__stats()['queue_length'] === 0, 'the site is free');
        } finally {
            if (!$own_released) {
                Site_Lock::_release($own);
            }

            foreach (['W1', 'R2', 'W3'] as $tag) {
                touch($dir . '/' . $tag . '.release');
            }

            foreach ($pids as $pid) {
                // Bounded wait on an external process (the house contention bound): the
                // helpers exit on their own once released; one that does not is killed here
                // so it can never become a stray holder of the site.
                for ($attempt = 0; $attempt < 1200 && $pid > 0 && file_exists('/proc/' . $pid); $attempt++) {
                    usleep(100000);
                }

                if ($pid > 0 && file_exists('/proc/' . $pid)) {
                    $out = [];
                    $rc = 0;
                    exec_safe('kill -9 ' . (int) $pid . ' 2>/dev/null', $out, $rc);
                }
            }

            RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . self::SITE_ID);

            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /** Start one detached helper; answer its pid (written by the helper as it starts). */
    private static function __spawn(string $helper_path, string $mode, string $tag, string $order_file, string $dir): int
    {
        $pid_file = $dir . '/' . $tag . '.pid';
        $args = array_map('escapeshellarg', [$helper_path, $mode, $tag, (string) self::SITE_ID, $order_file, $dir]);

        $out = [];
        $rc = 0;
        exec_safe('php ' . implode(' ', $args) . ' > ' . escapeshellarg($dir . '/' . $tag . '.log') . ' 2>&1 &', $out, $rc);

        static::__poll_until(fn () => file_exists($pid_file), "helper {$tag} started (see {$dir}/{$tag}.log)");

        return (int) file_get_contents($pid_file);
    }

    /**
     * Poll $condition every 100 ms. Bounded at the house bound (120 s, tests/CLAUDE.md): it
     * waits on detached helper processes this test does not control, truncates no work, and
     * expiry fails the test naming the condition.
     */
    private static function __poll_until(callable $condition, string $what): void
    {
        for ($attempt = 0; $attempt < 1200; $attempt++) {
            if ($condition()) {
                return;
            }
            usleep(100000);
        }

        static::__fail("Never reached: {$what}");
    }

    private static function __stats(): array
    {
        return RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . self::SITE_ID);
    }

    private static function __lines(string $file): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($file)), fn ($line) => $line !== ''));
    }

    /**
     * The helper: one handler run through the dispatchers' boundary in mode $argv[1].
     * Written out rather than shipped because it is meaningless outside this test.
     */
    private static function __helper_source(): string
    {
        $autoload = var_export(base_path('vendor/autoload.php'), true);
        $bootstrap = var_export(base_path('bootstrap/app.php'), true);

        return <<<PHP
<?php
require {$autoload};
\$app = require {$bootstrap};
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\RSpade\Core\Locks\Site_Lock;

// Lock names are scoped by the current database: coordinate in the test database's scope,
// as the test process does.
config(['database.default' => 'test']);
Illuminate\Support\Facades\DB::purge('test');

[, \$mode, \$tag, \$site_id, \$order_file, \$dir] = \$argv;
file_put_contents("{\$dir}/{\$tag}.pid", (string) getmypid());

Site_Lock::_run_request(\$mode, "helper {\$tag}", function () use (\$tag, \$order_file, \$dir) {
    file_put_contents(\$order_file, "{\$tag} granted\\n", FILE_APPEND | LOCK_EX);

    // Holds until the test releases it (the test kills a helper it gives up on).
    while (!file_exists("{\$dir}/{\$tag}.release")) {
        usleep(50000);
    }

    file_put_contents(\$order_file, "{\$tag} released\\n", FILE_APPEND | LOCK_EX);
}, (int) \$site_id);
PHP;
    }
}
