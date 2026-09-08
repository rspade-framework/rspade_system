<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use PhpParser\Node;
use PhpParser\NodeFinder;
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
     * CROSS-FILE: this rule judges the tree, not one file. The driver runs it once per
     * pass, gated on the fingerprint of what depends_on() declares.
     */
    public function kind(): string
    {
        return self::KIND_CROSS_FILE;
    }

    /**
     * Every indexed PHP file, the lineage indexes, and the model index the declaration is
     * checked against.
     *
     * @return array<int,string>
     */
    public function depends_on(): array
    {
        return [
            'files:*.php',
            'php_classes',
            'php_subclass_index',
            'models',
        ];
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
        // THE CHEAP QUESTION FIRST. #[Revision_Parent] is rare - a handful of methods in a
        // whole tree - and the member summary answers "does this class carry one anywhere"
        // without the rule ever holding an AST. Only a class that does carry one pays for
        // the walk below, which needs real nodes to find the belongsTo call.
        $members = $this->source()->declared_members($abs_file, $class_name);
        $annotated = [];

        foreach ($members['methods'] as $key => $method) {
            if (isset($method['attributes']['revision_parent'])) {
                $annotated[$key] = true;
            }
        }

        if (empty($annotated)) {
            return;
        }

        $class_node = $this->find_class_node($abs_file, $class_name);
        if ($class_node === null) {
            return;
        }

        $contents = $this->source()->content($abs_file);
        if ($contents === '') {
            return;
        }

        $marker = '@' . self::RULE_ID . '-EXCEPTION';

        // Whole-file exception. CodeQualityChecker applies this generically for rsx:check,
        // but the manifest-time driver does not - so honor it here too.
        if (str_contains($contents, $marker)) {
            return;
        }

        $lines = explode("\n", $contents);
        $declares_revisions = $this->class_summary_declares_revisions($members);

        foreach ($class_node->getMethods() as $method) {
            if (!isset($annotated[strtolower($method->name->toString())])) {
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
     * Whether a SIMPLE class name declares `$revisions = true`, climbing its manifest-visible
     * lineage until a class declares the property at all. Null when the class cannot be
     * located or parsed - the rule then declines to judge.
     *
     * Answered from Source_Cache::declared_members(): the property's LITERAL default is a
     * field of the summary, so an ancestor is summarized once for the whole pass instead of
     * re-parsed by every rule that asks about it.
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

            $members = $this->source()->declared_members($file, $name);

            if (empty($members['methods']) && empty($members['properties'])) {
                continue;
            }

            $located = true;

            if (isset($members['properties']['revisions'])) {
                return $members['properties']['revisions']['default'] === true;
            }
        }

        return $located ? false : null;
    }

    /**
     * Whether a class SUMMARY declares `$revisions = true`.
     */
    private function class_summary_declares_revisions(array $members): bool
    {
        return isset($members['properties']['revisions'])
            && $members['properties']['revisions']['default'] === true;
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
        // THE DRIVER OWNS PARSING, and it owns this lookup too: the node is memoized
        // beside the file's AST and evicted with it. Four rules each ran a full
        // NodeFinder traversal per ASK, and PHP-PARENT-CHAIN-01 asks once per ancestor
        // per method.
        $node = $this->source()->class_node($abs_file, $class_name);

        return $node instanceof Node\Stmt\ClassLike ? $node : null;
    }

}
