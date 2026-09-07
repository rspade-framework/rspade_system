<?php

namespace App\RSpade\Tests\DetailTables\Php;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Table;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\DetailTables\Php\Detail_Fixture_Party_Model;
use App\RSpade\Tests\DetailTables\Php\Detail_Fixture_Person_Detail_Model;

/**
 * DT-30..DT-32: the CTI write path - the auto-vivifying detail accessor (returns the
 * persisted row, else a new unsaved instance with the parent FK pre-set), save() does no
 * magic, the vivified blank is not serialized, the discriminator is immutable, and deleting
 * the base cascades the detail. Uses runtime fixture tables.
 */
class Detail_Write_Test extends Rsx_Test_Abstract
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

    private static function __new_person_base(): Detail_Fixture_Party_Model
    {
        $party = new Detail_Fixture_Party_Model();
        $party->type_id = Detail_Fixture_Party_Model::TYPE_PERSON;
        $party->name = 'Test Person';
        $party->save();

        return $party;
    }

    // DT-30
    public static function test_accessor_vivifies_new_detail_with_fk_preset()
    {
        $party = static::__new_person_base();
        $detail = $party->detail_fixture_person;

        static::__assert_instance_of(Detail_Fixture_Person_Detail_Model::class, $detail);
        static::__assert_false($detail->exists, 'vivified detail is a new unsaved instance');
        static::__assert_equals($party->id, $detail->detail_fixture_party_id, 'parent FK is pre-set on the vivified instance');

        $detail->first_name = 'Jane';
        $detail->save();

        static::__assert_true($detail->exists, 'filling and saving the vivified instance persists it');
        static::__assert_not_null(Detail_Fixture_Person_Detail_Model::for_parent($party->id));
    }

    public static function test_save_does_not_auto_create_detail()
    {
        $party = static::__new_person_base();
        static::__assert_null(Detail_Fixture_Person_Detail_Model::for_parent($party->id), 'base save() creates no detail row on its own');
    }

    public static function test_repeated_access_returns_same_instance()
    {
        $party = static::__new_person_base();
        $a = $party->detail_fixture_person;
        $b = $party->detail_fixture_person;
        static::__assert_true($a === $b, 'repeated accessor reads return the same vivified instance');
    }

    public static function test_accessor_returns_persisted_when_present()
    {
        $party = static::__new_person_base();
        $d = $party->detail_fixture_person;
        $d->first_name = 'Persisted';
        $d->save();

        $fresh = Detail_Fixture_Party_Model::find($party->id);
        $loaded = $fresh->detail_fixture_person;
        static::__assert_true($loaded->exists, 'accessor returns the persisted row when one exists');
        static::__assert_equals('Persisted', $loaded->first_name);
    }

    public static function test_toarray_omits_unsaved_vivified_detail()
    {
        $party = static::__new_person_base();
        $d = $party->detail_fixture_person;     // vivify (in memory only)
        $d->first_name = 'NotSaved';

        $array = $party->toArray();
        static::__assert_false(isset($array['__details']['detail_fixture_person']), 'an unsaved vivified detail is not serialized');
    }

    // DT-21 (still holds under vivify)
    public static function test_wrong_type_accessor_still_throws()
    {
        $party = static::__new_person_base();
        static::__assert_throws(RuntimeException::class, function () use ($party) {
            return $party->detail_fixture_company;
        }, 'not valid');
    }

    // DT-32 (immutability)
    public static function test_discriminator_is_immutable()
    {
        $party = static::__new_person_base();
        $party->type_id = Detail_Fixture_Party_Model::TYPE_COMPANY;

        static::__assert_throws(RuntimeException::class, function () use ($party) {
            return $party->save();
        }, 'immutable');
    }

    // DT-32 (cascade)
    public static function test_delete_base_cascades_detail()
    {
        $party = static::__new_person_base();
        $d = $party->detail_fixture_person;
        $d->first_name = 'Gone';
        $d->save();
        $party_id = $party->id;

        static::__assert_not_null(Detail_Fixture_Person_Detail_Model::for_parent($party_id));

        Detail_Fixture_Party_Model::find($party_id)->delete();

        static::__assert_null(Detail_Fixture_Person_Detail_Model::for_parent($party_id), 'deleting the base cascade-deletes its detail');
    }
}
