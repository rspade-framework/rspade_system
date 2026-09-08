<?php

namespace App\RSpade\Tests\TestRunner\Php;

use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\RSpade\Commands\Rsx\Rsx_Test_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Two decisions taken before a single test runs: WHETHER this invocation goes to docker,
 * and WHAT its verdict is recorded under. Both are cheap to get wrong in a way nobody
 * notices - a gate that opens on a box whose daemon cannot actually start a container
 * wastes an image build to reach an infrastructure failure, and a result cache that
 * forgets WHICH classes ran replays four green classes as a green suite.
 *
 * THE GATE ASKS ABOUT THE BOX AND NOTHING ELSE. Every invocation it passes is dispatched -
 * both suites, any selector, one class or the whole run - so what is asserted here is the
 * decision table of its four checks, each one closing it with ONE line, plus the structural
 * fact that it takes no selector to consider.
 *
 * @PHP-REFLECT-01-EXCEPTION worker_count(), selector_key(), results_cache_path() and
 * docker_mode_gate_passes() are protected decisions on an artisan command; their only other
 * caller is handle(), which would run tests. Reflection asserts the decision without taking
 * the action. evaluate_docker_gate() itself is public precisely so it needs none.
 */
class Docker_Dispatch_Test extends Rsx_Test_Abstract
{
    /**
     * Reads /proc, hashes strings and answers questions - no database.
     *
     * @var bool
     */
    protected static $use_database_transactions = false;

    /**
     * The probe set of a box where everything works. Each test overrides the one probe it
     * is about, so a failing check is asserted against an otherwise perfect environment.
     *
     * @param array $overrides
     * @return array<string,callable>
     */
    protected static function __probes(array $overrides = []): array
    {
        return array_merge([
            'dev_container' => fn (): bool => true,
            'docker_info' => fn (): bool => true,
            'dev_image' => fn (): string => 'ok',
            'build_dev_image' => fn (): bool => true,
            'trivial_run' => fn (): bool => true,
        ], $overrides);
    }

    /**
     * A command with an ArrayInput and a buffered output, so an option can be asserted
     * without an artisan invocation.
     *
     * @param array $options
     * @return Rsx_Test_Command
     */
    protected static function __make_command(array $options = []): Rsx_Test_Command
    {
        $command = new Rsx_Test_Command();
        $command->setLaravel(app());

        $input = new ArrayInput($options, $command->getDefinition());
        $buffer = new BufferedOutput();
        $buffer->setDecorated(false);

        $property = new ReflectionProperty(Rsx_Test_Command::class, 'input');
        $property->setAccessible(true);
        $property->setValue($command, $input);

        $property = new ReflectionProperty(Rsx_Test_Command::class, 'output');
        $property->setAccessible(true);
        $property->setValue($command, new SymfonyStyle($input, $buffer));

        return $command;
    }

