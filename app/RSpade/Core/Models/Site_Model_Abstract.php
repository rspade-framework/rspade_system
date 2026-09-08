<?php

namespace App\RSpade\Core\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\User_Model;
/**
 * Site model representing a workspace/organization
 *
 * Sites are the primary tenant boundary in multi-tenant applications.
 * Each site represents an isolated workspace with its own users, data, and settings.
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's Site_Model lives here;
 * `Site_Model.php` beside it is a shell an application replaces by declaring
 * `class Site_Model extends Site_Model_Abstract` under rsx/models/ - so an
 * application's customization is only the members it actually changes, and a frozen
 * clone can never miss the members the framework adds next.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: sites
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $timezone
 * @property int $is_enabled
 * @property string $deleted_at
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 *
 * @mixin \Eloquent
 */
abstract class Site_Model_Abstract extends Rsx_Model_Abstract
{
    use SoftDeletes;

    /**
     * Enum field definitions
     * @var array
     */
    public static $enums = [];

    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = 'sites';

    /**
     * The attributes that should be cast to native types
     *
     * @var array
     */
    protected $casts = [
        'is_enabled' => 'boolean',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Column metadata for special handling
     *
     * @var array
     */
    protected $columnMeta = [
        // No special metadata needed for sites table columns yet
    ];

    /**
     * Register model event guards.
     *
     * The Default site (id 0) is the FK target for sessionless (CLI / #[Task])
     * site-scoped writes - Rsx_Site_Model_Abstract::get_current_site_id() resolves
     * to 0 with no session, so those rows persist site_id=0. Deleting id 0 would
     * orphan that scope, so it is undeletable (soft-delete AND force-delete). This
     * is net-new: there is no other undeletable-row pattern in the framework.
     *
     * NOTE: when a tenant listing is built out (the framework panel's /_sys/sites
     * page, or an application's own root console), it MUST exclude
     * id = 0 from the tenant list - the Default site is an infrastructure FK target,
     * not a tenant workspace.
     */
    protected static function booted()
    {
        parent::booted();

        static::deleting(function ($model) {
            if ($model->id === 0) {
                shouldnt_happen('The Default site (id 0) cannot be deleted');
            }
        });

        static::forceDeleting(function ($model) {
            if ($model->id === 0) {
                shouldnt_happen('The Default site (id 0) cannot be deleted');
            }
        });
    }

    /**
     * Get all users associated with this site
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function users()
    {
        return $this->hasMany(User_Model::class, 'site_id');
    }

    /**
     * Get all files belonging to this site
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function files()
    {
        return $this->hasMany(File_Model::class, 'site_id');
    }

    /**
     * Check if the site is active
     *
     * @return bool
     */
    public function is_active()
    {
        return $this->is_enabled && !$this->trashed();
    }

    /**
     * Scope to only get enabled sites
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Find a site by its slug
     *
     * @param string $slug
     * @return static|null
     */
    public static function find_by_slug($slug)
    {
        return static::where('slug', $slug)->first();
    }
}
