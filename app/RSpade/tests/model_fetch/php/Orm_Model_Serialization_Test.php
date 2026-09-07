<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ModelFetch\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * JSON.stringify protocol contract for Rsx_Js_Model (MODELFETCH-ORM-TOJSON).
 *
 * toJSON() is the language hook JSON.stringify() calls, so it must return the VALUE to
 * serialize. Returning a JSON STRING instead makes a model round-trip into its own JSON
 * text, and jqhtml clones its shared on_load data cache with
 * JSON.parse(JSON.stringify(data)) - so the first component holding a given
 * (component, args) pair keeps the live instance while every same-args follower gets a
 * string and reads undefined off it.
 *
 * The real Core/Js/Rsx_Js_Model.js is loaded and exercised by the node harness in
 * ../resource/model_serialization_harness.js; the assertions live here.
 *
 * No database, no browser - the serialization path touches no bundle globals.
 */
class Orm_Model_Serialization_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __harness_path(): string
    {
        return base_path('app/RSpade/tests/model_fetch/resource/model_serialization_harness.js');
    }

    /**
     * Run the node harness and decode its single JSON output line.
     */
    private static function __run_harness(): array
    {
        $process = new Process(['node', static::__harness_path()]);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            static::__fail(
                "Serialization harness failed:\n" . $process->getOutput() . "\n" . $process->getErrorOutput()
            );
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (!is_array($decoded)) {
            static::__fail('Harness produced non-JSON output: ' . $process->getOutput());
        }

        return $decoded;
    }

    /**
     * toJSON() returns the plain object, never a string.
     */
    public static function test_to_json_returns_a_value_not_a_string()
    {
        $facts = static::__run_harness();

        static::__assert_equals(
            'object',
            $facts['to_json_type'],
            'toJSON() must return the value to serialize, not a JSON string'
        );
    }

    /**
     * A model survives JSON.parse(JSON.stringify(instance)) as a readable plain object,
     * nested model included - the jqhtml data-cache clone.
     */
    public static function test_stringify_round_trip_yields_a_readable_object()
    {
        $facts = static::__run_harness();

        static::__assert_equals(
            'object',
            $facts['cloned_type'],
            'Round-tripped model must be a plain object, got: ' . var_export($facts['cloned_preview'], true)
        );
        static::__assert_false($facts['cloned_is_array'], 'Round-tripped model must not be an array');
        static::__assert_equals(42, $facts['cloned_id'], 'Round-tripped model must expose its id');
        static::__assert_equals('Rollout', $facts['cloned_title'], 'Round-tripped model must expose its fields');
        static::__assert_equals(
            'object',
            $facts['cloned_nested_type'],
            'A nested model field must round-trip as an object'
        );
        static::__assert_equals(
            'Acme',
            $facts['cloned_nested_name'],
            'A nested model field must expose its own fields'
        );
    }

    /**
     * The shape jqhtml actually clones: the model sits inside a wrapper object, and the
     * follower component must still be able to read through it.
     */
    public static function test_stringify_round_trip_through_a_wrapper_object()
    {
        $facts = static::__run_harness();

        static::__assert_equals(
            'object',
            $facts['wrapped_rec_type'],
            'A model nested in a wrapper must round-trip as an object'
        );
        static::__assert_equals(42, $facts['wrapped_rec_id'], 'JSON round trip of {rec: instance} must keep rec.id');
    }
}
