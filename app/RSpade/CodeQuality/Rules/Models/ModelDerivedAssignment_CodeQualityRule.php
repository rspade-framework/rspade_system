<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-DERIVED-01 - a derived column is never assigned in code.
 *
 * A column declared `'col' => ['derived' => 'method', 'from' => [...]]` in a model's
 * `$columns` is written by the model's own save(), which throws a LogicException when one was
 * assigned by hand. This rule reports the assignment where it is written, before anything
 * runs it. Two receivers are recognized:
 *
 *   - `$this->col = ...` inside a model class whose effective declaration makes `col`
 *     derived (inherited declarations included);
 *   - `$v->col = ...` where the class of `$v` is evident in the same function: `$v = new X(...)`,
 *     `$v = X::find(...)` / `findOrFail` / `make_new` / `first...`, a builder chain rooted at
 *     `X::` ending in `first` / `find` / `sole` (and their OrFail forms), a parameter typed `X`,
 *     or a `/** @var X $v *\/` docblock.
 *
 * The receiver must be inferred: a column name alone means nothing (one table's derived
 * column is another's ordinary one). Anything else goes unreported - the save-time guard is
 * the enforcement, this is the early warning. Rsx_Model_Abstract, which writes derived
 * columns through setAttribute(), is not judged.
 *
 * rsx:check only. Suppressed by @MODEL-DERIVED-01-EXCEPTION anywhere in the file.
 */
class ModelDerivedAssignment_CodeQualityRule extends CodeQualityRule_Abstract
{
    /** Static calls on a model class that answer one record of it. */
    private const RECORD_FACTORIES = ['find', 'findOrFail', 'make_new', 'first', 'firstOrFail', 'firstOrNew', 'sole'];

    /** The last call of a builder chain that answers one record. */
    private const RECORD_TERMINALS = ['first', 'firstOrFail', 'find', 'findOrFail', 'sole'];

    public function get_id(): string
    {
        return 'MODEL-DERIVED-01';
    }

    public function get_name(): string
    {
        return 'Derived Column Assignment';
    }

    public function get_description(): string
    {
        return 'A derived column is computed by its model on save and never assigned in code';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (basename($file_path) === 'Rsx_Model_Abstract.php') {
            return;
        }

        $raw = (string) $this->source()->content($file_path);

        if (!str_contains($raw, '->')) {
            return;
        }

        $ast = $this->source()->ast($file_path);

        if (!$ast) {
            return;
        }

        $rule = $this;
        $found = [];

        $visitor = new class($rule, $found) extends NodeVisitorAbstract {
            /** @var array<int, string|null> class name per enclosing class */
            private array $classes = [];

            /** @var array<int, array<string, string>> variable => class name, per function scope */
            private array $scopes = [[]];

            private ModelDerivedAssignment_CodeQualityRule $rule;

            private array $found;

            public function __construct(ModelDerivedAssignment_CodeQualityRule $rule, array &$found)
            {
                $this->rule = $rule;
                $this->found = &$found;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\ClassLike) {
                    $this->classes[] = $node instanceof Node\Stmt\Class_ && $node->name !== null ? $node->name->toString() : null;
                }

                if ($node instanceof Node\FunctionLike) {
                    $scope = $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Expr\Closure
                        ? end($this->scopes)
                        : [];

                    foreach ($node->getParams() as $param) {
                        $class = $this->class_of_type($param->type);
                        if ($class !== null && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                            $scope[$param->var->name] = $class;
                        }
                    }

                    $this->scopes[] = $scope;
                }

                if ($node instanceof Node\Stmt && $node->getDocComment() !== null
                    && preg_match_all('/@var\s+\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $node->getDocComment()->getText(), $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $parts = explode('\\', $match[1]);
                        $this->scopes[count($this->scopes) - 1][$match[2]] = end($parts);
                    }
                }

                if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\AssignRef) {
                    $target = $node->var;

                    if ($target instanceof Node\Expr\PropertyFetch && $target->name instanceof Node\Identifier
                        && $target->var instanceof Node\Expr\Variable && is_string($target->var->name)) {
                        $receiver = $target->var->name === 'this'
                            ? end($this->classes)
                            : (end($this->scopes)[$target->var->name] ?? null);

                        if (is_string($receiver) && $receiver !== '') {
                            $this->found[] = [$receiver, $target->name->toString(), $node->getStartLine(), $target->var->name];
                        }
                    }

                    if ($node instanceof Node\Expr\Assign && $target instanceof Node\Expr\Variable && is_string($target->name)) {
                        $class = $this->class_of_expression($node->expr);
                        if ($class !== null) {
                            $this->scopes[count($this->scopes) - 1][$target->name] = $class;
                        }
                    }
                }

                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\FunctionLike) {
                    array_pop($this->scopes);
                }

                if ($node instanceof Node\Stmt\ClassLike) {
                    array_pop($this->classes);
                }

                return null;
            }

            /** The class an expression evidently answers a record of, or null. */
            private function class_of_expression(Node\Expr $expr): ?string
            {
                if ($expr instanceof Node\Expr\New_) {
                    return $this->class_of_name($expr->class);
                }

                if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier
                    && in_array($expr->name->toString(), $this->rule->record_factories(), true)) {
                    return $this->class_of_name($expr->class);
                }

                if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier
                    && in_array($expr->name->toString(), $this->rule->record_terminals(), true)) {
                    $root = $expr->var;
                    while ($root instanceof Node\Expr\MethodCall) {
                        $root = $root->var;
                    }

                    if ($root instanceof Node\Expr\StaticCall) {
                        return $this->class_of_name($root->class);
                    }
                }

                return null;
            }

            private function class_of_type($type): ?string
            {
                if ($type instanceof Node\NullableType) {
                    $type = $type->type;
                }

                return $type instanceof Node\Name ? $this->class_of_name($type) : null;
            }

            private function class_of_name($name): ?string
            {
                if (!$name instanceof Node\Name) {
                    return null;
                }

                $last = $name->getLast();

                if (in_array(strtolower($last), ['self', 'static'], true)) {
                    $current = end($this->classes);

                    return is_string($current) ? $current : null;
                }

                return $last;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $lines = explode("\n", $raw);
        $reported = [];

        foreach ($found as [$class_name, $column, $line, $variable]) {
            $derived = $this->derived_columns_of($class_name);

            if (!isset($derived[$column]) || isset($reported[$line . '|' . $column])) {
                continue;
            }

            $reported[$line . '|' . $column] = true;
            $spec = $derived[$column];
            $from = implode(', ', $spec['from']);

            $this->add_violation(
                $file_path,
                $line,
                "{$class_name}::{$column} is a derived column - {$class_name}::{$spec['method']}() computes it from "
                . "[{$from}] on save. Assigning it (\${$variable}->{$column}) makes save() throw.",
                trim($lines[$line - 1] ?? ''),
                "Assign the column(s) it is computed from ({$from}) and save; the model derives {$column} itself.\n"
                . "When what {$spec['method']}() reads lives elsewhere and has changed, call \$record->rederive().\n"
                . 'See: php artisan rsx:man model',
                'high'
            );
        }
    }

    /** @return array<int, string> */
    public function record_factories(): array
    {
        return self::RECORD_FACTORIES;
    }

    /** @return array<int, string> */
    public function record_terminals(): array
    {
        return self::RECORD_TERMINALS;
    }

    /**
     * The derived columns of a model class named by its simple name, or [] when it is not a
     * model.
     *
     * @return array<string, array{method: string, from: array<int, string>}>
     */
    private function derived_columns_of(string $class_name): array
    {
        if ($class_name === 'Rsx_Model_Abstract' || !Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return [];
        }

        $fqcn = Manifest::php_class_metadata($class_name)['fqcn'] ?? null;

        if (!$fqcn || !class_exists($fqcn)) {
            return [];
        }

        return $fqcn::derived_columns();
    }
}
