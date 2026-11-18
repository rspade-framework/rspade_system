<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Realtime\Realtime_Emitter_Service;
use App\RSpade\Core\Realtime\Realtime_Touch_Registry;
use Illuminate\Console\Command;

/**
 * rsx:realtime:map - the realtime graph of "what refreshes what", read-only.
 *
 * Four sections, all resolved from the manifest by the code paths the runtime uses, so the
 * map cannot disagree with what a write actually emits:
 *
 *   - the models with $realtime = true (Realtime_Touch_Registry::is_own_realtime());
 *   - the touch tree: each parent with every model whose write notifies it, transitively,
 *     from the #[Realtime_Touch] edges (Realtime_Touch_Registry::touch_metadata() - the same
 *     belongsTo reflection that resolves the foreign key and parent class at write time);
 *   - the models overriding realtime_touch() (Realtime_Touch_Registry::
 *     overrides_realtime_touch()), whose parents are known only per record at run time;
 *   - every #[Emitter] (Realtime_Emitter_Service::registered_emitters()).
 *
 * A #[Realtime_Touch] that does not resolve (not on a belongsTo) throws on every write of that
 * model; the map reports it as an [ERROR] row and exits 1.
 *
 *   --model=<X>  only what notifies X or is notified by X (transitively), and the emitters
 *                constrained to X
 *   --edges      the touch edges as a flat child -> parent list instead of the tree
 *   --json       {models, edges, emitters, errors}
 *
 * Framework models (outside rsx/) are marked [framework]. Exit 0; 1 on an unknown --model or
 * an unresolvable #[Realtime_Touch].
 */
class Realtime_Map_Command extends Command
{
    protected $signature = 'rsx:realtime:map
                            {--model= : Only what notifies, or is notified by, this model (simple class name)}
                            {--edges : Print the touch edges as a flat child -> parent list instead of a tree}
                            {--json : Print the graph as JSON}';

    protected $description = 'Show the realtime graph: $realtime models, #[Realtime_Touch] edges, realtime_touch() overrides and #[Emitter]s';

