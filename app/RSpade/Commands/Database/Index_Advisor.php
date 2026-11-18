<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Database;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * The rules rsx:db:check_indexes applies, as pure static functions.
 *
 * Nothing here reads the database, the manifest or the filesystem. The command gathers the
 * facts (source text, the schema, the existing indexes, which models are site-scoped) into a
 * CONTEXT array and hands them in; every decision about what a query needs and whether an
 * index serves it is made here, so each rule is testable from fixture arrays and fixture
 * source text.
 *
 * THE CONTEXT:
 *
 *   'models' => [
 *       'Simple_Class_Model' => [
 *           'table'        => 'table_name',
 *           'site_scoped'  => bool,      // Rsx_Site_Model_Abstract / Site_Scoped
 *           'scope_holder' => ?string,   // the class whose without_site_scope() flag this
 *                                        // model reads (the class adopting Site_Scoped)
 *       ],
 *   ],
 *   'tables' => [
 *       'table_name' => [
 *           'columns'         => ['col' => 'simplified type', ...],  // Rsx_Schema::simplify_type()
 *           'indexes'         => [['name' => 'PRIMARY', 'columns' => ['id']], ...],
 *           'primary_key'     => ['id'],
 *           'enum_columns'    => ['status_id', ...],  // declared model enums
 *           'framework'       => bool,                // a framework table
 *       ],
 *   ],
 *
 * A REQUIREMENT is what one query (or one relationship) needs from an index on one table:
 *
 *   ['table' => t, 'eq' => [...], 'range' => ?col, 'sort' => [...]]
 *
 * eq is a SET of equality-like columns (=, IN, IS NULL), held in canonical
 * order (site_id first, then alphabetical); range is at most one comparison column; sort is
 * the ORDER BY columns, kept only when there is no range column (an index can order by its
 * next column only when everything before it is fixed by equality).
 */
class Index_Advisor
{
    /**
     * Simplified column types (Rsx_Schema::simplify_type()) no ordinary B-tree index is
     * recommended on: long prose, binary and JSON.
     */
    const UNINDEXABLE_TYPES = [
        'text', 'tinytext', 'mediumtext', 'longtext',
        'blob', 'tinyblob', 'mediumblob', 'longblob',
        'json', 'geometry',
    ];

    /**
     * Comparison operators that make a column a RANGE condition.
     */
    const RANGE_OPERATORS = ['<', '>', '<=', '>=', '=<', '=>'];

    /**
     * MySQL's limit on an identifier's length.
     */
    const MAX_IDENTIFIER_LENGTH = 64;

    // =====================================================================================
    // SOURCES
    // =====================================================================================

    /**
     * Why a source file is not a query workload, or null when it is one.
     *
     *   'test'   - any path with a tests/ directory segment (rsx/tests, app/RSpade/tests, a
     *              module's own tests/): fixtures and assertions, not traffic
     *   'dev'    - rsx/app/dev: developer-only screens
     *   'seeder' - a path with a sample_data/, seeders/ or seeds/ directory segment: code that
     *              runs once to populate a database (a seeding CLASS anywhere is recognised
     *              from its source - see parse_source()'s is_seeder)
     *
     * @param string $file Path relative to base_path() ('rsx/...').
     */
    public static function source_exclusion(string $file): ?string
    {
        $segments = explode('/', str_replace('\\', '/', $file));
        array_pop($segments); // the file name itself is not a directory

        if (in_array('tests', $segments, true)) {
            return 'test';
        }

        if (str_starts_with($file, 'rsx/app/dev/')) {
            return 'dev';
        }

        foreach (['sample_data', 'seeders', 'seeds'] as $seed_dir) {
            if (in_array($seed_dir, $segments, true)) {
                return 'seeder';
            }
        }

        return null;
    }

