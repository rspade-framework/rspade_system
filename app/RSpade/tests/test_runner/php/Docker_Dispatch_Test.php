<?php

namespace App\RSpade\Tests\TestRunner\Php;

use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\RSpade\Commands\Rsx\Rsx_Test_Command;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Two decisions taken before a single test runs: WHETHER this invocation goes to docker,
 * and HOW MANY containers it gets. Both are cheap to get wrong in a way nobody notices -
 * a gate that opens on a subset would build an image to run four tests, and a worker count
 * that ignores memory would start eight mysqlds on a box that can hold three.
 *
 * The gate is deliberately narrow: the WHOLE framework suite, no narrowing selector, no
 * --sequential, inside an RSpade development container, with a docker daemon that answers.
 * Every one of those is asserted here as a REFUSAL, which is the half that matters - a
 * gate that fails open runs the wrong thing.
 *
 * @PHP-REFLECT-01-EXCEPTION docker_mode_gate_passes() and worker_count() are protected
 * decisions on an artisan command; their only other caller is handle(), which would run
 * the entire suite. Reflection asserts the decision without taking the action.
 */
class Docker_Dispatch_Test extends Rsx_Test_Abstract
{
    /**
     * Reads /proc and answers questions - no database.
     *
     * @var bool
     */
    protected static $use_database_transactions = false;

    /**
     * A command whose docker probe answers a FIXED value, so the gate's own logic can be
     * asserted without a docker daemon being present, absent or slow.
     *
     * @param array $options
     * @param bool $docker_usable
     * @return Rsx_Test_Command
     */
    protected static function __make_command(array $options = [], bool $docker_usable = true): Rsx_Test_Command
    {
        $command = new class ($docker_usable) extends Rsx_Test_Command {
            /**
             * @var bool
             */
            private bool $__docker_usable;

            /**
             * @param bool $docker_usable
             */
            public function __construct(bool $docker_usable)
            {
                parent::__construct();
                $this->__docker_usable = $docker_usable;
            }

            /**
             * The one seam this test stubs: whether `docker info` answers. Everything else
             * about the gate is the real code.
             *
             * @return bool
             */
            protected function docker_is_usable(): bool
            {
                return $this->__docker_usable;
            }
        };

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
     * @param array $options
     * @param array $specific_tests
     * @param array $filters
     * @param array $groups
     * @param bool $framework_only
     * @param bool $docker_usable
     * @return bool
     */
    protected static function __gate(
        array $options,
        array $specific_tests,
        array $filters,
        array $groups,
        bool $framework_only,
        bool $docker_usable = true
    ): bool {
        $command = self::__make_command($options, $docker_usable);

        return (bool) self::__call_protected(
            $command,
            'docker_mode_gate_passes',
            [$specific_tests, $filters, $groups, $framework_only]
        );
    }

    /**
     * The one combination that opens the gate. Asserted only where the environment can
     * satisfy the container precondition, which is not something a test can fake: the
     * check is Rsx::is_rspade_dev_container(), a file on disk.
     */
    public static function test_the_full_framework_suite_opens_the_gate()
    {
        if (!Rsx::is_rspade_dev_container()) {
            static::__skip('not an RSpade development container - the gate cannot open here');

            return;
        }

        static::__assert_true(
            self::__gate([], [], [], [], true),
            'the whole framework suite, no selectors, docker answering -> docker mode'
        );
    }

    /**
     * Every narrowing selector closes it. A subset is never worth an image build and N
     * container boots, and the containers carry only the framework's own environment.
     */
    public static function test_every_narrowing_selector_closes_the_gate()
    {
        static::__assert_false(
            self::__gate([], ['Locks_Test'], [], [], true),
            'a named class runs in-process'
        );

        static::__assert_false(
            self::__gate(['--filter' => ['throttle']], [], ['throttle'], [], true),
            'a --filter runs in-process'
        );

        static::__assert_false(
            self::__gate(['--group' => ['locks']], [], [], ['locks'], true),
            'a --group runs in-process'
        );
    }

    /**
     * --sequential is the documented escape hatch: it forces one process even when
     * everything else about the invocation would have gone to docker.
     */
    public static function test_sequential_closes_the_gate()
    {
        static::__assert_false(
            self::__gate(['--sequential' => true], [], [], [], true),
            '--sequential forces the single-process runner'
        );
    }

    /**
     * The application suite stays sequential: the containers are built around the
     * framework's own environment, and extending them to /rsx/ is a separate decision.
     */
    public static function test_the_application_suite_closes_the_gate()
    {
        static::__assert_false(
            self::__gate([], [], [], [], false),
            'the application suite runs in-process'
        );
    }

    /**
     * No docker daemon, no docker mode - and the answer is a quiet fall-through to the
     * sequential path, never a failure. A box without docker still runs its tests.
     */
    public static function test_an_unusable_docker_closes_the_gate()
    {
        static::__assert_false(
            self::__gate([], [], [], [], true, false),
            'a docker daemon that does not answer falls through to sequential'
        );
    }

    /**
     * min(8, cores, floor(RAM_MB / 1000)), floored at 1 and capped by the class count. The
     * inputs are this box's real /proc, so what is asserted is the SHAPE: inside the
     * declared bounds, and never more containers than there are classes to put in them.
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
