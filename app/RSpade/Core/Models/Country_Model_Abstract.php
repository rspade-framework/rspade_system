<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Region_Model;

/**
 * RSX:USE
 * Country_Model_Abstract - ISO 3166-1 country data
 *
 * Represents countries with their ISO codes and names.
 * Data populated from sokil/php-isocodes via rsx:seed:geographic-data command.
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's Country_Model lives here;
 * `Country_Model.php` beside it is a shell an application replaces by declaring
 * `class Country_Model extends Country_Model_Abstract` under rsx/models/ - so an
 * application's customization is only the members it actually changes, and a frozen
 * clone can never miss the members the framework adds next.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: countries
 *
 * @property int $id
 * @property string $alpha2
 * @property string $alpha3
 * @property string $numeric
 * @property string $name
 * @property string $common_name
 * @property int $enabled
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
abstract class Country_Model_Abstract extends Rsx_Model_Abstract
{
    public static $enums = [];

    protected $table = 'countries';

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Get all regions (subdivisions) for this country
     */
    public function regions()
    {
        return $this->hasMany(Region_Model::class, 'country_alpha2', 'alpha2');
    }

    /**
     * Scope to only enabled countries
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Get country by alpha2 code
     */
    public static function findByAlpha2(string $alpha2): ?self
    {
        return static::where('alpha2', $alpha2)->first();
    }

    /**
     * Get country by alpha3 code
     */
    public static function findByAlpha3(string $alpha3): ?self
    {
        return static::where('alpha3', $alpha3)->first();
    }
}
