<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * Two simultaneous identical writer requests produce ONE execution (Request_History, C3):
 * two separate PHP processes run the same writer request - same site, same actor, same
 * _request_ref, same params - through the dispatchers' boundary (Site_Lock::_run_request())
 * and its request history (Request_History_Call), exactly as an endpoint call does.
 *
 * The first holds the site's write lock inside its handler until this test releases it; the
 * second, arriving meanwhile, waits for the lock (read from rsx-lockd's own stats). Released,
 * the first records and commits; the second then finds the record and is answered from it -
 * its handler never runs. Order and state, never speed.
 */
class Request_History_Concurrency_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** A site id no other test locks. */
    private const SITE_ID = 987656;

    public static function test_two_simultaneous_identical_requests_run_once()
    {
        $dir = Rsx_Project_Paths::tmp_path('rsxtest_request_history_concurrency_' . getmypid());
        ensure_directory($dir);
        $order_file = $dir . '/order';
        $helper_path = $dir . '/helper.php';
        $ref = 'concurrency-' . bin2hex(random_bytes(8));
        file_put_contents($order_file, '');
        file_put_contents($helper_path, static::__helper_source());

        $pids = [];

        try {
            $pids[] = static::__spawn($helper_path, 'H1', $ref, $order_file, $dir);
            static::__poll_until(fn () => static::__lines($order_file) === ['H1 ran'], 'the first request ran its handler under the write lock');

            $pids[] = static::__spawn($helper_path, 'H2', $ref, $order_file, $dir);
            static::__poll_until(fn () => static::__stats()['queue_length'] === 1, 'the second request queued for the site write lock');
            static::__assert_equals(['H1 ran'], static::__lines($order_file), 'the second request waits while the first runs');

            touch($dir . '/H1.release');
            static::__poll_until(fn () => count(static::__lines($order_file)) === 3, 'both requests answered');

            static::__assert_equals(
                ['H1 ran', 'H1 answer:executed', 'H2 answer:replay'],
                static::__lines($order_file),
                'the second request was answered from the first one\'s record, and its handler never ran'
            );
            static::__assert_equals(1, (int) DB::table('_transactions')->where('request_ref', $ref)->count(), 'one record');
        } finally {
            foreach (['H1', 'H2'] as $tag) {
                touch($dir . '/' . $tag . '.release');
            }

            foreach ($pids as $pid) {
                // Bounded wait on an external process (the house contention bound): a helper
                // exits on its own once released; one that does not is killed here so it can
                // never become a stray holder of the site.
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
            DB::table('_transactions')->where('request_ref', $ref)->delete();

            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /** Start one detached helper; answer its pid (written by the helper as it starts). */
    private static function __spawn(string $helper_path, string $tag, string $ref, string $order_file, string $dir): int
    {
        $pid_file = $dir . '/' . $tag . '.pid';
        $args = array_map('escapeshellarg', [$helper_path, $tag, (string) self::SITE_ID, $ref, $order_file, $dir]);

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
     * The helper: one writer request through the boundary and its request history, acting as
     * the baseline user. Its handler appends "<tag> ran" and holds until released; the
     * request's outcome is appended as "<tag> answer:executed" or "<tag> answer:<kind>" when
     * it was answered from the history.
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
use App\RSpade\Core\RequestHistory\Request_History;
use App\RSpade\Core\RequestHistory\Request_History_Answer;
use App\RSpade\Core\RequestHistory\Request_History_Call;
use App\RSpade\Lib\Revisions\Revision;
use App\RSpade\Core\Session\Session;

// The test database, and lock names scoped by it, as the test process uses.
config(['database.default' => 'test']);
Illuminate\Support\Facades\DB::purge('test');

[, \$tag, \$site_id, \$ref, \$order_file, \$dir] = \$argv;
file_put_contents("{\$dir}/{\$tag}.pid", (string) getmypid());

Session::impersonate(1, 1, 1);
Revision::_reset_request_state('ajax', 'Request_History_Concurrency_Test::probe');

\$history = new Request_History_Call('writer', (int) \$site_id, \$ref, Request_History::fingerprint(['x' => 1]), '{"x":1}', hrtime(true));

try {
    \$answer = \$history->run(fn () => Site_Lock::_run_request('writer', "helper {\$tag}", function () use (\$history, \$tag, \$order_file, \$dir) {
        \$history->open();

        file_put_contents(\$order_file, "{\$tag} ran\\n", FILE_APPEND | LOCK_EX);

        // Holds until the test releases it (the test kills a helper it gives up on).
        while (!file_exists("{\$dir}/{\$tag}.release")) {
            usleep(50000);
        }

        \$history->record(200, json_encode(['_success' => true, '_ajax_return_value' => ['tag' => \$tag]]), true);

        return 'executed';
    }, (int) \$site_id));
} catch (Request_History_Answer \$e) {
    \$answer = \$e->kind;
}

file_put_contents(\$order_file, "{\$tag} answer:{\$answer}\\n", FILE_APPEND | LOCK_EX);
PHP;
    }
}
