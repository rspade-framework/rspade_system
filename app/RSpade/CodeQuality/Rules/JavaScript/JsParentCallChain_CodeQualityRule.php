<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\JsParsers\Js_Parser;
use App\RSpade\Core\Manifest\Manifest;

/**
 * JS-PARENT-CHAIN-01 - Default super-call chaining for JavaScript method overrides.
 *
 * The JavaScript twin of PHP-PARENT-CHAIN-01. A class method - instance or static - that
 * overrides a same-named method declared by an ANCESTOR JS class in the manifest MUST call
 * super.<method>(...) somewhere in its body, UNLESS the nearest ancestor that declares the
 * method marks it with the `@Replaceable` decorator (Core/Js/replaceable.js, a no-op marker).
 * Chaining is the default; `@Replaceable` is the explicit opt-out.
 *
 * Why it is fatal: JavaScript never requires an override to reach the method it shadows, and
 * jqhtml calls only the MOST-DERIVED lifecycle hook - an on_create() / on_ready() / on_stop()
 * that forgets super silently skips everything the parent class does there (an input that never
 * marks itself ready, a listener never removed, a layout region never wired). Nothing errors; a
 * feature just stops working. Making the chain the enforced default catches it at the save.
 *
 * Exempt by construction:
 *   - constructor: the language already refuses a derived constructor that does not call
 *     super() before touching `this`, so the obligation PHP-PARENT-CHAIN-01 places on
 *     __construct is enforced here by the engine;
 *   - getters, setters and #private methods: not overridable methods in this sense;
 *   - any ancestor OUTSIDE the manifest (npm / vendor / jqhtml's own base): the lineage stops
 *     at the last indexed class, so the first indexed class may define any method freely -
 *     the vendor exemption PHP-PARENT-CHAIN-01 has. jqhtml lifecycle hooks are not special:
 *     a component's on_ready() is free, a sub-component's on_ready() over it is enforced.
 * A parent method that only throws "must be implemented" is an abstract method by convention;
 * it is marked `@Replaceable`, never special-cased.
 *
 * Detection is AST-derived, never a regex: the parser service
 * (Core/JsParsers/resource/parser-service.js) records per method `super_calls` (the names a
 * body calls through `super`, arrow functions included, '*' for a computed `super[expr](...)`)
 * and `decorators`. A comment or string mentioning super.<method>( can neither satisfy nor
 * trip it. `super_calls` is read from Js_Parser::parse() - the one parse of the file, cached
 * on disk by content and parser version - and only for a class that has an obligation to
 * judge, so a manifest record carried forward from before the parser recorded it is never
 * trusted. The raw bytes are read only to honor a `@JS-PARENT-CHAIN-01-EXCEPTION <rationale>`
 * marker on a violating method, as PHP-PARENT-CHAIN-01 honors its own.
 *
 * Incremental: a build judges the classes declared in a CHANGED file plus every descendant of
 * them (a changed parent can move a descendant's verdict); a pass with no changed set (a full
 * rebuild, rsx:check) judges every indexed class.
 */
