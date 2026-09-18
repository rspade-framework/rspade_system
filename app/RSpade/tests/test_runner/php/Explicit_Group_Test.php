<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TestRunner\Php;

use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\RSpade\Commands\Rsx\Rsx_Test_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\ProdMode\Cli\Prod_Lifecycle_Cli_Test;

/**
 * TWO RULES ABOUT WHICH RUNS HAPPEN AT ALL.
 *
 * 1. A class marked $explicit_group_only runs only when its GROUP is named with --group or
 *    when the CLASS is named as a specific test. It exists for a class that mutates the box
 *    rather than the test database - the prod_mode lifecycle wrappers switch RSX_MODE and
 *    rebuild the real build tree - and the runner reports every class it passed over, so
 *    the rule can never quietly shrink a suite.
 *
 * 2. rsx:test refuses to run at all in a production mode, PRE-BOOT. The refusal is
 *    asserted against the real artisan in a subprocess, because pre-boot is the only place
 *    it can be observed: a check inside the command would be unreachable on an unsealed box
 *    (the unsealed-build fatal comes first, and describes the wrong problem) and useless on
 *    a sealed one, where the manifest carries no test trees and the run reported "No test
 *    classes found" and exited 0 - a false green.
 *
 * The selection half is asserted through Rsx_Test_Command::select_test_classes() itself -
 * THE one place every class-level selector is applied, shared by the sequential loop and
 * the docker orchestrator - with an injected class list, so no run is spawned to observe
 * which classes a run would choose.
 *
 * @PHP-REFLECT-01-EXCEPTION select_test_classes() is a protected method on an artisan
 * command whose only other caller is a whole test run. Reflection is what lets the rule be
 * asserted without one.
 *
 * @ARTISAN-SPAWN-01-EXCEPTION the pre-boot refusal IS the subject: it can only be observed
 * from a subprocess with an exact argv and an exact environment, and Rsx_Artisan would
 * insert its own command-line building between the test and the thing under test.
 */
class Explicit_Group_Test extends Rsx_Test_Abstract
{
    /**
     * Pure selection over an injected array, plus subprocesses. No database.
     *
     * @var bool
     */
    protected static $use_database_transactions = false;

    /** The explicit-only class the selection cases are asked about, and its group. */
    const EXPLICIT_CLASS = Prod_Lifecycle_Cli_Test::class;
    const EXPLICIT_FILE = 'app/RSpade/tests/prod_mode/cli/Prod_Lifecycle_Cli_Test.php';
    const EXPLICIT_GROUP = 'prod_mode';

    /** An ordinary class, which no rule below may touch. */
    const ORDINARY_CLASS = self::class;
    const ORDINARY_FILE = 'app/RSpade/tests/test_runner/php/Explicit_Group_Test.php';

    /** The refusal, exactly as bootstrap/rsx_preboot.php writes it. */
    const REFUSAL_FIRST_LINE = 'rsx:test does not run in a production mode';
    const REFUSAL_CI_LINE = 'The suite is a CI step that runs before a deployment, on a development box.';
    const REFUSAL_REMEDY = 'Return to development first: php artisan rsx:mode:set dev';

    /**
     * A command instance with an input and a buffered output attached, exactly as the
     * console would hand it one.
     *
     * @return Rsx_Test_Command
     */
    protected static function __make_command(): Rsx_Test_Command
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

