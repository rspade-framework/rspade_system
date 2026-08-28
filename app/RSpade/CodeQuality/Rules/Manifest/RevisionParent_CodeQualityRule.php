<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * REVISION-01 - the #[Revision_Parent] declaration must be coherent.
 *
 * #[Revision_Parent] sits on a child model's belongsTo method and says "a revision on me
 * belongs to that record's history". The root pair is written into every `_revisions` row
 * AT WRITE TIME, so an incoherent declaration is not a bug that shows up as an error - it
 * is a bug that shows up as a HISTORY SCREEN THAT IS QUIETLY MISSING ROWS, months later,
 * with nothing to trace it back to. Hence a manifest-build FATAL rather than advice.
 *
 * Three checks, all about the same invariant - the attribute must describe a real, opted-in
 * parent/child pair:
 *
 *   1. The declaring model must itself declare `public static $revisions = true`. Without
 *      it nothing on this model is ever recorded, so the attribute is inert and its author
 *      believes something that is not happening.
 *   2. The annotated method must return a belongsTo. A revision has exactly ONE root, and
 *      only a belongsTo names exactly one parent row; a hasMany or a morphTo cannot answer
 *      the question the attribute asks.
 *   3. The parent it points at must declare `$revisions = true` too. Filing a child's
 *      revisions under a record whose own writes are not recorded produces a half-history:
 *      the contacts show up, the client's own edits never do.
 *
 * NOT detected (deliberately): a belongsTo whose related class cannot be resolved
 * statically (a variable class name). This rule is fatal, so an unprovable case is skipped
 * rather than guessed at.
 *
 * Suppressed by @REVISION-01-EXCEPTION on the offending method (docblock, declaration line,
 * or the line above) or anywhere in the file.
 */
