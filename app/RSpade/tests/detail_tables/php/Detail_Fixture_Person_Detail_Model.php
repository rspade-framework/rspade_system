<?php

namespace App\RSpade\Tests\DetailTables\Php;

use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Model_Abstract;
use App\RSpade\Tests\DetailTables\Php\Detail_Fixture_Party_Model;

/**
 * Test fixture: the PERSON detail for Detail_Fixture_Party_Model. Tables are created at
 * runtime by the detail_tables tests (not a shipped migration).
 */
class Detail_Fixture_Person_Detail_Model extends Rsx_Detail_Model_Abstract
{
    protected $table = 'detail_fixture_person_details';
    protected $fillable = [];

    public static $enums = [];

    protected static $parent_model = Detail_Fixture_Party_Model::class;
}