class JsParentCallChain_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'JS-PARENT-CHAIN-01';

    private const REPLACEABLE = 'Replaceable';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'JavaScript Super Call Chaining Enforcement';
    }

    public function get_description(): string
    {
        return 'Requires a JS method override to call super.<method>() unless the nearest declaring '
            . 'ancestor method is decorated @Replaceable';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * A missing super call is a manifest-build FATAL, as its PHP twin is.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * CROSS-FILE: an override's obligation is decided by its nearest declaring ancestor.
     */
    public function kind(): string
    {
        return self::KIND_CROSS_FILE;
    }

    /**
     * Every indexed JS file and the JS lineage indexes: any ancestor moving can re-judge a child.
     *
     * @return array<int,string>
     */
    public function depends_on(): array
    {
        return [
            'files:*.js',
            'js_classes',
            'js_subclass_index',
        ];
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $index = Manifest::get_full_manifest()['data'] ?? [];
        $js_classes = $index['js_classes'] ?? [];
        if (empty($js_classes)) {
            return;
        }

        $subjects = $this->subjects(Manifest::get_changed_files(), $js_classes, $index['js_subclass_index'] ?? []);

        foreach ($subjects as $class_name) {
            $record = $js_classes[$class_name];
            if (empty($record['extends'])) {
                continue;
            }

            $ancestry = $this->manifest_ancestry($record['extends']);
            if (empty($ancestry)) {
                continue;
            }

            $this->evaluate_class(rsx_project_file_path($record['file']), Manifest::get_file($record['file']), $ancestry);
        }
    }

    /**
     * The classes this pass judges, by name. With no changed set every indexed JS class; else
     * each class declared in a changed file plus every descendant of it (`js_subclass_index`
     * lists all descendants, not only direct children), since a parent's change - a method
     * added, removed, or newly @Replaceable - moves exactly those verdicts.
     *
     * @param array<int,string>                $changed    project-relative paths
     * @param array<string,array>              $js_classes class => ['file', 'extends']
     * @param array<string,array<int,string>>  $subclasses class => descendant class names
     * @return array<int,string>
     */
    private function subjects(array $changed, array $js_classes, array $subclasses): array
    {
        if (empty($changed)) {
            return array_keys($js_classes);
        }

        $changed_lookup = [];
        foreach ($changed as $rel) {
            $changed_lookup[str_replace('\\', '/', $rel)] = true;
        }

        $subjects = [];
        foreach ($js_classes as $class_name => $record) {
            if (!isset($changed_lookup[str_replace('\\', '/', $record['file'])])) {
                continue;
            }

            $subjects[$class_name] = true;
            foreach ($subclasses[$class_name] ?? [] as $descendant) {
                if (isset($js_classes[$descendant])) {
                    $subjects[$descendant] = true;
                }
            }
        }

        return array_keys($subjects);
    }

    /**
     * The nearest -> root ancestry starting at $parent_name, each entry ['class' => name,
     * 'metadata' => its manifest file record]. Climbing stops at the first class outside the
     * manifest (npm / vendor): nothing it or its ancestors declare carries an obligation.
     *
     * @return array<int,array{class:string,metadata:array}>
     */
    private function manifest_ancestry(string $parent_name): array
    {
        $ancestry = [];
        foreach (array_merge([$parent_name], Manifest::js_get_lineage($parent_name)) as $ancestor_name) {
            try {
                $ancestor_rel = Manifest::js_find_class($ancestor_name);
            } catch (\RuntimeException $e) {
                break;
            }
            $ancestry[] = [
                'class' => $ancestor_name,
                'metadata' => Manifest::get_file($ancestor_rel),
            ];
        }

        return $ancestry;
    }

    /**
     * Judge one class against its nearest -> root ancestry (each entry: ['class' => name,
     * 'metadata' => that class's manifest file record]). The testable seam: production builds the
     * ancestry from the manifest, tests from Js_Parser::extract_metadata() over fixtures.
     *
     * Which methods override, and whether the declarer is @Replaceable, come from the records'
     * method maps; the child's `super_calls` come from its parse, read only once an obligation
     * stands.
     */
    private function evaluate_class(string $child_file, array $child_metadata, array $ancestry): void
    {
        $child_class = $child_metadata['class'];
        $parsed_methods = null;

        foreach (['public_instance_methods' => false, 'public_static_methods' => true] as $bucket => $is_static) {
            foreach ($child_metadata[$bucket] ?? [] as $method_name => $method) {
                $method_name = (string) $method_name;
                if (!$this->is_chainable($method_name, $method)) {
                    continue;
                }

                $declarer = $this->find_nearest_declarer($ancestry, $bucket, $method_name);
                if ($declarer === null || $this->is_replaceable($declarer['method'])) {
                    continue;
                }

                $parsed_methods ??= $this->parsed_methods($child_file, $child_class);
                $super_calls = $parsed_methods[$bucket][$method_name]['super_calls'] ?? [];
                if (in_array($method_name, $super_calls, true) || in_array('*', $super_calls, true)) {
                    continue;
                }

                $line = (int) ($method['line'] ?? 0);
                $lines = explode("\n", $this->source()->content($child_file, $child_metadata['hash'] ?? ''));
                if ($this->has_exception_marker($lines, $line)) {
                    continue;
                }

                $this->report($child_file, $line, $lines, $child_class, $declarer['class'], $method_name, $is_static);
            }
        }
    }

    /**
     * The class's method maps from the file's parse (Js_Parser's disk cache, keyed on content
     * and parser version): ['public_instance_methods' => [...], 'public_static_methods' => [...]].
     *
     * @return array<string,array>
     */
    private function parsed_methods(string $file, string $class_name): array
    {
        foreach (Js_Parser::parse($file)['classes'] ?? [] as $class) {
            if (($class['name'] ?? null) === $class_name) {
                return [
                    'public_instance_methods' => $class['public_instance_methods'] ?? [],
                    'public_static_methods' => $class['public_static_methods'] ?? [],
                ];
            }
        }

        shouldnt_happen("The JavaScript parse of {$file} does not declare class {$class_name}");
    }

    /**
     * An ordinary named method: not the constructor, not a getter / setter, not #private.
     */
    private function is_chainable(string $method_name, array $method): bool
    {
        if ($method_name === '' || $method_name === 'constructor' || str_starts_with($method_name, '#')) {
            return false;
        }

        return ($method['kind'] ?? 'method') === 'method';
    }

    /**
     * Whether a method record carries the @Replaceable decorator (compact [[name, args], ...]).
     */
    private function is_replaceable(array $method): bool
    {
        foreach ($method['decorators'] ?? [] as $decorator) {
            if (($decorator[0] ?? null) === self::REPLACEABLE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The nearest ancestor declaring $method_name in the same bucket (instance or static), as
     * ['class' => name, 'method' => its record]; null when none in the manifest does.
     */
    private function find_nearest_declarer(array $ancestry, string $bucket, string $method_name): ?array
    {
        foreach ($ancestry as $ancestor) {
            $method = $ancestor['metadata'][$bucket][$method_name] ?? null;
            if ($method !== null && $this->is_chainable($method_name, $method)) {
                return ['class' => $ancestor['class'], 'method' => $method];
            }
        }

        return null;
    }

    /**
     * True when the method's declaration line, or the comment / decorator block directly above
     * it, carries `@JS-PARENT-CHAIN-01-EXCEPTION` followed by a rationale. A bare marker
     * suppresses nothing.
     *
     * @param array<int,string> $lines
     */
    private function has_exception_marker(array $lines, int $line): bool
    {
        $pattern = '/@' . preg_quote(self::RULE_ID, '/') . '-EXCEPTION\s*[-:]?\s*\S/';

        for ($index = $line - 1; $index >= 0 && isset($lines[$index]); $index--) {
            $text = trim($lines[$index]);
            if ($index < $line - 1 && !preg_match('#^(//|/\*|\*|@)#', $text)) {
                break;
            }
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $lines
     */
    private function report(
        string $child_file,
        int $line,
        array $lines,
        string $child_class,
        string $ancestor_class,
        string $method_name,
        bool $is_static
    ): void {
        $static = $is_static ? 'static ' : '';
        $code_snippet = ($line > 0 && isset($lines[$line - 1])) ? trim($lines[$line - 1]) : '';

        $message = "{$child_class}.{$static}{$method_name}() overrides {$ancestor_class}.{$method_name}() "
            . "but never calls super.{$method_name}(). A missing super call silently skips everything the "
            . "parent does there - jqhtml calls only the most-derived lifecycle hook, and nothing errors.";

        $suggestion = "Decide which is true. If {$ancestor_class}.{$method_name}() does work every subclass "
            . "needs, call super.{$method_name}(...) in {$child_class}.{$method_name}() (await it when it is "
            . "async). If it is a hook overriders are meant to replace entirely (an empty default, a stub that "
            . "throws 'must implement'), decorate it `@Replaceable` on the line above its declaration in "
            . "{$ancestor_class}. An override of a framework method you cannot change takes "
            . "`// @" . self::RULE_ID . "-EXCEPTION <rationale>` above it - ask before adding one.";

        $this->add_violation($child_file, $line, $message, $code_snippet, $suggestion, 'critical');
    }
}
