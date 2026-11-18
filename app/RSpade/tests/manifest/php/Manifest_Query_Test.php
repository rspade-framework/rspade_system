<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE MANIFEST'S CLASS-HIERARCHY QUERIES, over a fixture hierarchy of known shape.
 *
 * Task, realtime, model and bundle discovery all ask the index "who extends X", so a wrong
 * answer silently drops a handler. Pinned here:
 *
 *   - php_is_subclass_of() walks the whole chain, accepts a simple name or an FQCN (with or
 *     without the leading backslash) on either side, is false for the class itself, and
 *     answers about PARENT classes only - an implemented interface or a used trait is not one;
 *   - an interface declared in a scanned file is in the class map as an interface, autoloads,
 *     records no parent, and has no descendants;
 *   - php_get_extending() answers every CONCRETE descendant (not only direct children),
 *     keyed by simple name, each with its file record; php_get_subclasses_of() and
 *     php_class_records_extending() include abstract descendants when asked;
 *   - the file record carries the interfaces and traits by FQCN, and a trait's public static
 *     method is part of the using class's static method map;
 *   - the JS twins (js_is_subclass_of(), js_get_extending()) answer the JS class chain, which
 *     has no abstract concept.
 *
 * Fixtures (indexed while the suite runs): Manifest_Query_Fixture_Root_Abstract <-
 * Manifest_Query_Fixture_Mid_Abstract <- Manifest_Query_Fixture_Leaf (implements
 * Manifest_Query_Fixture_Contract, an interface extending the built-in JsonSerializable; uses
 * Manifest_Query_Fixture_Behaviour), and
 * Manifest_Query_Fixture_Sibling directly under the root; JS Manifest_Query_Fixture_Js_Root
 * <- _Js_Mid <- _Js_Leaf.
 */