    /**
     * Read one source file's model query chains.
     *
     * Returns:
     *   'error'     => ?string  why the file could not be parsed (null when it parsed)
     *   'is_seeder' => bool     a class whose only role is seeding: it extends a *Seeder
     *                           class, or every #[Command] it declares is named *:seed*
     *   'chains'    => [ ['root' => 'X_Model', 'line' => n, 'unscoped_by' => ['Y_Model'],
     *                     'steps' => [...], 'abandoned' => ?string], ... ]
     *
     * A CHAIN is the outermost call of a fluent expression whose innermost call is a static
     * call on a class (`X_Model::where(...)->...`); `self::` / `static::` resolve to the
     * enclosing class. A chain built on a variable, a relation method or a closure result is
     * not read. 'unscoped_by' names the classes whose without_site_scope(fn) encloses the
     * chain. Each step is one of:
     *
     *   ['op' => 'eq',    'column' => c]   where(c, v), where(c, '=', v), whereIn, whereNull,
     *                                      where(['c' => v, ...])
     *   ['op' => 'range', 'column' => c]   where(c, <|>|<=|>=, v), whereBetween, whereNotNull,
     *                                      where(c, 'like', 'prefix%')
     *   ['op' => 'sort',  'column' => c]   orderBy, orderByDesc, latest, oldest
     *   ['op' => 'join',  'table' => t, 'alias' => a]   join / leftJoin / rightJoin /
     *                                      crossJoin; t null when not a literal
     *   ['op' => 'unscope']                withoutGlobalScope(s), without_site_scope()
     *   ['op' => 'skip',  'column' => c, 'reason' => r]  a literal column no index can use
     *                                      here ('leading_wildcard', 'operator')
     *
     * A chain is 'abandoned' (and contributes nothing) when a column name is not a literal
     * ('dynamic_column') or it ORs conditions together ('or').
     */
    public static function parse_source(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($code);
        } catch (\PhpParser\Error $e) {
            return ['error' => $e->getMessage(), 'is_seeder' => false, 'chains' => []];
        }

        $visitor = new class extends NodeVisitorAbstract {
            public array $chains = [];
            public bool $is_seeder = false;
            private array $class_stack = [];
            private array $unscope_stack = [];
            private array $inner = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $this->class_stack[] = $node->name ? $node->name->toString() : null;
                    $this->is_seeder = $this->is_seeder || Index_Advisor::_class_is_seeder($node);

                    return null;
                }

                if (!$node instanceof Node\Expr\MethodCall && !$node instanceof Node\Expr\StaticCall) {
                    return null;
                }

                if ($node instanceof Node\Expr\MethodCall) {
                    $this->inner[spl_object_id($node->var)] = true;
                }

                $is_inner = isset($this->inner[spl_object_id($node)]);

                if (!$is_inner) {
                    $chain = Index_Advisor::_read_chain($node, end($this->class_stack) ?: null);

                    if ($chain !== null) {
                        $chain['unscoped_by'] = array_values(array_unique($this->unscope_stack));
                        $this->chains[] = $chain;
                    }
                }

