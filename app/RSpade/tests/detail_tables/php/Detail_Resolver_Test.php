<?php

namespace App\RSpade\Tests\DetailTables\Php;

use App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * DT-10: Detail_Tables_Resolver interprets a $detail_tables map - discriminator column,
 * value->class lookup (null for absent values), accessor-name derivation, deduped
 * accessors, and the value->accessor map used by the JS stub. Pure logic - no database.
 */
class Detail_Resolver_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // A representative map: PERSON(1) and COMPANY(2)+ORG(3) share a detail; GROUP(4) absent.
    private static function __map(): array
    {
        return [
            'type_id' => [
                1 => 'Rsx\\Models\\Party_Person_Detail_Model',
                2 => 'Rsx\\Models\\Party_Company_Detail_Model',
                3 => 'Rsx\\Models\\Party_Company_Detail_Model',
            ],
        ];
    }

    public static function test_discriminator_column()
    {
        static::__assert_equals('type_id', Detail_Tables_Resolver::discriminator_column(static::__map()));
        static::__assert_null(Detail_Tables_Resolver::discriminator_column(null), 'null map -> no discriminator');
        static::__assert_null(Detail_Tables_Resolver::discriminator_column([]), 'empty map -> no discriminator');
    }

    public static function test_class_for_value_and_absent()
    {
        $map = static::__map();
        static::__assert_equals('Rsx\\Models\\Party_Person_Detail_Model', Detail_Tables_Resolver::class_for_value($map, 1));
        static::__assert_equals('Rsx\\Models\\Party_Company_Detail_Model', Detail_Tables_Resolver::class_for_value($map, 3), 'multiple values may share one detail');
        static::__assert_null(Detail_Tables_Resolver::class_for_value($map, 4), 'absent value (GROUP) has no detail');
    }

    public static function test_accessor_name_derivation()
    {
        static::__assert_equals('party_person', Detail_Tables_Resolver::accessor_name('Rsx\\Models\\Party_Person_Detail_Model'));
        static::__assert_equals('party_company', Detail_Tables_Resolver::accessor_name('Party_Company_Detail_Model'));
        static::__assert_equals('widget', Detail_Tables_Resolver::accessor_name('Widget_Model'), 'falls back to stripping _Model');
    }

    public static function test_accessors_deduped()
    {
        $accessors = Detail_Tables_Resolver::accessors(static::__map());
        // person + company (company value 2 and 3 collapse to one accessor)
        static::__assert_count(2, $accessors, 'shared detail collapses to one accessor');
        static::__assert_array_has_key('party_person', $accessors);
        static::__assert_array_has_key('party_company', $accessors);
    }

    public static function test_value_to_accessor_map()
    {
        $v2a = Detail_Tables_Resolver::value_to_accessor(static::__map());
        static::__assert_equals('party_person', $v2a[1]);
        static::__assert_equals('party_company', $v2a[2]);
        static::__assert_equals('party_company', $v2a[3]);
        static::__assert_false(isset($v2a[4]), 'absent value not in the map');
    }

    public static function test_detail_classes_distinct()
    {
        static::__assert_count(2, Detail_Tables_Resolver::detail_classes(static::__map()), 'distinct detail classes');
    }
}