        return $command;
    }

    /**
     * Run the selector over the two-class fixture list.
     *
     * @param array $specific_tests
     * @param array $filters
     * @param array $groups
     * @return array{0: array<int, string>, 1: array<int, string>, 2: int} selected short
     *         names, the groups skipped for being explicit-only, and how many classes that
     *         was
     */
    protected static function __select(array $specific_tests = [], array $filters = [], array $groups = []): array
    {
        $command = self::__make_command();

        $classes = [
            self::EXPLICIT_CLASS => ['fqcn' => self::EXPLICIT_CLASS, 'file' => self::EXPLICIT_FILE],
            self::ORDINARY_CLASS => ['fqcn' => self::ORDINARY_CLASS, 'file' => self::ORDINARY_FILE],
        ];

        $method = new ReflectionMethod($command, 'select_test_classes');
        $method->setAccessible(true);
        $selected = $method->invokeArgs($command, [$classes, $specific_tests, $filters, $groups, true]);

        $skipped = new ReflectionProperty($command, 'explicit_only_skipped');
        $skipped->setAccessible(true);

        $count = new ReflectionProperty($command, 'explicit_only_skipped_count');
        $count->setAccessible(true);

        return [
            array_column($selected, 'short'),
            $skipped->getValue($command),
            $count->getValue($command),
        ];
    }

    /**
     * The flag is declared on the base class, off, and the two wrappers turn it on. A class
     * that answers false is an ordinary class.
     */
    public static function test_the_flag_defaults_off_and_the_wrappers_turn_it_on()
    {
        static::__assert_false(
            Explicit_Group_Test::explicit_group_only(),
            'an ordinary test class is not explicit-only'
        );

        static::__assert_true(
            Prod_Lifecycle_Cli_Test::explicit_group_only(),
            'the prod_mode lifecycle wrapper is explicit-only'
        );
    }

    /**
     * A bare `rsx:test --framework` skips it, keeps every ordinary class, and RECORDS the
     * skip so the runner can say what it passed over.
     */
    public static function test_a_bare_run_skips_it_and_records_the_group()
    {
        [$selected, $skipped, $count] = self::__select();

        static::__assert_true(
            !in_array('Prod_Lifecycle_Cli_Test', $selected, true),
            'a bare run must not select an explicit-only class: ' . implode(', ', $selected)
        );

        static::__assert_true(
            in_array('Explicit_Group_Test', $selected, true),
            'an ordinary class is unaffected: ' . implode(', ', $selected)
        );

        static::__assert_equals(
            [self::EXPLICIT_GROUP],
            $skipped,
            'the skipped group is recorded; got: ' . implode(', ', $skipped)
        );
        static::__assert_equals(1, $count, 'one class was skipped');
    }

    /**
     * Naming the GROUP selects it.
     */
    public static function test_naming_the_group_selects_it()
    {
        [$selected, $skipped, $count] = self::__select([], [], [self::EXPLICIT_GROUP]);

        static::__assert_equals(
            ['Prod_Lifecycle_Cli_Test'],
            $selected,
            'the group selects the class, and only it; got: ' . implode(', ', $selected)
        );
        static::__assert_equals([], $skipped, 'nothing was passed over; got: ' . implode(', ', $skipped));
        static::__assert_equals(0, $count);
    }

    /**
     * Naming the CLASS selects it - the operator was explicit either way.
     */
    public static function test_naming_the_class_selects_it()
    {
        [$selected, $skipped, $count] = self::__select(['Prod_Lifecycle_Cli_Test']);

        static::__assert_equals(
            ['Prod_Lifecycle_Cli_Test'],
            $selected,
            'the class name selects it; got: ' . implode(', ', $selected)
        );
        static::__assert_equals([], $skipped, 'nothing was passed over; got: ' . implode(', ', $skipped));
        static::__assert_equals(0, $count);
    }

    /**
     * A --filter is not an explicit selection. The rule is checked BEFORE the filter, so a
     * filter that happens to match the class name cannot drag it into an ordinary run, and
     * the skip is still reported when the filter selects nothing at all.
     */
    public static function test_a_filter_is_not_an_explicit_selection()
    {
        [$selected, $skipped, $count] = self::__select([], ['Prod_Lifecycle']);

        static::__assert_equals(
            [],
            $selected,
            'a filter alone selects no explicit-only class; got: ' . implode(', ', $selected)
        );
        static::__assert_equals(
            [self::EXPLICIT_GROUP],
            $skipped,
            'the skip is still recorded; got: ' . implode(', ', $skipped)
        );
        static::__assert_equals(1, $count);
    }

    /**
     * php artisan <args>, with extra variables placed in the CHILD's environment.
     *
     * The pre-boot mode reader honours a real environment variable ahead of the
     * environment file (phpdotenv's precedence, mirrored) - which is what makes the mode
     * forceable for one subprocess without writing anything to this box. This is a test
     * FIXTURE built out of the environment proc_open() is given, and it is not an
     * invocation prefix: prefixing a command line with KEY=VALUE is not how RSpade
     * commands are invoked.
     *
     * @param string $args
     * @param array $env
     * @return array{0: int, 1: string}
     */
    protected static function __artisan(string $args, array $env = []): array
    {
        $out = [];
        $rc = 0;
        exec_safe('php ' . escapeshellarg(base_path('artisan')) . ' ' . $args, $out, $rc, $env);

        return [$rc, implode("\n", $out)];
    }

    /**
     * A production box refuses the suite, says which mode it is in, and names the way back.
     */
    public static function test_the_suite_is_refused_in_production_mode()
    {
        [$rc, $output] = self::__artisan(
            'rsx:test --framework Explicit_Group_Test --sequential',
            ['RSX_MODE' => 'production']
        );

        static::__assert_contains(self::REFUSAL_FIRST_LINE, $output, 'the refusal is printed');
        static::__assert_contains('(this box is in production mode)', $output, 'it names the mode');
        static::__assert_contains(self::REFUSAL_CI_LINE, $output, 'it says where the suite belongs');
        static::__assert_contains(self::REFUSAL_REMEDY, $output, 'it names the way back');
        static::__assert_equals(1, $rc, 'the refusal exits 1');

        // Pre-boot, therefore ahead of everything the sealed box would otherwise say
        // first - the message that used to arrive instead of this one.
        static::__assert_true(
            !str_contains($output, 'unsealed'),
            'the refusal replaces the unsealed-build fatal rather than following it: ' . $output
        );
    }

    /**
     * debug is a sealed build too, and is refused in the same words.
     */
    public static function test_debug_mode_is_refused_as_well()
    {
        [$rc, $output] = self::__artisan(
            'rsx:test --framework Explicit_Group_Test --sequential',
            ['RSX_MODE' => 'debug']
        );

        static::__assert_contains('(this box is in debug mode)', $output, 'it names debug');
        static::__assert_equals(1, $rc);
    }

    /**
     * The `prod` alias is the same mode, and the message says so in the word the framework
     * uses everywhere else.
     */
    public static function test_the_prod_alias_is_refused_and_normalized()
    {
        [$rc, $output] = self::__artisan(
            'rsx:test --framework Explicit_Group_Test --sequential',
            ['RSX_MODE' => 'prod']
        );

        static::__assert_contains('(this box is in production mode)', $output, 'the alias normalizes');
        static::__assert_equals(1, $rc);
    }

    /**
     * THE REFUSAL IS KEYED ON THE COMMAND, not on the mode. Another artisan invocation in
     * the same forced mode is left entirely alone - it fails, succeeds or refuses on its
     * own terms, and never in these words.
     *
     * A bare `php artisan` is the cheapest honest control: it lists commands on a box with
     * no build at all, so what it prints is decided by nothing this change touched.
     */
    public static function test_another_command_in_the_same_mode_is_not_refused()
    {
        [, $output] = self::__artisan('', ['RSX_MODE' => 'production']);

        static::__assert_true(
            !str_contains($output, self::REFUSAL_FIRST_LINE),
            'only rsx:test is refused: ' . $output
        );
    }
}
