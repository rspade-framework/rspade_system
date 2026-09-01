<?php

namespace App\RSpade\Core\Api;

use Illuminate\Support\Str;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;
use App\RSpade\Core\Models\User_Model;

/**
 * Api_Key_Model - System model for API key management
 *
 * API keys are system-level records that authenticate external API requests.
 * Keys are tied to users and establish session-like contexts for API access.
 *
 * Security:
 * - Keys are hashed before storage (plaintext never stored)
 * - Key prefix stored for identification without exposing full key
 * - Keys can be revoked (soft disable) or have expiration dates
 * - A key may be SCOPED below its holder's authority: the scopes column carries one bare
 *   path-pattern grant per line, whose entire meaning lives in Api_Scopes. NULL means
 *   unrestricted; any scope makes the key deny-by-default. Scopes subtract only - they never
 *   grant what the user lacks.
 *
 * @see Api_Scopes for the grammar, matching and the decision
 * @see external_api.txt for full documentation
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key_hash
 * @property string $key_prefix
 * @property int|null $user_role_id
 * @property \Carbon\Carbon|null $last_used_at
 * @property \Carbon\Carbon|null $expires_at
 * @property bool $is_revoked
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _api_keys
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key_hash
 * @property string $key_prefix
 * @property int $user_role_id
 * @property string $last_used_at
 * @property string $expires_at
 * @property int $is_revoked
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $scopes
 *
 * @mixin \Eloquent
 */
class Api_Key_Model extends Rsx_System_Model_Abstract
                  {
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * Nothing caps how many keys one user may mint.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_api_keys';

    public static $enums = [];

    protected $casts = [
        'is_revoked' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Generate a new API key for a user
     *
     * Returns the plaintext key (only time it's available) and the model.
     * The plaintext key must be shown to the user immediately as it cannot
     * be recovered after this method returns.
     *
     * @param int $user_id User ID to create key for
     * @param string $name Human-readable key name
     * @param string $environment 'live' or 'test'
     * @param int|null $user_role_id Optional role override
     * @param \Carbon\Carbon|null $expires_at Optional expiration date
     * @param string|null $scopes Optional scopes, one path pattern per line (see Api_Scopes);
     *                            null mints an unrestricted key carrying the user's full authority
     * @return array{key: string, model: Api_Key_Model} Plaintext key and saved model
     *
     * @throws Api_Scope_Validation_Exception when $scopes carries a scope that does not
     *         satisfy the grammar - the key is never minted, because a scope set that cannot
     *         be read must not become a credential somebody believes is narrow.
     */
    public static function generate(
        int $user_id,
        string $name,
        string $environment = 'live',
        ?int $user_role_id = null,
        ?\Carbon\Carbon $expires_at = null,
        ?string $scopes = null
    ): array {
        // {prefix}{env}_{32 random chars}, e.g. rsx_live_xxxxx. The leading token is
        // config('rsx.api.key_prefix') so a product's keys read as its own; lookup is by
        // hash of the whole key, so changing it never invalidates one already issued.
        $key_prefix = (string) config('rsx.api.key_prefix', 'rsx_');
        $random = Str::random(32);
        $plaintext_key = "{$key_prefix}{$environment}_{$random}";
        $prefix = "{$key_prefix}{$environment}_" . substr($random, 0, 4) . '...';

        $model = new self();
        $model->user_id = $user_id;
        $model->name = $name;
        $model->key_hash = hash('sha256', $plaintext_key);
        $model->key_prefix = $prefix;
        $model->user_role_id = $user_role_id;
        $model->expires_at = $expires_at;
        $model->is_revoked = false;
        // Normalized before the row is written, so what is stored is what Api_Scopes reads
        // back - and a malformed scope throws here rather than at first request.
        $model->scopes = Api_Scopes::canonicalize($scopes);
        $model->save();

        return [
            'key' => $plaintext_key,
            'model' => $model,
        ];
    }

    /**
     * Set this key's scopes and save.
     *
     * The text is normalized (one path pattern per line, duplicates collapsed) and validated
     * first: a malformed scope throws and NOTHING is written. A null or empty text clears the
     * scoping, returning the key to its holder's full authority.
     *
     * @throws Api_Scope_Validation_Exception naming the offending scope.
     */
    public function set_scopes(?string $scopes): void
    {
        $this->scopes = Api_Scopes::canonicalize($scopes);
        $this->save();
    }

    /**
     * Does this key carry a scope that does not satisfy the grammar?
     *
     * Every write path validates, so the only way to get one is a hand-edited row - and the
     * key then denies EVERYTHING, because a malformed scope is ignored for matching yet still
     * counts as a registered scope. Surfaced so an operator can be told which key to re-scope
     * rather than being left to read a log line.
     */
    public function has_malformed_scopes(): bool
    {
        return !empty(Api_Scopes::parse_all($this->scopes)['malformed']);
    }

    /**
     * Does this key carry its holder's full authority (no scopes)?
     */
    public function is_unrestricted(): bool
    {
        return Api_Scopes::is_unrestricted($this->scopes);
    }

    /**
     * This key's usable scopes - [] for an unrestricted key.
     *
     * MALFORMED SCOPES ARE NOT HERE, because they grant nothing. They still narrow the key
     * (see has_malformed_scopes()), so a caller counting these to decide "is this key
     * narrowed" is asking the wrong question - is_unrestricted() answers that one.
     *
     * @return array<int, string>
     */
    public function get_scope_rules(): array
    {
        return Api_Scopes::parse_all($this->scopes)['valid'];
    }

    /**
     * Find an API key by its plaintext value
     *
     * Hashes the provided key and looks up by hash.
     * Returns null if not found, revoked, or expired.
     *
     * @param string $plaintext_key The API key from request
     * @return Api_Key_Model|null The key model if valid, null otherwise
     */
    public static function find_by_key(string $plaintext_key): ?self
    {
        $hash = hash('sha256', $plaintext_key);

        $key = self::where('key_hash', $hash)
            ->where('is_revoked', false)
            ->first();

        if (!$key) {
            return null;
        }

        // Check expiration
        if ($key->expires_at && $key->expires_at->isPast()) {
            return null;
        }

        return $key;
    }

    /**
     * Get all keys for a user.
     *
     * Nothing caps how many keys one user may mint, so this returns an Rsx_Result_Set -
     * foreach it, count() it. Iteration walks by primary key, so the created_at ordering
     * is not preserved; sort in the view if the screen needs newest-first.
     *
     * @param int $user_id User ID
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    public static function get_for_user(int $user_id)
    {
        return self::where('user_id', $user_id)->result_set();
    }

    /**
     * Revoke this API key
     *
     * Soft-disables the key. It remains in the database but can no longer
     * be used for authentication.
     */
    public function revoke(): void
    {
        $this->is_revoked = true;
        $this->save();
    }

    /**
     * Update last_used_at timestamp
     *
     * Called when the key is used for authentication.
     */
    public function touch_last_used(): void
    {
        $this->last_used_at = now();
        $this->save();
    }

    /**
     * Get the user this key belongs to
     *
     * @return User_Model|null
     */
    public function get_user(): ?User_Model
    {
        return User_Model::find($this->user_id);
    }

    /**
     * Check if this key is currently valid
     *
     * @return bool
     */
    public function is_valid(): bool
    {
        if ($this->is_revoked) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
