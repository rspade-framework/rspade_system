<?php

namespace App\RSpade\Core\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Time\Rsx_Time;
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
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 * @property int $id
 * @property int $is_enabled
 * @property int $is_portal_enabled
 * @property string $name
 * @property string $portal_domain
 * @property string $slug
 * @property string $timezone
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
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
        'is_portal_enabled' => 'boolean',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
     *
     * For the same reason the Default site can never be DISABLED: sites.is_enabled is
     * framework-enforced (a disabled site signs nobody in and ends its live sessions - see
     * User_Model::is_active()), and the infrastructure scope must not be switchable off.
     *
     * portal_domain is stored in one spelling - a bare lowercase hostname, or NULL - so the
     * UNIQUE index and the portal's host lookup compare like with like
     * (normalize_portal_domain()). A value that is not a hostname is refused loudly.
     *
     * Every save, delete, restore and force-delete forgets the client portal's cached
     * lookups for the site (Portal_Site_Resolver::_forget_site()).
     *
     * A new site that was not given a timezone takes the server's
     * (Rsx_Time::get_server_timezone(), RSX_SERVER_TIMEZONE). The column's own database
     * default only covers a row inserted beneath the model.
     */
    protected static function booted()
    {
        parent::booted();

        static::creating(function ($model) {
            if (empty($model->timezone)) {
                $model->timezone = Rsx_Time::get_server_timezone();
            }
        });

        static::saving(function ($model) {
            if ($model->id === 0 && !$model->is_enabled) {
                shouldnt_happen('The Default site (id 0) cannot be disabled');
            }

            if ($model->isDirty('portal_domain')) {
                $model->portal_domain = static::normalize_portal_domain($model->portal_domain);
            }
        });

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

        // The client portal's cached site lookups (slug, portal_domain, the serving flags)
        // follow every change to a site.
        $forget_portal_lookups = static fn ($model) => \App\RSpade\Core\Portal\Portal_Site_Resolver::_forget_site($model);
        static::saved($forget_portal_lookups);
        static::deleted($forget_portal_lookups);
        static::restored($forget_portal_lookups);
        static::forceDeleted($forget_portal_lookups);
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
     * Is the site active - enabled (sites.is_enabled) and not deleted?
     *
     * The site half of User_Model::is_active(): a membership on an inactive site is unusable,
     * so the framework refuses its sign-in and ends its live sessions.
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
     * The one stored spelling of a site's portal domain: a bare lowercase hostname, or null.
     *
     * Forgiving about how the value was typed and strict about what it is. Surrounding
     * whitespace, a scheme ("https://"), a port, a path, a query and a trailing dot are
     * removed and the host lowercased; an empty result is null (no custom domain). What
     * remains must be a DNS name of at least two labels - each 1-63 of [a-z0-9-], neither
     * starting nor ending with '-', 253 characters in all, a top-level label that is not all
     * digits (so an IP address is refused) - written in ASCII (an internationalised name is
     * given in its xn-- form). Anything else throws: a portal domain the request router can
     * never match must not be stored as if it could.
     *
     * Public and pure so a settings endpoint validates with the same rule the model saves
     * with (catch the exception and render its message as the field error).
     *
     * @param string|null $value The domain as entered
     * @return string|null The normalised hostname, or null for none
     * @throws \InvalidArgumentException naming the value when it is not a hostname
     */
    public static function normalize_portal_domain(?string $value): ?string
    {
        $host = strtolower(trim((string) $value));

        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = preg_replace('#[/?\#].*$#s', '', $host);
        $host = preg_replace('#:[0-9]*$#', '', $host);
        $host = rtrim($host, '.');

        if ($host === '') {
            return null;
        }

        $labels = explode('.', $host);
        $valid = strlen($host) <= 253 && count($labels) >= 2 && !ctype_digit(end($labels));

        foreach ($labels as $label) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                $valid = false;
            }
        }

        if (!$valid) {
            throw new \InvalidArgumentException(
                'A portal domain must be a hostname such as portal.example.com (letters, digits and'
                . ' hyphens, at least two labels, an internationalised name in its xn-- form).'
                . ' Given: "' . (string) $value . '".'
            );
        }

        return $host;
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
