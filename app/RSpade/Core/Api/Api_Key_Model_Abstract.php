<?php

namespace App\RSpade\Core\Api;

use Illuminate\Support\Str;
use App\RSpade\Core\Api\Api_Key_Event;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;
use App\RSpade\Core\Models\User_Model;

/**
 * Api_Key_Model_Abstract - System model for API key management
 *
 * API keys are system-level records that authenticate external API requests.
 * Keys are tied to users and establish session-like contexts for API access.
 *
 * Security:
 * - Keys are hashed before storage (plaintext never stored)
 * - Key prefix stored for identification without exposing full key
 * - Keys can be revoked (soft disable) or have expiration dates
 * - A key may be READ-ONLY: read_only = 1 means it may execute GET requests only, and every
 *   non-GET request with it is refused 403 read_only_key by Api_Dispatcher, before its scopes
 *   are even consulted. The flag is set at mint and never changes - see generate().
 * - A key may be TEMPORARY: is_temporary = 1 marks a short-lived key minted for one run of a
 *   script (rsx:api:key:temp), set at mint. It changes nothing about what the key may do; it
 *   is reported on the key events so a handler can tell such a key from a standing credential
 *   without comparing names.
 * - A key may be a TESTER KEY: tester_session_id names the browser (_sessions.id) that minted
 *   it for the /apidocs tester (generate_tester_key()). Such a key authenticates ONLY on a
 *   request carrying that browser's session cookie while the browser is signed in as the key's
 *   user (is_bound_to(), asked by Rsx_Api_Bearer::authenticate()); anywhere else it answers 401
 *   exactly like an invalid key. It is not is_temporary: a CLI temporary key is bearer-only
 *   and works from any script. Set at mint, never changed.
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
 * @property bool $read_only
 * @property bool $is_temporary
 * @property int|null $tester_session_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's Api_Key_Model lives here;
 * `Api_Key_Model.php` beside it is a shell an application replaces by declaring
 * `class Api_Key_Model extends Api_Key_Model_Abstract` under rsx/models/ - so an
 * application's customization is only the members it actually changes, and a frozen
 * clone can never miss the members the framework adds next.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _api_keys
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property string $expires_at
 * @property int $id
 * @property bool $is_revoked
 * @property bool $is_temporary
 * @property string $key_hash never exported to the browser
 * @property string $key_prefix
 * @property string $last_used_at
 * @property string $name
 * @property bool $read_only
 * @property string $scopes
 * @property int $tester_session_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
 * @property int $user_id
 * @property int $user_role_id
 *
 * @mixin \Eloquent
 */
