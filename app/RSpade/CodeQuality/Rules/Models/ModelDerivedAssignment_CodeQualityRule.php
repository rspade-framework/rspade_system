<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\Receiver_Inference_Visitor;
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
 * The inference is Support/Receiver_Inference_Visitor, shared with MODEL-MONEY-02.
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

        $found = [];

        $visitor = new class($found) extends Receiver_Inference_Visitor {
            private array $found;

            public function __construct(array &$found)
            {
                $this->found = &$found;
            }

            protected function enter(Node $node): void
            {
                if (!($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\AssignRef)) {
                    return;
                }

                $target = $node->var;

                if ($target instanceof Node\Expr\PropertyFetch && $target->name instanceof Node\Identifier
                    && $target->var instanceof Node\Expr\Variable && is_string($target->var->name)) {
                    $receiver = $this->class_of_variable($target->var);

                    if (is_string($receiver) && $receiver !== '') {
                        $this->found[] = [$receiver, $target->name->toString(), $node->getStartLine(), $target->var->name];
                    }
                }
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
