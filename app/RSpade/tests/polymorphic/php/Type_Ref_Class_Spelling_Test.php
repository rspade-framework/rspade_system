<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Polymorphic\Php;

use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A type ref identifies a model by its SHORT class name, and every public lookup accepts
 * either spelling of that name - the short one, or the fully qualified one `Model::class`
 * evaluates to - and answers the same. The stored row carries the short name whichever
 * spelling asked. From a downstream field report: `class_to_id(File_Attachment_Model::class)`
 * missed the short-keyed map and died in the auto-create path on a class "not found in the
 * manifest", which punished the one spelling an IDE completes and a rename refactor follows.
 *
 * The property worth having: because only the last segment is kept, a name written against a
 * namespace the model has since LEFT still resolves.
 */
class Type_Ref_Class_Spelling_Test extends Rsx_Test_Abstract
{
    public static function test_every_spelling_resolves_to_the_same_id()
    {
        $expected = Type_Ref_Registry::class_to_id('Site_Model');

        $spellings = [
            'Site_Model',
            Site_Model::class,
            '\\' . Site_Model::class,
            'Some\\Former\\Namespace\\Site_Model',
        ];

        foreach ($spellings as $spelling) {
            static::__assert_equals($expected, Type_Ref_Registry::class_to_id($spelling), 'class_to_id: ' . $spelling);
            static::__assert_equals($expected, Type_Ref_Registry::find_id_by_class_name($spelling), 'find_id_by_class_name: ' . $spelling);
            static::__assert_true(Type_Ref_Registry::has_class($spelling), 'has_class: ' . $spelling);
            static::__assert_true(Type_Ref_Registry::class_resolves($spelling), 'class_resolves: ' . $spelling);
        }

        static::__assert_equals('Site_Model', Type_Ref_Registry::id_to_class($expected), 'the registry answers in its own vocabulary');
    }

    public static function test_a_namespaced_name_never_reaches_the_table()
    {
        $rows = \Illuminate\Support\Facades\DB::table('_type_refs')->where('class_name', 'like', '%\\\\%')->count();

        static::__assert_equals(0, $rows, 'no stored type ref carries a namespace');
    }

    public static function test_an_unknown_class_still_throws_whatever_the_spelling()
    {
        $threw = false;

        try {
            Type_Ref_Registry::class_to_id('Some\\Namespace\\Nonexistent_Poly_Model');
        } catch (\Throwable $e) {
            $threw = true;
        }

        static::__assert_true($threw, 'a genuinely unknown class is refused');
    }
}