abstract class Api_Key_Model_Abstract extends Rsx_System_Model_Abstract
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

    /**
     * key_hash is the SHA-256 of the whole key: never a payload field. With it a caller could
     * not authenticate (lookup hashes the presented key), but it identifies the credential and
     * invites an offline guess, so no serializer may carry it. The other columns are display
     * data (key_prefix is the shown part), the key's own policy (scopes, read_only), or ids that
     * grant nothing.
     */
    public static $columns = [
        'key_hash' => ['never_export' => true],
    ];

    /**
     * A key's life is reported by the framework, whatever wrote it (Api_Key_Event):
     *
     *   - api_key.created once an inserted key has COMMITTED;
     *   - api_key.revoked once a write that ENDED a live key has committed - a save setting
     *     is_revoked (revoke()), or the row's deletion (rsx:api:key:delete --purge). A key that
     *     was already revoked or past its expiry fires nothing.
     *
     * DELETING A KEY SIGNS OUT the user session its use was listed under, as revoke() and
     * expiry do (Session::SIGN_OUT_API_KEY_DELETED), in the `deleting` hook - before the row
     * goes, while the key's browser-less row still names it - so every deleter is covered.
     * That row and the user session are kept (_sessions.api_key_id is nulled with the key).
     *
     * All of these run on a model write only; a write that bypasses the model (raw SQL,
     * ->raw_bulk()) gets none of them.
     */
    protected static function booted()
    {
        parent::booted();

        static::created(function ($model) {
            Api_Key_Event::_after_created($model);
        });

        static::updated(function ($model) {
            if ($model->wasChanged('is_revoked') && (bool) $model->is_revoked) {
                Api_Key_Event::_after_ended($model, Api_Key_Event::HOW_REVOKED);
            }
        });

        static::deleting(function ($model) {
            \App\RSpade\Core\Session\Session::_end_api_key_session((int) $model->id, \App\RSpade\Core\Session\Session::SIGN_OUT_API_KEY_DELETED);
        });

        static::deleted(function ($model) {
            Api_Key_Event::_after_ended($model, Api_Key_Event::HOW_DELETED);
        });
    }

    protected $casts = [
        'is_revoked' => 'boolean',
        'read_only' => 'boolean',
        'is_temporary' => 'boolean',
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
     * @param bool $read_only True mints a key that may execute GET requests only. A non-GET
     *                        request with it is refused 403 read_only_key before its scopes are
     *                        consulted, so a read-only key with no scopes still GETs everything
     *                        its holder may. THERE IS NO SETTER: like its scopes, a key's
     *                        read_only is fixed at mint, because widening a credential already
     *                        in service would change what it can do under the integration
     *                        holding it, silently. Revoke and mint a replacement instead.
     * @param bool $is_temporary True marks a short-lived key minted for one run of a script
     *                           (rsx:api:key:temp). It grants and limits nothing; it rides the
     *                           api_key.created / api_key.revoked events so a handler can skip
     *                           announcing such a key. Fixed at mint, like read_only.
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
        ?string $scopes = null,
        bool $read_only = false,
        bool $is_temporary = false
    ): array {
        return self::__mint($user_id, $name, $environment, $user_role_id, $expires_at, $scopes, $read_only, $is_temporary, null);
    }

    /**
     * The name every /apidocs tester key carries, so it reads for what it is in
     * Settings > API Keys. A label only: what makes a key a tester key is tester_session_id.
     */
    public const TESTER_KEY_NAME = 'API Tester (temporary)';

    /**
     * Mint a one-hour key for the /apidocs tester, BOUND TO THE BROWSER THAT MINTED IT.
     *
     * tester_session_id records $session_id (the minting browser's _sessions.id). From then
     * on the key authenticates only on a request carrying that browser's session cookie while
     * the browser is signed in as $user_id (is_bound_to(), asked by
     * Rsx_Api_Bearer::authenticate() before any identity exists): a tester call from the
     * console that minted it. Presented from a script, another browser, or this browser
     * signed in as somebody else - or signed out - it answers 401 exactly as an invalid key.
     * It expires at $expires_at like any key; its holder can revoke it sooner.
     *
     * NO NOTICE IS SENT FOR IT, deliberately. api_key.created / api_key.revoked still fire
     * (Api_Key_Event), and the reference application's handler skips a tester key
     * (is_tester_key()): the key cannot be used outside the session that minted it, so
     * whoever holds that session already holds everything the key could do - a hijacked key
     * gains nothing a "a key was created on your account" notice would warn about.
     *
     * Minted outside generate(), so an application's override of generate() does not see a
     * tester key - the key's shape (name, environment, unscoped, read+write, the binding) is
     * fixed here.
     *
     * @return array{key: string, model: Api_Key_Model}
     */
    public static function generate_tester_key(int $user_id, int $session_id, \Carbon\Carbon $expires_at): array
    {
        return self::__mint($user_id, static::TESTER_KEY_NAME, 'live', null, $expires_at, null, false, false, $session_id);
    }

    /**
     * Is this a key minted for the /apidocs tester, bound to its minting browser?
     */
    public function is_tester_key(): bool
    {
        return $this->tester_session_id !== null;
    }

    /**
     * May a request whose browser is $sign_in present this key? Always true for an unbound
     * key. A TESTER key answers true only for the browser that minted it, signed in as the
     * key's user; $sign_in is Session::_cookie_staff_sign_in() for the request (null: no
     * cookie, or not signed in).
     *
     * "Signed in as the key's user" is the membership: the key's users row's login identity
     * on that row's site.
     *
     * @param array{session_id: int, login_user_id: int, site_id: int}|null $sign_in
     */
    public function is_bound_to(?array $sign_in): bool
    {
        if (!$this->is_tester_key()) {
            return true;
        }

        if ($sign_in === null || (int) $sign_in['session_id'] !== (int) $this->tester_session_id) {
            return false;
        }

        // Read without the site scope: the API channel has no site declared yet.
        $user = User_Model::without_site_scope(fn () => User_Model::find((int) $this->user_id));

        return $user !== null
            && (int) $sign_in['login_user_id'] === (int) $user->login_user_id
            && (int) $sign_in['site_id'] === (int) $user->site_id;
    }

    /**
     * The one insert behind generate() and generate_tester_key().
     */
    private static function __mint(
        int $user_id,
        string $name,
        string $environment,
        ?int $user_role_id,
        ?\Carbon\Carbon $expires_at,
        ?string $scopes,
        bool $read_only,
        bool $is_temporary,
        ?int $tester_session_id
    ): array {
        // {prefix}{env}_{32 random chars}, e.g. rsx_live_xxxxx. The leading token is
        // config('rsx.api.key_prefix') so a product's keys read as its own; lookup is by
        // hash of the whole key, so changing it never invalidates one already issued.
        $key_prefix = (string) config('rsx.api.key_prefix', 'rsx_');
        $random = Str::random(32);
        $plaintext_key = "{$key_prefix}{$environment}_{$random}";
        $prefix = "{$key_prefix}{$environment}_" . substr($random, 0, 4) . '...';

        // The display prefix is stored in a fixed-width column; a long configured prefix or
        // environment would overflow it, so the combination is refused before a key exists.
        $prefix_max = static::field_length('key_prefix');
        if (strlen($prefix) > $prefix_max) {
            throw new \RuntimeException(
                "API key display prefix '{$prefix}' is " . strlen($prefix) . " characters; the "
                . "_api_keys.key_prefix column holds {$prefix_max}. Shorten config('rsx.api.key_prefix') "
                . "('{$key_prefix}') or the environment ('{$environment}'): together they may be at most "
                . ($prefix_max - 8) . ' characters.'
            );
        }

        $model = new static();
        $model->user_id = $user_id;
        $model->name = $name;
        $model->key_hash = hash('sha256', $plaintext_key);
        $model->key_prefix = $prefix;
        $model->user_role_id = $user_role_id;
        $model->expires_at = $expires_at;
        $model->is_revoked = false;
        // Stored explicitly, exactly as every other field is - the column's DEFAULT 0 is for
        // the rows that already existed, never a substitute for saying what this key is.
        $model->read_only = $read_only;
        $model->is_temporary = $is_temporary;
        $model->tester_session_id = $tester_session_id;
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
     * Returns null if not found, revoked, or expired. An expired key's user session is signed
     * out here (Session::SIGN_OUT_API_KEY_EXPIRED), so the refusal and the record agree.
     *
     * @param string $plaintext_key The API key from request
     * @return Api_Key_Model|null The key model if valid, null otherwise
     */
    public static function find_by_key(string $plaintext_key): ?self
    {
        $hash = hash('sha256', $plaintext_key);

        $key = static::where('key_hash', $hash)
            ->where('is_revoked', false)
            ->first();

        if (!$key) {
            return null;
        }

        // An expired key authenticates nothing, and whatever is still signed in through it is
        // signed out now rather than at the next hourly sweep.
        if ($key->expires_at && $key->expires_at->isPast()) {
            \App\RSpade\Core\Session\Session::_end_api_key_session((int) $key->id, \App\RSpade\Core\Session\Session::SIGN_OUT_API_KEY_EXPIRED);

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
        return static::where('user_id', $user_id)->result_set();
    }

    /**
     * Revoke this API key
     *
     * Soft-disables the key. It remains in the database but can no longer
     * be used for authentication, and the user session its use was listed under is signed
     * out.
     */
    public function revoke(): void
    {
        $this->is_revoked = true;
        $this->save();

        \App\RSpade\Core\Session\Session::_end_api_key_session((int) $this->id, \App\RSpade\Core\Session\Session::SIGN_OUT_API_KEY_REVOKED);
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
