<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Manifest\RevisionParent_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the REVISION-01 rule (RevisionParent_CodeQualityRule).
 *
 * The rule flags an incoherent #[Revision_Parent]: one on a model that records no
 * revisions, one that is not on a belongsTo, and one pointing at a parent that records no
 * revisions either.
 *
 * Tests drive the rule's evaluate_file() seam over synthetic fixture files written to a
 * temp directory, so real nikic/php-parser output is exercised end to end. The attribute
 * name and the relationship verbs live in STRING constants assembled into fixture source,
 * so this test file itself contains nothing the rule could read as a violation when it
 * scans app/RSpade/tests. The parent-side cases point at REAL models the manifest can
 * resolve: Revision_Fixture_Model (which declares $revisions = true) and
 * Realtime_Fixture_Model (which does not).
 */
class Revision_Parent_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is pure AST parsing over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'REVISION-01';

    /** The attribute, as source text. */
    private const ATTRIBUTE = 'Revision' . '_Parent';

    /** Relationship verbs, as source text. */
    private const BELONGS_TO = 'belongs' . 'To';
    private const HAS_MANY = 'has' . 'Many';

    /** A real framework test model that declares $revisions = true. */
    private const RECORDED_PARENT = '\\App\\RSpade\\Tests\\Revisions\\Php\\Revision_Fixture_Model::class';

    /** A real framework test model that does NOT record revisions. */
    private const UNRECORDED_PARENT = '\\App\\RSpade\\Tests\\Realtime\\Php\\Realtime_Fixture_Model::class';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Build a fixture class whose single annotated method $method_name contains $body.
     */
    private static function __class_source(
        string $class_name,
        bool $declares_revisions,
        string $method_name,
        string $body,
        string $method_docblock = ''
    ): string {
        $declaration = $declares_revisions
            ? "    public static \$revisions = true;\n\n"
            : '';

        return "<?php\n"
            . "class {$class_name}\n"
            . "{\n"
            . $declaration
            . $method_docblock
            . '    #[' . self::ATTRIBUTE . "]\n"
            . "    public function {$method_name}()\n"
            . "    {\n"
            . '        return ' . $body . ";\n"
            . "    }\n"
            . "}\n";
    }

    /**
     * Write $source to a fresh temp file, run the rule over it, and return the collected
     * REVISION-01 violations.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $class_name): array
    {
        $dir = storage_path('rsx-tmp') . '/revision_rule_fixture_' . uniqid();
        $path = $dir . '/' . $class_name . '.php';
        ensure_directory($dir);
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new RevisionParent_CodeQualityRule($collector);
        $rule->evaluate_file($path, $class_name);

        $violations = $collector->get_by_rule(self::RULE_ID);

        @unlink($path);
        @rmdir($dir);

        return $violations;
    }

    /**
     * Unique fixture class name per case, so no cache entry can bleed between tests.
     */
    private static function __unique_class(string $suffix): string
    {
        return 'Revision_Probe_' . $suffix;
    }

    // =====================================================================
    // The coherent declaration
    // =====================================================================

    public static function test_a_coherent_declaration_passes()
    {
        $class = static::__unique_class('Good');
        $source = static::__class_source(
            $class,
            true,
            'owner',
            '$this->' . self::BELONGS_TO . '(' . self::RECORDED_PARENT . ", 'owner_id')"
        );

        static::__assert_count(0, static::__run($source, $class), 'a recorded child pointing at a recorded parent is fine');
    }

    public static function test_a_method_without_the_attribute_is_ignored()
    {
        $class = static::__unique_class('Unattributed');
        $source = "<?php\n"
            . "class {$class}\n"
            . "{\n"
            . "    public function owner()\n"
            . "    {\n"
            . '        return $this->' . self::HAS_MANY . '(' . self::UNRECORDED_PARENT . ");\n"
            . "    }\n"
            . "}\n";

        static::__assert_count(0, static::__run($source, $class), 'the rule only judges annotated methods');
    }

    // =====================================================================
    // CHECK 1 - the child must record revisions
    // =====================================================================

    public static function test_an_undeclared_child_is_flagged()
    {
        $class = static::__unique_class('ChildNotOptedIn');
        $source = static::__class_source(
            $class,
            false,
            'owner',
            '$this->' . self::BELONGS_TO . '(' . self::RECORDED_PARENT . ", 'owner_id')"
        );

        $violations = static::__run($source, $class);

        static::__assert_count(1, $violations, 'a child that records nothing must be flagged');
        static::__assert_contains('$revisions = true', $violations[0]->message);
    }

    // =====================================================================
    // CHECK 2 - it must be a belongsTo
    // =====================================================================

    public static function test_the_attribute_on_a_non_belongs_to_is_flagged()
    {
        $class = static::__unique_class('NotBelongsTo');
        $source = static::__class_source(
            $class,
            true,
            'children',
            '$this->' . self::HAS_MANY . '(' . self::RECORDED_PARENT . ", 'owner_id')"
        );

        $violations = static::__run($source, $class);

        static::__assert_count(1, $violations);
        static::__assert_contains('belongsTo', $violations[0]->message);
    }

    // =====================================================================
    // CHECK 3 - the parent must record revisions too
    // =====================================================================

    public static function test_an_undeclared_parent_is_flagged()
    {
        $class = static::__unique_class('ParentNotOptedIn');
        $source = static::__class_source(
            $class,
            true,
            'owner',
            '$this->' . self::BELONGS_TO . '(' . self::UNRECORDED_PARENT . ", 'owner_id')"
        );

        $violations = static::__run($source, $class);

        static::__assert_count(1, $violations, 'filing revisions under an unrecorded parent is a half history');
        static::__assert_contains('Realtime_Fixture_Model', $violations[0]->message);
    }

    public static function test_an_unresolvable_parent_is_not_judged()
    {
        $class = static::__unique_class('DynamicParent');
        $source = static::__class_source(
            $class,
            true,
            'owner',
            '$this->' . self::BELONGS_TO . '($some_class, \'owner_id\')'
        );

        static::__assert_count(
            0,
            static::__run($source, $class),
            'a fatal rule never guesses at a target it cannot resolve statically'
        );
    }

    // =====================================================================
    // The exception marker
    // =====================================================================

    public static function test_a_method_level_exception_suppresses_the_finding()
    {
        $class = static::__unique_class('Excepted');
        $source = static::__class_source(
            $class,
            true,
            'owner',
            '$this->' . self::BELONGS_TO . '(' . self::UNRECORDED_PARENT . ", 'owner_id')",
            "    /** @" . self::RULE_ID . "-EXCEPTION deliberate, for this test */\n"
        );

        static::__assert_count(0, static::__run($source, $class));
    }
}
