<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\Country_Model;

/**
 * RSX:USE
 * Region_Model_Abstract - ISO 3166-2 subdivision data (states, provinces, territories)
 *
 * Represents geographic subdivisions with their ISO codes and names.
 * Data populated from sokil/php-isocodes via rsx:seed:geographic-data command.
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's Region_Model lives here;
 * `Region_Model.php` beside it is a shell an application replaces by declaring
 * `class Region_Model extends Region_Model_Abstract` under rsx/models/ - so an
 * application's customization is only the members it actually changes, and a frozen
 * clone can never miss the members the framework adds next.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: regions
 *
 * @property int $id
 * @property string $code
 * @property string $country_alpha2
 * @property string $name
 * @property string $type
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
abstract class Region_Model_Abstract extends Rsx_Model_Abstract
{
    public static $enums = [];

    protected $table = 'regions';

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Get the country this region belongs to
     */
    public function country()
    {
        return $this->belongsTo(Country_Model::class, 'country_alpha2', 'alpha2');
    }

    /**
     * Scope to only enabled regions
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to regions for a specific country
     */
    public function scopeForCountry($query, string $country_alpha2)
    {
        return $query->where('country_alpha2', $country_alpha2);
    }

    /**
     * Get region by code
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }
}
