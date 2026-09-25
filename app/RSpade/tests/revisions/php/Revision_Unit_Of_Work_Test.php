<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Revisions\Transaction_Model;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Revisions\Php\Revision_Fixture_Model;

/**
 * The public unit-of-work seam: Revision::begin_unit_of_work() and Revision::unit_of_work().
 *
 * A process that performs many logical units (an import script, a long-running task)
 * declares each one, so each is its own _transactions row instead of the whole run being
 * one. Proves: a script-shaped loop files each iteration under its own row carrying the
 * given description and the script's endpoint and source; the closure form hands the
 * caller its own transaction back, on return and on a throw; nesting; the source and
 * endpoint carry over while the description does not; an empty unit leaves no row; and
 * the framework boundary (_reset_request_state) and system/script.php's run boundary are
 * as documented.
 *
 * Writes run inside the runner's wrapping transaction and roll back afterwards.
 */
class Revision_Unit_Of_Work_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__drop_tables();

        DB::statement('CREATE TABLE revision_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NULL DEFAULT NULL,
            name VARCHAR(255) NULL,
            counter INT NOT NULL DEFAULT 0,
            _internal VARCHAR(64) NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            created_by_id BIGINT NULL DEFAULT NULL,
            created_by_type BIGINT NULL DEFAULT NULL,
            updated_by_id BIGINT NULL DEFAULT NULL,
            updated_by_type BIGINT NULL DEFAULT NULL,
            deleted_by_id BIGINT NULL DEFAULT NULL,
            deleted_by_type BIGINT NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Committed outside the per-test transaction - see Revision_Recording_Test::setup().
        Type_Ref_Registry::class_to_id('Revision_Fixture_Model');
    }

    public static function teardown()
    {
        static::__drop_tables();
    }

    private static function __drop_tables()
    {
        DB::statement('DROP TABLE IF EXISTS revision_fixtures');
    }

    private static function __write(string $name): Revision_Fixture_Model
    {
        $record = new Revision_Fixture_Model();
        $record->name = $name;
        $record->save();

        return $record;
    }

    private static function __current_id(): int
    {
        return (int) Revision::current_transaction()->id;
    }

    private static function __row(int $transaction_id): Transaction_Model
    {
        return Transaction_Model::find($transaction_id);
    }

    // -------------------------------------------------------------------------

    public static function test_a_script_loop_files_each_iteration_under_its_own_transaction()
    {
        // The state a system/script.php run starts in: no declared source (derived 'cli'),
        // then the script's own run boundary.
        Revision::_testing_reset();
        Revision::_reset_request_state('cli', 'import_records.php');

        $ids = [];
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            Revision::begin_unit_of_work('Imported ' . $name);
            static::__write($name);
            static::__write($name . '-second-write');
            $ids[] = static::__current_id();
        }

        static::__assert_count(3, array_unique($ids), 'each iteration minted its own transaction');

        foreach (['alpha', 'beta', 'gamma'] as $i => $name) {
            $row = static::__row($ids[$i]);
            static::__assert_equals('Imported ' . $name, $row->description, 'the unit carries its description');
            static::__assert_equals('import_records.php', $row->endpoint, 'the endpoint carries over from the run boundary');
            static::__assert_equals(Transaction_Model::SOURCE_CLI, (int) $row->source_id, 'the source carries over');
            static::__assert_equals(2, (int) $row->revision_count, 'both writes of the iteration, and nothing else');
        }
    }

    public static function test_the_closure_form_restores_the_callers_transaction()
    {
        static::__write('caller-before');
        $caller_id = static::__current_id();

        $inner_id = Revision::unit_of_work('Imported one record', function () {
            static::__write('inner');
            return static::__current_id();
        });

        static::__assert_not_equals($caller_id, $inner_id, 'the callable ran as its own unit');
        static::__assert_equals('Imported one record', static::__row($inner_id)->description);
        static::__assert_equals($caller_id, static::__current_id(), 'the caller has its transaction back');
        static::__assert_equals(1, count(Revision::current_revisions()), 'and its own revision list');

        static::__write('caller-after');
        static::__assert_equals(2, (int) static::__row($caller_id)->revision_count, 'a later caller write joins the caller unit');
        static::__assert_equals(1, (int) static::__row($inner_id)->revision_count, 'and not the inner one');
    }

    /**
     * A task run in-process is its own unit, and the caller gets its unit back afterwards:
     * a request that runs a task keeps filing its own later writes under its own
     * transaction, never under the task's.
     */
    public static function test_an_in_process_task_hands_the_callers_transaction_back()
    {
        static::__write('caller-before');
        $caller_id = static::__current_id();

        Task::internal('Test_Echo_Service', 'echo_params', ['probe' => 1]);

        static::__assert_equals($caller_id, static::__current_id(), 'the caller has its transaction back after the task');

        static::__write('caller-after');
        static::__assert_equals(2, (int) static::__row($caller_id)->revision_count, 'a later caller write joins the caller unit');
    }

    public static function test_the_closure_form_restores_on_a_throw()
    {
        static::__write('caller');
        $caller_id = static::__current_id();

        static::__assert_throws(RuntimeException::class, function () {
            Revision::unit_of_work('Failing unit', function () {
                static::__write('inner');
                throw new RuntimeException('import failed');
            });
        }, 'import failed');

        static::__assert_equals($caller_id, static::__current_id(), 'a throw inside cannot leave the caller on the inner unit');
    }

    public static function test_units_nest()
    {
        static::__write('caller');
        $caller_id = static::__current_id();

        $seen = Revision::unit_of_work('Outer', function () {
            static::__write('outer-1');
            $outer_id = static::__current_id();

            $inner_id = Revision::unit_of_work('Inner', function () {
                static::__write('inner');
                return static::__current_id();
            });

            $after_inner = static::__current_id();
            static::__write('outer-2');

            return [$outer_id, $inner_id, $after_inner];
        });

        [$outer_id, $inner_id, $after_inner] = $seen;
        static::__assert_count(3, array_unique([$caller_id, $outer_id, $inner_id]), 'three distinct units');
        static::__assert_equals($outer_id, $after_inner, 'the inner unit hands the outer one back');
        static::__assert_equals(2, (int) static::__row($outer_id)->revision_count, 'both outer writes in the outer unit');
        static::__assert_equals(1, (int) static::__row($inner_id)->revision_count);
        static::__assert_equals($caller_id, static::__current_id(), 'the outer unit hands the caller back');
    }

    public static function test_source_and_endpoint_carry_over_and_description_does_not()
    {
        // The runner declared this method's unit: source 'test', endpoint Class::method.
        Revision::describe('the caller unit');
        static::__write('caller');
        $caller = static::__row(static::__current_id());

        Revision::begin_unit_of_work();
        static::__write('carried');
        $carried = static::__row(static::__current_id());
        static::__assert_equals(Transaction_Model::SOURCE_TEST, (int) $carried->source_id, 'source kept');
        static::__assert_equals($caller->endpoint, $carried->endpoint, 'endpoint kept when none is given');
        static::__assert_null($carried->description, 'a description is never carried to the next unit');

        Revision::begin_unit_of_work('Named', 'Import::step_two');
        static::__write('named');
        static::__assert_equals('Import::step_two', static::__row(static::__current_id())->endpoint, 'an explicit endpoint replaces it');
    }

    public static function test_a_unit_that_writes_nothing_leaves_no_row()
    {
        $before = Transaction_Model::count();

        for ($i = 0; $i < 5; $i++) {
            Revision::begin_unit_of_work('Nothing ' . $i);
        }
        Revision::unit_of_work('Nothing either', fn () => null);

        static::__assert_equals($before, Transaction_Model::count(), 'the mint is lazy');
        static::__assert_null(Revision::current_transaction());
    }

    public static function test_the_framework_boundary_is_unchanged()
    {
        Revision::describe('described');
        static::__write('one');
        $first = static::__current_id();

        Revision::_reset_request_state('ajax', 'Fixture_Controller::save');
        static::__assert_null(Revision::current_transaction(), 'the reset closes the unit');
        static::__write('two');
        $row = static::__row(static::__current_id());
        static::__assert_not_equals($first, (int) $row->id);
        static::__assert_equals(Transaction_Model::SOURCE_AJAX, (int) $row->source_id, 'the declared source');
        static::__assert_equals('Fixture_Controller::save', $row->endpoint);
        static::__assert_null($row->description, 'the reset clears the description');

        static::__assert_throws(RuntimeException::class, function () {
            Revision::_reset_request_state('import');
        }, 'unknown source');
    }

    public static function test_script_entry_declares_one_unit_per_run()
    {
        $source = file_get_contents(base_path('script.php'));
        $boot = strpos($source, '->bootstrap();');
        $reset = strpos($source, "Revision::_reset_request_state('cli', basename(");

        static::__assert_true($reset !== false, 'system/script.php declares the run boundary');
        static::__assert_true($boot !== false && $reset > $boot, 'after the application has booted');
    }
}
