<?php

namespace App\RSpade\Core\Naming;

/**
 * The ONE home for the shape of an RSpade identifier.
 *
 * An RSpade name - a PHP class, a JS class, a jqhtml component, a Blade `@rsx_id` -
 * is a capital letter followed by letters, digits and underscores, OPTIONALLY preceded
 * by a SINGLE leading underscore:
 *
 *     /^_?[A-Z][A-Za-z0-9_]*$/
 *
 * The single leading underscore is the FRAMEWORK-APPLICATION PREFIX. It marks a name
 * declared by the framework's own application tree (system/app/RSpade/Sys/) - the
 * shipped control panel and the surfaces that travel with it - and it is RESERVED:
 * application code under rsx/ may not declare one (NAME-RESERVED-01). The convention
 * mirrors the `_`-prefixed system TABLES and system COLUMNS: one character says "the
 * framework owns this", with no registry to keep in step.
 *
 * TWO or more leading underscores are not a name at all. `__MODEL` and friends are
 * payload keys, and Babel's generated bindings live in that namespace too.
 *
 * jqhtml implements the identical rule in JavaScript and exports it as
 * `COMPONENT_NAME_PATTERN` / `is_component_name` from @jqhtml/parser.
 */
class Rsx_Identifier
{
    /**
     * The name shape: optional single leading underscore, then a capital, then word characters.
     */
    const CLASS_NAME_PATTERN = '/^_?[A-Z][A-Za-z0-9_]*$/';

    /**
     * Human-readable statement of the rule, for error and remediation text.
     */
    const CLASS_NAME_RULE = 'must start with a capital letter, optionally preceded by a single underscore';

    /**
     * The framework's own application tree, as it is spelled in a manifest path.
     */
    const FRAMEWORK_APP_TREE = 'app/RSpade/Sys/';

    /**
     * Is this a well-formed RSpade class / component / id name?
     */
    public static function is_class_name(string $name): bool
    {
        return preg_match(static::CLASS_NAME_PATTERN, $name) === 1;
    }

    /**
     * Is this a framework-application name - exactly one leading underscore, then a capital?
     */
    public static function is_framework_reserved(string $name): bool
    {
        return preg_match('/^_[A-Z][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * Should a NAME appear in a developer-facing inventory listing on this box?
     *
     * The framework's own application (app/RSpade/Sys/) declares `_`-prefixed names, and
     * those names are RESERVED from application code: an application must not declare,
     * extend, render or route to one. Listing them in rsx:routes, rsx:manifest:show,
     * rsx:jqhtml:glossary and friends only invites the references the rule forbids, so
     * the inventory tools hide them - unless this box authors the framework, where they
     * are exactly the names the developer is working on.
     *
     * DISPLAY ONLY. Nothing leaves the manifest and no route stops resolving.
     */
    public static function is_visible_to_developer(string $name): bool
    {
        return !static::is_framework_reserved($name)
            || config('rsx.code_quality.is_framework_developer', false);
    }

    /**
     * Should a FILE PATH appear in a developer-facing inventory listing on this box?
     *
     * The path counterpart of is_visible_to_developer(): a listing that enumerates files
     * rather than names hides the framework's own application tree on the same terms.
     */
    public static function is_path_visible_to_developer(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        // The tree itself, and anything DERIVED from one of its names (a generated JS stub
        // sits outside the tree but is still named for the class it mirrors).
        $reserved = str_contains($normalized, static::FRAMEWORK_APP_TREE)
            || static::is_framework_reserved(preg_replace('/\..*$/', '', basename($normalized)));

        return !$reserved || config('rsx.code_quality.is_framework_developer', false);
    }

    /**
     * The name with its single leading framework-application underscore removed.
     * Any other name is returned unchanged.
     */
    public static function bare(string $name): string
    {
        if (static::is_framework_reserved($name)) {
            return substr($name, 1);
        }

        return $name;
    }

    /**
     * The ONE PascalCase-to-snake_case conversion.
     *
     * Inserts an underscore before each interior capital and before the first digit of a
     * run of digits, collapses runs of underscores, and PRESERVES a single leading
     * underscore: `_Sys_Card` -> `_root_card`, `Root_Card` -> `root_card`,
     * `TestComponent1` -> `test_component_1`.
     */
    public static function to_snake_case(string $name): string
    {
        $reserved = static::is_framework_reserved($name);
        $bare = static::bare($name);

        // Insert underscore before uppercase letters (except the first character)
        $result = preg_replace('/(?<!^)([A-Z])/', '_$1', $bare);

        // Insert underscore before the first digit in a run of digits
        $result = preg_replace('/(?<!^)(?<![0-9])([0-9])/', '_$1', $result);

        // Collapse runs of underscores
        $result = preg_replace('/_+/', '_', $result);

        $result = strtolower($result);

        return $reserved ? '_' . $result : $result;
    }
}
