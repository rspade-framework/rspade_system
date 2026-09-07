<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Convention\NameReservedReference_CodeQualityRule;
use App\RSpade\CodeQuality\Support\Validation_Ledger;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for NAME-RESERVED-02 (NameReservedReference_CodeQualityRule).
 *
 * NAME-RESERVED-01 governs who may DECLARE a `_`-prefixed name. This rule governs who may
 * REFERENCE one, which is the direction an application actually trips over.
 *
 * The rule's whole discrimination is the manifest: a `_`-prefixed name is its business only
 * when the framework really declares that name under `app/RSpade/`. So the fixtures name
 * REAL framework symbols (`_Sys_Spa_Controller`, `_Sys_Layout`, `_Sys_Section`,
 * `_Apidocs_App`, `Ajax::_is_internal_call`, `Rsx_Api_Docs::__restrict_groups`,
 * `Rsx._escape_html`) rather than invented ones - an invented name would pass for the wrong
 * reason and the test would prove nothing. The negatives are the other half: the template app
 * alone makes ~938 `static::__helper()` calls, and every one of them must stay legal.
 *
 * The sanctioned carrier the fixtures quote, `Rsx::Route('_Sys_Dashboard_Action')`, names
 * the panel's index ACTION - which is how a SPA route is registered - so it RESOLVES, and
 * this file needs no ROUTE-EXISTS-01 exception. (It used to quote
 * `_Sys_Spa_Controller::index`, which Rsx::Route() refuses like every other SPA bootstrap
 * controller, and carried an exception to say so.)
 */