    /**
     * @param Rsx_Test_Command $command
     * @param string $method
     * @param array $args
     * @return mixed
     */
    protected static function __call_protected(Rsx_Test_Command $command, string $method, array $args = [])
    {
        $reflection = new ReflectionMethod(Rsx_Test_Command::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($command, $args);
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    protected static function __invoke_static(string $method, array $args)
    {
        $reflection = new ReflectionMethod(Rsx_Test_Command::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }

    /**
     * A box where every check answers yes dispatches to docker - and the answer is NULL,
     * because the gate's return value IS the reason it closed.
     */
    public static function test_a_working_box_opens_the_gate()
    {
        static::__assert_null(
            Rsx_Test_Command::evaluate_docker_gate(self::__probes()),
            'dev container, live daemon, matched image, a container that runs -> docker mode'
        );
    }

    /**
     * Check 1. Nowhere else has the nested daemon or the shipped image, so nowhere else is
     * asked anything further.
     */
    public static function test_a_non_development_container_closes_the_gate()
    {
        $reason = Rsx_Test_Command::evaluate_docker_gate(self::__probes([
            'dev_container' => fn (): bool => false,
            // Every later probe would THROW if it were reached: the checks are ordered, and
            // a box that is not a development container is never asked about docker at all.
            'docker_info' => fn (): bool => shouldnt_happen('docker_info probed after the container check failed'),
        ]));

        static::__assert_true(is_string($reason), 'a closed gate names its check');
        static::__assert_true(
            str_contains((string) $reason, '/.rspade_container_dev'),
            'the line names the container marker: ' . $reason
        );
    }

    /**
     * Check 2. `docker info` is the whole probe - absent binary, dead daemon, unreachable
     * socket - and a box without it still runs its tests, sequentially.
     */
    public static function test_a_daemon_that_does_not_answer_closes_the_gate()
    {
        $reason = Rsx_Test_Command::evaluate_docker_gate(self::__probes([
            'docker_info' => fn (): bool => false,
            'dev_image' => fn (): string => shouldnt_happen('the image was probed after docker info failed'),
        ]));

        static::__assert_true(
            is_string($reason) && str_contains($reason, 'docker info'),
            'the line names the daemon probe: ' . $reason
        );
    }

    /**
     * Check 3: the dev image is BUILT on every invocation, before it is checked - a cached
     * docker build, instant when nothing changed, and the only guarantee the image matches
     * the checkout. Never a manual step.
     */
    public static function test_the_dev_image_is_built_on_every_invocation()
    {
        $built = false;

        $reason = Rsx_Test_Command::evaluate_docker_gate(self::__probes([
            'build_dev_image' => function () use (&$built): bool {
                $built = true;

                return true;
            },
        ]));

        static::__assert_null($reason, 'a built, matching image opens the gate');
        static::__assert_true($built, 'the image was built without any check deciding whether to');
    }

    /**
     * A build that fails, and a build after which the image still does not match, both close
     * the gate - with one line each: the first names the build command, the second the state.
     */
    public static function test_a_failed_or_mismatched_dev_image_build_closes_the_gate()
    {
        $reason = Rsx_Test_Command::evaluate_docker_gate(self::__probes([
            'build_dev_image' => fn (): bool => false,
            'dev_image' => fn (): string => shouldnt_happen('the image was checked after a failed build'),
            'trivial_run' => fn (): bool => shouldnt_happen('a container was run against an image that was never built'),
        ]));

        static::__assert_true(
            is_string($reason) && str_contains($reason, 'build.sh dev'),
            'the line names the build command: ' . $reason
        );

        $still_stale = Rsx_Test_Command::evaluate_docker_gate(self::__probes([
            'dev_image' => fn (): string => 'stale',
        ]));

        static::__assert_true(
            is_string($still_stale) && str_contains($still_stale, 'stale after building it'),
            'a build that did not produce the declared image closes the gate: ' . $still_stale
        );
    }

    /**
     * Check 4, the one the first three cannot make. Nested docker breaks in runc, in the
     * cgroup mount and in the network namespace; a daemon that answers proves none of them,
     * and a run that reached the containers to discover it would have cost an image build.
     */
    public static function test_a_daemon_that_cannot_run_a_container_closes_the_gate()
    {
        $reason = Rsx_Test_Command::evaluate_docker_gate(self::__probes([
            'trivial_run' => fn (): bool => false,
        ]));

        static::__assert_true(
            is_string($reason) && str_contains($reason, 'docker run'),
            'the line names the container probe: ' . $reason
        );
    }

    /**
     * --sequential is the explicit escape hatch: the operator saying so, ahead of every
     * check, and it prints nothing because nothing failed.
     */
    public static function test_sequential_closes_the_gate_before_any_probe()
    {
        $command = self::__make_command(['--sequential' => true]);

        static::__assert_false(
            (bool) self::__call_protected($command, 'docker_mode_gate_passes'),
            '--sequential forces the single-process runner'
        );
    }

    /**
     * THE DISPATCH RULE ITSELF. The gate takes no argument, so there is no suite and no
     * selector it could weigh: every invocation a passing box makes goes to the containers,
     * a single class included. This is the assertion that a future "just for subsets"
     * shortcut has to break on purpose.
     */
    public static function test_the_gate_considers_no_selector_at_all()
    {
        $reflection = new ReflectionMethod(Rsx_Test_Command::class, 'docker_mode_gate_passes');

        static::__assert_equals(
            0,
            $reflection->getNumberOfParameters(),
            'the gate is about the box - a suite or a selector it could read is a second dispatch rule'
        );
    }

    /**
     * The selector key is the suite plus the normalised class list: order and duplication
     * do not change it, membership does, and the suite does.
     */
    public static function test_the_selector_key_normalises_the_class_list()
    {
        $alpha = ['fqcn' => 'App\\Fixture\\Alpha_Test', 'short' => 'Alpha_Test'];
        $beta = ['fqcn' => 'App\\Fixture\\Beta_Test', 'short' => 'Beta_Test'];

        $one = self::__invoke_static('selector_key', ['framework', [$alpha, $beta]]);
        $reordered = self::__invoke_static('selector_key', ['framework', [$beta, $alpha, $alpha]]);

        static::__assert_equals($one, $reordered, 'order and duplication do not change the set');

        static::__assert_true(
            $one !== self::__invoke_static('selector_key', ['framework', [$alpha]]),
            'a subset is a different selector'
        );

        static::__assert_true(
            $one !== self::__invoke_static('selector_key', ['application', [$alpha, $beta]]),
            'the same class names in the other suite are a different selector'
        );
    }

    /**
     * The path carries suite, build key and selector, and the record is only a hit for the
     * selector it was recorded under - which is what stops a subset's green verdict from
     * being replayed as the whole suite's.
     */
    public static function test_a_verdict_is_recorded_per_selector()
    {
        $framework = self::__invoke_static('results_cache_path', ['framework', 'deadbeef', 'sel1']);
        static::__assert_true(
            str_ends_with($framework, '/rsx-tmp/test-results/framework_deadbeef_sel1.json'),
            'the path names suite, build key and selector: ' . $framework
        );

        $application = self::__invoke_static('results_cache_path', ['application', 'deadbeef', 'sel1']);
        static::__assert_true($framework !== $application, 'the two suites never share a record');

        try {
            self::__invoke_static('write_cached_results', [$framework, 'deadbeef', 'sel1', '/tmp/run_1', [], 0]);

            static::__assert_not_null(
                self::__invoke_static('read_cached_results', [$framework, 'deadbeef', 'sel1']),
                'the record reads back under the key it was written with'
            );

            static::__assert_null(
                self::__invoke_static('read_cached_results', [$framework, 'deadbeef', 'sel2']),
                'another selector never reads this verdict'
            );

            static::__assert_null(
                self::__invoke_static('read_cached_results', [$framework, 'cafe', 'sel1']),
                'another code state never reads this verdict'
            );
        } finally {
            @unlink($framework);
        }
    }

    /**
     * min(8, cores, floor(RAM_MB / 1000)), floored at 1 and capped by the class count. The
     * inputs are this box's real /proc, so what is asserted is the SHAPE: inside the
     * declared bounds, and never more containers than there are classes to put in them -
     * which is what makes a one-class run one container rather than eight.
     */
    public static function test_the_worker_count_stays_inside_its_declared_bounds()
    {
        $command = self::__make_command();

        $many = (int) self::__call_protected($command, 'worker_count', [1000]);
        static::__assert_greater_than(0, $many, 'at least one worker, always');
        static::__assert_less_than(9, $many, 'never more than WORKER_MAX (8)');

        static::__assert_equals(
            1,
            (int) self::__call_protected($command, 'worker_count', [1]),
            'one class gets one container'
        );

        static::__assert_equals(
            min(3, $many),
            (int) self::__call_protected($command, 'worker_count', [3]),
            'the class count caps the formula'
        );

        static::__assert_equals(
            1,
            (int) self::__call_protected($command, 'worker_count', [0]),
            'an empty selection still floors at one'
        );
    }

    /**
     * --workers=N is the experiment knob. It overrides the formula but not the floors:
     * still at least one, still never more than there are classes.
     */
    public static function test_the_workers_override_still_honours_the_floors()
    {
        $command = self::__make_command(['--workers' => '32']);

        static::__assert_equals(
            32,
            (int) self::__call_protected($command, 'worker_count', [1000]),
            'the override wins over the formula'
        );

        static::__assert_equals(
            5,
            (int) self::__call_protected($command, 'worker_count', [5]),
            'but never exceeds the class count'
        );

        $command = self::__make_command(['--workers' => '0']);
        static::__assert_greater_than(
            0,
            (int) self::__call_protected($command, 'worker_count', [1000]),
            'a nonsense override falls back to the formula rather than zero containers'
        );
    }
}
