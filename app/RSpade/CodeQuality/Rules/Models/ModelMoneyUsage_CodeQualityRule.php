<?php

namespace App\RSpade\CodeQuality\Rules\Models;

use PhpParser\PrettyPrinter\Standard as Pretty_Printer;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\Receiver_Inference_Visitor;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-MONEY-02 - an Rsx_Money is never compared, tested for truth, cast or used in
 * arithmetic with PHP's operators.
 *
 * Rsx_Money is an object. PHP compares two objects property by property (`<`, `==`) and by
 * identity (`===`), treats every object as true (`if`, `!`, `&&`, `?:`, `empty()`), and turns
 * one into 1 under `(int)` / `(float)` - none of which asks about the AMOUNT, and none of which
 * the class can intercept. Arithmetic, concatenation and `(string)` throw at run time; this
 * rule reports them where they are written. The class's methods are the operations:
 * equals(), compare(), less_than() and the other questions, is_zero(), add(), subtract(),
 * multiply(), to_decimal(), format().
 *
 * A MONEY EXPRESSION is, in the same function (receiver inference: Support/
 * Receiver_Inference_Visitor, shared with MODEL-DERIVED-01):
 *
 *   - `$v->col` / `$v?->col` where the class of `$v` is evident and is a model declaring `col`
 *     ['money' => true] (`$this` inside the model included);
 *   - a variable evidently holding an Rsx_Money: a parameter typed Rsx_Money, a `@var
 *     Rsx_Money $v`, or an assignment from a money expression;
 *   - a static call on Rsx_Money answering a value (from_*, zero, sum, min, max, average), or a
 *     call on a money expression of a method answering one (add, subtract, negate, abs,
 *     multiply, multiply_fraction, percent, divide_rounded, round_to, clamp).
 *
 * Reported when a money expression is an operand of `==` `!=` `<>` `<` `<=` `>` `>=` `<=>`;
 * of `===` / `!==` unless the other side is null; of `!` `&&` `||` `and` `or` `xor`; the
 * condition of `if` / `elseif` / `while` / `do` / `for` / a ternary / `?:` / a `match (true)`
 * arm; a `(bool)` `(int)` `(float)` `(string)` cast; `empty()`; an argument of intval,
 * floatval, boolval, strval, round, number_format, abs, max, min or array_sum; `+ - * / % **`,
 * unary `-` / `+`, their assignment forms, `.` / `.=` and string interpolation.
 *
 * Never reported: `=== null` / `!== null`, `is_null()`, `isset()`, `??`, `?->`, `instanceof`,
 * passing the value as an argument, returning it. Null is how "no amount" is spelled.
 *
 * Accepted false negatives: values reached through array elements, collections, untyped
 * variables and other method returns, and anything in JavaScript. The rule never guesses a
 * receiver, so what it reports is a real misuse.
 *
 * rsx:check only. Suppressed by @MODEL-MONEY-02-EXCEPTION anywhere in the file.
 */
class ModelMoneyUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    /** Static calls on Rsx_Money that answer an Rsx_Money. */
    public const MONEY_STATIC_CALLS = ['zero', 'sum', 'min', 'max', 'average'];

    /** Methods of Rsx_Money that answer an Rsx_Money. */
    public const MONEY_METHODS = ['add', 'subtract', 'negate', 'abs', 'multiply', 'multiply_fraction', 'percent', 'divide_rounded', 'round_to', 'clamp'];

    /** Functions that read their argument as a number, string or bool. */
    public const SCALAR_FUNCTIONS = ['intval', 'floatval', 'boolval', 'strval', 'round', 'number_format', 'abs', 'max', 'min', 'array_sum'];

    /** Comparison operator node class => [the operator, the method(s) to use]. */
    private const COMPARISONS = [
        Node\Expr\BinaryOp\Equal::class => ['==', '->equals($other)'],
        Node\Expr\BinaryOp\NotEqual::class => ['!=', '! $x->equals($other)'],
        Node\Expr\BinaryOp\Smaller::class => ['<', '->less_than($other) or ->compare($other)'],
        Node\Expr\BinaryOp\SmallerOrEqual::class => ['<=', '->less_than_or_equal($other) or ->compare($other)'],
        Node\Expr\BinaryOp\Greater::class => ['>', '->greater_than($other) or ->compare($other)'],
        Node\Expr\BinaryOp\GreaterOrEqual::class => ['>=', '->greater_than_or_equal($other) or ->compare($other)'],
        Node\Expr\BinaryOp\Spaceship::class => ['<=>', '->compare($other)'],
    ];

    /** Arithmetic operator node class => [the operator, the method to use]. */
    private const ARITHMETIC = [
        Node\Expr\BinaryOp\Plus::class => ['+', '->add($other)'],
        Node\Expr\BinaryOp\Minus::class => ['-', '->subtract($other)'],
        Node\Expr\BinaryOp\Mul::class => ['*', '->multiply($factor, $rounding)'],
        Node\Expr\BinaryOp\Div::class => ['/', '->divide_rounded($divisor, $rounding) or ->allocate($ratios)'],
        Node\Expr\BinaryOp\Mod::class => ['%', '->divide($n) (quotient and remainder)'],
        Node\Expr\BinaryOp\Pow::class => ['**', '->multiply($factor, $rounding)'],
        Node\Expr\AssignOp\Plus::class => ['+=', '$x = $x->add($other)'],
        Node\Expr\AssignOp\Minus::class => ['-=', '$x = $x->subtract($other)'],
        Node\Expr\AssignOp\Mul::class => ['*=', '$x = $x->multiply($factor, $rounding)'],
        Node\Expr\AssignOp\Div::class => ['/=', '$x = $x->divide_rounded($divisor, $rounding)'],
        Node\Expr\AssignOp\Mod::class => ['%=', '->divide($n) (quotient and remainder)'],
        Node\Expr\AssignOp\Pow::class => ['**=', '$x = $x->multiply($factor, $rounding)'],
        Node\Expr\UnaryMinus::class => ['unary -', '->negate()'],
        Node\Expr\UnaryPlus::class => ['unary +', 'the value itself'],
    ];

    /** Logical operator node class => its operator. */
    private const LOGICAL = [
        Node\Expr\BinaryOp\BooleanAnd::class => '&&',
        Node\Expr\BinaryOp\BooleanOr::class => '||',
        Node\Expr\BinaryOp\LogicalAnd::class => 'and',
        Node\Expr\BinaryOp\LogicalOr::class => 'or',
        Node\Expr\BinaryOp\LogicalXor::class => 'xor',
    ];

    /** Cast node class => its spelling. */
    private const CASTS = [
        Node\Expr\Cast\Bool_::class => '(bool)',
        Node\Expr\Cast\Int_::class => '(int)',
        Node\Expr\Cast\Double::class => '(float)',
        Node\Expr\Cast\String_::class => '(string)',
    ];

    public function get_id(): string
    {
        return 'MODEL-MONEY-02';
    }

    public function get_name(): string
    {
        return 'Money Compared, Tested Or Cast With Operators';
    }

    public function get_description(): string
    {
        return 'An Rsx_Money is compared, tested, cast and calculated with its methods, never with PHP operators';
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
        $raw = (string) $this->source()->content($file_path);

        // A money expression is a property fetch or a call on Rsx_Money / a typed variable;
        // a file with neither arrow nor the class name holds none.
        if (!str_contains($raw, '->') && !str_contains($raw, 'Rsx_Money')) {
            return;
        }

        $ast = $this->source()->ast($file_path);

        if (!$ast) {
            return;
        }

        $rule = $this;
        $found = [];

        $visitor = new class($rule, $found) extends Receiver_Inference_Visitor {
            private ModelMoneyUsage_CodeQualityRule $rule;

            private array $found;

            public function __construct(ModelMoneyUsage_CodeQualityRule $rule, array &$found)
            {
                $this->rule = $rule;
                $this->found = &$found;
            }

            protected function enter(Node $node): void
            {
                foreach ($this->rule->misuses_of($node, fn (Node\Expr $expr) => $this->is_money($expr)) as $misuse) {
                    $this->found[] = $misuse;
                }
            }

            /** An assignment from a money expression makes the variable hold an Rsx_Money. */
            protected function class_of_expression(Node\Expr $expr): ?string
            {
                return $this->is_money($expr) ? 'Rsx_Money' : parent::class_of_expression($expr);
            }

            public function is_money(Node\Expr $expr): bool
            {
                if ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch) {
                    if (!$expr->name instanceof Node\Identifier) {
                        return false;
                    }

                    $receiver = $expr->var instanceof Node\Expr\Variable
                        ? $this->class_of_variable($expr->var)
                        : $this->class_of_expression($expr->var);

                    return $receiver !== null && $this->rule->is_money_column_of($receiver, $expr->name->toString());
                }

                // `$this` inside Rsx_Money is the implementation, never a use of the value.
                if ($expr instanceof Node\Expr\Variable) {
                    return $expr->name !== 'this' && $this->class_of_variable($expr) === 'Rsx_Money';
                }

                if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier) {
                    $method = $expr->name->toString();

                    return $this->class_of_name($expr->class) === 'Rsx_Money'
                        && (str_starts_with($method, 'from_') || in_array($method, ModelMoneyUsage_CodeQualityRule::MONEY_STATIC_CALLS, true));
                }

                if (($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall)
                    && $expr->name instanceof Node\Identifier) {
                    return in_array($expr->name->toString(), ModelMoneyUsage_CodeQualityRule::MONEY_METHODS, true)
                        && $this->is_money($expr->var);
                }

                return false;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $lines = explode("\n", $raw);
        $printer = new Pretty_Printer();
        $reported = [];

        foreach ($found as [$line, $expr, $shape, $use_instead]) {
            $text = $printer->prettyPrintExpr($expr);
            $key = $line . '|' . $text . '|' . $shape;

            if (isset($reported[$key])) {
                continue;
            }

            $reported[$key] = true;

            $this->add_violation(
                $file_path,
                $line,
                "'{$text}' is an Rsx_Money: {$shape} Use {$use_instead}.",
                trim($lines[$line - 1] ?? ''),
                "Rsx_Money is an object: PHP's operators, casts and truth tests do not see its amount. Write the method named above.\n"
                . 'See: php artisan rsx:man money',
                'high'
            );
        }
    }

    /**
     * Every misuse of a money operand $node commits, as [line, money expression, what the
     * operator does, what to use instead]. The testable core of the rule: $is_money answers
     * whether an expression is a money expression in the scope at $node.
     *
     * @param callable(Node\Expr): bool $is_money
     * @return array<int, array{0: int, 1: Node\Expr, 2: string, 3: string}>
     */
    public function misuses_of(Node $node, callable $is_money): array
    {
        $line = $node->getStartLine();
        $out = [];
        $truthiness = fn (Node\Expr $expr, string $where) => [
            $expr->getStartLine(), $expr,
            "{$where} tests the object, which is always true - even for \$0.00.",
            '=== null for "no value" or ->is_zero() for zero',
        ];

        $class = get_class($node);

        if (isset(self::COMPARISONS[$class])) {
            [$op, $use] = self::COMPARISONS[$class];
            foreach ([$node->left, $node->right] as $side) {
                if ($is_money($side)) {
                    $out[] = [$line, $side, "'{$op}' compares the objects' properties, not the amounts.", $use];
                }
            }

            return $out;
        }

        if ($node instanceof Node\Expr\BinaryOp\Identical || $node instanceof Node\Expr\BinaryOp\NotIdentical) {
            $op = $node instanceof Node\Expr\BinaryOp\Identical ? '===' : '!==';
            foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$side, $other]) {
                if ($is_money($side) && !self::is_null_constant($other)) {
                    $out[] = [$line, $side, "'{$op}' compares object identity, not the amounts.", "->equals(\$other), or {$op} null for \"no value\""];
                }
            }

            return $out;
        }

        if (isset(self::LOGICAL[$class])) {
            foreach ([$node->left, $node->right] as $side) {
                if ($is_money($side)) {
                    $out[] = $truthiness($side, "'" . self::LOGICAL[$class] . "'");
                }
            }

            return $out;
        }

        if ($node instanceof Node\Expr\BooleanNot && $is_money($node->expr)) {
            return [$truthiness($node->expr, "'!'")];
        }

        if (($node instanceof Node\Stmt\If_ || $node instanceof Node\Stmt\ElseIf_ || $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_)
            && $is_money($node->cond)) {
            return [$truthiness($node->cond, 'a condition')];
        }

        if ($node instanceof Node\Stmt\For_ && $node->cond !== [] && $is_money(end($node->cond))) {
            return [$truthiness(end($node->cond), 'a condition')];
        }

        if ($node instanceof Node\Expr\Ternary && $is_money($node->cond)) {
            return [$truthiness($node->cond, $node->if === null ? "'?:'" : 'a ternary condition')];
        }

        if ($node instanceof Node\Expr\Match_ && $node->cond instanceof Node\Expr\ConstFetch
            && strtolower($node->cond->name->toString()) === 'true') {
            foreach ($node->arms as $arm) {
                foreach ($arm->conds ?? [] as $cond) {
                    if ($is_money($cond)) {
                        $out[] = $truthiness($cond, 'a match (true) arm');
                    }
                }
            }

            return $out;
        }

        if (isset(self::CASTS[$class]) && $is_money($node->expr)) {
            $cast = self::CASTS[$class];

            return [$cast === '(bool)'
                ? $truthiness($node->expr, "'(bool)'")
                : [$line, $node->expr, "'{$cast}' cannot convert it to the amount.", '->to_decimal() for the exact string, or ->format() for display']];
        }

        if ($node instanceof Node\Expr\Empty_ && $is_money($node->expr)) {
            return [$truthiness($node->expr, "'empty()'")];
        }

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
            && in_array(strtolower($node->name->getLast()), self::SCALAR_FUNCTIONS, true)) {
            $function = strtolower($node->name->getLast());
            foreach ($node->getArgs() as $arg) {
                if ($is_money($arg->value)) {
                    $out[] = [$line, $arg->value, "{$function}() reads it as a number or a string, not as an amount.", self::function_instead($function)];
                }
            }

            return $out;
        }

        if (isset(self::ARITHMETIC[$class])) {
            [$op, $use] = self::ARITHMETIC[$class];
            $operands = $node instanceof Node\Expr\UnaryMinus || $node instanceof Node\Expr\UnaryPlus
                ? [$node->expr]
                : ($node instanceof Node\Expr\AssignOp ? [$node->var, $node->expr] : [$node->left, $node->right]);
            foreach ($operands as $side) {
                if ($is_money($side)) {
                    $out[] = [$line, $side, "'{$op}' is not arithmetic on an amount - it throws.", $use];
                }
            }

            return $out;
        }

        if ($node instanceof Node\Expr\BinaryOp\Concat || $node instanceof Node\Expr\AssignOp\Concat) {
            $operands = $node instanceof Node\Expr\AssignOp ? [$node->expr] : [$node->left, $node->right];
            foreach ($operands as $side) {
                if ($is_money($side)) {
                    $out[] = [$line, $side, "'.' converts it to a string, which throws.", '->format() for display or ->to_decimal() for the exact string'];
                }
            }

            return $out;
        }

        if ($node instanceof Node\Scalar\InterpolatedString) {
            foreach ($node->parts as $part) {
                if ($part instanceof Node\Expr && $is_money($part)) {
                    $out[] = [$line, $part, 'interpolating it into a string converts it to a string, which throws.', '->format() for display or ->to_decimal() for the exact string'];
                }
            }

            return $out;
        }

        return $out;
    }

    /**
     * Whether a model class (named by its simple name) declares $column money. False for
     * anything that is not a model.
     */
    public function is_money_column_of(string $class_name, string $column): bool
    {
        if ($class_name === 'Rsx_Model_Abstract' || $class_name === 'Rsx_Money'
            || !Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return false;
        }

        $fqcn = Manifest::php_class_metadata($class_name)['fqcn'] ?? null;

        if (!$fqcn || !class_exists($fqcn)) {
            return false;
        }

        return $fqcn::is_money_column($column);
    }

    private static function is_null_constant(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null';
    }

    /** What to write instead of a scalar function applied to an amount. */
    private static function function_instead(string $function): string
    {
        return match ($function) {
            'abs' => '->abs()',
            'round' => '->round_to($step, $rounding)',
            'max' => 'Rsx_Money::max($list)',
            'min' => 'Rsx_Money::min($list)',
            'array_sum' => 'Rsx_Money::sum($list)',
            'number_format' => '->format()',
            'boolval' => '=== null for "no value" or ->is_zero() for zero',
            default => '->to_decimal() for the exact string, or ->format() for display',
        };
    }
}
