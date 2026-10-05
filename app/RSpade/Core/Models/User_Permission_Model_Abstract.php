<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Realtime\Realtime;

/**
 * User_Permission_Model_Abstract - one supplementary GRANT or DENY layered over a
 * user's role, the per-user exception half of the ACL system (rsx:man acls).
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's User_Permission_Model lives here;
 * `User_Permission_Model.php` beside it is a shell an application replaces by declaring
 * `class User_Permission_Model extends User_Permission_Model_Abstract` under rsx/models/ - so an
 * application's customization is only the members it actually changes, and a frozen
 * clone can never miss the members the framework adds next.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _user_permissions
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $id
 * @property int $is_grant
 * @property int $permission_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property int $user_id
 *
 * @mixin \Eloquent
 */
abstract class User_Permission_Model_Abstract extends Rsx_Model_Abstract
{
    protected $table = '_user_permissions';
    protected $fillable = []; // No mass assignment - always explicit

    /**
     * Enum field definitions (none for this simple model)
     * @var array
     */
    public static $enums = [];

    /**
     * In-memory per-process cache generation counter, keyed by user_id.
     *
     * Bumped whenever a user's supplementary permissions change (grant/deny/remove).
     * User_Model records the generation its cached _supplementary_permissions were
     * loaded at and reloads when this counter advances, so a grant/deny/remove is
     * visible to an already-loaded User_Model instance within the same request.
     *
     * Not persisted and not shared across processes - it only needs to invalidate
     * in-memory instance caches, which never survive a request anyway.
     *
     * @var array<int,int>
     */
    private static array $_cache_generation = [];

    // =========================================================================
    // STATIC MANAGEMENT METHODS
    // =========================================================================

    /**
     * Grant a permission to a user
     *
     * @param int $user_id User ID
     * @param int $permission_id Permission constant
     * @return User_Permission_Model
     * @throws Rsx_Caller_Exception when the id is not in User_Model::$permission_definitions
     */
    public static function grant(int $user_id, int $permission_id): User_Permission_Model
    {
        static::__assert_known_permission($permission_id);

        // Remove any existing entry first (could be DENY)
        static::where('user_id', $user_id)
            ->where('permission_id', $permission_id)
            ->delete();

        $perm = new static();
        $perm->user_id = $user_id;
        $perm->permission_id = $permission_id;
        $perm->is_grant = true;
        $perm->save();

        // Invalidate any already-loaded User_Model instance cache for this user
        static::_bump_generation($user_id);

        // ACL row changed -> push a refresh to every live connection of this user.
        static::__realtime_push_user_refresh($user_id);

        return $perm;
    }

    /**
     * Deny a permission to a user
     *
     * @param int $user_id User ID
     * @param int $permission_id Permission constant
     * @return User_Permission_Model
     * @throws Rsx_Caller_Exception when the id is not in User_Model::$permission_definitions
     */
    public static function deny(int $user_id, int $permission_id): User_Permission_Model
    {
        static::__assert_known_permission($permission_id);

        // Remove any existing entry first (could be GRANT)
        static::where('user_id', $user_id)
            ->where('permission_id', $permission_id)
            ->delete();

        $perm = new static();
        $perm->user_id = $user_id;
        $perm->permission_id = $permission_id;
        $perm->is_grant = false;
        $perm->save();

        // Invalidate any already-loaded User_Model instance cache for this user
        static::_bump_generation($user_id);

        // ACL row changed -> push a refresh to every live connection of this user.
        static::__realtime_push_user_refresh($user_id);

        return $perm;
    }

    /**
     * Remove a supplementary permission (revert to role default)
     *
     * @param int $user_id User ID
     * @param int $permission_id Permission constant
     * @return bool True if removed, false if not found
     */
    public static function remove(int $user_id, int $permission_id): bool
    {
        $deleted = static::where('user_id', $user_id)
            ->where('permission_id', $permission_id)
            ->delete();

        // Invalidate any already-loaded User_Model instance cache for this user
        static::_bump_generation($user_id);

        // Only a real deletion changed the ACL -> push a refresh (a no-op remove is silent).
        if ($deleted > 0) {
            static::__realtime_push_user_refresh($user_id);
        }

        return $deleted > 0;
    }