                // X_Model::without_site_scope(fn) - everything inside the closure runs unscoped
                if ($node instanceof Node\Expr\StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'without_site_scope') {
                    $this->unscope_stack[] = Index_Advisor::_class_name($node->class, end($this->class_stack) ?: null) ?? '?';
                    $node->setAttribute('index_advisor_unscope', true);
                }

                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    array_pop($this->class_stack);
                }

                if ($node->getAttribute('index_advisor_unscope')) {
                    array_pop($this->unscope_stack);
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return ['error' => null, 'is_seeder' => $visitor->is_seeder, 'chains' => $visitor->chains];
    }

    /**
     * Whether a class declaration is a seeder: it extends a class named *Seeder, or it
     * declares at least one #[Command] and every one is named with a last segment beginning
     * "seed" (rsx_app:seed, app:seed_demo).
     *
     * @internal Called from parse_source()'s visitor.
     */
    public static function _class_is_seeder(Node\Stmt\Class_ $class): bool
    {
        if ($class->extends !== null && str_ends_with($class->extends->getLast(), 'Seeder')) {
            return true;
        }

        $command_names = [];

        foreach ($class->getMethods() as $method) {
            foreach ($method->attrGroups as $group) {
                foreach ($group->attrs as $attr) {
                    if ($attr->name->getLast() !== 'Command') {
                        continue;
                    }

                    $first = $attr->args[0]->value ?? null;
                    $command_names[] = $first instanceof Node\Scalar\String_ ? $first->value : '';
                }
            }
        }

        if (empty($command_names)) {
            return false;
        }

        foreach ($command_names as $name) {
            $parts = explode(':', $name);

            if (!str_starts_with(end($parts), 'seed')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The simple class name a static call names, or null when it is not a plain name
     * (`$class::`, `parent::`).
     *
     * @internal
     */
    public static function _class_name($class, ?string $enclosing_class): ?string
    {
        if (!$class instanceof Node\Name) {
            return null;
        }

        $name = $class->toString();
        $lower = strtolower($name);

        if ($lower === 'self' || $lower === 'static') {
            return $enclosing_class;
        }

        if ($lower === 'parent') {
            return null;
        }

        return $class->getLast();
    }

    /**
     * Read one fluent chain from its outermost call, or null when it does not root at a
     * static call on a named class.
     *
     * @internal Called from parse_source()'s visitor.
     */
    public static function _read_chain(Node\Expr $outermost, ?string $enclosing_class): ?array
    {
        $calls = [];
        $current = $outermost;

        while ($current instanceof Node\Expr\MethodCall) {
            $calls[] = $current;
            $current = $current->var;
        }

        if (!$current instanceof Node\Expr\StaticCall) {
            return null;
        }

        $root = static::_class_name($current->class, $enclosing_class);

        if ($root === null) {
            return null;
        }

        $calls[] = $current;
        $calls = array_reverse($calls);

        $steps = [];

        foreach ($calls as $call) {
            if (!$call->name instanceof Node\Identifier) {
                return ['root' => $root, 'line' => $outermost->getStartLine(), 'steps' => [], 'abandoned' => 'dynamic_method'];
            }

            $args = [];

            foreach ($call->args as $arg) {
                if ($arg instanceof Node\Arg) {
                    $args[] = $arg->value;
                }
            }

            $result = static::_call_steps($call->name->toString(), $args);

            if (is_string($result)) {
                return ['root' => $root, 'line' => $outermost->getStartLine(), 'steps' => [], 'abandoned' => $result];
            }

            array_push($steps, ...$result);
        }

        return ['root' => $root, 'line' => $outermost->getStartLine(), 'steps' => $steps, 'abandoned' => null];
    }

    /**
     * The steps one call contributes, or a string naming why the whole chain is abandoned.
     *
     * @param Node\Expr[] $args
     * @return array|string
     */
    private static function _call_steps(string $method, array $args)
    {
        $method_lower = strtolower($method);
        $first = $args[0] ?? null;

        // ORed conditions: no composite index serves an OR across columns
        if (str_starts_with($method_lower, 'orwhere')) {
            return 'or';
        }

        switch ($method_lower) {
            case 'where':
                return static::_where_steps($args);

            case 'wherein':
            case 'wherenull':
                if ($first instanceof Node\Expr\Closure || $first instanceof Node\Expr\ArrowFunction) {
                    return [];
                }

                return static::_literal_column($first, 'eq');

            // IS NOT NULL reads every non-null key: a range for index use
            case 'wherenotnull':
            case 'wherebetween':
                return static::_literal_column($first, 'range');

            case 'orderby':
            case 'orderbydesc':
                return static::_literal_column($first, 'sort');

            case 'latest':
            case 'oldest':
                if ($first === null) {
                    return [['op' => 'sort', 'column' => 'created_at']];
                }

                return static::_literal_column($first, 'sort');

            case 'join':
            case 'leftjoin':
            case 'rightjoin':
            case 'crossjoin':
                return [static::_join_step($first)];

            case 'withoutglobalscope':
            case 'withoutglobalscopes':
            case 'without_site_scope':
                return [['op' => 'unscope']];
        }

        return [];
    }

    /**
     * Steps of a where() call.
     *
     * @param Node\Expr[] $args
     * @return array|string
     */
    private static function _where_steps(array $args)
    {
        $first = $args[0] ?? null;

        // A nested group: where(function ($q) { ... }) - its conditions are not read
        if ($first instanceof Node\Expr\Closure || $first instanceof Node\Expr\ArrowFunction) {
            return [];
        }

        // where(['a' => 1, 'b' => 2])
        if ($first instanceof Node\Expr\Array_) {
            $steps = [];

            foreach ($first->items as $item) {
                if ($item === null || !$item->key instanceof Node\Scalar\String_) {
                    return 'dynamic_column';
                }

                $steps[] = ['op' => 'eq', 'column' => $item->key->value];
            }

            return $steps;
        }

        if (!$first instanceof Node\Scalar\String_) {
            return 'dynamic_column';
        }

        $column = $first->value;

        if (count($args) < 3) {
            return [['op' => 'eq', 'column' => $column]];
        }

        $operator = $args[1];

        if (!$operator instanceof Node\Scalar\String_) {
            return [['op' => 'skip', 'column' => $column, 'reason' => 'operator']];
        }

        $operator = strtolower(trim($operator->value));

        if ($operator === '=' || $operator === '<=>') {
            return [['op' => 'eq', 'column' => $column]];
        }

        if (in_array($operator, self::RANGE_OPERATORS, true)) {
            return [['op' => 'range', 'column' => $column]];
        }

        if ($operator === 'like') {
            $pattern = static::_leading_literal($args[2]);

            if ($pattern === null || $pattern === '' || str_starts_with($pattern, '%') || str_starts_with($pattern, '_')) {
                return [['op' => 'skip', 'column' => $column, 'reason' => 'leading_wildcard']];
            }

            return [['op' => 'range', 'column' => $column]];
        }

        // !=, <>, not like, and anything else: no index range to read
        return [['op' => 'skip', 'column' => $column, 'reason' => 'operator']];
    }

    /**
     * The literal text an expression starts with: a string literal, the left end of a
     * concatenation, or the first literal part of an interpolated string. Null when it
     * starts with something not literal.
     */
    private static function _leading_literal(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return static::_leading_literal($expr->left);
        }

        if ($expr instanceof Node\Scalar\InterpolatedString) {
            $part = $expr->parts[0] ?? null;

            return $part instanceof Node\InterpolatedStringPart ? $part->value : null;
        }

        return null;
    }

    /**
     * One step on a literal column, or the abandon reason when the column is not literal.
     *
     * @return array|string
     */
    private static function _literal_column(?Node\Expr $expr, string $op)
    {
        if (!$expr instanceof Node\Scalar\String_) {
            return 'dynamic_column';
        }

        return [['op' => $op, 'column' => $expr->value]];
    }

    /**
     * A join step: the joined table and its alias ('tasks as t'), table null when the
     * argument is not a literal.
     */
    private static function _join_step(?Node\Expr $expr): array
    {
        if (!$expr instanceof Node\Scalar\String_) {
            return ['op' => 'join', 'table' => null, 'alias' => null];
        }

        if (preg_match('/^\s*(\S+)\s+(?:as\s+)?(\S+)\s*$/i', $expr->value, $m)) {
            return ['op' => 'join', 'table' => $m[1], 'alias' => $m[2]];
        }

        $table = trim($expr->value);

        return ['op' => 'join', 'table' => $table, 'alias' => $table];
    }

    // =====================================================================================
    // REQUIREMENTS
    // =====================================================================================

    /**
     * The index requirements one parsed chain places, against the context.
     *
     * Columns are credited by their qualifier:
     *   - unqualified, or qualified with the root table: the root table's column - except in
     *     a chain with a join, where an unqualified column is kept only when it exists on the
     *     root table and on no joined table (every joined table must be known to tell);
     *   - qualified with a joined table (or its alias) that is a known model table: a
     *     requirement on THAT table, with no implicit site_id (the global scope filters the
     *     root model only);
     *   - qualified with anything else: dropped.
     *
     * The root model's site scope adds site_id to the equality set unless the chain calls
     * withoutGlobalScope(s) / without_site_scope(), or runs inside a without_site_scope(fn)
     * on a model sharing the root's scope flag.
     *
     * Returns ['requirements' => [...], 'reason' => ?string] - reason names why a chain on a
     * model produced nothing ('abandoned:or', 'unknown_model', 'no_table', 'no_filter').
     */
    public static function chain_requirements(array $chain, array $context): array
    {
        $model = $context['models'][$chain['root']] ?? null;

        if ($model === null) {
            return ['requirements' => [], 'reason' => 'unknown_model'];
        }

        if ($chain['abandoned'] !== null) {
            return ['requirements' => [], 'reason' => 'abandoned:' . $chain['abandoned']];
        }

        $root_table = $model['table'];

        if (!isset($context['tables'][$root_table])) {
            return ['requirements' => [], 'reason' => 'no_table'];
        }

        // Pass 1: joins and scope switches
        $aliases = [$root_table => $root_table];
        $joined = [];
        $has_unknown_join = false;
        $unscoped = false;

        foreach ($chain['steps'] as $step) {
            if ($step['op'] === 'join') {
                if ($step['table'] === null) {
                    $has_unknown_join = true;
                    continue;
                }

                $aliases[$step['alias']] = $step['table'];
                $aliases[$step['table']] = $step['table'];
                $joined[$step['table']] = true;
            } elseif ($step['op'] === 'unscope') {
                $unscoped = true;
            }
        }

        $has_join = $has_unknown_join || !empty($joined);

        foreach ($chain['unscoped_by'] ?? [] as $unscoping_class) {
            $holder = $context['models'][$unscoping_class]['scope_holder'] ?? null;

            if ($holder !== null && $holder === $model['scope_holder']) {
                $unscoped = true;
            }
        }

        // Pass 2: credit each column to its table
        $per_table = [];

        foreach ($chain['steps'] as $step) {
            if (!in_array($step['op'], ['eq', 'range', 'sort'], true)) {
                continue;
            }

            $table = static::_credit_table($step['column'], $root_table, $aliases, $joined, $has_join, $has_unknown_join, $context);

            if ($table === null) {
                continue;
            }

            $column = static::_unqualified($step['column']);
            $per_table[$table][$step['op']][] = $column;
        }

        $requirements = [];

        foreach ($per_table as $table => $parts) {
            $site_scoped = $table === $root_table && $model['site_scoped'] && !$unscoped;
            $requirement = static::make_requirement(
                $table,
                $parts['eq'] ?? [],
                $parts['range'] ?? [],
                $parts['sort'] ?? [],
                $site_scoped,
                $context
            );

            if ($requirement !== null) {
                $requirements[] = $requirement;
            }
        }

        return ['requirements' => $requirements, 'reason' => empty($requirements) ? 'no_filter' : null];
    }

    /**
     * The table a column is credited to, or null when it is dropped.
     */
    private static function _credit_table(
        string $column,
        string $root_table,
        array $aliases,
        array $joined,
        bool $has_join,
        bool $has_unknown_join,
        array $context
    ): ?string {
        if (str_contains($column, '.')) {
            [$qualifier] = explode('.', $column, 2);
            $table = $aliases[$qualifier] ?? $qualifier;

            if ($table === $root_table) {
                return $root_table;
            }

            // Another table: credited only when it is a known model table
            foreach ($context['models'] as $model) {
                if ($model['table'] === $table && isset($context['tables'][$table])) {
                    return $table;
                }
            }

            return null;
        }

        if (!$has_join) {
            return $root_table;
        }

        // Unqualified in a joined query: only when it can belong to the root table alone
        if ($has_unknown_join) {
            return null;
        }

        if (!isset($context['tables'][$root_table]['columns'][$column])) {
            return null;
        }

        foreach (array_keys($joined) as $joined_table) {
            if (!isset($context['tables'][$joined_table])) {
                return null;
            }

            if (isset($context['tables'][$joined_table]['columns'][$column])) {
                return null;
            }
        }

        return $root_table;
    }

    /**
     * A column name without its table qualifier.
     */
    private static function _unqualified(string $column): string
    {
        $pos = strrpos($column, '.');

        return $pos === false ? $column : substr($column, $pos + 1);
    }

    /**
     * The normalized requirement for these columns on $table, or null when it needs no index.
     *
     *   - a column the table does not have, or of an unindexable type (TEXT, BLOB, JSON), is
     *     dropped;
     *   - site_id joins the equality set for a site-scoped query;
     *   - the first range column is kept and a range column already fixed by equality is
     *     dropped; sort columns are kept only when there is no range column, minus any fixed
     *     by equality;
     *   - an equality set holding the whole primary key is a primary-key lookup: null;
     *   - a range or sort on the primary key is dropped: InnoDB appends it to every secondary
     *     index, so (a) already orders and ranges by id within a;
     *   - no equality and no range column (nothing filters): null.
     *
     * @param string[] $eq
     * @param string[] $range
     * @param string[] $sort
     */
    public static function make_requirement(
        string $table,
        array $eq,
        array $range,
        array $sort,
        bool $site_scoped,
        array $context
    ): ?array {
        $table_info = $context['tables'][$table] ?? null;

        if ($table_info === null) {
            return null;
        }

        $usable = function (string $column) use ($table_info): bool {
            $type = $table_info['columns'][$column] ?? null;

            return $type !== null && !in_array($type, self::UNINDEXABLE_TYPES, true);
        };

        $eq = array_values(array_unique(array_filter($eq, $usable)));

        if ($site_scoped && isset($table_info['columns']['site_id']) && !in_array('site_id', $eq, true)) {
            $eq[] = 'site_id';
        }

        $primary_key = $table_info['primary_key'] ?? [];

        if (!empty($primary_key) && empty(array_diff($primary_key, $eq))) {
            return null;
        }

        $single_pk = count($primary_key) === 1 ? $primary_key[0] : null;

        $range_column = null;

        foreach ($range as $column) {
            if ($usable($column) && !in_array($column, $eq, true) && $column !== $single_pk) {
                $range_column = $column;
                break;
            }
        }

        $sort_columns = [];

        if ($range_column === null) {
            foreach ($sort as $column) {
                if ($column === $single_pk) {
                    break; // everything after the primary key is already ordered
                }

                if ($usable($column) && !in_array($column, $eq, true) && !in_array($column, $sort_columns, true)) {
                    $sort_columns[] = $column;
                }
            }
        }

        if (empty($eq) && $range_column === null) {
            return null;
        }

        return [
            'table' => $table,
            'eq' => static::canonical_eq($eq),
            'range' => $range_column,
            'sort' => $sort_columns,
        ];
    }

    /**
     * An equality set in canonical order: site_id first, then alphabetical.
     *
     * @param string[] $eq
     * @return string[]
     */
    public static function canonical_eq(array $eq): array
    {
        $eq = array_values(array_unique($eq));
        sort($eq);

        if (in_array('site_id', $eq, true)) {
            $eq = array_merge(['site_id'], array_values(array_diff($eq, ['site_id'])));
        }

        return $eq;
    }

    /**
     * The index a requirement recommends: the equality columns (site_id first), then the
     * range column or the sort columns.
     *
     * @return string[]
     */
    public static function recommended_shape(array $requirement): array
    {
        if ($requirement['range'] !== null) {
            return array_merge($requirement['eq'], [$requirement['range']]);
        }

        return array_merge($requirement['eq'], $requirement['sort']);
    }

    /**
     * A requirement's identity: identical requirements from different call sites share it.
     */
    public static function signature(array $requirement): string
    {
        return $requirement['table'] . '|' . implode(',', $requirement['eq'])
            . '|' . ($requirement['range'] ?? '')
            . '|' . implode(',', $requirement['sort']);
    }

    /**
     * The first index that serves a requirement, or null.
     *
     * An index serves it when either:
     *   - its leading columns are EXACTLY the equality set, in any order (equality is
     *     order-insensitive); or
     *   - its leading columns are all in the equality set and include at least one
     *     SELECTIVE column - one that is neither site_id nor low-cardinality
     *     (is_low_cardinality_column()). A selective column already narrows the read to a
     *     handful of rows, and the remaining equality conditions filter those cheaply:
     *     (client_id) serves {site_id, client_id}, because one client's rows all sit in one
     *     site. (site_id) alone, or (status_id) alone, narrows by a fraction and serves
     *     nothing wider than itself.
     *
     * Whatever follows the matched columns - the range or sort columns, or nothing - is
     * optional. A requirement with no equality column is served by an index leading with its
     * range column.
     *
     * @param array<int, array{name: string, columns: string[]}> $indexes
     * @param array $table_info The table's context entry (columns, enum_columns).
     */
    public static function serving_index(array $requirement, array $indexes, array $table_info): ?array
    {
        $eq = $requirement['eq'];
        $n = count($eq);

        foreach ($indexes as $index) {
            $columns = array_values($index['columns']);

            if ($n === 0) {
                if (($columns[0] ?? null) === $requirement['range']) {
                    return $index;
                }

                continue;
            }

            $leading = array_slice($columns, 0, $n);

            if (count($leading) === $n && count(array_unique($leading)) === $n && empty(array_diff($eq, $leading))) {
                return $index;
            }

            foreach ($columns as $column) {
                if (!in_array($column, $eq, true)) {
                    break;
                }

                if ($column !== 'site_id' && !static::is_low_cardinality_column($column, $table_info)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * An index that ALMOST serves a requirement - its leading columns are all but one of
     * the equality set - as ['index' => ..., 'leading' => [the matched columns],
     * 'missing' => col], or null; an index that is exactly those columns (and so can be
     * extended in place) is preferred. Only for an
     * equality set of two or more columns, and only meaningful for a requirement no index
     * serves: such an index then holds only site_id and low-cardinality columns, and adding
     * the missing (selective) column to it is often better than adding a new index.
     *
     * @param array<int, array{name: string, columns: string[]}> $indexes
     */
    public static function nearly_serving_index(array $requirement, array $indexes): ?array
    {
        $eq = $requirement['eq'];
        $n = count($eq);

        if ($n < 2) {
            return null;
        }

        $found = null;

        foreach ($indexes as $index) {
            $columns = array_values($index['columns']);
            $leading = array_slice($columns, 0, $n - 1);

            if (count($leading) !== $n - 1 || count(array_unique($leading)) !== $n - 1 || !empty(array_diff($leading, $eq))) {
                continue;
            }

            $missing = array_values(array_diff($eq, $leading));
            $candidate = ['index' => $index, 'leading' => $leading, 'missing' => $missing[0]];

            // An index that IS those columns can simply be extended: prefer it.
            if (count($columns) === $n - 1) {
                return $candidate;
            }

            $found = $found ?? $candidate;
        }

        return $found;
    }

    /**
     * Whether a requirement is LOW VALUE: apart from site_id it filters only by equality on
     * low-cardinality columns, so an index narrows the read by a constant fraction at best.
     *
     * A column is low-cardinality when it is a boolean (TINYINT(1)), a MySQL ENUM, a declared
     * model enum ($columns 'enum'), or named like a flag or status (is_*, has_*, status,
     * *_status, status_id).
     */
    public static function is_low_value(array $requirement, array $context): bool
    {
        if ($requirement['range'] !== null) {
            return false;
        }

        $columns = array_values(array_diff($requirement['eq'], ['site_id']));

        if (empty($columns)) {
            return false;
        }

        $table_info = $context['tables'][$requirement['table']] ?? [];

        foreach ($columns as $column) {
            if (!static::is_low_cardinality_column($column, $table_info)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether one column is low-cardinality (see is_low_value()).
     */
    public static function is_low_cardinality_column(string $column, array $table_info): bool
    {
        $type = $table_info['columns'][$column] ?? null;

        if ($type === 'boolean' || $type === 'enum') {
            return true;
        }

        if (in_array($column, $table_info['enum_columns'] ?? [], true)) {
            return true;
        }

        return (bool) preg_match('/^(is|has)_|^status$|_status$|^status_id$/', $column);
    }

    // =====================================================================================
    // AGGREGATION AND THE DIFF
    // =====================================================================================

    /**
     * Group identical requirements: one entry per signature with every distinct call site.
     *
     * @param array $items [['requirement' => [...], 'source' => 'file:line', 'kind' => 'query'|'relationship', 'type' => 'where'|'HasMany'...], ...]
     * @return array<string, array> signature => ['requirement', 'sources' => [...], 'kinds' => [...], 'types' => [...]]
     */
    public static function aggregate(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $sig = static::signature($item['requirement']);

            if (!isset($groups[$sig])) {
                $groups[$sig] = [
                    'requirement' => $item['requirement'],
                    'sources' => [],
                    'kinds' => [],
                    'types' => [],
                ];
            }

            if (!in_array($item['source'], $groups[$sig]['sources'], true)) {
                $groups[$sig]['sources'][] = $item['source'];
            }

            $groups[$sig]['kinds'][$item['kind']] = true;
            $groups[$sig]['types'][$item['type']] = true;
        }

        return $groups;
    }

    /**
     * The analysis of one table's aggregated requirements against its existing indexes.
     *
     * Returns:
     *   'creates'   => recommended indexes (normal value), each ['columns' => shape,
     *                  'sources' => [...], 'requirements' => [...served], 'kinds', 'types',
     *                  'nearly' => ?['index', 'missing']]
     *   'low_value' => the same shape, for low-value requirements
     *   'drops'     => existing idx_auto_* indexes serving no requirement
     *
     * A requirement an existing index serves (serving_index()) needs nothing. The unserved
     * ones are merged by the same rule: a recommended index absorbs every requirement it
     * would serve, so one index carries several call sites. Normal requirements are
     * merged first (longest shape first); a low-value requirement is folded into a normal
     * index that serves it before it is listed on its own.
     *
     * @param array<string, array> $groups From aggregate(), all on $table.
     */
    public static function table_diff(string $table, array $groups, array $context): array
    {
        $table_info = $context['tables'][$table];
        $indexes = $table_info['indexes'];

        $normal = [];
        $low = [];

        foreach ($groups as $group) {
            if (static::serving_index($group['requirement'], $indexes, $table_info) !== null) {
                continue;
            }

            if (static::is_low_value($group['requirement'], $context)) {
                $low[] = $group;
            } else {
                $normal[] = $group;
            }
        }

        $none = [];
        $creates = static::_merge_groups($normal, $none, $table_info);
        $low_value = static::_merge_groups($low, $creates, $table_info);

        // Drops: an auto-generated index serving no requirement at all
        $drops = [];

        foreach ($indexes as $index) {
            if (!str_starts_with($index['name'], 'idx_auto_')) {
                continue;
            }

            $used = false;

            foreach ($groups as $group) {
                if (static::serving_index($group['requirement'], [$index], $table_info) !== null) {
                    $used = true;
                    break;
                }
            }

            if (!$used) {
                $drops[] = $index;
            }
        }

        return ['creates' => $creates, 'low_value' => $low_value, 'drops' => $drops];
    }

    /**
     * Merge requirement groups into recommended indexes, longest shape first. A group some
     * index in $absorbing already serves is folded into that index (its call sites added)
     * instead of being listed; so is one an earlier recommendation in this pass serves.
     *
     * @param array $absorbing Recommended indexes from an earlier pass; updated in place.
     * @param array $table_info The table's context entry (its indexes, for the nearly-served
     *                          note; its columns, for the serving rule).
     */
    private static function _merge_groups(array $groups, array &$absorbing, array $table_info): array
    {
        usort($groups, function ($a, $b) {
            $by_length = count(static::recommended_shape($b['requirement'])) <=> count(static::recommended_shape($a['requirement']));

            return $by_length !== 0 ? $by_length : strcmp(static::signature($a['requirement']), static::signature($b['requirement']));
        });

        $result = [];

        foreach ($groups as $group) {
            if (static::_fold_into($absorbing, $group, $table_info) || static::_fold_into($result, $group, $table_info)) {
                continue;
            }

            $result[] = [
                'columns' => static::recommended_shape($group['requirement']),
                'requirements' => [$group['requirement']],
                'sources' => $group['sources'],
                'kinds' => $group['kinds'],
                'types' => $group['types'],
                'nearly' => static::nearly_serving_index($group['requirement'], $table_info['indexes']),
            ];
        }

        return $result;
    }

    /**
     * Fold a requirement group into the first recommended index in $pool that serves it.
     * Returns whether it was folded.
     */
    private static function _fold_into(array &$pool, array $group, array $table_info): bool
    {
        foreach ($pool as $i => $recommended) {
            if (static::serving_index($group['requirement'], [['name' => '', 'columns' => $recommended['columns']]], $table_info) === null) {
                continue;
            }

            $pool[$i]['requirements'][] = $group['requirement'];
            $pool[$i]['sources'] = array_values(array_unique(array_merge($recommended['sources'], $group['sources'])));
            $pool[$i]['kinds'] += $group['kinds'];
            $pool[$i]['types'] += $group['types'];

            return true;
        }

        return false;
    }

    // =====================================================================================
    // MIGRATION TEXT
    // =====================================================================================

    /**
     * The name of an auto-generated index on $columns: idx_auto_<cols>, shortened with a
     * hash suffix when it would pass MySQL's 64-character identifier limit.
     *
     * @param string[] $columns
     */
    public static function index_name(array $columns): string
    {
        $name = 'idx_auto_' . implode('_', $columns);

        if (strlen($name) <= self::MAX_IDENTIFIER_LENGTH) {
            return $name;
        }

        return substr($name, 0, self::MAX_IDENTIFIER_LENGTH - 9) . '_' . substr(md5($name), 0, 8);
    }

    /**
     * The body of a migration's up(): one ALTER TABLE per index to drop or create, each
     * create preceded by the call sites it serves.
     *
     * @param array<string, array> $creates table => recommended indexes
     * @param array<string, array> $drops table => existing indexes
     */
    public static function migration_up_body(array $creates, array $drops): string
    {
        $lines = [];

        foreach ($drops as $table => $table_drops) {
            foreach ($table_drops as $index) {
                $lines[] = "        // {$table}: no analyzed query uses this auto-generated index any more";
                $lines[] = "        DB::statement('ALTER TABLE `{$table}` DROP INDEX `{$index['name']}`');";
                $lines[] = '';
            }
        }

        foreach ($creates as $table => $table_creates) {
            foreach ($table_creates as $create) {
                $name = static::index_name($create['columns']);
                $columns = implode(', ', array_map(fn ($c) => "`{$c}`", $create['columns']));

                $lines[] = "        // {$table} (" . implode(', ', $create['columns']) . ') - ' . count($create['sources']) . ' call site(s):';

                foreach ($create['sources'] as $source) {
                    $lines[] = "        //   {$source}";
                }

                $lines[] = "        DB::statement('ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columns})');";
                $lines[] = '';
            }
        }

        return rtrim(implode("\n", $lines));
    }
}
