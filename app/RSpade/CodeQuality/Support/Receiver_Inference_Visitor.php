<?php

namespace App\RSpade\CodeQuality\Support;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * The per-function receiver inference the model rules share: while a NodeTraverser walks a
 * file, it keeps the class each variable evidently holds, so a rule can ask what `$v` in
 * `$v->col` is.
 *
 * A variable's class is evident from, in the same function: a parameter typed `X` (or `?X`),
 * a `/** @var X $v *\/` docblock on a statement, or an assignment `$v = <expr>` whose
 * expression class_of_expression() answers - `new X(...)`, `X::find(...)`-style record
 * factories (RECORD_FACTORIES), a builder chain rooted at `X::` ending in a record terminal
 * (RECORD_TERMINALS). An assignment whose class is not evident forgets what the variable held,
 * so a reassigned variable is never judged as its earlier class - except a variable a @var
 * docblock declared, whose stated type holds for the rest of the function (the docblock sits
 * on the very assignment it describes: `/** @var X $v *\/ $v = load();`). `$this` is the enclosing
 * class. Closures and arrow functions start from the enclosing scope; every other function
 * starts empty. Names are simple class names (the last segment); `self` / `static` are the
 * enclosing class.
 *
 * A rule extends this with an anonymous class, implements enter() / leave() for its own
 * checks, and may widen class_of_expression(). The inference is deliberately shallow: what it
 * cannot see is unknown, never guessed.
 */
#[Instantiatable]
abstract class Receiver_Inference_Visitor extends NodeVisitorAbstract
{
    /** Static calls on a model class that answer one record of it. */
    public const RECORD_FACTORIES = ['find', 'findOrFail', 'make_new', 'first', 'firstOrFail', 'firstOrNew', 'sole'];

    /** The last call of a builder chain that answers one record. */
    public const RECORD_TERMINALS = ['first', 'firstOrFail', 'find', 'findOrFail', 'sole'];

    /** @var array<int, string|null> class name per enclosing class */
    protected array $classes = [];

    /** @var array<int, array<string, string>> variable => class name, per function scope */
    protected array $scopes = [[]];

    /** @var array<int, array<string, true>> variables a @var docblock declared, per function scope */
    protected array $declared = [[]];

    /** The rule's own work on entering a node, after the scope has been updated for it. */
    abstract protected function enter(Node $node): void;

    /** The rule's own work on leaving a node, before its scope is dropped. */
    protected function leave(Node $node): void
    {
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
                if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                    continue;
                }

                $class = $this->class_of_type($param->type);
                if ($class !== null) {
                    $scope[$param->var->name] = $class;
                } else {
                    unset($scope[$param->var->name]);
                }
            }

            $this->scopes[] = $scope;
            $this->declared[] = $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Expr\Closure
                ? end($this->declared)
                : [];
        }

        if ($node instanceof Node\Stmt && $node->getDocComment() !== null
            && preg_match_all('/@var\s+\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $node->getDocComment()->getText(), $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parts = explode('\\', $match[1]);
                $this->scopes[count($this->scopes) - 1][$match[2]] = end($parts);
                $this->declared[count($this->declared) - 1][$match[2]] = true;
            }
        }

        $this->enter($node);

        if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            $class = $this->class_of_expression($node->expr);
            if ($class !== null) {
                $this->scopes[count($this->scopes) - 1][$node->var->name] = $class;
            } elseif (!isset(end($this->declared)[$node->var->name])) {
                unset($this->scopes[count($this->scopes) - 1][$node->var->name]);
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        $this->leave($node);

        if ($node instanceof Node\FunctionLike) {
            array_pop($this->scopes);
            array_pop($this->declared);
        }

        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classes);
        }

        return null;
    }

    /** The class a variable evidently holds in the current scope (`$this` included), or null. */
    protected function class_of_variable(Node\Expr\Variable $variable): ?string
    {
        if (!is_string($variable->name)) {
            return null;
        }

        if ($variable->name === 'this') {
            $current = end($this->classes);

            return is_string($current) && $current !== '' ? $current : null;
        }

        return end($this->scopes)[$variable->name] ?? null;
    }

    /** The class an expression evidently answers a record of, or null. */
    protected function class_of_expression(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\New_) {
            return $this->class_of_name($expr->class);
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier
            && in_array($expr->name->toString(), self::RECORD_FACTORIES, true)) {
            return $this->class_of_name($expr->class);
        }

        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier
            && in_array($expr->name->toString(), self::RECORD_TERMINALS, true)) {
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

    protected function class_of_type($type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        return $type instanceof Node\Name ? $this->class_of_name($type) : null;
    }

    protected function class_of_name($name): ?string
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
}
