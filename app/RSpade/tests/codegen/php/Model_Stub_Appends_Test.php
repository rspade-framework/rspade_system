<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use ReflectionMethod;
use App\RSpade\Core\Database\Model_Lineage_Fingerprint;
use App\RSpade\Core\Database\Model_Stub_ManifestSupport;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Codegen\Php\Appends_Fixture_Model;
use App\RSpade\Tests\Codegen\Php\No_Appends_Fixture_Model;
use App\RSpade\Tests\Codegen\Php\Split_Fixture_Model;

/**
 * The JS model stub DECLARES a model's derived properties ($appends).
 *
 * Without this the property arrives on the fetched record at runtime with no declared surface
 * on Base_*_Model.js at all - no autocomplete, and nothing telling a reader of the stub that it
 * exists. The stub is generated from columns, enums, relationships and constants, and a derived
 * property is none of those.
 *
 * Two things are asserted and one is asserted NOT to happen: the names are declared as JSDoc
 * @property lines, field_length() keeps answering null for them (they are not columns and never
 * become varchar lengths), and a model with no $appends gains nothing.
 */
class Model_Stub_Appends_Test extends Rsx_Test_Abstract
{
    /**
     * The generator is private - it is an implementation of the manifest build, not an API - so
     * the test reaches it the same way the build does, with a manifest_data array carrying no
     * columns for the fixture (it has no table, which is also what proves the appended names do
     * not arrive through the column path).
     */
    private static function __generate(string $fqcn, string $class_name, string $stub_class_name): string
    {
        $method = new ReflectionMethod(Model_Stub_ManifestSupport::class, '_generate_model_stub_content');
        $method->setAccessible(true);

        return $method->invoke(null, $fqcn, $class_name, $stub_class_name, ['data' => ['models' => []]]);
    }

    // CODEGEN-APPENDS-DECLARED: each $appends entry is named on the stub, in declaration order.
    public static function test_the_stub_declares_each_appended_property()
    {
        $content = static::__generate(
            Appends_Fixture_Model::class,
            'Appends_Fixture_Model',
            'Base_Appends_Fixture_Model'
        );

        static::__assert_contains(
            '@property {*} display_id - derived (appended by the PHP model); read-only',
            $content,
            'the first appended name is declared'
        );
        static::__assert_contains(
            '@property {*} is_flagged - derived (appended by the PHP model); read-only',
            $content,
            'and the second'
        );

        $display_at = strpos($content, 'display_id');
        $flagged_at = strpos($content, 'is_flagged');
        static::__assert_true(
            $display_at !== false && $flagged_at !== false && $display_at < $flagged_at,
            'in the order the model declares them'
        );

        // Inside the class docblock, which is what an editor reads - not loose in the body.
        $docblock = substr($content, 0, strpos($content, '*/'));
        static::__assert_contains('display_id', $docblock, 'the declaration lives in the class docblock');
        static::__assert_contains('@Instantiatable', $content, 'and the existing markers survive');
    }

    // CODEGEN-APPENDS-NOT-A-COLUMN: a derived property is not a column, so field_length() answers
    // null for it exactly as it does for any non-varchar - the client can never hold a length
    // for a value with no column behind it.
    public static function test_an_appended_property_never_becomes_a_field_length()
    {
        $content = static::__generate(
            Appends_Fixture_Model::class,
            'Appends_Fixture_Model',
            'Base_Appends_Fixture_Model'
        );

        static::__assert_contains('static field_length(column)', $content, 'the stub still declares field_length');
        static::__assert_false(
            str_contains($content, '"display_id":'),
            'and the derived name appears in no length map'
        );
    }

    // CODEGEN-APPENDS-NONE: a model that declares no derived properties gains no lines. The
    // feature is opt-in by declaration and adds nothing to a stub that has nothing to declare.
    public static function test_a_model_without_appends_declares_nothing()
    {
        $content = static::__generate(
            No_Appends_Fixture_Model::class,
            'No_Appends_Fixture_Model',
            'Base_No_Appends_Fixture_Model'
        );

        static::__assert_false(
            str_contains($content, '@property'),
            'no @property line is emitted for a model with no $appends'
        );
    }

    // CODEGEN-APPENDS-REAL: the framework model that actually uses the pattern - proof the build
    // this test runs inside produces the declaration, not just the generator in isolation.
    public static function test_the_attachment_model_declares_its_four_derived_properties()
    {
        $content = static::__generate(
            File_Attachment_Model::class,
            'File_Attachment_Model',
            'Base_File_Attachment_Model'
        );

        foreach (['is_image', 'is_video', 'is_document', 'can_open_inline'] as $name) {
            static::__assert_contains(
                "@property {*} {$name} - derived (appended by the PHP model); read-only",
                $content,
                "the stub declares {$name}"
            );
        }
    }

    // =====================================================================
    // A SPLIT model: the members live on the base
    // =====================================================================

    // CODEGEN-SPLIT-REFLECTS-BASE: a framework model carries every member on an abstract base
    // and ships a three-line concrete an application replaces. The stub is generated for the
    // CONCRETE, so it has to reflect what the BASE declares - the appended property, the
    // constants, the field_length table - or the split would silently empty every stub in the
    // framework.
    public static function test_the_stub_of_a_split_model_declares_the_bases_members()
    {
        $content = static::__generate(
            Split_Fixture_Model::class,
            'Split_Fixture_Model',
            'Base_Split_Fixture_Model'
        );

        static::__assert_contains(
            '@property {*} base_display_id - derived (appended by the PHP model); read-only',
            $content,
            'the appended property declared on the base reaches the concrete\'s stub'
        );
        static::__assert_contains(
            'SPLIT_FIXTURE_STATE_OPEN',
            $content,
            'and so do the constants the base declares'
        );
        static::__assert_contains(
            'static field_length(column)',
            $content,
            'and the stub is otherwise a normal one'
        );
    }

    // CODEGEN-SPLIT-STALENESS-KEY: the generator decides whether to rewrite a stub from a
    // staleness key, and a key computed from the CONCRETE file alone cannot move when the base
    // moves - so a renamed constant would keep its old name in the browser with nothing
    // reporting it. The key covers the whole lineage, up to but excluding Rsx_Model_Abstract.
    public static function test_the_staleness_key_covers_the_whole_lineage()
    {
        $manifest_data = Manifest::get_full_manifest();

        $files = Model_Lineage_Fingerprint::lineage_files('Split_Fixture_Model', $manifest_data);

        static::__assert_contains(
            'Split_Fixture_Model.php',
            implode(' ', $files),
            'the concrete is in the key'
        );
        static::__assert_contains(
            'Split_Fixture_Model_Abstract.php',
            implode(' ', $files),
            'and so is the base whose members the stub actually declares'
        );

        foreach ($files as $file) {
            static::__assert_false(
                str_contains($file, 'Rsx_Model_Abstract.php'),
                'the walk stops before the framework base machinery: ' . $file
            );
        }

        // The key is a function of those files' content hashes, so a different lineage is a
        // different key - which is the whole property the generator relies on.
        static::__assert_not_equals(
            Model_Lineage_Fingerprint::lineage_hash('Appends_Fixture_Model', $manifest_data),
            Model_Lineage_Fingerprint::lineage_hash('Split_Fixture_Model', $manifest_data),
            'two models with different lineages have different keys'
        );
    }
}