class Manifest_Query_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const NS = 'App\\RSpade\\Tests\\Manifest\\Php\\';

    private const ROOT = 'Manifest_Query_Fixture_Root_Abstract';
    private const MID = 'Manifest_Query_Fixture_Mid_Abstract';
    private const LEAF = 'Manifest_Query_Fixture_Leaf';
    private const SIBLING = 'Manifest_Query_Fixture_Sibling';
    private const CONTRACT = 'Manifest_Query_Fixture_Contract';
    private const BEHAVIOUR = 'Manifest_Query_Fixture_Behaviour';

    private const JS_ROOT = 'Manifest_Query_Fixture_Js_Root';
    private const JS_MID = 'Manifest_Query_Fixture_Js_Mid';
    private const JS_LEAF = 'Manifest_Query_Fixture_Js_Leaf';

    private static function __sorted_keys(array $map): array
    {
        $keys = array_keys($map);
        sort($keys);

        return $keys;
    }

    private static function __sorted(array $list): array
    {
        sort($list);

        return $list;
    }

    public static function test_php_is_subclass_of_walks_the_whole_chain()
    {
        static::__assert_true(Manifest::php_is_subclass_of(self::LEAF, self::MID), 'direct parent');
        static::__assert_true(Manifest::php_is_subclass_of(self::LEAF, self::ROOT), 'grandparent');
        static::__assert_true(Manifest::php_is_subclass_of(self::SIBLING, self::ROOT));

        static::__assert_false(Manifest::php_is_subclass_of(self::SIBLING, self::MID), 'a sibling branch is not a descendant');
        static::__assert_false(Manifest::php_is_subclass_of(self::ROOT, self::LEAF), 'an ancestor is not a subclass of its descendant');
        static::__assert_false(Manifest::php_is_subclass_of(self::LEAF, self::LEAF), 'a class is not its own subclass');
        static::__assert_false(Manifest::php_is_subclass_of('Manifest_Query_Fixture_No_Such_Class', self::ROOT), 'an unknown class is a subclass of nothing');
    }

    public static function test_php_is_subclass_of_normalizes_an_fqcn_on_either_side()
    {
        foreach ([self::NS . self::LEAF, '\\' . self::NS . self::LEAF, self::LEAF] as $sub) {
            foreach ([self::NS . self::ROOT, '\\' . self::NS . self::ROOT, self::ROOT] as $super) {
                static::__assert_true(Manifest::php_is_subclass_of($sub, $super), "{$sub} extends {$super}");
            }
        }
    }

    public static function test_an_interface_or_trait_is_not_a_parent()
    {
        static::__assert_false(Manifest::php_is_subclass_of(self::LEAF, self::CONTRACT), 'implementing an interface is not extending it');
        static::__assert_false(Manifest::php_is_subclass_of(self::LEAF, 'JsonSerializable'), 'nor is implementing the interface it extends');
        static::__assert_false(Manifest::php_is_subclass_of(self::LEAF, self::BEHAVIOUR), 'using a trait is not extending it');

        $record = Manifest::php_get_metadata_by_class(self::LEAF);

        static::__assert_equals(
            static::__sorted([self::NS . self::CONTRACT, 'JsonSerializable']),
            static::__sorted($record['implements'] ?? []),
            'the record names every interface by FQCN'
        );
        static::__assert_equals([self::NS . self::BEHAVIOUR], $record['traits'] ?? null, 'the record names the trait by FQCN');
        static::__assert_true(
            isset($record['public_static_methods']['fixture_behaviour']),
            "the trait's public static method is in the using class's static method map"
        );
        static::__assert_false(isset(Manifest::php_get_metadata_by_class(self::SIBLING)['implements']), 'a class with no interface records none');
    }

    public static function test_an_interface_in_a_scanned_file_is_usable()
    {
        $class_record = Manifest::php_class_metadata(self::CONTRACT);

        static::__assert_not_null($class_record, 'the interface is in the class map');
        static::__assert_equals(self::NS . self::CONTRACT, $class_record['fqcn']);
        static::__assert_true($class_record['interface'] ?? false, 'the class map marks it an interface');
        static::__assert_false(isset($class_record['extends']), 'the interface it extends is not recorded as a parent');

        $record = Manifest::php_get_metadata_by_class(self::CONTRACT);
        static::__assert_true($record['is_interface'] ?? false, 'the file record marks it an interface');
        static::__assert_false(isset($record['public_static_methods']), 'an interface indexes no methods');

        static::__assert_true(interface_exists(self::NS . self::CONTRACT), 'it autoloads by FQCN');
        static::__assert_equals('leaf', (self::NS . self::LEAF)::fixture_contract_name(), 'the implementing class loads and runs');
        static::__assert_equals([], Manifest::php_get_extending(self::CONTRACT), 'an interface has no descendants');
        static::__assert_false(Manifest::php_is_subclass_of(self::CONTRACT, 'JsonSerializable'), 'extending an interface is not a parent class');
    }

    public static function test_php_get_extending_answers_every_concrete_descendant()
    {
        $from_root = Manifest::php_get_extending(self::ROOT);

        static::__assert_equals(
            static::__sorted([self::LEAF, self::SIBLING]),
            static::__sorted_keys($from_root),
            'grandchildren included, the abstract middle class left out'
        );

        foreach ($from_root as $class => $record) {
            static::__assert_equals($class, $record['class'] ?? null, "{$class}: keyed by simple name, valued by its file record");
            static::__assert_true(str_ends_with($record['file'] ?? '', '/' . $class . '.php'), "{$class}: the record carries its file");
        }

        static::__assert_equals([self::LEAF], array_keys(Manifest::php_get_extending(self::MID)));
        static::__assert_equals([self::LEAF], array_keys(Manifest::php_get_extending(self::NS . self::MID)), 'an FQCN parent is normalized');
        static::__assert_equals([], Manifest::php_get_extending(self::LEAF), 'a leaf has no descendants');
    }

    public static function test_abstract_descendants_are_included_only_when_asked()
    {
        static::__assert_equals(
            static::__sorted([self::LEAF, self::SIBLING]),
            static::__sorted(Manifest::php_get_subclasses_of(self::ROOT))
        );
        static::__assert_equals(
            static::__sorted([self::LEAF, self::MID, self::SIBLING]),
            static::__sorted(Manifest::php_get_subclasses_of(self::ROOT, concrete_only: false))
        );
        static::__assert_equals(
            static::__sorted([self::LEAF, self::MID, self::SIBLING]),
            static::__sorted_keys(Manifest::php_class_records_extending(self::ROOT, concrete_only: false))
        );
        static::__assert_equals(
            static::__sorted([self::LEAF, self::SIBLING]),
            static::__sorted_keys(Manifest::php_class_records_extending(self::ROOT))
        );

        static::__assert_true(Manifest::php_is_abstract(self::ROOT));
        static::__assert_true(Manifest::php_is_abstract(self::NS . self::MID));
        static::__assert_false(Manifest::php_is_abstract(self::LEAF));
    }

    public static function test_php_get_lineage_runs_from_parent_to_root()
    {
        static::__assert_equals([self::MID, self::ROOT], Manifest::php_get_lineage(self::LEAF));
        static::__assert_equals([self::MID, self::ROOT], Manifest::php_get_lineage('\\' . self::NS . self::LEAF));
        static::__assert_equals([], Manifest::php_get_lineage(self::ROOT));
    }

    public static function test_js_is_subclass_of_walks_the_js_chain()
    {
        static::__assert_true(Manifest::js_is_subclass_of(self::JS_LEAF, self::JS_MID));
        static::__assert_true(Manifest::js_is_subclass_of(self::JS_LEAF, self::JS_ROOT), 'multi-level');
        static::__assert_true(Manifest::js_is_subclass_of('Some\\Namespace\\' . self::JS_LEAF, self::JS_ROOT), 'a namespaced spelling is reduced to its last segment');

        static::__assert_false(Manifest::js_is_subclass_of(self::JS_ROOT, self::JS_LEAF));
        static::__assert_false(Manifest::js_is_subclass_of(self::JS_LEAF, self::JS_LEAF));
        static::__assert_false(Manifest::js_is_subclass_of(self::LEAF, self::ROOT), 'a PHP class is not in the JS chain');
        static::__assert_false(Manifest::js_is_subclass_of('Manifest_Query_Fixture_No_Such_Js', self::JS_ROOT));
    }

    public static function test_js_get_extending_answers_every_descendant()
    {
        $from_root = Manifest::js_get_extending(self::JS_ROOT);

        static::__assert_equals(static::__sorted([self::JS_LEAF, self::JS_MID]), static::__sorted_keys($from_root), 'JS has no abstract filter');

        foreach ($from_root as $class => $record) {
            static::__assert_true(str_ends_with($record['file'] ?? '', '/' . $class . '.js'), "{$class}: the record carries its file");
        }

        static::__assert_equals([self::JS_LEAF], array_keys(Manifest::js_get_extending(self::JS_MID)));
        static::__assert_equals([], Manifest::js_get_extending(self::JS_LEAF));
        static::__assert_equals([self::JS_MID, self::JS_ROOT], Manifest::js_get_lineage(self::JS_LEAF));
    }
}