    public function handle()
    {
        $graph = self::_build_graph();

        $model = $this->option('model');

        if ($model !== null) {
            if (!Manifest::is_php_model_class($model)) {
                $this->error("[ERROR] '{$model}' is not a model class in the manifest.");

                return 1;
            }

            $graph = self::_filter_to_model($graph, $model);
        }

        $exit = $graph['errors'] === [] ? 0 : 1;

        if ($this->option('json')) {
            $this->line(json_encode([
                'models' => array_values($graph['models']),
                'edges' => $graph['edges'],
                'emitters' => $graph['emitters'],
                'errors' => $graph['errors'],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $exit;
        }

        foreach ($graph['errors'] as $error) {
            $this->error('[ERROR] ' . $error['class'] . ': ' . $error['message']);
        }

        $this->_print_realtime_models($graph);
        $this->line('');

        if ($this->option('edges')) {
            $this->_print_edge_list($graph);
        } else {
            $this->_print_touch_tree($graph);
        }

        $this->line('');
        $this->_print_emitters($graph);

        return $exit;
    }

    // =========================================================================
    // Graph
    // =========================================================================

    /**
     * Every model with a realtime surface, every touch edge, every emitter.
     *
     * `runtime` names the models whose realtime_touch() override is part of the map.
     *
     * @return array{models: array<string, array>, runtime: array<int, string>, edges: array<int, array>, emitters: array<int, array>, errors: array<int, array>}
     */
    private static function _build_graph(): array
    {
        $all = [];
        $edges = [];
        $errors = [];

        foreach (Manifest::php_get_subclasses_of('Rsx_Model_Abstract', concrete_only: true) as $class) {
            $all[$class] = self::_node($class);
        }

        foreach ($all as $class => $node) {
            try {
                $touches = Realtime_Touch_Registry::touch_metadata($node['fqcn']);
            } catch (\RuntimeException $e) {
                // The exception the first write of this model would throw - reported, not hidden.
                $errors[] = ['class' => $class, 'message' => trim($e->getMessage())];

                continue;
            }

            foreach ($touches as $touch) {
                $edges[] = [
                    'child' => $class,
                    'method' => $touch['method'],
                    'fk_column' => $touch['fk_column'],
                    'parent' => $touch['parent_class'],
                ];

                if (!isset($all[$touch['parent_class']])) {
                    $all[$touch['parent_class']] = self::_node($touch['parent_class']);
                }
            }
        }

        $emitters = [];

        foreach (Realtime_Emitter_Service::registered_emitters() as $emitter) {
            $emitters[] = [
                'class' => class_basename($emitter['class']),
                'method' => $emitter['method'],
                'topic' => $emitter['topic'],
                'model_constraint' => $emitter['model_constraint'],
            ];
        }

        // Keep the models that take part: their own frame, a touch in either direction, a
        // run-time touch, a broken declaration, or an emitter constrained to them.
        $in_graph = [];

        foreach ($edges as $edge) {
            $in_graph[$edge['child']] = true;
            $in_graph[$edge['parent']] = true;
        }

        foreach ($errors as $error) {
            $in_graph[$error['class']] = true;
        }

        foreach ($emitters as $emitter) {
            if ($emitter['model_constraint'] !== null && isset($all[$emitter['model_constraint']])) {
                $in_graph[$emitter['model_constraint']] = true;
            }
        }

        $models = [];

        foreach ($all as $class => $node) {
            if ($node['realtime'] || $node['overrides_realtime_touch'] || isset($in_graph[$class])) {
                unset($node['fqcn']);
                $models[$class] = $node;
            }
        }

        ksort($models);

        $runtime = array_keys(array_filter($models, fn (array $node) => $node['overrides_realtime_touch']));
        usort($edges, fn (array $a, array $b) => [$a['parent'], $a['child'], $a['method']] <=> [$b['parent'], $b['child'], $b['method']]);
        usort($emitters, fn (array $a, array $b) => [$a['class'], $a['method'], $a['topic']] <=> [$b['class'], $b['method'], $b['topic']]);

        return ['models' => $models, 'runtime' => $runtime, 'edges' => $edges, 'emitters' => $emitters, 'errors' => $errors];
    }

    /**
     * One model's facts. `fqcn` is working state, dropped before output.
     */
    private static function _node(string $class): array
    {
        $record = Manifest::php_class_metadata($class);

        if ($record === null) {
            shouldnt_happen("Model class '{$class}' is not in the manifest's class index.");
        }

        return [
            'class' => $class,
            'fqcn' => $record['fqcn'],
            'file' => $record['file'],
            'framework' => !str_starts_with($record['file'], 'rsx/'),
            'realtime' => Realtime_Touch_Registry::is_own_realtime($record['fqcn']),
            'overrides_realtime_touch' => Realtime_Touch_Registry::overrides_realtime_touch($record['fqcn']),
        ];
    }

    /**
     * Narrow the graph to what notifies $model (its children, transitively) and what $model
     * notifies (its parents, transitively), plus the emitters constrained to it.
     */
    private static function _filter_to_model(array $graph, string $model): array
    {
        $upward = self::_reach($graph['edges'], $model, 'child', 'parent');
        $downward = self::_reach($graph['edges'], $model, 'parent', 'child');

        $edges = [];
        $keep = [$model => true];

        foreach ($graph['edges'] as $edge) {
            if (isset($upward[$edge['child']]) || isset($downward[$edge['parent']])) {
                $edges[] = $edge;
                $keep[$edge['child']] = true;
                $keep[$edge['parent']] = true;
            }
        }

        $models = [];

        foreach ($graph['models'] as $class => $node) {
            if (!isset($keep[$class])) {
                continue;
            }

            $models[$class] = $node;
        }

        return [
            'models' => $models,
            // A run-time touch matters to $model only on $model itself or on a model it notifies.
            'runtime' => array_values(array_filter($graph['runtime'], fn (string $class) => isset($upward[$class]))),
            'edges' => $edges,
            'emitters' => array_values(array_filter($graph['emitters'], fn (array $emitter) => $emitter['model_constraint'] === $model)),
            'errors' => array_values(array_filter($graph['errors'], fn (array $error) => isset($keep[$error['class']]))),
        ];
    }

    /**
     * Every model reachable from $start by walking edges $from -> $to, $start included.
     *
     * @return array<string, true>
     */
    private static function _reach(array $edges, string $start, string $from, string $to): array
    {
        $reached = [$start => true];
        $queue = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($edges as $edge) {
                if ($edge[$from] === $current && !isset($reached[$edge[$to]])) {
                    $reached[$edge[$to]] = true;
                    $queue[] = $edge[$to];
                }
            }
        }

        return $reached;
    }

    // =========================================================================
    // Text output
    // =========================================================================

    private function _print_realtime_models(array $graph): void
    {
        $this->line('<info>REALTIME MODELS</info> ($realtime = true: every save()/delete() publishes Model_Changed_Topic)');

        $any = false;

        foreach ($graph['models'] as $class => $node) {
            if ($node['realtime']) {
                $this->line('  ' . $class . ($node['framework'] ? '  <comment>[framework]</comment>' : ''));
                $any = true;
            }
        }

        if (!$any) {
            $this->line('  (none)');
        }
    }

    private function _print_touch_tree(array $graph): void
    {
        $this->line('<info>TOUCH TREE</info> (each parent, then every model whose write notifies it)');

        $children = [];
        $has_outgoing = [];

        foreach ($graph['edges'] as $edge) {
            $children[$edge['parent']][] = $edge;
            $has_outgoing[$edge['child']] = true;
        }

        $runtime = $graph['runtime'];

        // Roots: parents that notify nothing further. A parent on a pure cycle has no such
        // root and is printed from itself afterwards.
        $printed = [];
        $roots = array_keys($children);
        sort($roots);

        foreach ($roots as $root) {
            if (!isset($has_outgoing[$root]) && !in_array($root, $runtime, true)) {
                $this->_print_subtree($graph, $children, $root, null, 1, [], $printed);
            }
        }

        foreach ($roots as $root) {
            if (!isset($printed[$root]) && !in_array($root, $runtime, true)) {
                $this->_print_subtree($graph, $children, $root, null, 1, [], $printed);
            }
        }

        if ($runtime !== []) {
            $this->line('  <comment>(parent resolved at run time by realtime_touch())</comment>');

            foreach ($runtime as $class) {
                $this->_print_subtree($graph, $children, $class, ['method' => 'realtime_touch', 'fk_column' => null], 2, [], $printed);
            }
        }

        if ($children === [] && $runtime === []) {
            $this->line('  (none)');
        }
    }

    /**
     * Print $class (reached over $edge, or as a root when null) and, beneath it, every model
     * whose write notifies it. $path guards against a touch cycle.
     */
    private function _print_subtree(array $graph, array $children, string $class, ?array $edge, int $depth, array $path, array &$printed): void
    {
        $printed[$class] = true;
        $indent = str_repeat('   ', $depth - 1) . '  ';
        $node = $graph['models'][$class];

        if ($edge === null) {
            $this->line($indent . $class . self::_marks($node));
        } else {
            $via = $edge['fk_column'] !== null ? '  via ' . $edge['fk_column'] : '';
            $this->line($indent . '<- ' . $class . '::' . $edge['method'] . '()' . $via . self::_marks($node));
        }

        if (isset($path[$class])) {
            return;
        }

        $path[$class] = true;

        foreach ($children[$class] ?? [] as $child_edge) {
            if (isset($path[$child_edge['child']])) {
                $this->line(str_repeat('   ', $depth) . '  <- ' . $child_edge['child'] . '::' . $child_edge['method'] . '()  via ' . $child_edge['fk_column'] . '  <comment>(cycle)</comment>');

                continue;
            }

            $this->_print_subtree($graph, $children, $child_edge['child'], $child_edge, $depth + 1, $path, $printed);
        }
    }

    private function _print_edge_list(array $graph): void
    {
        $this->line('<info>TOUCH EDGES</info> (child -> the parent its write notifies)');

        $rows = [];

        foreach ($graph['edges'] as $edge) {
            $rows[] = [$edge['child'] . '::' . $edge['method'] . '()', $edge['fk_column'], $edge['parent']];
        }

        foreach ($graph['runtime'] as $class) {
            $rows[] = [$class . '::realtime_touch()', '', '(resolved at run time)'];
        }

        if ($rows === []) {
            $this->line('  (none)');

            return;
        }

        $child_width = max(array_map(fn (array $row) => strlen($row[0]), $rows));
        $fk_width = max(array_map(fn (array $row) => strlen($row[1]), $rows));

        foreach ($rows as $row) {
            $this->line(sprintf('  %-' . $child_width . 's  %-' . $fk_width . 's  -> %s', $row[0], $row[1], $row[2]));
        }
    }

    private function _print_emitters(array $graph): void
    {
        $this->line('<info>EMITTERS</info> (#[Emitter] - publishes its topic when the computed value changes)');

        if ($graph['emitters'] === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($graph['emitters'] as $emitter) {
            $constraint = $emitter['model_constraint'] !== null ? '  model ' . $emitter['model_constraint'] : '';
            $this->line('  ' . $emitter['class'] . '::' . $emitter['method'] . '()  -> ' . $emitter['topic'] . $constraint);
        }
    }

    private static function _marks(array $node): string
    {
        $marks = '';

        if ($node['realtime']) {
            $marks .= '  [realtime]';
        }

        if ($node['framework']) {
            $marks .= '  <comment>[framework]</comment>';
        }

        return $marks;
    }
}