    /**
     * Remove every supplementary permission of a user - back to the role defaults.
     *
     * @param int $user_id User ID
     * @return int Number of rows removed (0 when the user had none)
     */
    public static function remove_all(int $user_id): int
    {
        $deleted = static::where('user_id', $user_id)->delete();

        static::_bump_generation($user_id);

        if ($deleted > 0) {
            static::__realtime_push_user_refresh($user_id);
        }

        return $deleted;
    }

    /**
     * Get all supplementary permissions for a user
     *
     * @param int $user_id User ID
     * @return array ['grants' => [permission_ids], 'denies' => [permission_ids]]
     */
    public static function for_user(int $user_id): array
    {
        $result = [
            'grants' => [],
            'denies' => [],
        ];

        $permissions = static::where('user_id', $user_id)->get();

        foreach ($permissions as $perm) {
            if ($perm->is_grant) {
                $result['grants'][] = $perm->permission_id;
            } else {
                $result['denies'][] = $perm->permission_id;
            }
        }

        return $result;
    }

    /**
     * The supplementary permissions of several users in one query - what a list screen
     * reads for a page of users instead of one for_user() call per row.
     *
     * Every requested id is present in the result, with empty lists when it has no rows.
     *
     * @param int[] $user_ids User IDs (the caller's page; bounded by it)
     * @return array<int, array{grants: int[], denies: int[]}> keyed by user id
     */
    public static function for_users(array $user_ids): array
    {
        $result = [];

        foreach ($user_ids as $user_id) {
            $result[(int) $user_id] = ['grants' => [], 'denies' => []];
        }

        if (empty($result)) {
            return $result;
        }

        $rows = static::whereIn('user_id', array_keys($result))->get();

        foreach ($rows as $row) {
            $result[(int) $row->user_id][$row->is_grant ? 'grants' : 'denies'][] = (int) $row->permission_id;
        }

        return $result;
    }

    /**
     * Refuse a permission id the catalogue does not define, so a write path fed by a form
     * can never store a row that decides nothing.
     *
     * @throws Rsx_Caller_Exception
     */
    private static function __assert_known_permission(int $permission_id): void
    {
        if (!User_Model::permission_exists($permission_id)) {
            throw new Rsx_Caller_Exception("Unknown permission id {$permission_id}: it is not in User_Model::\$permission_definitions");
        }
    }

    /**
     * Current cache generation for a user's supplementary permissions.
     *
     * Public because User_Model (different class) compares it against the
     * generation its cached permissions were loaded at.
     *
     * @param int $user_id User ID
     * @return int
     */
    public static function _current_generation(int $user_id): int
    {
        return self::$_cache_generation[$user_id] ?? 0;
    }

    /**
     * Advance the cache generation for a user, invalidating any in-memory
     * User_Model instance cache of that user's supplementary permissions.
     *
     * @param int $user_id User ID
     */
    private static function _bump_generation(int $user_id): void
    {
        self::$_cache_generation[$user_id] = self::_current_generation($user_id) + 1;
    }

    /**
     * Push a realtime user-refresh for the given site user after an ACL row change. Resolves
     * the user's site (the connection stamp targets site + user) via a light lookup; skips
     * silently if the user no longer exists. No-op when realtime is disabled.
     *
     * @param int $user_id users.id whose supplementary permissions changed
     */
    private static function __realtime_push_user_refresh(int $user_id): void
    {
        $user = User_Model::withTrashed()->find($user_id);

        if (!$user) {
            return;
        }

        Realtime::push_user_refresh((int) $user->site_id, $user_id);
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    #[Relationship]
    public function user()
    {
        return $this->belongsTo(User_Model::class, 'user_id');
    }
}
