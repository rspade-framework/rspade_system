<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Realtime\Cli;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:realtime:map - the realtime graph read off the manifest.
 *
 * Driven in-process over the realtime concern's own fixture models, which are in the manifest
 * while the suite runs: a #[Realtime_Touch] child -> parent pair (and a touch-only sibling),
 * an onward chain whose parent overrides realtime_touch(), a method-only touch model, a
 * $realtime model with a model-constrained #[Emitter], and a #[Realtime_Touch] on a hasMany
 * that cannot resolve. That last one is in every unfiltered map here, so an unfiltered run
 * exits 1 and names it.
 *
 * Pure manifest + reflection, no DB.
 */
class Realtime_Map_Command_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * @return array{exit: int, output: string}
     */
    private static function __run(array $options = []): array
    {
        $exit = Artisan::call('rsx:realtime:map', $options);

        return ['exit' => $exit, 'output' => Artisan::output()];
    }

    private static function __run_json(array $options = []): array
    {
        $run = static::__run($options + ['--json' => true]);
        $graph = json_decode($run['output'], true);

        static::__assert_true(is_array($graph), '--json prints one JSON document');

        return ['exit' => $run['exit'], 'graph' => $graph];
    }

    private static function __edge(array $graph, string $child, string $method): ?array
    {
        foreach ($graph['edges'] as $edge) {
            if ($edge['child'] === $child && $edge['method'] === $method) {
                return $edge;
            }
        }

        return null;
    }

    private static function __model(array $graph, string $class): ?array
    {
        foreach ($graph['models'] as $model) {
            if ($model['class'] === $class) {
                return $model;
            }
        }

        return null;
    }

    /** The tree shows each edge with its method and resolved FK, the run-time node and the cascade beneath it. */
    public static function test_tree_shows_edges_runtime_touches_and_cascade()
    {
        $run = static::__run();
        $output = $run['output'];

        static::__assert_contains('Realtime_Attr_Child_Fixture_Model::parent()  via parent_id  [realtime]  [framework]', $output, 'an attribute edge names its method and FK, and marks the child');
        static::__assert_contains('Realtime_Attr_Touch_Only_Fixture_Model::parent()  via parent_id  [framework]', $output, 'a touch-only child is shown without [realtime]');
        static::__assert_contains('(parent resolved at run time by realtime_touch())', $output, 'the run-time node is printed');
        static::__assert_contains('<- Realtime_Method_Touch_Fixture_Model::realtime_touch()', $output, 'a realtime_touch() override hangs off the run-time node');

        // The onward parent overrides realtime_touch(); its own attribute child sits one level beneath it.
        static::__assert_contains(
            "     <- Realtime_Attr_Onward_Parent_Fixture_Model::realtime_touch()  [realtime]  [framework]\n"
            . "        <- Realtime_Attr_Onward_Child_Fixture_Model::parent()  via parent_id  [realtime]  [framework]",
            $output,
            'the cascade under a run-time node is printed transitively'
        );

        static::__assert_contains('Realtime_Composition_Fixture_Service::fixture_derived()  -> Model_Changed_Topic  model Realtime_Fixture_Model', $output, 'an emitter shows its topic and model constraint');
    }

    /** A #[Realtime_Touch] that is not on a belongsTo is an [ERROR] row and exit 1, not a crash. */
    public static function test_unresolvable_touch_is_an_error_row()
    {
        $run = static::__run();

        static::__assert_equals(1, $run['exit'], 'an unresolvable declaration fails the run');
        static::__assert_contains('[ERROR] Realtime_Attr_Bad_Fixture_Model:', $run['output'], 'the broken model is named');
        static::__assert_contains('requires a belongsTo relationship', $run['output'], 'with the runtime\'s own message');
    }

    /** --json carries the same graph: models with their flags, edges, emitters, errors. */
    public static function test_json_graph()
    {
        $json = static::__run_json();
        $graph = $json['graph'];

        static::__assert_equals(1, $json['exit'], '--json exits as the text form does');
        static::__assert_equals(
            ['child' => 'Realtime_Attr_Child_Fixture_Model', 'method' => 'parent', 'fk_column' => 'parent_id', 'parent' => 'Realtime_Attr_Parent_Fixture_Model'],
            static::__edge($graph, 'Realtime_Attr_Child_Fixture_Model', 'parent'),
            'the edge as the touch registry resolves it'
        );

        $fixture = static::__model($graph, 'Realtime_Fixture_Model');
        static::__assert_not_null($fixture, 'a $realtime model is listed');
        static::__assert_true($fixture['realtime'], 'and flagged realtime');
        static::__assert_true($fixture['framework'], 'a model outside rsx/ is marked framework');

        $method = static::__model($graph, 'Realtime_Method_Touch_Fixture_Model');
        static::__assert_true($method['overrides_realtime_touch'], 'a realtime_touch() override is flagged');
        static::__assert_false($method['realtime'], 'a method-only model is not $realtime');

        static::__assert_true(in_array('Realtime_Attr_Bad_Fixture_Model', array_column($graph['errors'], 'class'), true), 'the broken declaration is in errors');
    }

    /** --model keeps what notifies the model or is notified by it, transitively, and its emitters. */
    public static function test_model_filter()
    {
        $json = static::__run_json(['--model' => 'Realtime_Attr_Parent_Fixture_Model']);
        $graph = $json['graph'];
        $classes = array_column($graph['models'], 'class');

        static::__assert_equals(0, $json['exit'], 'the broken model is outside this slice');
        static::__assert_not_null(static::__edge($graph, 'Realtime_Attr_Child_Fixture_Model', 'parent'), 'a child that notifies it is kept');
        static::__assert_not_null(static::__edge($graph, 'Realtime_Attr_Touch_Only_Fixture_Model', 'parent'), 'a touch-only child is kept');
        static::__assert_false(in_array('Realtime_Method_Touch_Fixture_Model', $classes, true), 'an unrelated model is dropped');
        static::__assert_false(in_array('Realtime_Fixture_Model', $classes, true), 'an unrelated $realtime model is dropped');

        foreach ($graph['edges'] as $edge) {
            static::__assert_equals('Realtime_Attr_Parent_Fixture_Model', $edge['parent'], 'every kept edge leads to the filtered model');
        }

        static::__assert_equals([], $graph['emitters'], 'no emitter is constrained to it');

        // Upward: the onward child notifies its parent, whose run-time touch is the child's concern.
        $upward = static::__run(['--model' => 'Realtime_Attr_Onward_Child_Fixture_Model']);
        static::__assert_contains('<- Realtime_Attr_Onward_Parent_Fixture_Model::realtime_touch()', $upward['output'], 'a parent reached upward shows its run-time touch');

        $emitter = static::__run_json(['--model' => 'Realtime_Fixture_Model']);
        static::__assert_equals(['Realtime_Composition_Fixture_Service'], array_column($emitter['graph']['emitters'], 'class'), 'the emitter constrained to the model is kept');
    }

    /** --edges is a flat child -> parent list, run-time touches included. */
    public static function test_edge_list()
    {
        $run = static::__run(['--edges' => true, '--model' => 'Realtime_Attr_Parent_Fixture_Model']);

        static::__assert_equals(1, preg_match('/Realtime_Attr_Child_Fixture_Model::parent\(\)\s+parent_id\s+-> Realtime_Attr_Parent_Fixture_Model/', $run['output']), 'one row per edge');

        $runtime = static::__run(['--edges' => true, '--model' => 'Realtime_Method_Touch_Fixture_Model']);
        static::__assert_equals(1, preg_match('/Realtime_Method_Touch_Fixture_Model::realtime_touch\(\)\s+-> \(resolved at run time\)/', $runtime['output']), 'a run-time touch is a row');
    }

    /** A name that is not a model is refused. */
    public static function test_unknown_model_is_refused()
    {
        $run = static::__run(['--model' => 'Not_A_Model_At_All']);

        static::__assert_equals(1, $run['exit'], 'exit 1');
        static::__assert_contains("'Not_A_Model_At_All' is not a model class in the manifest.", $run['output'], 'and says why');
    }
}
