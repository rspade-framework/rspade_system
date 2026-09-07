<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Manifest\MorphStringPattern_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the POLY-01 rule (MorphStringPattern_CodeQualityRule).
 *
 * The rule flags Laravel's string-morph pattern: a polymorphic relation whose *_type
 * discriminator column is not declared in the owning model's $type_ref_columns (so it
 * is a VARCHAR class name rather than a BIGINT type ref), plus pivot-table morphs that
 * no declaration can ever cover.
 *
 * Tests drive the rule's evaluate_file() seam over synthetic fixture files written to a
 * temp directory, so real nikic/php-parser output is exercised end to end. The morph
 * verbs live in STRING constants assembled into fixture source, so this test file itself
 * contains no morph call the rule could read as a violation when it scans
 * app/RSpade/tests. The related-side cases point at a REAL framework model
 * (Portal_Notification_Model, which declares subject_type) so the manifest lookup the
 * rule performs for morphOne/morphMany is exercised for real.
 */
class Morph_String_Pattern_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is pure AST parsing over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'POLY-01';

    /** Morph verbs, as source text. */
    private const MORPH_TO = 'morph' . 'To';
    private const MORPH_MANY = 'morph' . 'Many';
    private const MORPH_ONE = 'morph' . 'One';
    private const MORPH_TO_MANY = 'morph' . 'ToMany';
    private const MORPHED_BY_MANY = 'morphed' . 'ByMany';

    /** A real framework model that declares subject_type in $type_ref_columns. */
    private const REAL_MODEL = '\\App\\RSpade\\Core\\Models\\Portal_Notification_Model::class';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Build a fixture class named $class_name whose $type_ref_columns is $declared and
     * whose single method $method_name contains $body.
     *
     * @param array<int,string> $declared
     */
    private static function __class_source(
        string $class_name,
        array $declared,
        string $method_name,
        string $body,
        string $method_docblock = ''
    ): string {
        $columns = implode(', ', array_map(fn($c) => "'" . $c . "'", $declared));

        return "<?php\n"
            . "class {$class_name}\n"
            . "{\n"
            . "    protected static \$type_ref_columns = [{$columns}];\n"
            . "\n"
            . $method_docblock
            . "    public function {$method_name}()\n"
            . "    {\n"
            . '        return ' . $body . ";\n"
            . "    }\n"
            . "}\n";
    }

    /**
     * Write $source to a fresh temp file, run the rule over it, and return the collected
     * POLY-01 violations.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source, string $class_name): array
    {
        $dir = storage_path('rsx-tmp') . '/poly_rule_fixture_' . uniqid();
        $path = $dir . '/' . $class_name . '.php';
        ensure_directory($dir);
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new MorphStringPattern_CodeQualityRule($collector);
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
        return 'Poly_Probe_' . $suffix;
    }

    // =====================================================================
    // morphTo - the column lives on the declaring model
    // =====================================================================

    public static function test_morph_to_undeclared_column_is_flagged()
    {
        $class = static::__unique_class('MtUndeclared');
        $source = static::__class_source(
            $class,
            ['other_type'],
            'widget',
            '$this->' . self::MORPH_TO . "('widget', 'widget_type', 'widget_id')"
        );

        $violations = static::__run($source, $class);

        static::__assert_count(1, $violations, 'An undeclared morphTo type column must be flagged');
        static::__assert_contains('widget_type', $violations[0]->message);
    }

    public static function test_morph_to_declared_column_passes()
    {
        $class = static::__unique_class('MtDeclared');
        $source = static::__class_source(
            $class,
            ['widget_type'],
            'widget',
            '$this->' . self::MORPH_TO . "('widget', 'widget_type', 'widget_id')"
        );

        static::__assert_count(0, static::__run($source, $class), 'A declared type column must pass');
    }

    /**
     * A bare morphTo() derives its columns from the METHOD name, exactly as
     * HasRelationships::morphTo() does. The rule must derive the same column.
     */
    public static function test_morph_to_derives_column_from_method_name()
    {
        $class = static::__unique_class('MtDerived');
        $source = static::__class_source($class, [], 'gadget', '$this->' . self::MORPH_TO . '()');

        $violations = static::__run($source, $class);

        static::__assert_count(1, $violations, 'A bare morphTo() must be resolved against the method name');
        static::__assert_contains('gadget_type', $violations[0]->message);
    }

    public static function test_morph_to_derived_column_passes_when_declared()
    {
        $class = static::__unique_class('MtDerivedOk');
        $source = static::__class_source($class, ['gadget_type'], 'gadget', '$this->' . self::MORPH_TO . '()');

        static::__assert_count(0, static::__run($source, $class));
    }

    /**
     * A receiver that is not $this names a model whose declaration this file cannot
     * speak for, so the call is skipped rather than guessed at.
     */
    public static function test_morph_to_on_foreign_receiver_is_skipped()
    {
        $class = static::__unique_class('MtForeign');
        $source = static::__class_source(
            $class,
            [],
            'widget',
            '$other->' . self::MORPH_TO . "('widget', 'widget_type', 'widget_id')"
        );

        static::__assert_count(0, static::__run($source, $class));
    }

    // =====================================================================
    // morphOne / morphMany - the column lives on the RELATED model
    // =====================================================================

    public static function test_morph_many_passes_when_related_model_declares_the_column()
    {
        $class = static::__unique_class('MmOk');
        $source = static::__class_source(
            $class,
            [],
            'notifications',
            '$this->' . self::MORPH_MANY . '(' . self::REAL_MODEL . ", 'subject')"
        );

        static::__assert_count(
            0,
            static::__run($source, $class),
            'subject_type IS declared on Portal_Notification_Model, so the relation is fine'
        );
    }

    public static function test_morph_many_is_flagged_when_related_model_lacks_the_column()
    {
        $class = static::__unique_class('MmBad');
        $source = static::__class_source(
            $class,
            [],
            'gadgets',
            '$this->' . self::MORPH_MANY . '(' . self::REAL_MODEL . ", 'gadget')"
        );

        $violations = static::__run($source, $class);

        static::__assert_count(1, $violations, 'gadget_type is not declared on the related model');
        static::__assert_contains('gadget_type', $violations[0]->message);
    }

    public static function test_morph_one_is_checked_against_the_related_model_too()
    {
        $class = static::__unique_class('MoBad');
        $source = static::__class_source(
            $class,
            [],
            'gadget',
            '$this->' . self::MORPH_ONE . '(' . self::REAL_MODEL . ", 'gadget')"
        );

        static::__assert_count(1, static::__run($source, $class));
    }

    /**
     * The DECLARING model's own $type_ref_columns is irrelevant on the related side -
     * declaring the column locally must not excuse a missing declaration on the model
     * whose table actually carries it.
     */
    public static function test_morph_many_ignores_the_declaring_models_own_declaration()
    {
        $class = static::__unique_class('MmLocalDecl');
        $source = static::__class_source(
            $class,
            ['gadget_type'],
            'gadgets',
            '$this->' . self::MORPH_MANY . '(' . self::REAL_MODEL . ", 'gadget')"
        );

        static::__assert_count(1, static::__run($source, $class));
    }

    /**
     * A related class the rule cannot resolve statically is skipped: this rule is fatal,
     * so an unprovable case must never fire.
     */
    public static function test_unresolvable_related_class_is_skipped()
    {
        $class = static::__unique_class('MmDynamic');
        $source = static::__class_source(
            $class,
            [],
            'gadgets',
            '$this->' . self::MORPH_MANY . "(\$some_class, 'gadget')"
        );

        static::__assert_count(0, static::__run($source, $class));
    }

    // =====================================================================
    // Pivot morphs - no model owns the pivot table's type column
    // =====================================================================

    public static function test_morph_to_many_is_always_flagged()
    {
        $class = static::__unique_class('Mtm');
        $source = static::__class_source(
            $class,
            [],
            'tags',
            '$this->' . self::MORPH_TO_MANY . '(' . self::REAL_MODEL . ", 'taggable')"
        );

        $violations = static::__run($source, $class);

        static::__assert_count(1, $violations);
        static::__assert_contains('PIVOT TABLE', $violations[0]->message);
    }

    public static function test_morphed_by_many_is_always_flagged()
    {
        $class = static::__unique_class('Mbm');
        $source = static::__class_source(
            $class,
            [],
            'posts',
            '$this->' . self::MORPHED_BY_MANY . '(' . self::REAL_MODEL . ", 'taggable')"
        );

        static::__assert_count(1, static::__run($source, $class));
    }

    // =====================================================================
    // The pair-naming standard
    // =====================================================================

    public static function test_type_column_not_ending_in_type_is_flagged()
    {
        $class = static::__unique_class('BadSuffix');
        $source = static::__class_source(
            $class,
            ['widget_kind'],
            'widget',
            '$this->' . self::MORPH_TO . "('widget', 'widget_kind', 'widget_id')"
        );

        $violations = static::__run($source, $class);

        static::__assert_count(1, $violations, 'Even a DECLARED column must use the _type suffix');
        static::__assert_contains('{relation}_type', $violations[0]->message);
    }

    /**
     * The audit-column pair shape (created_by_type / created_by_id) is the standard shape,
     * not an exception to it - the rule must accept it once declared.
     */
    public static function test_audit_column_pair_shape_is_accepted()
    {
        $class = static::__unique_class('AuditPair');
        $source = static::__class_source(
            $class,
            ['created_by_type'],
            'created_by',
            '$this->' . self::MORPH_TO . "('created_by', 'created_by_type', 'created_by_id')"
        );

        static::__assert_count(0, static::__run($source, $class));
    }

    // =====================================================================
    // What the rule must NOT match
    // =====================================================================

    /**
     * An enum discriminator is `type_id`, never `*_type`, and is not a polymorphic
     * reference at all. A non-morph relation over it must be invisible to this rule.
     */
    public static function test_type_id_enum_column_is_not_matched()
    {
        $class = static::__unique_class('TypeId');
        $source = static::__class_source(
            $class,
            [],
            'kind',
            '$this->belongsTo(' . self::REAL_MODEL . ", 'type_id')"
        );

        static::__assert_count(0, static::__run($source, $class));
    }

    // =====================================================================
    // Exception marker
    // =====================================================================

    public static function test_method_docblock_exception_suppresses()
    {
        $class = static::__unique_class('MethodExc');
        $source = static::__class_source(
            $class,
            [],
            'widget',
            '$this->' . self::MORPH_TO . "('widget', 'widget_type', 'widget_id')",
            "    /**\n     * @" . self::RULE_ID . "-EXCEPTION - external table, discriminator not ours\n     */\n"
        );

        static::__assert_count(0, static::__run($source, $class));
    }

    public static function test_file_level_exception_suppresses()
    {
        $class = static::__unique_class('FileExc');
        $source = "<?php\n// @" . self::RULE_ID . "-EXCEPTION - whole file is an external adapter\n"
            . substr(
                static::__class_source(
                    $class,
                    [],
                    'widget',
                    '$this->' . self::MORPH_TO . "('widget', 'widget_type', 'widget_id')"
                ),
                6
            );

        static::__assert_count(0, static::__run($source, $class));
    }
}
