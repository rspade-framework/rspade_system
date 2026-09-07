<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\CodeQuality\Support\Validation_Ledger;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Identifier;

/**
 * NAME-RESERVED-02 - application code may not REFERENCE a framework-reserved name.
 *
 * NAME-RESERVED-01 governs DECLARATIONS: who may spell a name with the single leading
 * underscore. This rule governs the other direction, which is the one an application
 * actually trips over - USING one of those names.
 *
 *     class My_Page extends _Sys_Layout          // no
 *     _Sys_Spa_Controller::index($request)       // no
 *     Ajax::_is_internal_call()                  // no
 *     <_Sys_Section>...</_Sys_Section>           // no
 *     @rsx_extends('_Apidocs_App')               // no
 *
 * Everything under `app/RSpade/` is framework property: it is reset to the upstream tip by
 * every `rsx:framework:pull`, and every one of these names may be renamed, restructured or
 * deleted in any release. A reference to one is a landmine that goes off on somebody else's
 * schedule, and it does not go off loudly - a renamed component simply stops rendering.
 *
 * WHAT COUNTS AS A FRAMEWORK-RESERVED NAME. Two conditions, both required:
 *
 *   1. `Rsx_Identifier::is_framework_reserved($name)` - ONE leading underscore, then a
 *      capital. And
 *   2. the MANIFEST knows that name as declared by a file under `app/RSpade/`.
 *
 * The second condition is what keeps this rule from being a blanket underscore ban. The
 * template app alone makes ~938 `static::__helper()` calls, and PHP magic methods,
 * `__DIR__` and vendor APIs all live in the same character. None of them name anything the
 * framework declares, so none of them are this rule's business. The same test decides a
 * `_`-prefixed STATIC METHOD: `Framework_Class::_method()` is a violation when
 * `Framework_Class` is declared under `app/RSpade/` and `_method` is one of its indexed
 * static methods - and nothing else is.
 *
 * `static::`, `self::` and `parent::` receivers are NEVER checked. Those are a class's own
 * helpers, which is exactly the legitimate 938.
 *
 * THE SANCTIONED CARRIERS ARE LEGAL BY CONSTRUCTION. The one documented reference to the
 * panel is a STRING:
 *
 *     Rsx::Route('_Sys_Dashboard_Action')
 *     Permission::can_access('_Sys_Dashboard_Action')
 *     Rsx.Route('_Sys_Dashboard_Action')
 *     Permission.can_access('_Sys_Dashboard_Action')
 *
 * This rule never reads a string literal. PHP is inspected as an AST, where a string is a
 * scalar and not a name; JavaScript is inspected after comments AND string CONTENTS are
 * blanked. So the carriers need no allowlist - they are simply not code that names a class,
 * and an allowlist would have been a list to keep in step.
 *
 * THE PASS LEDGER. This rule parses and indexes, so a file that passed is remembered by its
 * manifest file hash in the shared `Validation_Ledger` rather than re-judged. The ledger key
 * is `NAME-RESERVED-02@<short hash of the reserved-name index>`, NOT the bare rule id: the
 * verdict depends on the framework's own names as much as on the file's bytes, and a stale
 * index must never be allowed to vouch for a file. Change the framework's declared names and
 * every old verdict is keyed to an id nothing asks about again.
 *
 * See: rsx:man sys_panel, rsx:man coding_standards, rsx:man code_quality.
 *
 * THE CARRIER THIS FILE QUOTES RESOLVES. `Rsx::Route('_Sys_Dashboard_Action')` names the
 * panel's index ACTION, which is how a SPA route is registered, so ROUTE-EXISTS-01 checks it
 * and finds it. (It used to quote `_Sys_Spa_Controller::index`, which Rsx::Route() refuses
 * like every other SPA bootstrap controller, and this file carried a ROUTE-EXISTS-01
 * exception to say so. The exception went with the reason for it.)
 */
