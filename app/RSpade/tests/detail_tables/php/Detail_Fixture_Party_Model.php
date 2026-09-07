<?php

namespace App\RSpade\Tests\DetailTables\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\DetailTables\Php\Detail_Fixture_Company_Detail_Model;
use App\RSpade\Tests\DetailTables\Php\Detail_Fixture_Person_Detail_Model;

/**
 * Test fixture: a typed base model exercising Class-Table Inheritance. PERSON and COMPANY
 * have detail tables; GROUP is an absent-detail type (all fields universal). Tables are
 * created at runtime by the detail_tables tests (not a shipped migration).
 */
class Detail_Fixture_Party_Model extends Rsx_Model_Abstract
{
    protected $table = 'detail_fixture_parties';
    protected $fillable = [];

    const TYPE_PERSON = 1;
    const TYPE_COMPANY = 2;
    const TYPE_GROUP = 3;

    public static $enums = [
        'type_id' => [
            1 => ['constant' => 'TYPE_PERSON', 'label' => 'Person'],
            2 => ['constant' => 'TYPE_COMPANY', 'label' => 'Company'],
            3 => ['constant' => 'TYPE_GROUP', 'label' => 'Group'],
        ],
    ];

    public static $detail_tables = [
        'type_id' => [
            self::TYPE_PERSON => Detail_Fixture_Person_Detail_Model::class,
            self::TYPE_COMPANY => Detail_Fixture_Company_Detail_Model::class,
            // TYPE_GROUP absent => no detail row.
        ],
    ];
}