class Name_Reserved_Reference_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is text/AST inspection over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'NAME-RESERVED-02';

    /** A throwaway ledger, so a test never writes over the developer's own memo. */
    private static ?string $ledger_path = null;

    /**
     * Write $source to a fixture at $relative_name under a throwaway root, run the rule over
     * it, and return the violations it produced.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $relative_name, string $source, array $metadata = []): array
    {
        self::$ledger_path = storage_path('rsx-tmp') . '/name_reserved_02_ledger_' . uniqid() . '.php';
        Validation_Ledger::_use_path_for_tests(self::$ledger_path);

        $root = storage_path('rsx-tmp') . '/name_reserved_02_fixture_' . uniqid();
        $path = $root . '/' . $relative_name;
        ensure_directory(dirname($path));
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new NameReservedReference_CodeQualityRule($collector);
        $rule->check($path, $source, $metadata);

        $violations = array_values($collector->get_by_rule(self::RULE_ID));

        self::__remove_tree($root);

        if (file_exists(self::$ledger_path)) {
            @unlink(self::$ledger_path);
        }

        Validation_Ledger::_use_path_for_tests(null);
        self::$ledger_path = null;

        return $violations;
    }

    private static function __remove_tree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($root);
    }

    // =====================================================================
    // PHP - class references
    // =====================================================================

    /**
     * Extending a framework-reserved class is the headline case: the application inherits
     * from something that vanishes at the next framework pull.
     */
    public static function test_php_extends_reserved_class_is_a_violation()
    {
        $violations = self::__run(
            'rsx/lib/probe_layout.php',
            "<?php\n\nclass Probe_Layout extends _Sys_Controller\n{\n}\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('_Sys_Controller', $violations[0]->message);
        static::__assert_contains('app/RSpade/Sys/app/sys/_Sys_Controller.php', $violations[0]->suggestion);
    }

    /**
     * A static call on a reserved class is a reference to the CLASS - reported once, as the
     * class, rather than twice.
     */
    public static function test_php_static_call_on_reserved_class_is_a_violation()
    {
        $violations = self::__run(
            'rsx/lib/probe_call.php',
            "<?php\n\nclass Probe_Call\n{\n    public static function go()\n    {\n        return _Sys_Spa_Controller::index(request());\n    }\n}\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('_Sys_Spa_Controller', $violations[0]->message);
        static::__assert_equals(7, $violations[0]->line_number, 'reported at the call site');
    }

    /**
     * Every other way PHP names a class reaches the same index: new, instanceof, ::class,
     * a `use` import, a type hint and a catch type.
     */
    public static function test_php_every_naming_form_is_a_violation()
    {
        $forms = [
            'new'        => "        return new _Sys_Bundle();",
            'instanceof' => "        return \$x instanceof _Sys_Controller;",
            'classconst' => "        return _Sys_Controller::class;",
            'typehint'   => "        return static::take(null);",
            'catch'      => "        try { } catch (_Manifest_Cache_Helper \$e) { }",
        ];

        foreach ($forms as $label => $body) {
            $source = "<?php\n\nclass Probe_Form\n{\n    public static function go(\$x)\n    {\n"
                . $body . "\n    }\n\n    public static function take(?_Sys_Controller \$y)\n    {\n    }\n}\n";

            $violations = self::__run("rsx/lib/probe_form_{$label}.php", $source);

            static::__assert_greater_than(0, count($violations), "the {$label} form must be flagged");
        }
    }

    /**
     * A `use` import names the class as surely as anything else does.
     */
    public static function test_php_use_import_of_reserved_class_is_a_violation()
    {
        $violations = self::__run(
            'rsx/lib/probe_use.php',
            "<?php\n\nuse App\\RSpade\\Sys\\App\\Sys\\_Sys_Controller;\n\nclass Probe_Use\n{\n}\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('_Sys_Controller', $violations[0]->message);
    }

    // =====================================================================
    // PHP - internal method references
    // =====================================================================

    /**
     * A `_`-prefixed static on an ORDINARY-named framework class is the second direction:
     * the class is public API, the method is not.
     */
    public static function test_php_framework_internal_static_is_a_violation()
    {
        $violations = self::__run(
            'rsx/lib/probe_internal.php',
            "<?php\n\nclass Probe_Internal\n{\n    public static function go()\n    {\n        return Ajax::_is_internal_call();\n    }\n}\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('Ajax::_is_internal_call', $violations[0]->message);
        static::__assert_contains('framework-internal', $violations[0]->message);
    }

    /**
     * The double underscore - the framework's own PRIVATE spelling - is the same violation.
     */
    public static function test_php_framework_private_static_is_a_violation()
    {
        $violations = self::__run(
            'rsx/lib/probe_private.php',
            "<?php\n\nclass Probe_Private\n{\n    public static function go()\n    {\n        return Rsx_Api_Docs::__restrict_groups([]);\n    }\n}\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('Rsx_Api_Docs::__restrict_groups', $violations[0]->message);
    }

    // =====================================================================
    // PHP - the negatives that keep the rule from being an underscore ban
    // =====================================================================

    /**
     * A class's own helpers are the legitimate ~938. `static::`, `self::` and `parent::` are
     * never checked, and neither is an instance call.
     */
    public static function test_php_own_class_helpers_are_clean()
    {
        $violations = self::__run(
            'rsx/lib/probe_own.php',
            "<?php\n\nclass Probe_Own\n{\n    public function go()\n    {\n"
                . "        return static::__helper() . self::_x() . \$this->_y() . parent::__z();\n"
                . "    }\n}\n"
        );

        static::__assert_count(0, $violations, 'a class calling its own helpers is never flagged');
    }

    /**
     * PHP's own vocabulary lives in the same character and is nobody's framework name.
     */
    public static function test_php_magic_and_constants_are_clean()
    {
        $violations = self::__run(
            'rsx/lib/probe_magic.php',
            "<?php\n\nclass Probe_Magic\n{\n    public function __construct()\n    {\n"
                . "        \$dir = __DIR__;\n        \$file = __FILE__;\n    }\n}\n"
        );

        static::__assert_count(0, $violations, '__construct and __DIR__ are language, not framework names');
    }

    /**
     * The manifest is the whole discrimination: a `_`-prefixed static on a class the
     * framework does not declare is somebody else's API.
     */
    public static function test_php_unknown_class_underscore_call_is_clean()
    {
        $violations = self::__run(
            'rsx/lib/probe_vendor.php',
            "<?php\n\nclass Probe_Vendor\n{\n    public static function go()\n    {\n        return Some_Vendor_Client::_call();\n    }\n}\n"
        );

        static::__assert_count(0, $violations, 'a class the manifest does not know is out of scope');
    }

    /**
     * The sanctioned carrier is a STRING handed to a resolver, and PHP is read as an AST
     * where a string is a scalar - so it is legal by construction, with no allowlist.
     */
    public static function test_php_sanctioned_string_carriers_are_clean()
    {
        $violations = self::__run(
            'rsx/lib/probe_carrier.php',
            "<?php\n\nclass Probe_Carrier\n{\n    public static function go()\n    {\n"
                . "        if (Permission::can_access('_Sys_Dashboard_Action')) {\n"
                . "            return Rsx::Route('_Sys_Dashboard_Action');\n"
                . "        }\n\n        return null;\n    }\n}\n"
        );

        static::__assert_count(0, $violations, 'the two documented carriers are strings, not references');
    }

    // =====================================================================
    // JavaScript
    // =====================================================================

    /**
     * `new _X(` and `extends _X` are the two shapes an application reaches a panel component
     * with.
     */
    public static function test_js_class_references_are_violations()
    {
        $construct = self::__run(
            'rsx/lib/probe_new.js',
            "function probe_new()\n{\n    return new _Sys_Sidebar_Nav();\n}\n"
        );

        static::__assert_count(1, $construct, 'new _Sys_Sidebar_Nav() is a violation');
        static::__assert_contains('_Sys_Sidebar_Nav', $construct[0]->message);
        static::__assert_equals(3, $construct[0]->line_number);

        $extends = self::__run(
            'rsx/lib/probe_extends.js',
            "class Probe_Extends extends _Sys_Layout\n{\n}\n"
        );

        static::__assert_count(1, $extends, 'extends _Sys_Layout is a violation');
        static::__assert_contains('_Sys_Layout', $extends[0]->message);
    }

    /**
     * The JS method direction, on a class an application legitimately uses every day.
     */
    public static function test_js_framework_internal_method_is_a_violation()
    {
        $violations = self::__run(
            'rsx/lib/probe_js_internal.js',
            "function probe()\n{\n    return Rsx._escape_html('x');\n}\n"
        );

        static::__assert_count(1, $violations, 'expected exactly one violation');
        static::__assert_contains('Rsx._escape_html', $violations[0]->message);
    }

    /**
     * The JS carriers, and a reserved name merely MENTIONED inside a string. This is what the
     * string blanking buys, and it is the reason there is no allowlist to keep in step.
     */
    public static function test_js_strings_never_match()
    {
        $rows = [
            'route'      => "function probe() { return Rsx.Route('_Sys_Dashboard_Action'); }\n",
            'can_access' => "function probe() { return Permission.can_access('_Sys_Dashboard_Action'); }\n",
            'literal'    => "function probe() { const label = 'the _Sys_Layout component'; return label; }\n",
            'template'   => "function probe() { return `see _Sys_Section for details`; }\n",
            'comment'    => "// _Sys_Layout is documented in rsx:man sys_panel\nfunction probe() { return 1; }\n",
        ];

        foreach ($rows as $label => $source) {
            $violations = self::__run("rsx/lib/probe_js_{$label}.js", $source);

            static::__assert_count(0, $violations, "the {$label} row must not be flagged");
        }
    }

    /**
     * An application's own `_`-prefixed members are not framework names.
     */
    public static function test_js_own_members_are_clean()
    {
        $violations = self::__run(
            'rsx/lib/probe_js_own.js',
            "class Probe_Js_Own\n{\n    go()\n    {\n        return this._helper() + Probe_Js_Own.__made_up();\n    }\n}\n"
        );

        static::__assert_count(0, $violations, 'own instance and static helpers are never flagged');
    }

    // =====================================================================
    // jqhtml and Blade
    // =====================================================================

    /**
     * The manifest already indexes the components a jqhtml file references, so the rule reads
     * that list. The direct scan (used when there is no manifest entry) reaches the same
     * verdict.
     */
    public static function test_jqhtml_reserved_component_is_a_violation()
    {
        $source = "<Define:Probe_Panel tag=\"div\">\n    <_Sys_Section>hello</_Sys_Section>\n</Define:Probe_Panel>\n";

        $from_metadata = self::__run('rsx/app/frontend/probe_panel.jqhtml', $source, [
            'components' => ['_Sys_Section'],
        ]);

        static::__assert_count(1, $from_metadata, 'the indexed component list is read');
        static::__assert_contains('_Sys_Section', $from_metadata[0]->message);
        static::__assert_equals(2, $from_metadata[0]->line_number);

        $from_scan = self::__run('rsx/app/frontend/probe_panel_scan.jqhtml', $source);

        static::__assert_count(1, $from_scan, 'a file with no manifest entry is scanned instead');
    }

    /**
     * An ordinary component is never the rule's business.
     */
    public static function test_jqhtml_ordinary_component_is_clean()
    {
        $violations = self::__run(
            'rsx/app/frontend/probe_ordinary.jqhtml',
            "<Define:Probe_Ordinary tag=\"div\">\n    <Section>hello</Section>\n</Define:Probe_Ordinary>\n",
            ['components' => ['Section']]
        );

        static::__assert_count(0, $violations, 'an application component is untouched');
    }

    /**
     * Blade reaches a reserved name through @rsx_extends / @rsx_include and through a tag.
     */
    public static function test_blade_reserved_reference_is_a_violation()
    {
        $extends = self::__run(
            'rsx/app/frontend/probe_page.blade.php',
            "@rsx_extends('_Apidocs_App')\n\n<div>hello</div>\n"
        );

        static::__assert_count(1, $extends, 'expected exactly one violation');
        static::__assert_contains('_Apidocs_App', $extends[0]->message);
        static::__assert_equals(1, $extends[0]->line_number);

        $tag = self::__run(
            'rsx/app/frontend/probe_tag.blade.php',
            "@rsx_id('Probe_Tag')\n\n<_Sys_Section>hello</_Sys_Section>\n"
        );

        static::__assert_count(1, $tag, 'a reserved component tag in Blade is the same violation');
        static::__assert_contains('_Sys_Section', $tag[0]->message);
    }

    /**
     * An application Blade page that declares its own id and renders its own components is
     * clean - the rule must not become a tax on rsx/.
     */
    public static function test_blade_ordinary_page_is_clean()
    {
        $violations = self::__run(
            'rsx/app/frontend/probe_clean.blade.php',
            "@rsx_id('Probe_Clean')\n\n<Section>hello</Section>\n"
        );

        static::__assert_count(0, $violations, 'an ordinary page is untouched');
    }

    // =====================================================================
    // Scope
    // =====================================================================

    /**
     * The framework referencing its own names is the point of having them. This also covers
     * the reference_app symlink, whose path contains app/RSpade/.
     */
    public static function test_framework_code_is_out_of_scope()
    {
        $violations = self::__run(
            'app/RSpade/Sys/app/sys/dashboard/_Sys_Probe_Action.js',
            "class _Sys_Probe_Action extends _Sys_Layout\n{\n}\n"
        );

        static::__assert_count(0, $violations, 'framework code may reference framework names');
    }

    /**
     * Vendored and mirrored third-party code is not ours to rewrite.
     */
    public static function test_third_party_trees_are_out_of_scope()
    {
        foreach (['rsx/theme/vendor/probe.js', 'rsx/node_modules/probe/probe.js', 'rsx/resource/.cdn-cache/probe.js'] as $relative) {
            $violations = self::__run($relative, "class Probe extends _Sys_Layout\n{\n}\n");

            static::__assert_count(0, $violations, "{$relative} is out of scope");
        }
    }

    /**
     * A clean file's verdict is banked in the shared ledger under an id that carries the
     * reserved-name index's own hash - so a framework change retires it rather than letting
     * a stale index vouch for a file.
     */
    public static function test_a_clean_file_is_recorded_in_the_ledger()
    {
        $ledger_path = storage_path('rsx-tmp') . '/name_reserved_02_ledger_' . uniqid() . '.php';
        Validation_Ledger::_use_path_for_tests($ledger_path);

        $root = storage_path('rsx-tmp') . '/name_reserved_02_fixture_' . uniqid();
        $path = $root . '/rsx/lib/probe_ledger.php';
        ensure_directory(dirname($path));

        $source = "<?php\n\nclass Probe_Ledger\n{\n    public static function go()\n    {\n        return static::__helper();\n    }\n}\n";
        file_put_contents($path, $source);

        try {
            $collector = new ViolationCollector();
            $rule = new NameReservedReference_CodeQualityRule($collector);
            $rule->check($path, $source, []);

            static::__assert_count(0, $collector->get_by_rule(self::RULE_ID), 'precondition: the fixture is clean');

            // The rule id is not the bare NAME-RESERVED-02: it carries the reserved-name
            // index's own hash, so a framework change retires every verdict keyed to the old
            // index instead of letting a stale index vouch for a file. Flushing is what puts
            // that id on disk where the test can read it back.
            Validation_Ledger::flush();

            $written = include $ledger_path;
            $ids = array_keys($written['rules']);

            static::__assert_count(1, $ids, 'exactly one rule id was recorded');
            static::__assert_contains(self::RULE_ID . '@', $ids[0], 'the id carries the index hash, not the bare rule id');

            // And the verdict is keyed by the FILE HASH, so a second pass over the same
            // bytes finds it and short-circuits.
            static::__assert_array_has_key(
                sha1_file($path),
                $written['rules'][$ids[0]],
                'the clean verdict is banked against the file hash'
            );

        } finally {
            self::__remove_tree($root);

            if (file_exists($ledger_path)) {
                @unlink($ledger_path);
            }

            Validation_Ledger::_use_path_for_tests(null);
        }
    }
}
