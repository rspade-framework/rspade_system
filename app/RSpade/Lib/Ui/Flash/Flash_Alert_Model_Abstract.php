<?php

namespace App\RSpade\Lib\Ui\Flash;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Flash_Alert_Model_Abstract - one queued flash alert, held against the session until
 * the next page renders it.
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's Flash_Alert_Model lives here;
 * `Flash_Alert_Model.php` beside it is a shell an application replaces by declaring
 * `class Flash_Alert_Model extends Flash_Alert_Model_Abstract` under rsx/models/ - so an
 * application's customization is only the members it actually changes, and a frozen
 * clone can never miss the members the framework adds next.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _flash_alerts
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property int $id
 * @property bool $is_portal
 * @property string $message
 * @property int $session_id
 * @property int $type_id enum: TYPE_SUCCESS, TYPE_ERROR, TYPE_INFO, TYPE_WARNING (also $type_id__label and the other $type_id__* properties)
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
 *
 * @property-read string $type_id__label
 * @property-read string $type_id__constant
 *
 * @method static array type_id__enum() Get all enum definitions with full metadata
 * @method static array type_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array type_id__enum_labels() Get simple id => label map
 * @method static array type_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
abstract class Flash_Alert_Model_Abstract extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const TYPE_SUCCESS = 1;
    const TYPE_ERROR = 2;
    const TYPE_INFO = 3;
    const TYPE_WARNING = 4;

    public static $columns = [
        'type_id' => ['enum' => [
            1 => ['constant' => 'TYPE_SUCCESS', 'label' => 'Success'],
            2 => ['constant' => 'TYPE_ERROR', 'label' => 'Error'],
            3 => ['constant' => 'TYPE_INFO', 'label' => 'Info'],
            4 => ['constant' => 'TYPE_WARNING', 'label' => 'Warning'],
        ]],
    ];

    public static $rel = [];

    protected $table = '_flash_alerts';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the type as a string for client consumption
     *
     * @return string 'success'|'error'|'info'|'warning'
     */
    public function get_type_string(): string
    {
        $type_map = [
            self::TYPE_SUCCESS => 'success',
            self::TYPE_ERROR => 'error',
            self::TYPE_INFO => 'info',
            self::TYPE_WARNING => 'warning',
        ];

        return $type_map[$this->type_id] ?? 'info';
    }
}