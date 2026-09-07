<?php

namespace App\RSpade\Tests\DetailTables\Php;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Table;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\DetailTables\Php\Detail_Fixture_Party_Model;
use App\RSpade\Tests\DetailTables\Php\Detail_Fixture_Person_Detail_Model;

/**
 * DT-20..DT-22: the CTI read path - eager-embed into toArray(), the magic detail accessor,
 * throw-on-wrong-type, absent-detail types, isset() semantics, and preload_details() N+1
 * avoidance. Uses runtime fixture tables (created in setup, dropped in teardown).
 */
class Detail_Read_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__drop_tables();

        DB::statement("CREATE TABLE detail_fixture_parties (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type_id BIGINT NOT NULL,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        Rsx_Detail_Table::create('detail_fixture_person_details', 'detail_fixture_parties', [
            ['name' => 'first_name', 'type' => 'VARCHAR(255)'],
            ['name' => 'last_name', 'type' => 'VARCHAR(255)'],
        ]);

        Rsx_Detail_Table::create('detail_fixture_company_details', 'detail_fixture_parties', [
            ['name' => 'legal_name', 'type' => 'VARCHAR(255)'],
            ['name' => 'tax_identifier', 'type' => 'VARCHAR(255)'],
        ]);
    }

    public static function teardown()
    {
        static::__drop_tables();
    }

    private static function __drop_tables()
    {
        DB::statement("DROP TABLE IF EXISTS detail_fixture_person_details");
        DB::statement("DROP TABLE IF EXISTS detail_fixture_company_details");
        DB::statement("DROP TABLE IF EXISTS detail_fixture_parties");
    }

    private static function __make_person(string $first = 'Jane', string $last = 'Doe'): Detail_Fixture_Party_Model
    {
        $party = new Detail_Fixture_Party_Model();
        $party->type_id = Detail_Fixture_Party_Model::TYPE_PERSON;
        $party->name = trim("{$first} {$last}");
        $party->save();

        $detail = new Detail_Fixture_Person_Detail_Model();
        $detail->detail_fixture_party_id = $party->id;
        $detail->first_name = $first;
        $detail->last_name = $last;
        $detail->save();

        return $party;
    }

    // DT-20
    public static function test_toarray_embeds_active_detail_only()
    {
        $party = static::__make_person('Jane', 'Doe');
        $fresh = Detail_Fixture_Party_Model::find($party->id);
        $array = $fresh->toArray();

        static::__assert_array_has_key('detail_fixture_person', $array['__details'] ?? [], 'active person detail embedded under __details');
        static::__assert_equals('Jane', $array['__details']['detail_fixture_person']['first_name']);
        static::__assert_equals('Detail_Fixture_Person_Detail_Model', $array['__details']['detail_fixture_person']['__MODEL'], 'detail carries __MODEL for client hydration');
        static::__assert_false(isset($array['__details']['detail_fixture_company']), 'inactive detail not embedded');
    }

    // DT-20 (accessor)
    public static function test_magic_accessor_returns_detail()
    {
        $party = static::__make_person('Ann', 'Smith');
        $fresh = Detail_Fixture_Party_Model::find($party->id);
        $detail = $fresh->detail_fixture_person;

        static::__assert_instance_of(Detail_Fixture_Person_Detail_Model::class, $detail);
        static::__assert_equals('Ann', $detail->first_name);
    }

    // DT-21
    public static function test_wrong_type_accessor_throws()
    {
        $party = static::__make_person('Bob', 'Lee');
        $fresh = Detail_Fixture_Party_Model::find($party->id);

        static::__assert_throws(RuntimeException::class, function () use ($fresh) {
            return $fresh->detail_fixture_company;
        }, 'not valid');
    }

    // DT-20 (absent type)
    public static function test_absent_detail_type_embeds_nothing()
    {
        $group = new Detail_Fixture_Party_Model();
        $group->type_id = Detail_Fixture_Party_Model::TYPE_GROUP;
        $group->name = 'A Group';
        $group->save();

        $fresh = Detail_Fixture_Party_Model::find($group->id);
        $array = $fresh->toArray();

        static::__assert_false(isset($array['__details']), 'group embeds no details at all');
        static::__assert_null($fresh->active_detail(), 'absent-detail type has no active detail');
    }

    public static function test_isset_semantics_do_not_throw()
    {
        $party = static::__make_person('Cy', 'Ng');
        $fresh = Detail_Fixture_Party_Model::find($party->id);

        static::__assert_true(isset($fresh->detail_fixture_person), 'active accessor is set');
        static::__assert_false(isset($fresh->detail_fixture_company), 'inactive accessor is not set (no throw)');
        static::__assert_null($fresh->detail_fixture_company ?? null, 'null-coalesce on wrong-type yields null, not a throw');
    }

    // DT-22
    public static function test_preload_avoids_n_plus_1()
    {
        $ids = [];
        foreach (['A', 'B', 'C'] as $n) {
            $ids[] = static::__make_person($n, 'Person')->id;
        }

        $collection = Detail_Fixture_Party_Model::whereIn('id', $ids)->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Detail_Fixture_Party_Model::preload_details($collection);
        $after_preload = count(DB::getQueryLog());
        foreach ($collection as $record) {
            $record->toArray();
        }
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        static::__assert_equals(1, $after_preload, 'preload issues exactly one detail query for the whole batch');
        static::__assert_equals(1, $total, 'toArray() after preload issues no additional detail queries');
    }
}