class RevisionParent_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'REVISION-01';

    /** @var mixed Shared php-parser instance. */
    protected static $parser = null;

    /**
     * Parsed-AST cache keyed by absolute file path (value: Node[]|false). Per-INSTANCE, so
     * a fixture class name from one test can never answer for a different class in the next.
     *
     * @var array
     */
    private $ast_cache = [];

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Revision Parent Declaration';
    }

    public function get_description(): string
    {
        return 'A #[Revision_Parent] must sit on a belongsTo, on a model that records revisions, '
            . 'and point at a parent that records them too';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Runs during the manifest scan: every failure mode here is silent at runtime - the
     * root pair is simply written wrong, and only a history screen months later shows it.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * Cross-file rule: the parent's opt-in lives in another file.
     */
    public function is_incremental(): bool
    {
        return false;
    }

    /**
     * Runs once per build. Scopes to the files that changed in this scan (falling back to
     * the whole manifest on a full rebuild).
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        $files = Manifest::get_all();
        if (empty($files)) {
            return;
        }

        $changed = Manifest::get_changed_files();
        $changed_lookup = null;
        if (!empty($changed)) {
            $changed_lookup = [];
            foreach ($changed as $rel) {
                $changed_lookup[str_replace('\\', '/', $rel)] = true;
            }
        }

        foreach ($files as $rel_path => $file_metadata) {
            if (($file_metadata['extension'] ?? '') !== 'php') {
                continue;
            }
            if (empty($file_metadata['class'])) {
                continue;
            }

            $normalized_rel = str_replace('\\', '/', $rel_path);

            // Meta-code ABOUT the attribute (this rule, its fixtures) is not an instance of it.
            if (str_contains($normalized_rel, '/CodeQuality/')) {
                continue;
            }

            if ($changed_lookup !== null && !isset($changed_lookup[$normalized_rel])) {
                continue;
            }

            $this->evaluate_file(base_path($rel_path), $file_metadata['class']);
        }
    }

    /**
     * Validate every #[Revision_Parent] inside one class file.
     *
     * This is the testable seam: production passes manifest-resolved paths, tests pass
     * synthetic fixture files, so attribute detection, the belongsTo check, the opt-in
     * lookups and the exception marker are all exercised over real AST.
     */
    public function evaluate_file(string $abs_file, string $class_name): void
    {
        $class_node = $this->find_class_node($abs_file, $class_name);
        if ($class_node === null) {
            return;
        }

        $contents = @file_get_contents($abs_file);
        if ($contents === false) {
            return;
        }

        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        // Whole-file exception. CodeQualityChecker applies this generically for rsx:check,
        // but the manifest-time driver does not - so honor it here too.
        if (str_contains($contents, $marker)) {
            return;
        }

        $lines = explode("\n", $contents);
        $declares_revisions = $this->declares_revisions_in_node($class_node);

        foreach ($class_node->getMethods() as $method) {
            if (!$this->method_has_revision_parent($method)) {
                continue;
            }

            $finding = $this->classify_method($method, $class_name, $class_node, $declares_revisions);
            if ($finding === null) {
                continue;
            }

            if ($this->method_has_exception($method, $lines, $marker)) {
                continue;
            }

            $line = $method->getStartLine();
            $snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

            $this->add_violation(
                $abs_file,
                $line,
                $finding['message'],
                $snippet,
                $finding['suggestion'],
                'critical'
            );
        }
    }

    /**
     * Classify one annotated method. Returns null when the declaration is coherent or
     * cannot be judged statically.
     *
     * @return array{message:string,suggestion:string}|null
     */
    private function classify_method(
        Node\Stmt\ClassMethod $method,
        string $class_name,
        Node\Stmt\ClassLike $class_node,
        bool $declares_revisions
    ): ?array {
        $method_name = $method->name->toString();

        // --- CHECK 1: the child must record revisions at all ----------------------
        if (!$declares_revisions) {
            return [
                'message' => sprintf(
                    "%s::%s() carries #[Revision_Parent], but %s does not declare "
                        . "`public static \$revisions = true`. Nothing on this model is recorded, so the "
                        . "attribute does nothing at all - and the failure is silent: no error, no row, "
                        . "and a history screen that is simply missing this model's writes.",
                    $class_name,
                    $method_name,
                    $class_name
                ),
                'suggestion' => "Declare the opt-in on {$class_name}:\n"
                    . "    public static \$revisions = true;\n"
                    . "or remove the #[Revision_Parent] if this model is not meant to be recorded.\n"
                    . "See: php artisan rsx:man revisions",
            ];
        }

        // --- CHECK 2: it must be a belongsTo --------------------------------------
        $belongs_to = $this->find_belongs_to($method);

        if ($belongs_to === null) {
            return [
                'message' => sprintf(
                    "%s::%s() carries #[Revision_Parent] but does not return a belongsTo. A revision "
                        . "has exactly ONE root, and only a belongsTo names exactly one parent row.",
                    $class_name,
                    $method_name
                ),
                'suggestion' => "Move the attribute onto the belongsTo relationship that names this "
                    . "record's parent, or drop it - a hasMany/hasOne points the wrong way and a "
                    . "morphTo has no single answer.\n"
                    . "See: php artisan rsx:man revisions",
            ];
        }

        // --- CHECK 3: the parent must record revisions too -------------------------
        $parent_class = $this->class_name_arg($belongs_to, 0);

        if ($parent_class === null) {
            return null; // Not statically resolvable - never guess on a fatal rule.
        }

        $parent_records = $this->class_declares_revisions($parent_class);

        if ($parent_records === null) {
            return null; // Parent not locatable in the manifest - cannot judge.
        }

        if ($parent_records) {
            return null;
        }

        return [
            'message' => sprintf(
                "%s::%s() files its revisions under %s, but %s does not declare "
                    . "`public static \$revisions = true`. That produces a HALF HISTORY: this model's "
                    . "writes are recorded against the parent, and the parent's own writes never are.",
                $class_name,
                $method_name,
                $parent_class,
                $parent_class
            ),
            'suggestion' => "Declare the opt-in on {$parent_class}:\n"
                . "    public static \$revisions = true;\n"
                . "A parent that should not be recorded is not a revision parent - remove the "
                . "attribute instead, and this model's revisions will be filed under itself.\n"
                . "See: php artisan rsx:man revisions",
        ];
    }

    /**
     * Whether a method carries #[Revision_Parent] (bare or namespaced).
     */
    private function method_has_revision_parent(Node\Stmt\ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $parts = explode('\\', $attribute->name->toString());

                if (end($parts) === 'Revision_Parent') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The `$this->belongsTo(...)` call inside a method, or null when there is none.
     */
    private function find_belongs_to(Node\Stmt\ClassMethod $method): ?Node\Expr\MethodCall
    {
        if ($method->stmts === null) {
            return null;
        }

        $node_finder = new NodeFinder();

        foreach ($node_finder->findInstanceOf($method->stmts, Node\Expr\MethodCall::class) as $call) {
            if (!$call->name instanceof Node\Identifier) {
                continue;
            }

            if ($call->name->toString() !== 'belongsTo') {
                continue;
            }

            if (!($call->var instanceof Node\Expr\Variable) || $call->var->name !== 'this') {
                continue;
            }

            return $call;
        }

        return null;
    }

    /**
     * Whether a class node declares `$revisions = true`.
     */
    private function declares_revisions_in_node(Node\Stmt\ClassLike $class_node): bool
    {
        foreach ($class_node->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() !== 'revisions') {
                    continue;
                }

                return $prop->default instanceof Node\Expr\ConstFetch
                    && strcasecmp($prop->default->name->toString(), 'true') === 0;
            }
        }

        return false;
    }

    /**
     * Whether a SIMPLE class name declares `$revisions = true`, climbing its manifest-visible
     * lineage until a class declares the property at all. Null when the class cannot be
     * located or parsed - the rule then declines to judge.
     */
    private function class_declares_revisions(string $class_name): ?bool
    {
        $chain = [$class_name];

        try {
            foreach (Manifest::php_get_lineage($class_name) as $ancestor) {
                $chain[] = $ancestor;
            }
        } catch (\Throwable $e) {
            // No lineage available: judge the class alone.
        }

        $located = false;

        foreach ($chain as $name) {
            try {
                $file = base_path(Manifest::php_find_class($name));
            } catch (\Throwable $e) {
                continue;
            }

            $node = $this->find_class_node($file, $name);
            if ($node === null) {
                continue;
            }

            $located = true;

            foreach ($node->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() !== 'revisions') {
                        continue;
                    }

                    return $prop->default instanceof Node\Expr\ConstFetch
                        && strcasecmp($prop->default->name->toString(), 'true') === 0;
                }
            }
        }

        // Located, and nothing in the lineage declares the property: it inherits the base's
        // `false`, which is a definite answer.
        return $located ? false : null;
    }

    /**
     * The SIMPLE class name named by argument $index, accepting both Foo_Model::class and
     * the 'Foo_Model' string form. Null when it is neither.
     */
    private function class_name_arg(Node\Expr\MethodCall $call, int $index): ?string
    {
        $arg = $call->args[$index] ?? null;
        if (!$arg instanceof Node\Arg || $arg->name !== null) {
            return null;
        }

        $value = $arg->value;

        if ($value instanceof Node\Expr\ClassConstFetch
            && $value->class instanceof Node\Name
            && $value->name instanceof Node\Identifier
            && strcasecmp($value->name->toString(), 'class') === 0
        ) {
            $parts = explode('\\', $value->class->toString());

            return end($parts);
        }

        if ($value instanceof Node\Scalar\String_) {
            $parts = explode('\\', $value->value);

            return end($parts);
        }

        return null;
    }

    /**
     * Per-method exception detection for @REVISION-01-EXCEPTION: in the method's docblock,
     * on its declaration line, or on the line immediately above it.
     */
    private function method_has_exception(Node\Stmt\ClassMethod $method, array $lines, string $marker): bool
    {
        foreach ($method->getComments() as $comment) {
            if (str_contains($comment->getText(), $marker)) {
                return true;
            }
        }

        $index = $method->getStartLine() - 1;

        if ($index >= 0 && isset($lines[$index]) && str_contains($lines[$index], $marker)) {
            return true;
        }
        if ($index - 1 >= 0 && isset($lines[$index - 1]) && str_contains($lines[$index - 1], $marker)) {
            return true;
        }

        return false;
    }

    /**
     * Locate the ClassLike node named $class_name inside $abs_file, or null when the file is
     * missing/unparseable or the class is absent.
     */
    private function find_class_node(string $abs_file, string $class_name): ?Node\Stmt\ClassLike
    {
        $ast = $this->parse_file($abs_file);
        if ($ast === null) {
            return null;
        }

        $node_finder = new NodeFinder();

        foreach ($node_finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class_like) {
            if ($class_like->name !== null && strcasecmp($class_like->name->toString(), $class_name) === 0) {
                return $class_like;
            }
        }

        return null;
    }

    /**
     * Parse a file into an AST (Node[]), cached per absolute path. Null on a missing file or
     * a parse error (a syntax error is reported by the linter, not by this rule).
     */
    private function parse_file(string $abs_file): ?array
    {
        if (array_key_exists($abs_file, $this->ast_cache)) {
            $cached = $this->ast_cache[$abs_file];

            return $cached === false ? null : $cached;
        }

        if (!is_file($abs_file)) {
            $this->ast_cache[$abs_file] = false;

            return null;
        }

        $code = @file_get_contents($abs_file);
        if ($code === false) {
            $this->ast_cache[$abs_file] = false;

            return null;
        }

        try {
            $ast = $this->get_parser()->parse($code);
        } catch (Error $error) {
            $this->ast_cache[$abs_file] = false;

            return null;
        }

        if (!$ast) {
            $this->ast_cache[$abs_file] = false;

            return null;
        }

        $this->ast_cache[$abs_file] = $ast;

        return $ast;
    }

    /**
     * Get or create the shared php-parser instance.
     */
    private function get_parser()
    {
        if (self::$parser === null) {
            self::$parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return self::$parser;
    }
}