class NameReservedReference_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'NAME-RESERVED-02';

    /** The framework tree. Everything declared under it is framework property. */
    private const FRAMEWORK_TREE = 'app/RSpade/';

    /** The reserved-name index, built once per process. */
    private static ?array $index = null;

    /** The nikic parser, built once per process. */
    private static $parser = null;

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Reserved Framework Name Reference';
    }

    public function get_description(): string
    {
        return 'Application code may not reference a framework-reserved (`_`-prefixed) class, '
            . 'component, template id or static method that the framework declares';
    }

    public function get_file_patterns(): array
    {
        return ['*.php', '*.js', '*.jqhtml', '*.blade.php'];
    }

    /**
     * Blocking: the referenced thing is framework property that may vanish in any release,
     * and its disappearance is silent (a component that stops rendering, a route that stops
     * resolving) rather than loud.
     */
    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Per-file: a reference is decided by the file's own text against the framework index.
     */
    public function is_incremental(): bool
    {
        return true;
    }

    /**
     * Manifest-time, for the same reason as NAME-RESERVED-01: the reference must not survive
     * to the point where a framework update removes what it names.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $normalized = str_replace('\\', '/', $file_path);

        // Framework code referencing its own names is the point of having them. This also
        // covers the reference_app symlink, whose path contains app/RSpade/.
        if (str_contains($normalized, self::FRAMEWORK_TREE)) {
            return;
        }

        // The manifest spells application paths RELATIVE (`rsx/app/...`); fixtures and the
        // IDE spell them absolute (`/var/www/html/rsx/...`).
        if (!str_starts_with($normalized, 'rsx/') && !str_contains($normalized, '/rsx/')) {
            return;
        }

        if (str_contains($normalized, '/vendor/')
            || str_contains($normalized, '/node_modules/')
            || str_contains($normalized, '/.cdn-cache/')) {
            return;
        }

        $index = static::__index();

        $file_hash = $metadata['hash'] ?? null;

        if (!is_string($file_hash) || $file_hash === '') {
            $file_hash = is_file($file_path) ? (sha1_file($file_path) ?: null) : null;
        }

        $ledger_id = self::RULE_ID . '@' . $index['hash'];

        if ($file_hash !== null && Validation_Ledger::has_passed($ledger_id, $file_hash)) {
            return;
        }

        $before = count($this->collector->get_by_rule(self::RULE_ID));

        if (str_ends_with($normalized, '.blade.php')) {
            $this->__check_blade($file_path, $contents, $index);
        } elseif (str_ends_with($normalized, '.jqhtml')) {
            $this->__check_jqhtml($file_path, $contents, $metadata, $index);
        } elseif (preg_match('/\.(js|jsx|ts|tsx)$/', $normalized)) {
            $this->__check_javascript($file_path, $contents, $index);
        } else {
            $this->__check_php($file_path, $contents, $index);
        }

        $after = count($this->collector->get_by_rule(self::RULE_ID));

        if ($file_hash !== null && $after === $before) {
            Validation_Ledger::record_pass($ledger_id, $file_hash);
        }
    }

    // =====================================================================
    // The reserved-name index
    // =====================================================================

    /**
     * Every name the framework declares, by kind, built ONCE per process.
     *
     * PHP and JS carry EVERY framework class (not only the reserved ones), because the
     * method direction needs to ask "is `Ajax` a framework class, and is `_is_internal_call`
     * one of its statics" about a class whose own name is perfectly ordinary.
     *
     * @return array{php: array<string,array{path:string,reserved:bool,methods:array<string,int>}>,
     *               js: array<string,array{path:string,reserved:bool,methods:array<string,int>}>,
     *               jqhtml: array<string,string>, blade: array<string,string>, hash: string}
     */
    private static function __index(): array
    {
        if (static::$index !== null) {
            return static::$index;
        }

        $files = Manifest::$_has_init
            ? (Manifest::$data['data']['files'] ?? [])
            : Manifest::get_all();

        $index = ['php' => [], 'js' => [], 'jqhtml' => [], 'blade' => []];

        foreach ($files as $path => $metadata) {
            $normalized = str_replace('\\', '/', (string) $path);

            if (!str_contains($normalized, self::FRAMEWORK_TREE)) {
                continue;
            }

            $extension = $metadata['extension'] ?? '';
            $class = $metadata['class'] ?? '';
            $id = $metadata['id'] ?? '';

            if ($extension === 'php' && $class !== '') {
                $index['php'][$class] = static::__class_entry($normalized, $class, $metadata);
            } elseif (in_array($extension, ['js', 'jsx', 'ts', 'tsx'], true) && $class !== '') {
                $index['js'][$class] = static::__class_entry($normalized, $class, $metadata);
            } elseif ($extension === 'jqhtml' && $id !== '') {
                $index['jqhtml'][$id] = $normalized;
            } elseif ($extension === 'blade.php' && $id !== '') {
                $index['blade'][$id] = $normalized;
            }
        }

        // The hash must be STABLE across processes and change when any declared name or
        // indexed method changes - it is what retires stale ledger verdicts.
        $fingerprint = [];

        foreach (['php', 'js'] as $kind) {
            ksort($index[$kind]);

            foreach ($index[$kind] as $name => $entry) {
                $methods = array_keys($entry['methods']);
                sort($methods);
                $fingerprint[] = $kind . ':' . $name . ':' . implode(',', $methods);
            }
        }

        foreach (['jqhtml', 'blade'] as $kind) {
            ksort($index[$kind]);
            $fingerprint[] = $kind . ':' . implode(',', array_keys($index[$kind]));
        }

        $index['hash'] = substr(sha1(implode("\n", $fingerprint)), 0, 12);

        static::$index = $index;

        return static::$index;
    }

    /**
     * One class row of the index.
     */
    private static function __class_entry(string $path, string $class, array $metadata): array
    {
        $methods = [];

        foreach (['public_static_methods', 'methods'] as $key) {
            foreach ((array) ($metadata[$key] ?? []) as $method_key => $method) {
                $name = is_array($method) ? ($method['name'] ?? null) : (is_string($method) ? $method : null);
                $name = $name ?? (is_string($method_key) ? $method_key : null);

                if (is_string($name) && $name !== '') {
                    $methods[$name] = 1;
                }
            }
        }

        return [
            'path' => $path,
            'reserved' => Rsx_Identifier::is_framework_reserved($class),
            'methods' => $methods,
        ];
    }

    /**
     * Discard the process-local index. The seam a test uses to rebuild it.
     */
    public static function _reset_index(): void
    {
        static::$index = null;
    }

    // =====================================================================
    // PHP
    // =====================================================================

    /**
     * PHP is inspected as an AST, so a string literal that MENTIONS a reserved name is a
     * scalar and never a reference - which is precisely what makes
     * `Rsx::Route('_Sys_Dashboard_Action')` legal with no allowlist.
     *
     * RSpade code references framework classes by their BARE name (the manifest resolves
     * them; the fixer strips imports), so every name node is reduced to its simple name
     * with class_basename() and matched against the index that way. An FQCN spelling
     * reduces to the same simple name and is caught identically.
     */
    private function __check_php(string $file_path, string $contents, array $index): void
    {
        try {
            $ast = static::__parser()->parse($contents);
        } catch (Error $error) {
            // A file that does not parse is the syntax lint's problem, not this rule's.
            return;
        }

        if (!$ast) {
            return;
        }

        $finder = new NodeFinder();

        // ---- Names used AS a class: new / extends / implements / instanceof / Foo:: /
        //      use / type hints / catch.
        $named = [];

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Use_::class) as $use) {
            foreach ($use->uses as $item) {
                $named[] = [$item->name->getLast(), $item->getLine()];
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Stmt\GroupUse::class) as $group) {
            foreach ($group->uses as $item) {
                $named[] = [$item->name->getLast(), $item->getLine()];
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Expr\New_::class) as $new) {
            if ($new->class instanceof Node\Name) {
                $named[] = [$new->class->getLast(), $new->getLine()];
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Expr\Instanceof_::class) as $node) {
            if ($node->class instanceof Node\Name) {
                $named[] = [$node->class->getLast(), $node->getLine()];
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            if ($class->extends instanceof Node\Name) {
                $named[] = [$class->extends->getLast(), $class->extends->getLine()];
            }

            foreach ($class->implements as $interface) {
                $named[] = [$interface->getLast(), $interface->getLine()];
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Interface_::class) as $interface) {
            foreach ($interface->extends as $parent) {
                $named[] = [$parent->getLast(), $parent->getLine()];
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Catch_::class) as $catch) {
            foreach ($catch->types as $type) {
                $named[] = [$type->getLast(), $type->getLine()];
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Param::class) as $param) {
            foreach (static::__type_names($param->type) as $name) {
                $named[] = [$name, $param->getLine()];
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\FunctionLike::class) as $function) {
            foreach (static::__type_names($function->getReturnType()) as $name) {
                $named[] = [$name, $function->getLine()];
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Property::class) as $property) {
            foreach (static::__type_names($property->type) as $name) {
                $named[] = [$name, $property->getLine()];
            }
        }

        // ---- Static receivers: the class half is a name reference, and the method half is
        //      the second direction of this rule.
        $static_nodes = array_merge(
            $finder->findInstanceOf($ast, Node\Expr\StaticCall::class),
            $finder->findInstanceOf($ast, Node\Expr\StaticPropertyFetch::class),
            $finder->findInstanceOf($ast, Node\Expr\ClassConstFetch::class)
        );

        $reported_lines = [];

        foreach ($static_nodes as $node) {
            if (!($node->class instanceof Node\Name)) {
                continue;
            }

            // static:: / self:: / parent:: are a class's own helpers. Never checked.
            if ($node->class->isSpecialClassName()) {
                continue;
            }

            $simple = $node->class->getLast();

            if ($this->__is_reserved_class($simple, $index['php'])) {
                $this->__report_class($file_path, $node->getLine(), $contents, $simple, 'PHP class', $index['php'][$simple]['path']);
                $reported_lines[$node->getLine() . ':' . $simple] = true;
                continue;
            }

            if (!($node instanceof Node\Expr\StaticCall) || !($node->name instanceof Node\Identifier)) {
                continue;
            }

            $method = $node->name->name;

            if (!str_starts_with($method, '_')) {
                continue;
            }

            if (!isset($index['php'][$simple]['methods'][$method])) {
                continue;
            }

            $this->__report_method(
                $file_path,
                $node->getLine(),
                $contents,
                $simple,
                $method,
                'PHP',
                $index['php'][$simple]['path']
            );
        }

        foreach ($named as [$name, $line]) {
            if (!$this->__is_reserved_class($name, $index['php'])) {
                continue;
            }

            if (isset($reported_lines[$line . ':' . $name])) {
                continue;
            }

            $reported_lines[$line . ':' . $name] = true;

            $this->__report_class($file_path, $line, $contents, $name, 'PHP class', $index['php'][$name]['path']);
        }
    }

    /**
     * Every class name inside a (possibly nullable, union or intersection) type declaration.
     *
     * @return string[]
     */
    private static function __type_names($type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof Node\Name) {
            return [$type->getLast()];
        }

        if ($type instanceof Node\NullableType) {
            return static::__type_names($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];

            foreach ($type->types as $member) {
                $names = array_merge($names, static::__type_names($member));
            }

            return $names;
        }

        return [];
    }

    private static function __parser()
    {
        if (static::$parser === null) {
            static::$parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return static::$parser;
    }

    // =====================================================================
    // JavaScript
    // =====================================================================

    /**
     * JavaScript has no AST here, so the guard against a string literal is to BLANK string
     * contents (and comments) before matching - which is what keeps
     * `Rsx.Route('_Sys_Dashboard_Action')` and `Permission.can_access('...')` legal, and
     * keeps a `_Sys_` name mentioned in prose or in a selector out of the rule's way.
     *
     * The blanking is done here rather than through `FileSanitizer::sanitize_javascript()`
     * because the manifest-time driver hands this rule RAW bytes and the node service is not
     * a dependency a manifest build should acquire for a lint.
     *
     * A reserved CLASS is matched as a whole word in any position: `new _X(`, `extends _X`,
     * `_X.method()`, `_X(` and an import all name the same forbidden thing, and enumerating
     * the syntaxes would only leave holes.
     */
    private function __check_javascript(string $file_path, string $contents, array $index): void
    {
        $code = static::__blank_js_strings_and_comments($contents);
        $lines = explode("\n", $code);

        foreach ($lines as $offset => $line) {
            $line_number = $offset + 1;

            if (trim($line) === '') {
                continue;
            }

            if (!str_contains($line, '_')) {
                continue;
            }

            // Reserved class names, whole-word.
            if (preg_match_all('/(?<![A-Za-z0-9_$])(_[A-Z][A-Za-z0-9_$]*)(?![A-Za-z0-9_$])/', $line, $matches)) {
                foreach (array_unique($matches[1]) as $name) {
                    if (!isset($index['js'][$name]) || !$index['js'][$name]['reserved']) {
                        continue;
                    }

                    $this->__report_class($file_path, $line_number, $contents, $name, 'JavaScript class', $index['js'][$name]['path']);
                }
            }

            // Framework_Class._method( - the method direction.
            if (preg_match_all('/(?<![A-Za-z0-9_$])([A-Z][A-Za-z0-9_$]*)\.(_[A-Za-z0-9_$]*)\s*\(/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    [, $class, $method] = $match;

                    if (!isset($index['js'][$class]['methods'][$method])) {
                        continue;
                    }

                    $this->__report_method($file_path, $line_number, $contents, $class, $method, 'JavaScript', $index['js'][$class]['path']);
                }
            }
        }
    }

    /**
     * Blank every comment and every string CONTENT, preserving line structure and offsets.
     *
     * Modeled on FileSanitizer::blank_js_comments(), which explains why a `/` that opens a
     * regex literal cannot be confused with a comment opener: `//` is a comment in every
     * position where it is legal, and `/*` is not a valid regex start. A template literal is
     * treated as one string, so an interpolated `${_Sys_X.y()}` is blanked with it - a
     * deliberate fail-open on an exotic shape rather than a quoting engine of our own.
     */
    private static function __blank_js_strings_and_comments(string $content): string
    {
        $out = '';
        $length = strlen($content);
        $i = 0;

        while ($i < $length) {
            $char = $content[$i];
            $next = $i + 1 < $length ? $content[$i + 1] : '';

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $out .= $char;
                $i++;

                while ($i < $length) {
                    $inner = $content[$i];

                    if ($inner === '\\' && $i + 1 < $length) {
                        $out .= $content[$i] === "\n" ? "\n" : ' ';
                        $out .= $content[$i + 1] === "\n" ? "\n" : ' ';
                        $i += 2;
                        continue;
                    }

                    if ($inner === $quote) {
                        $out .= $inner;
                        $i++;
                        break;
                    }

                    // An unterminated single-quoted string never crosses a newline.
                    if ($inner === "\n" && $quote !== '`') {
                        break;
                    }

                    $out .= $inner === "\n" ? "\n" : ' ';
                    $i++;
                }

                continue;
            }

            if ($char === '/' && $next === '/') {
                while ($i < $length && $content[$i] !== "\n") {
                    $out .= ' ';
                    $i++;
                }

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($content, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;

                for (; $i < $end; $i++) {
                    $out .= $content[$i] === "\n" ? "\n" : ' ';
                }

                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    // =====================================================================
    // jqhtml
    // =====================================================================

    /**
     * The manifest already indexes every component a jqhtml file REFERENCES, `_`-aware, in
     * `$metadata['components']` - so the reference list is read rather than re-derived. Line
     * numbers are not indexed, so the tag is located in the source for reporting; a fixture
     * with no manifest entry falls back to scanning the tags itself.
     */
    private function __check_jqhtml(string $file_path, string $contents, array $metadata, array $index): void
    {
        $body = FileSanitizer::blank_template_comments($contents);

        $components = $metadata['components'] ?? null;

        if (!is_array($components) || empty($components)) {
            $components = [];

            if (preg_match_all('/<(_[A-Z][A-Za-z0-9_]*)/', $body, $matches)) {
                $components = array_unique($matches[1]);
            }
        }

        foreach ($components as $component) {
            if (!is_string($component) || !isset($index['jqhtml'][$component])) {
                continue;
            }

            if (!Rsx_Identifier::is_framework_reserved($component)) {
                continue;
            }

            $this->__report_class(
                $file_path,
                static::__find_line($body, '<' . $component),
                $contents,
                $component,
                'jqhtml component',
                $index['jqhtml'][$component]
            );
        }
    }

    // =====================================================================
    // Blade
    // =====================================================================

    /**
     * A Blade page reaches a reserved name three ways: a component tag, `@rsx_extends` and
     * `@rsx_include`. The last two name a Blade `@rsx_id` or a jqhtml component, so both
     * indexes are consulted.
     */
    private function __check_blade(string $file_path, string $contents, array $index): void
    {
        $body = FileSanitizer::blank_template_comments($contents);
        $lines = explode("\n", $body);

        foreach ($lines as $offset => $line) {
            $line_number = $offset + 1;

            if (!str_contains($line, '_')) {
                continue;
            }

            $found = [];

            if (preg_match_all('/<(_[A-Z][A-Za-z0-9_]*)/', $line, $matches)) {
                $found = array_merge($found, $matches[1]);
            }

            if (preg_match_all('/@rsx_(?:extends|include)\s*\(\s*[\'"](_[A-Z][A-Za-z0-9_]*)[\'"]/', $line, $matches)) {
                $found = array_merge($found, $matches[1]);
            }

            foreach (array_unique($found) as $name) {
                $path = $index['blade'][$name] ?? $index['jqhtml'][$name] ?? null;

                if ($path === null) {
                    continue;
                }

                $kind = isset($index['blade'][$name]) ? 'Blade template' : 'jqhtml component';

                $this->__report_class($file_path, $line_number, $contents, $name, $kind, $path);
            }
        }
    }

    // =====================================================================
    // Reporting
    // =====================================================================

    private function __is_reserved_class(string $name, array $classes): bool
    {
        return isset($classes[$name]) && $classes[$name]['reserved'];
    }

    private function __report_class(string $file_path, int $line_number, string $contents, string $name, string $kind, string $declared_at): void
    {
        $this->add_violation(
            $file_path,
            $line_number,
            "Application code references the framework-reserved {$kind} '{$name}'.",
            $this->__snippet($contents, $line_number),
            "'{$name}' carries the FRAMEWORK-APPLICATION PREFIX - one leading underscore - and is "
                . "declared by the framework at {$declared_at}.\n"
                . "\n"
                . "Everything under app/RSpade/ is framework property. `rsx:framework:pull` resets that "
                . "whole tree to the upstream tip, so '{$name}' may be renamed, restructured or deleted in "
                . "any release - and its disappearance is SILENT: a component simply stops rendering, a "
                . "class simply stops resolving.\n"
                . "\n"
                . "There are exactly two legal references to the framework's own application, and both are "
                . "STRINGS handed to a resolver rather than names used as code:\n"
                . "    Rsx::Route('_Sys_Dashboard_Action')       Rsx.Route('_Sys_Dashboard_Action')\n"
                . "    Permission::can_access('_Sys_Dashboard_Action')\n"
                . "\n"
                . "FIX: build what you need in rsx/ under a name of your own. If the framework's version is "
                . "the thing you actually want, that is a framework change request, not a reference.\n"
                . "\n"
                . "Rule: " . self::RULE_ID . ". See: rsx:man sys_panel, rsx:man coding_standards",
            $this->get_default_severity()
        );
    }

    private function __report_method(string $file_path, int $line_number, string $contents, string $class, string $method, string $language, string $declared_at): void
    {
        $separator = $language === 'PHP' ? '::' : '.';

        $this->add_violation(
            $file_path,
            $line_number,
            "Application code calls the framework-internal method {$class}{$separator}{$method}().",
            $this->__snippet($contents, $line_number),
            "A leading underscore on a framework method means INTERNAL: '{$class}' is declared by the "
                . "framework at {$declared_at}, and '{$method}' is not part of its API. It may change "
                . "signature, behaviour or existence in any release, and nothing announces that.\n"
                . "\n"
                . "This is a reference to a framework NAME, not to your own helper - `static::`, `self::` "
                . "and `parent::` receivers are never checked, so a class calling its own "
                . "`_`/`__`-prefixed helpers is unaffected.\n"
                . "\n"
                . "FIX: call the public method that does this ({$class} without the underscore), or file a "
                . "framework change request if no public method exists.\n"
                . "\n"
                . "Rule: " . self::RULE_ID . ". See: rsx:man sys_panel, rsx:man coding_standards",
            $this->get_default_severity()
        );
    }

    private static function __find_line(string $contents, string $needle): int
    {
        foreach (explode("\n", $contents) as $offset => $line) {
            if (str_contains($line, $needle)) {
                return $offset + 1;
            }
        }

        return 1;
    }

    private function __snippet(string $contents, int $line_number): string
    {
        $lines = explode("\n", $contents);

        return isset($lines[$line_number - 1]) ? trim($lines[$line_number - 1]) : '';
    }
}
