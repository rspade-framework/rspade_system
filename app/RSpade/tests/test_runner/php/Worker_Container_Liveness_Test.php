<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TestRunner\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A test container ends ITSELF when its orchestrator dies - SIGKILL included.
 *
 * No handler survives SIGKILL, so this half of "a run's containers die with their run" is
 * structural: the worker wrapper (resource/docker/rsx-test-worker-run.sh) holds a connection
 * to the orchestrator's queue socket from the first moment of the container, and when the
 * kernel closes it - because the process serving it is gone - the wrapper kills everything in
 * the container and exits, and the container (run --rm) is removed.
 *
 * Asserted against the REAL thing: the real test image, the checkout's own wrapper
 * bind-mounted over the image's copy (so what runs is the code under test, not whatever the
 * last image build baked), and a real Queue_Server in a node process that is SIGKILLed. The
 * container is started the way the orchestrator starts one - same label, tmpfs, ipc mount,
 * hostname and CMD.
 *
 * $explicit_group_only: it needs a docker daemon and an already-built rspade-test:latest,
 * and it starts a full container stack. Run it on the development box, outside docker
 * dispatch:
 *
 *     php artisan rsx:test --framework Worker_Container_Liveness_Test --sequential
 *
 * Inside a dispatched test container there is no daemon, and it skips saying so.
 *
 * EVERY WAIT IS ON AN EVENT - the server's "serving" line, the wrapper's "holding" line,
 * a process exiting - never a deadline. If the mechanism under test is broken, the container
 * never exits and this test visibly hangs on it: that is the fault, shown rather than masked.
 */
class Worker_Container_Liveness_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    protected static $explicit_group_only = true;

    private const IMAGE = 'rspade-test:latest';

    private const RUN_LABEL = 'rsx-test-run';

    /**
     * SIGKILL the orchestrator's queue server while a container is up: the container ends
     * itself, says why, and leaves nothing behind.
     */
    public static function test_a_container_ends_when_its_orchestrator_is_killed()
    {
        self::__require_docker();

        $run_id = 'test-run-liveness-' . bin2hex(random_bytes(4));
        $name = 'rsx-test-liveness-' . bin2hex(random_bytes(4));
        $dir = Rsx_Project_Paths::tmp_path($run_id);
        ensure_directory($dir . '/ipc');

        $server = null;
        $container = null;

        try {
            // The orchestrator's queue, served by a node process of its own so it can be
            // SIGKILLed like the orchestrator would be.
            $server = new Process([
                'node', '-e',
                'const { Queue_Server } = require(process.argv[1]);'
                . 'const q = new Queue_Server({ socket_path: process.argv[2], classes: [], results_path: process.argv[3] });'
                . 'q.start().then(() => { process.stdout.write("serving\n"); });',
                base_path('bin/rsx-testd/lib/queue_server.js'),
                $dir . '/ipc/orchestrator.sock',
                $dir . '/results.jsonl',
            ]);
            $server->setTimeout(null);
            $server->start();
            $server->waitUntil(static fn ($type, $output) => str_contains($server->getOutput(), 'serving'));
            static::__assert_true($server->isRunning(), 'the queue server is serving: ' . $server->getErrorOutput());

            $host_name = $name . '.dev.local';
            $container = new Process([
                'docker', 'run', '--rm',
                '--name', $name,
                '--hostname', $host_name,
                '--add-host', $host_name . ':127.0.0.1',
                '--label', self::RUN_LABEL . '=' . $run_id,
                '--tmpfs', '/var/lib/mysql:size=2g',
                '-v', $dir . '/ipc:/rsx-test-ipc',
                '-v', base_path('app/RSpade/resource/docker/rsx-test-worker-run.sh') . ':/usr/local/bin/rsx-test-worker-run:ro',
                self::IMAGE,
                'bash', '/usr/local/bin/rsx-test-worker-run', '1', '/rsx-test-ipc/orchestrator.sock', 'framework',
            ]);
            $container->setTimeout(null);
            $container->start();

            // The wrapper announces the connection it holds; the container may also end
            // before that (a broken image), which waitUntil() returns on too.
            $container->waitUntil(static fn () => str_contains(
                $container->getOutput() . $container->getErrorOutput(),
                'holding the orchestrator connection'
            ));
            static::__assert_true(
                $container->isRunning(),
                'the container is up and holding the orchestrator connection: '
                    . $container->getOutput() . $container->getErrorOutput()
            );

            // The orchestrator dies the one way nothing can intercept.
            $server->signal(SIGKILL);
            $server->wait();

            // No `docker kill`, no sweep, no new run: the container has to end on its own.
            $container->wait();

            $output = $container->getOutput() . $container->getErrorOutput();
            static::__assert_contains(
                'the orchestrator is gone',
                $output,
                'the wrapper ended the container because the orchestrator connection closed'
            );

            static::__assert_equals(
                '',
                self::__docker_ps($run_id),
                'no container carrying this run\'s label is left, running or stopped'
            );
        } finally {
            if ($container !== null && $container->isRunning()) {
                exec_safe('docker kill ' . escapeshellarg($name) . ' > /dev/null 2>&1');
                $container->wait();
            }
            if ($server !== null && $server->isRunning()) {
                $server->signal(SIGKILL);
                $server->wait();
            }
            exec_safe('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * Skip - never fail - where the mechanism cannot be exercised: no daemon (inside a
     * dispatched test container there is none), or no test image yet.
     */
    private static function __require_docker(): void
    {
        $output = [];
        $code = 0;
        exec_safe('docker info > /dev/null 2>&1', $output, $code);
        if ($code !== 0) {
            static::__skip('no reachable docker daemon - run this class on the development box with --sequential');
        }

        exec_safe('docker image inspect ' . escapeshellarg(self::IMAGE) . ' > /dev/null 2>&1', $output, $code);
        if ($code !== 0) {
            static::__skip(self::IMAGE . ' is not built yet - any docker-dispatched rsx:test run builds it');
        }
    }

    /**
     * Ids of every container, running or stopped, carrying this run's label.
     */
    private static function __docker_ps(string $run_id): string
    {
        $output = [];
        $code = 0;
        exec_safe(
            'docker ps -a -q --filter ' . escapeshellarg('label=' . self::RUN_LABEL . '=' . $run_id),
            $output,
            $code
        );

        return trim(implode("\n", $output));
    }
}
