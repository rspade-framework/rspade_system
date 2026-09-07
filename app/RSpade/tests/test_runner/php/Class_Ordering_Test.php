<?php

namespace App\RSpade\Tests\TestRunner\Php;

use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\RSpade\Commands\Rsx\Rsx_Test_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Database\Php\Audit_Delete_Stamp_Test;
use App\RSpade\Tests\Database\Php\Rsx_Result_Set_Test;
use App\RSpade\Tests\Session\Php\Session_Cap_Test;

/**
 * The queue is seeded LONGEST FIRST, because the last class handed out decides when the
 * whole run ends: a 90-second class picked up by the final idle worker adds 90 seconds to
 * the wall clock no matter how well everything before it packed.
 *
 * The ordering is a HINT, never a correctness input - a wrong guess costs a slightly worse
 * pack and nothing else - so what is asserted here is the priority ladder: a measured
 * timing beats everything, a $requires_db_reset class outranks an unmeasured ordinary one
 * (those carry the re-provision cost), and equal scores keep their incoming order so a
 * run's seeding is reproducible.
 *
 * @PHP-REFLECT-01-EXCEPTION order_classes_longest_first() is a protected method on an
 * artisan command whose only other caller is a full docker run. Reflection is what lets
 * the ladder be asserted without one.
 */
class Class_Ordering_Test extends Rsx_Test_Abstract
{
    /**
     * Pure ordering over an injected array - no database.
     *
     * @var bool
     */
    protected static $use_database_transactions = false;

    /**
     * @return array{0: Rsx_Test_Command, 1: BufferedOutput}
     */
    protected static function __make_command(): array
    {
        $command = new Rsx_Test_Command();
        $command->setLaravel(app());

        $input = new ArrayInput([], $command->getDefinition());
        $buffer = new BufferedOutput();
        $buffer->setDecorated(false);

        $property = new ReflectionProperty($command, 'input');
        $property->setAccessible(true);
        $property->setValue($command, $input);

        $property = new ReflectionProperty($command, 'output');
        $property->setAccessible(true);
        $property->setValue($command, new SymfonyStyle($input, $buffer));

        return [$command, $buffer];
    }

    /**
     * @param Rsx_Test_Command $command
     * @param string $method
     * @param array $args
     * @return mixed
     */
    protected static function __call_protected(Rsx_Test_Command $command, string $method, array $args = [])
    {
        $reflection = new ReflectionMethod($command, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($command, $args);
    }

    /**
     * Order a selection with the timings file set to exactly $timings (restored afterwards).
     *
     * @param array $selected
     * @param array|null $timings null = no timings file at all
     * @return array<int, string> the resulting short names, in order
     */
    protected static function __order_with_timings(array $selected, ?array $timings): array
    {
        [$command] = self::__make_command();

        $path = self::__call_protected($command, 'timings_path');
        $backup = is_file($path) ? file_get_contents($path) : null;

        try {
            if ($timings === null) {
                @unlink($path);
            } else {
                file_put_contents($path, json_encode($timings));
            }

            $ordered = self::__call_protected($command, 'order_classes_longest_first', [$selected]);
        } finally {
            if ($backup === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $backup);
            }
        }

        return array_map(static fn ($entry) => $entry['short'], $ordered);
    }

    /**
     * Three REAL framework test classes - the ordering code asks a class name for
     * requires_db_reset(), so the inputs have to be classes that answer it. Session_Cap_Test
     * declares a reset; the other two do not. They are named 'Reset', 'Plain_A' and 'Plain_B'
     * in the selection so what the assertions read is the ROLE, not the class; the
     * precondition itself is asserted below, so a class that changes its declaration
     * reports that plainly instead of failing an ordering assertion for the wrong reason.
     *
     * The 'file' entry is empty on purpose: an unmeasured non-reset class scores off its
     * source size, and an empty path scores 0, which is what makes the two plain classes
     * tie and lets the stability rule be asserted.
     *
     * @return array
     */
    protected static function __selection(): array
    {
        return [
            ['fqcn' => Rsx_Result_Set_Test::class, 'short' => 'Plain_A', 'class_matches' => false, 'file' => ''],
            ['fqcn' => Session_Cap_Test::class, 'short' => 'Reset', 'class_matches' => false, 'file' => ''],
            ['fqcn' => Audit_Delete_Stamp_Test::class, 'short' => 'Plain_B', 'class_matches' => false, 'file' => ''],
        ];
    }

    /**
     * A measured timing outranks everything: the slowest class goes first even when it is
     * an ordinary transaction-based one and a $requires_db_reset class is in the set.
     */
    public static function test_a_timings_file_orders_longest_first()
    {
        $order = self::__order_with_timings(self::__selection(), [
            Rsx_Result_Set_Test::class => 90.0,
            Session_Cap_Test::class => 30.0,
            Audit_Delete_Stamp_Test::class => 60.0,
        ]);

        static::__assert_equals(['Plain_A', 'Plain_B', 'Reset'], $order, 'measured durations, descending');
    }

    /**
     * With nothing measured, the $requires_db_reset class leads: it carries the
     * re-provision cost, which is the largest known-in-advance term.
     */
    public static function test_without_timings_reset_classes_lead()
    {
        static::__assert_true(
            Session_Cap_Test::requires_db_reset(),
            'precondition: the class standing in for "expensive" still declares a reset'
        );
        static::__assert_false(
            Rsx_Result_Set_Test::requires_db_reset(),
            'precondition: the class standing in for "ordinary" still declares none'
        );

        $order = self::__order_with_timings(self::__selection(), null);

        static::__assert_equals('Reset', $order[0], 'the reset class is seeded first');
        static::__assert_count(3, $order, 'and nothing is dropped');
    }

    /**
     * Equal scores keep their incoming order, so two runs over an unchanged tree seed the
     * queue identically. The two plain classes have no timing and an empty source path in
     * this selection, so they score exactly the same.
     */
    public static function test_equal_scores_are_stable_in_incoming_order()
    {
        $order = self::__order_with_timings(self::__selection(), null);

        static::__assert_equals(
            ['Reset', 'Plain_A', 'Plain_B'],
            $order,
            'ties resolve by the position the class arrived in'
        );
    }

    /**
     * Ordering never adds or loses a class - the queue runs exactly the set discovery
     * selected.
     */
    public static function test_ordering_preserves_the_selection_exactly()
    {
        $selected = self::__selection();

        $with = self::__order_with_timings($selected, [Audit_Delete_Stamp_Test::class => 5.0]);
        $without = self::__order_with_timings($selected, null);

        sort($with);
        sort($without);

        static::__assert_equals(['Plain_A', 'Plain_B', 'Reset'], $with, 'same set with timings');
        static::__assert_equals(['Plain_A', 'Plain_B', 'Reset'], $without, 'same set without timings');
    }
}
