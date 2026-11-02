<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php;

use Redis;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Companion\Companion_Verifier;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx-companiond as a process manager, end to end: real daemons started against a scratch tree,
 * a fake application answering its calls (resource/fake_app.js), and a scriptable child
 * (resource/fixture_child.js). Each test owns its daemons and kills them in a finally; the
 * supervised daemon, if any, is never connected to or signalled.
 *
 * Isolation: a scratch storage root (RSX_STORAGE_PATH), a scratch build and project root (the
 * daemon's --build-root and --project-root), and a scratch DB_DATABASE, which gives the daemon a
 * Redis prefix nobody else has. The tests run the daemon in production mode unless the subject
 * is development behaviour.
 */
class Companion_Daemon_Process_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Every scratch tree a test made, cleaned in its finally. */
    private static array $__processes = [];

    // ------------------------------------------------------------------------------------
    // The tests
    // ------------------------------------------------------------------------------------

    public static function test_a_started_companion_runs_with_its_record_and_environment()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'sdk');
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);

            $summary = static::__wait_status($s, 'alpha', 'running');
            $child = static::__fixture_record($s, $summary['instance_id']);

            // The record reaches Redis on the daemon's next flush, not with the status change.
            static::__poll_until(fn () => (static::__redis_record($s, $summary['instance_id'])['status'] ?? null) === 'running', 'the running status reached the Redis record');
            $record = static::__redis_record($s, $summary['instance_id']);

            static::__assert_equals('alpha', $record['name']);
            static::__assert_equals($record['started_at'], $child['env']['RSX_COMPANION_STARTED_AT']);
            static::__assert_true((bool) preg_match('/^[0-9]{13,20}$/', $child['env']['RSX_COMPANION_STARTED_AT']), 'started_at is decimal milliseconds');
            static::__assert_true($child['env']['has_secret'], 'the secret is passed by environment');
            static::__assert_false($child['env']['has_app_key'], 'a child never inherits APP_KEY');
            static::__assert_false($child['env']['secret_left_in_env'], 'the SDK removes the secret from process.env once read');
            static::__assert_equals(static::__contract('alpha'), $child['env']['RSX_COMPANION_CONTRACT_KEY']);
            static::__assert_contains('/_companion/_daemon/ready/alpha', static::__requests($s));
            static::__assert_contains('/_companion/alpha/rsx_sdk_contract_probe', static::__requests($s), 'ready() proves the contract with the application');
        });
    }

    public static function test_nothing_runs_until_desired_and_stop_is_graceful()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'sdk', ['environment' => ['FIXTURE_STOP_MS' => '300']]);
            static::__start_daemon($s);

            static::__send($s, ['verb' => 'reload', 'wait' => true]);
            static::__assert_equals('stopped', static::__status($s, 'alpha')['status'], 'no flag file, nothing runs');

            static::__desire($s, 'alpha', true);
            $summary = static::__wait_status($s, 'alpha', 'running');
            $pid = (int) $summary['pid'];

            static::__desire($s, 'alpha', false);

            static::__assert_false(static::__alive($pid), 'a stop with wait answers after the child is gone');

            $last = static::__status($s, 'alpha')['last_exit'];
            static::__assert_equals('stopped', $last['how_ended']);
            static::__assert_equals(0, $last['code']);
            static::__assert_equals('stopped', static::__history($s, 'alpha')[0]['how_ended']);
            static::__assert_null(static::__redis_record($s, $summary['instance_id']), 'an ended record leaves the live set');
        });
    }

    public static function test_the_grace_ends_in_sigkill_of_the_whole_group()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'ignore_term', ['shutdown_grace_seconds' => 1]);
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);

            $summary = static::__wait_status($s, 'alpha', 'running');
            static::__desire($s, 'alpha', false);

            static::__assert_false(static::__alive((int) $summary['pid']), 'SIGKILL ends a child that ignores SIGTERM');
            static::__assert_equals('killed', static::__status($s, 'alpha')['last_exit']['how_ended']);
        });
    }

    public static function test_an_exit_takes_the_childs_whole_process_group()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'grandchild');
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);

            $summary = static::__wait_status($s, 'alpha', 'running');
            $child = static::__fixture_record($s, $summary['instance_id']);

            static::__assert_true(static::__alive($child['grandchild']), 'the grandchild runs');

            static::__desire($s, 'alpha', false);

            static::__poll_until(fn () => !static::__alive($child['grandchild']), 'the grandchild that ignores SIGTERM was killed with its group');
        });
    }

    public static function test_children_are_reaped_and_backoff_cannot_storm()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'exit_1', [
                'restart_backoff_initial_seconds' => 1,
                'restart_backoff_ceiling_seconds' => 2,
            ]);
            $daemon = static::__start_daemon($s);
            static::__desire($s, 'alpha', true);

            $started = microtime(true);
            static::__poll_until(fn () => static::__status($s, 'alpha')['restart_count'] >= 3, 'three restarts of a crashing companion');
            $elapsed = microtime(true) - $started;

            // The delays before restarts 1, 2 and 3 are 1, 2 and 2 seconds: 5 at least, however
            // fast the box is. A storm would be three restarts in a fraction of that.
            static::__assert_greater_than(4.5, $elapsed, 'three restarts took at least the backoff they owe');

            $summary = static::__status($s, 'alpha');
            static::__assert_equals('crashed', $summary['last_exit']['status']);
            static::__assert_contains('crashed', static::__requests($s), 'each crash fires companion.error through the event verb');

            static::__assert_equals([], static::__zombie_children($daemon->getPid()), 'no exited child is left unreaped');
        });
    }

    public static function test_waiting_never_counts_as_a_restart()
    {
        static::__in_scratch(function (array $s) {
            file_put_contents($s['app'] . '/ready', json_encode(['ready' => false, 'scope' => 'install', 'reason' => '2 migrations are pending']));

            static::__declare($s, 'alpha', 'sdk');
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);

            static::__poll_until(
                fn () => static::__status($s, 'alpha')['waiting_reason'] === 'install not ready: 2 migrations are pending',
                'the companion waits with the install reason'
            );

            unlink($s['app'] . '/ready');

            // A poke stands for the recheck interval: the daemon asks again on its own later.
            $summary = static::__wait_status($s, 'alpha', 'running', true);

            static::__assert_equals(0, $summary['restart_count'], 'waiting never counts');
        });
    }

    public static function test_maintenance_holds_companions_down_and_desired_state_survives()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'sdk');
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);
            static::__wait_status($s, 'alpha', 'running');

            touch($s['storage'] . '/state/companions/.maintenance');
            static::__send($s, ['verb' => 'reload', 'wait' => true]);

            $summary = static::__status($s, 'alpha');
            static::__assert_equals('stopped', $summary['status'], 'the overlay stops it');
            static::__assert_equals('running', $summary['desired'], 'the overlay leaves desired state alone');

            unlink($s['storage'] . '/state/companions/.maintenance');
            static::__wait_status($s, 'alpha', 'running', true);

            file_put_contents($s['storage'] . '/state/.maintenance.mode.framework.update', "test\nmode=production\n");
            static::__send($s, ['verb' => 'reload', 'wait' => true]);
            static::__assert_equals('stopped', static::__status($s, 'alpha')['status'], 'the framework window holds it down too');
        });
    }

    public static function test_an_operator_stop_is_held_until_started_and_pause_reaches_the_sdk()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'sdk');
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);
            static::__wait_status($s, 'alpha', 'running');

            static::__send($s, ['verb' => 'control', 'name' => 'alpha', 'action' => 'pause']);
            static::__assert_equals('paused', static::__status($s, 'alpha')['status']);
            static::__send($s, ['verb' => 'control', 'name' => 'alpha', 'action' => 'resume']);
            static::__assert_equals('running', static::__status($s, 'alpha')['status']);

            static::__send($s, ['verb' => 'control', 'name' => 'alpha', 'action' => 'stop', 'wait' => true]);
            $summary = static::__status($s, 'alpha');
            static::__assert_equals('stopped', $summary['status']);
            static::__assert_true($summary['held']);
            static::__assert_equals('running', $summary['desired'], 'an operator stop leaves desired state alone');

            static::__send($s, ['verb' => 'control', 'name' => 'alpha', 'action' => 'start', 'wait' => true]);
            static::__wait_status($s, 'alpha', 'running');
        });
    }

    public static function test_pause_is_refused_for_a_child_that_does_not_use_the_sdk()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'ignore_term', ['shutdown_grace_seconds' => 1]);
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);
            static::__wait_status($s, 'alpha', 'running');

            $answer = static::__send($s, ['verb' => 'control', 'name' => 'alpha', 'action' => 'pause'], false);
            static::__assert_false($answer['ok']);
            static::__assert_contains('does not use the SDK', $answer['error']);
        });
    }

    public static function test_a_contract_mismatch_restarts_on_the_new_contract_uncounted_as_a_crash()
    {
        static::__in_scratch(function (array $s) {
            // Development with policy ignore: only the child's own exit can restart it.
            static::__declare($s, 'alpha', 'exit_contract', ['on_code_change' => 'ignore']);
            static::__start_daemon($s, ['RSX_MODE' => 'development']);
            static::__desire($s, 'alpha', true);

            $first = static::__wait_status($s, 'alpha', 'running');

            static::__declare($s, 'alpha', 'sdk', ['on_code_change' => 'ignore'], 'moved');
            touch($s['fixtures'] . '/go');

            static::__poll_until(function () use ($s, $first) {
                $now = static::__status($s, 'alpha');

                return $now['instance_id'] !== null && $now['instance_id'] !== $first['instance_id'] && $now['status'] === 'running';
            }, 'a new instance after the contract mismatch exit');

            $summary = static::__status($s, 'alpha');
            static::__assert_equals(0, $summary['crash_streak'], 'a moved contract is not a crash');
            static::__assert_equals(static::__contract('alpha', 'moved'), $summary['contract_key']);
            static::__assert_equals('contract_mismatch', static::__history($s, 'alpha')[0]['how_ended']);
        });
    }

    public static function test_daemon_death_leaves_no_orphans()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'sdk');
            static::__declare($s, 'beta', 'ignore_term', ['shutdown_grace_seconds' => 1]);
            $daemon = static::__start_daemon($s);
            static::__desire($s, 'alpha', true);
            static::__desire($s, 'beta', true);

            $sdk_pid = (int) static::__wait_status($s, 'alpha', 'running')['pid'];
            $plain_pid = (int) static::__wait_status($s, 'beta', 'running')['pid'];

            $daemon->signal(SIGKILL);

            static::__poll_until(fn () => !static::__alive($sdk_pid), 'the SDK child exits on its control pipe\'s end of file');
            static::__assert_true(static::__alive($plain_pid), 'a child with no SDK outlives a killed daemon');

            static::__start_daemon($s);

            static::__poll_until(fn () => !static::__alive($plain_pid), 'the next daemon sweeps the orphan by its process group');
            static::__assert_contains('orphan swept: beta', (string) @file_get_contents($s['storage'] . '/logs/rsx-companiond.log'));
        });
    }

    public static function test_a_stalled_log_writer_never_blocks_a_child()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'flood', ['shutdown_grace_seconds' => 1]);

            // A FIFO nobody reads: opening it for writing never completes, so the log writer is
            // stalled for good. The pipe buffer is 64 KiB; the child writes far past it only if
            // the daemon keeps reading.
            ensure_directory($s['storage'] . '/logs/companions/alpha');
            posix_mkfifo($s['storage'] . '/logs/companions/alpha/alpha.log', 0600);

            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);

            $instance = static::__wait_status($s, 'alpha', 'running')['instance_id'];
            $counter = $s['fixtures'] . '/' . $instance . '.flood';

            // Past the daemon's own 32 MiB queue bound, so the drop path is exercised too.
            static::__poll_until(
                fn () => (int) @file_get_contents($counter) > 48 * 1024 * 1024,
                'the flooding child kept writing with its log writer stalled'
            );
        }, true);
    }

    public static function test_logs_rotate_by_size_and_compress()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'flood', [
                'shutdown_grace_seconds' => 1,
                'logging' => ['max_bytes' => 1048576, 'days_uncompressed' => 0, 'days_retention' => 0],
            ]);
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);

            $directory = $s['storage'] . '/logs/companions/alpha';

            static::__poll_until(fn () => is_file($directory . '/alpha.log.3') || is_file($directory . '/alpha.log.3.gz'), 'three rotated generations');
            static::__poll_until(fn () => glob($directory . '/alpha.log.*.gz') !== [], 'a generation older than days_uncompressed is compressed');

            static::__desire($s, 'alpha', false);
        });
    }

    public static function test_the_socket_requires_the_daemon_key()
    {
        static::__in_scratch(function (array $s) {
            $daemon = static::__start_daemon($s);

            $ping = static::__raw($s, [['verb' => 'ping', 'id' => 1]]);
            static::__assert_equals($daemon->getPid(), $ping[0]['pid'], 'ping needs no key');

            $refused = static::__raw($s, [['verb' => 'status', 'id' => 1]]);
            static::__assert_equals('hello_required', $refused[0]['error']);

            $bad = static::__raw($s, [['verb' => 'hello', 'id' => 1, 'ts' => time(), 'sig' => str_repeat('0', 64)]]);
            static::__assert_equals('invalid_hello', $bad[0]['error']);
        });
    }

    public static function test_records_are_republished_after_redis_loses_them()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'sdk');
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);
            $instance = static::__wait_status($s, 'alpha', 'running')['instance_id'];

            static::__redis()->del([$s['prefix'] . 'epoch', $s['prefix'] . 'live', $s['prefix'] . 'instance:' . $instance]);

            static::__poll_until(fn () => (static::__redis_record($s, $instance)['status'] ?? null) === 'running', 'the record is back after the epoch moved');
            static::__assert_true((bool) static::__redis()->sIsMember($s['prefix'] . 'live', $instance));
        });
    }

    public static function test_a_source_edit_restarts_it_in_development()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'sdk');
            static::__start_daemon($s, ['RSX_MODE' => 'development']);
            static::__desire($s, 'alpha', true);
            $first = static::__wait_status($s, 'alpha', 'running')['instance_id'];

            file_put_contents($s['project'] . '/companions/alpha/main.txt', 'edited');

            static::__poll_until(function () use ($s, $first) {
                $now = static::__status($s, 'alpha');

                return $now['status'] === 'running' && $now['instance_id'] !== $first;
            }, 'a new instance after the companion\'s own source changed');
        });
    }

    public static function test_restart_policy_never_leaves_it_ended()
    {
        static::__in_scratch(function (array $s) {
            static::__declare($s, 'alpha', 'exit_0', ['restart_policy' => 'never']);
            static::__start_daemon($s);
            static::__desire($s, 'alpha', true);

            static::__poll_until(fn () => (static::__status($s, 'alpha')['last_exit']['how_ended'] ?? null) === 'exited', 'the companion exited');
            static::__send($s, ['verb' => 'reload', 'wait' => true]);

            $summary = static::__status($s, 'alpha');
            static::__assert_equals('exited', $summary['status']);
            static::__assert_equals(0, $summary['restart_count']);
        });
    }

    public static function test_single_instance_is_elected_through_rsx_lockd()
    {
        $lockd_host = (string) env('LOCK_SERVER_HOST', '127.0.0.1');
        $lockd_port = (int) env('LOCK_SERVER_PORT', 6210);
        $probe = @fsockopen($lockd_host, $lockd_port);

        if ($probe === false) {
            static::__skip("rsx-lockd is not reachable at {$lockd_host}:{$lockd_port}");
        }
        fclose($probe);

        static::__in_scratch(function (array $s) {
            $b = static::__second_storage($s);

            static::__declare($s, 'alpha', 'sdk', ['single_instance' => true]);
            $daemon_a = static::__start_daemon($s);
            $daemon_b = static::__start_daemon($b);
            static::__desire($s, 'alpha', true);
            static::__desire($b, 'alpha', true);

            static::__poll_until(function () use ($s, $b) {
                $states = [static::__status($s, 'alpha')['status'], static::__status($b, 'alpha')['status']];
                sort($states);

                return $states === ['running', 'waiting'];
            }, 'one host runs the companion and the other waits');

            $a_runs = static::__status($s, 'alpha')['status'] === 'running';
            [$loser, $winner_daemon] = $a_runs ? [$b, $daemon_a] : [$s, $daemon_b];
            static::__assert_equals('another host holds the instance', static::__status($loser, 'alpha')['waiting_reason']);

            $winner_daemon->signal(SIGKILL);

            static::__wait_status($loser, 'alpha', 'running');
        });
    }

    public static function test_supervisor_waits_out_the_companions_grace()
    {
        static::__assert_shipped_program_shape();

        $supervisord = (new ExecutableFinder())->find('supervisord');
        $supervisorctl = (new ExecutableFinder())->find('supervisorctl');
        if ($supervisord === null || $supervisorctl === null) {
            static::__skip('supervisord is not installed on this box');
        }

        static::__in_scratch(function (array $s) use ($supervisord, $supervisorctl) {
            static::__declare($s, 'alpha', 'sdk', ['environment' => ['FIXTURE_STOP_MS' => '3000']]);
            touch($s['storage'] . '/state/companions/alpha.running');

            $conf = static::__supervisor_conf($s);
            static::__run([$supervisord, '-c', $conf]);

            try {
                $summary = static::__wait_status($s, 'alpha', 'running');

                $started = microtime(true);
                static::__run([$supervisorctl, '-c', $conf, 'stop', 'rsx-companiond']);
                $elapsed = microtime(true) - $started;

                static::__assert_greater_than(2.9, $elapsed, 'supervisorctl stop returned only after the 3 second graceful stop');
                static::__assert_false(static::__alive((int) $summary['pid']), 'the companion had exited when supervisor said stopped');
                static::__assert_contains('instance ' . $summary['instance_id'] . ' ended: stopped (exit 0)', (string) file_get_contents($s['storage'] . '/logs/companions/alpha/alpha.log'));
            } finally {
                static::__run([$supervisorctl, '-c', $conf, 'shutdown']);
            }
        });
    }

    // ------------------------------------------------------------------------------------
    // The shipped supervisor program
    // ------------------------------------------------------------------------------------

    /**
     * The properties that make supervisor's stop wait for the companions: the daemon is the
     * program's main pid (no retry wrapper, the runner ends in exec), and stopwaitsecs exceeds
     * the largest grace a build accepts.
     */
    private static function __assert_shipped_program_shape(): void
    {
        $conf = (string) file_get_contents(base_path('app/RSpade/resource/docker/supervisor/conf.d/rsx-companiond.conf'));

        static::__assert_true((bool) preg_match('/^command=bash \S+\/companiond-run\.sh$/m', $conf), 'the program runs the runner directly, never through rspade-retry-run');
        static::__assert_true((bool) preg_match('/^stopwaitsecs=(\d+)$/m', $conf, $match), 'stopwaitsecs is set');
        static::__assert_greater_than(\App\RSpade\Core\Companion\Companion_Service_Abstract::MAX_SHUTDOWN_GRACE_SECONDS, (int) $match[1]);

        $runner = (string) file_get_contents(base_path('bin/rsx-companiond/companiond-run.sh'));
        static::__assert_true((bool) preg_match('/^exec "\$NODE_BIN" "\$ENTRY" run "\$@"$/m', $runner), 'the runner replaces itself with node');
    }

    private static function __supervisor_conf(array $s): string
    {
        $shipped = (string) file_get_contents(base_path('app/RSpade/resource/docker/supervisor/conf.d/rsx-companiond.conf'));
        preg_match('/^stopwaitsecs=(\d+)$/m', $shipped, $match);

        $environment = [];
        foreach (static::__daemon_env($s) as $key => $value) {
            $environment[] = $key . '="' . $value . '"';
        }

        $command = 'bash ' . base_path('bin/rsx-companiond/companiond-run.sh')
            . ' --project-root=' . $s['project'] . ' --build-root=' . $s['build'] . ' --host=' . $s['host'];

        $conf = "[unix_http_server]\nfile={$s['root']}/supervisor.sock\n\n"
            . "[supervisord]\nlogfile={$s['root']}/supervisord.log\npidfile={$s['root']}/supervisord.pid\n\n"
            . "[rpcinterface:supervisor]\nsupervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface\n\n"
            . "[supervisorctl]\nserverurl=unix://{$s['root']}/supervisor.sock\n\n"
            . "[program:rsx-companiond]\ncommand={$command}\nautostart=true\nautorestart=true\nstartsecs=1\n"
            . "stopwaitsecs={$match[1]}\nenvironment=" . implode(',', $environment) . "\n"
            . "stdout_logfile={$s['root']}/companiond.out\nredirect_stderr=true\n";

        $file = $s['root'] . '/supervisord.conf';
        file_put_contents($file, $conf);

        return $file;
    }

    // ------------------------------------------------------------------------------------
    // Scratch trees
    // ------------------------------------------------------------------------------------

    /**
     * Run $test in a fresh scratch tree with a fake application, and remove every process and
     * key it made afterwards, whatever happened.
     */
    private static function __in_scratch(callable $test, bool $kill_daemons_first = false): void
    {
        $root = rtrim(sys_get_temp_dir(), '/') . '/companiond-test-' . bin2hex(random_bytes(4));
        $database = 'companiond_test_' . bin2hex(random_bytes(6));
        $host = (string) config('database.connections.' . config('database.default') . '.host');

        $s = [
            'root' => $root,
            'host' => 'scratch-a',
            'storage' => $root . '/storage',
            'build' => $root . '/build',
            'project' => $root . '/project',
            'fixtures' => $root . '/fixtures',
            'app' => $root . '/app',
            'database' => $database,
            'db_host' => $host,
            'prefix' => 'rsx_companion:' . md5($database . '@' . $host) . ':',
        ];

        foreach (['storage', 'build', 'project', 'fixtures', 'app'] as $key) {
            ensure_directory($s[$key]);
        }
        ensure_directory($s['storage'] . '/logs');
        ensure_directory($s['storage'] . '/state/companions');
        ensure_directory($s['build'] . '/companions');
        file_put_contents($s['build'] . '/build_key', 'scratch');

        static::$__processes = [];

        $app = new Process(['node', base_path('app/RSpade/tests/companions/resource/fake_app.js'), $s['app']]);
        $app->setTimeout(null);
        $app->start();
        static::$__processes[] = $app;

        static::__poll_until(fn () => is_file($s['app'] . '/port'), 'the fake application is listening');
        $s['base_url'] = 'http://127.0.0.1:' . trim((string) file_get_contents($s['app'] . '/port'));

        try {
            $test($s);
        } finally {
            static::__clean($s, $kill_daemons_first);
        }
    }

    /** A second storage root over the same build, project and Redis prefix: another host. */
    private static function __second_storage(array $s): array
    {
        $b = $s;
        $b['host'] = 'scratch-b';
        $b['storage'] = $s['root'] . '/storage-b';
        ensure_directory($b['storage'] . '/logs');
        ensure_directory($b['storage'] . '/state/companions');

        return $b;
    }

    private static function __clean(array $s, bool $kill_first): void
    {
        foreach (array_reverse(static::$__processes) as $process) {
            if ($process->isRunning()) {
                $process->signal($kill_first ? SIGKILL : SIGTERM);
            }
        }

        foreach (array_reverse(static::$__processes) as $process) {
            if ($process->isRunning()) {
                $process->wait();
            }
        }

        // Whatever a killed daemon left: its recorded groups, in every storage root.
        foreach (glob($s['root'] . '/storage*/state/companiond.children.json') ?: [] as $file) {
            foreach (json_decode((string) file_get_contents($file), true) ?: [] as $child) {
                @posix_kill(-$child['pid'], SIGKILL);
            }
        }

        $redis = static::__redis();
        $iterator = null;
        while (($keys = $redis->scan($iterator, $s['prefix'] . '*', 1000)) !== false) {
            if ($keys !== []) {
                $redis->del($keys);
            }
        }

        static::__run(['rm', '-rf', $s['root']]);
    }

    // ------------------------------------------------------------------------------------
    // Declaring and running
    // ------------------------------------------------------------------------------------

    private static function __contract(string $name, string $version = 'v1'): string
    {
        return hash('sha256', $name . '|' . $version);
    }

    /**
     * Write a companion's descriptor and client into the scratch build, as the manifest would,
     * and move the build key.
     */
    private static function __declare(array $s, string $name, string $mode, array $overrides = [], string $version = 'v1'): void
    {
        $directory = $s['project'] . '/companions/' . $name;
        ensure_directory($directory);
        if (!is_file($directory . '/main.txt')) {
            file_put_contents($directory . '/main.txt', $name);
        }

        $contract = static::__contract($name, $version);

        $descriptor = array_replace([
            'schema' => 1,
            'name' => $name,
            'description' => 'A process test fixture',
            'class' => 'Fixture',
            'directory' => 'companions/' . $name,
            'command' => ['node', base_path('app/RSpade/tests/companions/resource/fixture_child.js'), $mode],
            'working_directory' => '.',
            'environment' => [],
            'shutdown_grace_seconds' => 30,
            'restart_policy' => 'always',
            'restart_backoff_initial_seconds' => 1,
            'restart_backoff_ceiling_seconds' => 300,
            'single_instance' => false,
            'on_code_change' => 'restart_if_affected',
            'endpoints_hash' => $contract,
            'source_hash' => 'scratch',
            'endpoints' => ['ping'],
            'logging' => ['max_bytes' => 52428800, 'days_uncompressed' => 2, 'days_retention' => 30],
            'history_keep' => 50,
            'output_stream_max_lines' => 5000,
        ], $overrides);

        $descriptor['environment'] = (object) array_merge(['FIXTURE_DIR' => $s['fixtures']], $overrides['environment'] ?? []);

        $build = $s['build'] . '/companions/' . $name;
        ensure_directory($build);
        file_put_contents($build . '/descriptor.json', json_encode($descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($build . '/client.js', "'use strict';\nmodule.exports = { name: '{$name}', endpoints_hash: '{$contract}', endpoints: ['ping'], bind: (call) => ({ ping: (params) => call('ping', params) }) };\n");
        file_put_contents($s['build'] . '/build_key', bin2hex(random_bytes(8)));
    }

    private static function __daemon_env(array $s, array $extra = []): array
    {
        return array_merge([
            'RSX_STORAGE_PATH' => $s['storage'],
            'DB_DATABASE' => $s['database'],
            'DB_HOST' => $s['db_host'],
            'RSX_COMPANION_BASE_URL' => $s['base_url'],
            'RSX_MODE' => 'production',
        ], $extra);
    }

    private static function __start_daemon(array $s, array $env = []): Process
    {
        $process = new Process(
            [
                'node', base_path('bin/rsx-companiond/companiond.js'), 'run',
                '--project-root=' . $s['project'],
                '--build-root=' . $s['build'],
                '--host=' . $s['host'],
            ],
            null,
            static::__daemon_env($s, $env)
        );
        $process->setTimeout(null);
        $process->start();
        static::$__processes[] = $process;

        static::__poll_until(function () use ($s, $process) {
            $answer = static::__raw($s, [['verb' => 'ping', 'id' => 1]]);

            return ($answer[0]['pid'] ?? null) === $process->getPid();
        }, 'the scratch daemon answers ping (' . $process->getErrorOutput() . ')');

        return $process;
    }

    private static function __desire(array $s, string $name, bool $running): void
    {
        $file = $s['storage'] . '/state/companions/' . $name . '.running';

        if ($running) {
            touch($file);
        } elseif (is_file($file)) {
            unlink($file);
        }

        static::__send($s, ['verb' => 'reload', 'wait' => true]);
    }

    // ------------------------------------------------------------------------------------
    // Observing
    // ------------------------------------------------------------------------------------

    /**
     * One authenticated verb. Answers the reply; with $require_ok, fails the test on a refusal.
     */
    private static function __send(array $s, array $frame, bool $require_ok = true): array
    {
        $timestamp = time();
        $answers = static::__raw($s, [
            ['verb' => 'hello', 'id' => 1, 'ts' => $timestamp, 'sig' => hash_hmac('sha256', 'companiond-socket|' . $timestamp, Companion_Verifier::daemon_key())],
            $frame + ['id' => 2],
        ]);

        static::__assert_true($answers[0]['ok'] ?? false, 'the daemon accepted the hello');

        if ($require_ok) {
            static::__assert_true($answers[1]['ok'] ?? false, 'the daemon refused ' . $frame['verb'] . ': ' . ($answers[1]['error'] ?? ''));
        }

        return $answers[1];
    }

    /** Frames in, one reply per frame out, over a fresh connection. */
    private static function __raw(array $s, array $frames): array
    {
        $socket = @stream_socket_client('unix://' . $s['storage'] . '/state/companiond.sock');

        if ($socket === false) {
            return [];
        }

        $answers = [];
        foreach ($frames as $frame) {
            fwrite($socket, json_encode($frame) . "\n");
            $answers[] = json_decode((string) fgets($socket), true);
        }
        fclose($socket);

        return $answers;
    }

    private static function __status(array $s, string $name): array
    {
        return static::__send($s, ['verb' => 'status', 'name' => $name])['companions'][0];
    }

    /** Wait for a status; with $poke, a reload each round stands in for the daemon's recheck interval. */
    private static function __wait_status(array $s, string $name, string $status, bool $poke = false): array
    {
        $summary = null;

        static::__poll_until(function () use ($s, $name, $status, $poke, &$summary) {
            if (static::__raw($s, [['verb' => 'ping', 'id' => 1]]) === []) {
                return false;
            }
            if ($poke) {
                static::__send($s, ['verb' => 'control', 'name' => $name, 'action' => 'start']);
            }
            $summary = static::__status($s, $name);

            return $summary['status'] === $status;
        }, "{$name} reaches {$status}");

        return $summary;
    }

    private static function __fixture_record(array $s, string $instance_id): array
    {
        $file = $s['fixtures'] . '/' . $instance_id . '.json';
        static::__poll_until(fn () => is_file($file), 'the fixture child recorded itself');

        return json_decode((string) file_get_contents($file), true);
    }

    private static function __requests(array $s): string
    {
        return (string) @file_get_contents($s['app'] . '/requests');
    }

    private static function __redis(): Redis
    {
        return \App\RSpade\Core\Companion\Companion_Store::connection();
    }

    private static function __redis_record(array $s, string $instance_id): ?array
    {
        $record = static::__redis()->hGetAll($s['prefix'] . 'instance:' . $instance_id);

        return $record === [] ? null : $record;
    }

    private static function __history(array $s, string $name): array
    {
        return array_map(fn ($entry) => json_decode($entry, true), static::__redis()->lRange($s['prefix'] . 'history:' . $name, 0, -1));
    }

    private static function __alive(int $pid): bool
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');

        if ($stat === false) {
            return false;
        }

        // A zombie has exited: it is waiting for its parent to reap it.
        return substr($stat, strrpos($stat, ')') + 2, 1) !== 'Z';
    }

    /** The daemon's children in state Z. */
    private static function __zombie_children(int $daemon_pid): array
    {
        $zombies = [];

        foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
            $stat = @file_get_contents($file);
            if ($stat === false) {
                continue;
            }

            $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
            if ((int) $fields[1] === $daemon_pid && $fields[0] === 'Z') {
                $zombies[] = basename(dirname($file));
            }
        }

        return $zombies;
    }

    private static function __run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();
    }

    /**
     * A bounded poll of a condition, the house shape. The 120 s bound is a wait on processes
     * this test does not control, expiry truncates no work, and it FAILS the test naming the
     * condition: without it a broken daemon would hang the suite with no evidence at all.
     */
    private static function __poll_until(callable $condition, string $condition_description): void
    {
        $deadline = microtime(true) + 120;

        do {
            if ($condition()) {
                return;
            }

            usleep(50000);
        } while (microtime(true) < $deadline);

        static::__fail($condition_description . ' (waited 120s)');
    }
}
