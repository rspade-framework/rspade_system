<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Database\Php\Missing_Property_Fixture_Note_Detail_Model;

/**
 * Fixture for Model_Missing_Property_Test: one model carrying every kind of magic read a
 * missing-property refusal must leave alone - enum properties with a custom one
 * (`role_id__can_admin_roles`), a system column (`_sys_note`), a never_export column, an
 * appended property, a relationship and a detail accessor.
 *
 * The tables are created and dropped by Model_Missing_Property_Test.
 */
class Missing_Property_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'missing_property_fixtures';

    protected $fillable = [];

    public $timestamps = false;

    const ROLE_ADMIN = 1;
    const ROLE_MEMBER = 2;

    const KIND_NOTE = 1;
    const KIND_PLAIN = 2;

    public static $columns = [
        'role_id' => ['enum' => [
            1 => ['constant' => 'ROLE_ADMIN', 'label' => 'Admin', 'can_admin_roles' => true],
            2 => ['constant' => 'ROLE_MEMBER', 'label' => 'Member'],
        ]],
        'kind_id' => ['enum' => [
            1 => ['constant' => 'KIND_NOTE', 'label' => 'Note'],
            2 => ['constant' => 'KIND_PLAIN', 'label' => 'Plain'],
        ]],
        'secret_token' => ['never_export' => true],
    ];

    public static $detail_tables = [
        'kind_id' => [
            self::KIND_NOTE => Missing_Property_Fixture_Note_Detail_Model::class,
        ],
    ];

    protected $appends = ['display_name'];

    public function display_name(): string
    {
        return 'Fixture ' . $this->name;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->display_name();
    }

    #[Relationship]
    public function parent()
    {
        return $this->belongsTo(Missing_Property_Fixture_Model::class, 'parent_id');
    }
}
