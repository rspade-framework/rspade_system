<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use PhpParser\Node;
use PhpParser\NodeFinder;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * PHP-NAMED-OPTIONAL-01 - an optional parameter reached by a bare literal in a positional slot.
 *
 * The house function design (rsx:man coding_standards, OPTIONAL PARAMETERS): required,
 * obvious arguments are positional; an optional setting is a defaulted parameter the CALLER
 * NAMES. This rule finds the two call shapes that standard exists to end:
 *
 *     Rsx::Route('Foo_Action', null, ['tab' => 'x'])   // a placeholder to reach a later slot
 *     rmdir_recursive($dir, false)                     // a bare flag: false WHAT?
 *
 * A literal null / true / false passed POSITIONALLY into a parameter that has a default is
 * flagged. Spelled by name it reads, and a misspelt name fails loudly ("Unknown named
 * parameter"); positionally, a parameter inserted ahead of it or a miscounted slot shifts the
 * value into the wrong setting with no error at all - the commonest way an agent or a new
 * developer breaks a call.
 *
 * WHAT IS JUDGED. Calls the rule can resolve to a declaration it can read:
 *   - Class::method(...) where the class is in the manifest (simple name, an import, or a
 *     qualified name agreeing with the manifest's FQCN), and self:: / static:: / parent::
 *     inside a class the manifest indexes;
 *   - a global helper function(...) declared by framework or application code.
 * The callee is read by reflection - the declaring class or function file must not be under
 * vendor/, so an inherited Eloquent or Laravel method is never judged. A call through a
 * variable, an instance call, a constructor, an unresolvable name and a variadic or required
 * parameter are skipped. Judging stops at the first named or unpacked (...$x) argument.
 *
 * WHY rsx:check, NOT MANIFEST-TIME. The defect is legibility and fragility, not a broken
 * application: the call works today. And the verdict is a relation between a call site and
 * a declaration in ANOTHER file, read by reflection, which needs the classes loadable - the
 * manifest build is exactly when they may not be. Severity is HIGH: it is the standard every
 * new call is written to, and the sweep left the tree clean.
 *
 * STALENESS. A per-file verdict is remembered against the call site's hash; the rule's
 * fingerprint carries a digest of every manifest-indexed public static signature and every
 * framework/application function signature, so a callee whose parameter gains or loses a
 * default retires every remembered verdict.
 *
 * EXCEPTION. `// @PHP-NAMED-OPTIONAL-01-EXCEPTION <rationale>` on the call's first line or
 * the line above it excuses that call. A marker with no rationale excuses nothing.
 */
class NamedOptionalArgument_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'PHP-NAMED-OPTIONAL-01';

    /** [simple class name => [method lower => ?\ReflectionFunctionAbstract]] resolved callees, per pass. */
    private array $method_memo = [];

    /** [function lower => ?\ReflectionFunction] resolved helper functions, per pass. */
    private array $function_memo = [];

    /** [function lower => true] every user-defined (non-builtin) function, loaded once. */
    private ?array $user_functions = null;

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Optional parameters are passed by name';
    }

    public function get_description(): string
    {
        return 'Flags a literal null/true/false passed positionally into a defaulted parameter of a framework or application function or static method; pass it as a named argument';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function supports_console_commands(): bool
    {
        return true;
    }

    /**
     * The marker is honored per call, so one excused call leaves the rest of the file judged.
     */
    public function reads_own_exception_markers(): bool
    {
        return true;
    }

    /**
     * The callee signatures decide the verdict: a digest of whether each parameter of every
     * indexed public static method, and of every non-vendor function, has a default.
     */
    public function fingerprint_extra(): string
    {
        $parts = [];

        foreach (Manifest::get_all() as $file => $record) {
            foreach ($record['public_static_methods'] ?? [] as $name => $method) {
                $shape = '';
                foreach ($method['parameters'] ?? [] as $param) {
                    $shape .= !empty($param['optional']) ? 'o' : 'r';
                }
                $parts[] = ($record['class'] ?? $file) . '::' . $name . ':' . $shape;
            }
        }

        foreach ($this->__user_functions() as $lower => $unused) {
            $function = $this->__function($lower);
            if ($function !== null) {
                $parts[] = $lower . ':' . $this->__shape($function);
            }
        }

        sort($parts);

        return md5(implode("\n", $parts));
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $normalized = str_replace('\\', '/', $file_path);
        if (str_contains($normalized, '/vendor/') || str_contains($normalized, '/node_modules/')) {
            return;
        }

        $hash = (string) ($metadata['hash'] ?? '');
        $raw = $this->source()->content($file_path, $hash);

        // Cheap bail: no literal flag or null anywhere in the file, nothing to judge.
        if (!preg_match('/\b(?:null|true|false)\b/i', $raw)) {
            return;
        }

        $ast = $this->source()->ast($file_path, $hash);
        if ($ast === null) {
            return;
        }

        $lines = explode("\n", $raw);
        $finder = new NodeFinder();
        $namespace = '';
        $uses = [];

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Namespace_::class) as $ns) {
            $namespace = $ns->name ? $ns->name->toString() : '';
        }
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Use_::class) as $use) {
            if ($use->type !== Node\Stmt\Use_::TYPE_NORMAL && $use->type !== Node\Stmt\Use_::TYPE_UNKNOWN) {
                continue;
            }
            foreach ($use->uses as $item) {
                $alias = $item->alias ? $item->alias->name : $item->name->getLast();
                $uses[strtolower($alias)] = ltrim($item->name->toString(), '\\');
            }
        }

        // Calls inside a class: self/static/parent resolve against it.
        $seen = [];
        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
            $class_name = $class->name ? $class->name->name : null;
            $parent_name = ($class instanceof Node\Stmt\Class_ && $class->extends) ? $class->extends : null;

            foreach ($finder->findInstanceOf($class->stmts, Node\Expr\StaticCall::class) as $call) {
                $seen[spl_object_id($call)] = true;
                $this->__judge_static($call, $class instanceof Node\Stmt\Class_ ? $class_name : null, $parent_name, $namespace, $uses, $file_path, $lines);
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
            if (!isset($seen[spl_object_id($call)])) {
                $this->__judge_static($call, null, null, $namespace, $uses, $file_path, $lines);
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Expr\FuncCall::class) as $call) {
            if (!$call->name instanceof Node\Name) {
                continue;
            }
            if (!$this->__has_candidate_literal($call->args)) {
                continue;
            }
            $function = $this->__function(strtolower($call->name->getLast()));
            if ($function === null) {
                continue;
            }
            $this->__judge_call($call, $function, $call->name->toString() . '()', $file_path, $lines);
        }
    }

    // =====================================================================
    // Resolution
    // =====================================================================

    private function __judge_static(
        Node\Expr\StaticCall $call,
        ?string $class_name,
        ?Node\Name $parent_name,
        string $namespace,
        array $uses,
        string $file_path,
        array $lines
    ): void {
        if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier) {
            return;
        }
        if (!$this->__has_candidate_literal($call->args)) {
            return;
        }

        $written = $call->class->toString();
        $lower = strtolower($written);

        if ($lower === 'self' || $lower === 'static') {
            if ($class_name === null) {
                return;
            }
            $simple = $class_name;
            $fqcn_written = $namespace === '' ? $class_name : $namespace . '\\' . $class_name;
        } elseif ($lower === 'parent') {
            if ($parent_name === null) {
                return;
            }
            [$simple, $fqcn_written] = $this->__resolve_name($parent_name, $namespace, $uses);
        } else {
            [$simple, $fqcn_written] = $this->__resolve_name($call->class, $namespace, $uses);
        }

        $method = $this->__method($simple, $fqcn_written, $call->name->name);
        if ($method === null) {
            return;
        }

        $this->__judge_call($call, $method, $written . '::' . $call->name->name . '()', $file_path, $lines);
    }

    /**
     * [simple name, the FQCN the code names or null when the code leaves it to RSX's simple-name
     * resolution].
     */
    private function __resolve_name(Node\Name $name, string $namespace, array $uses): array
    {
        $simple = $name->getLast();

        if ($name->isFullyQualified()) {
            return [$simple, ltrim($name->toString(), '\\')];
        }

        $first = strtolower($name->getFirst());
        if (isset($uses[$first])) {
            $rest = $name->slice(1);

            return [$simple, $uses[$first] . ($rest ? '\\' . $rest->toString() : '')];
        }

        if ($name->isQualified()) {
            return [$simple, ($namespace === '' ? '' : $namespace . '\\') . $name->toString()];
        }

        return [$simple, null];
    }

    private function __method(string $simple, ?string $fqcn_written, string $method_name): ?\ReflectionMethod
    {
        $record = Manifest::php_class_metadata($simple);
        if ($record === null || empty($record['fqcn'])) {
            return null;
        }

        // A qualified or imported name that is not the manifest's class names some other class.
        if ($fqcn_written !== null && strcasecmp($fqcn_written, $record['fqcn']) !== 0
            && strcasecmp(ltrim($fqcn_written, '\\'), $simple) !== 0) {
            return null;
        }

        $key = strtolower($method_name);
        if (array_key_exists($key, $this->method_memo[$simple] ?? [])) {
            return $this->method_memo[$simple][$key];
        }

        $resolved = null;
        if (class_exists($record['fqcn']) || trait_exists($record['fqcn']) || interface_exists($record['fqcn'])) {
            $class = new \ReflectionClass($record['fqcn']);
            if ($class->hasMethod($method_name)) {
                $method = $class->getMethod($method_name);
                if (!$this->__is_vendor((string) $method->getFileName())) {
                    $resolved = $method;
                }
            }
        }

        $this->method_memo[$simple][$key] = $resolved;

        return $resolved;
    }

    private function __function(string $lower): ?\ReflectionFunction
    {
        if (array_key_exists($lower, $this->function_memo)) {
            return $this->function_memo[$lower];
        }

        $resolved = null;
        if (isset($this->__user_functions()[$lower])) {
            $function = new \ReflectionFunction($lower);
            if (!$this->__is_vendor((string) $function->getFileName())) {
                $resolved = $function;
            }
        }

        return $this->function_memo[$lower] = $resolved;
    }

    private function __user_functions(): array
    {
        if ($this->user_functions === null) {
            $this->user_functions = array_fill_keys(get_defined_functions()['user'], true);
        }

        return $this->user_functions;
    }

    private function __is_vendor(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        return $file === '' || str_contains($file, '/vendor/') || str_contains($file, '/node_modules/');
    }

    private function __shape(\ReflectionFunctionAbstract $function): string
    {
        $shape = '';
        foreach ($function->getParameters() as $param) {
            $shape .= $param->isVariadic() ? 'v' : ($param->isDefaultValueAvailable() ? 'o' : 'r');
        }

        return $shape;
    }

    // =====================================================================
    // Judgement
    // =====================================================================

    private function __has_candidate_literal(array $args): bool
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack || $arg->name !== null) {
                return false;
            }
            if (self::literal_of($arg->value) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * 'null' / 'true' / 'false' when the expression is that bare literal, else null.
     */
    public static function literal_of(Node\Expr $expr): ?string
    {
        if (!$expr instanceof Node\Expr\ConstFetch) {
            return null;
        }

        $name = strtolower($expr->name->toString());

        return in_array($name, ['null', 'true', 'false'], true) ? $name : null;
    }

    private function __judge_call(Node\Expr $call, \ReflectionFunctionAbstract $callee, string $label, string $file_path, array $lines): void
    {
        $params = $callee->getParameters();

        foreach ($call->args as $index => $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack || $arg->name !== null) {
                return;
            }

            $param = $params[$index] ?? null;
            if ($param === null || $param->isVariadic()) {
                return;
            }

            $literal = self::literal_of($arg->value);
            if ($literal === null || !$param->isDefaultValueAvailable()) {
                continue;
            }

            $line = $call->getStartLine();
            if ($this->__excused($lines, $line)) {
                return;
            }

            $name = $param->getName();
            $this->add_violation(
                $file_path,
                $arg->getStartLine(),
                "{$label} receives a bare positional {$literal} in its optional parameter \${$name}.",
                trim($lines[$arg->getStartLine() - 1] ?? ''),
                "Pass an optional setting BY NAME: {$name}: {$literal}. Every positional argument after it\n"
                . "becomes named too, and a placeholder that only repeats a default is dropped:\n"
                . "    f(\$a, null, true)   ->   f(\$a, third: true)\n"
                . "A named argument reads at the call site and a misspelt name fails loudly; a\n"
                . "positional literal silently lands in the wrong setting when the signature moves.\n"
                . "rsx:man coding_standards, OPTIONAL PARAMETERS.",
                'high'
            );

            return; // one finding per call
        }
    }

    /**
     * A marker carrying a rationale on the call's first line or the line above it.
     */
    private function __excused(array $lines, int $line): bool
    {
        foreach ([$line - 1, $line - 2] as $index) {
            $text = $lines[$index] ?? '';
            if (preg_match('/@' . preg_quote(self::RULE_ID, '/') . '-EXCEPTION\b[\s:-]*(.*)$/', $text, $match)) {
                $rationale = trim(preg_replace('/\*\/\s*$/', '', $match[1]));
                if ($rationale !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
