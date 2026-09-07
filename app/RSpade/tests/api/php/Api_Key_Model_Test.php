<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scope_Validation_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Api_Key_Model - generation, sha256 hashing, prefix format, lookup, and validity gating
 * (revoked / expired). Writes _api_keys rows; the default per-test transaction rollback
 * keeps them isolated, and find_by_key sees the uncommitted row on the same connection.
 */
class Api_Key_Model_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    // -------------------------------------------------------------------------
    // generate()
    // -------------------------------------------------------------------------

    public static function test_generate_returns_plaintext_and_model()
    {
        $result = Api_Key_Model::generate(self::USER_ID, 'unit test key');

        static::__assert_array_has_key('key', $result);
        static::__assert_array_has_key('model', $result);
        static::__assert_instance_of(Api_Key_Model::class, $result['model']);
        // Read from config, not a literal: the leading token is configurable
        // (rsx.api.key_prefix), and hardcoding it here would make the test assert one
        // installation's branding rather than the format.
        $key_prefix = (string) config('rsx.api.key_prefix', 'rsx_');
        static::__assert_true(str_starts_with($result['key'], $key_prefix), 'plaintext carries the configured prefix');
    }

    public static function test_generate_stores_sha256_of_plaintext_not_plaintext()
    {
        $result = Api_Key_Model::generate(self::USER_ID, 'hash check');
        $plaintext = $result['key'];
        $model = $result['model'];

        static::__assert_equals(hash('sha256', $plaintext), $model->key_hash, 'stored hash is sha256(plaintext)');
        static::__assert_not_equals($plaintext, $model->key_hash, 'plaintext is never stored');
    }

    public static function test_generate_prefix_is_masked()
    {
        $result = Api_Key_Model::generate(self::USER_ID, 'prefix check', 'test');
        $prefix = $result['model']->key_prefix;

        $key_prefix = (string) config('rsx.api.key_prefix', 'rsx_');
        static::__assert_true(str_starts_with($prefix, $key_prefix . 'test_'), 'prefix carries env segment');
        static::__assert_true(str_ends_with($prefix, '...'), 'prefix is truncated with an ellipsis');
    }

    // -------------------------------------------------------------------------
    // find_by_key()
    // -------------------------------------------------------------------------

    public static function test_find_by_key_roundtrip()
    {
        $result = Api_Key_Model::generate(self::USER_ID, 'roundtrip');
        $found = Api_Key_Model::find_by_key($result['key']);

        static::__assert_not_null($found);
        static::__assert_equals($result['model']->id, $found->id);
    }

    public static function test_find_by_key_wrong_key_is_null()
    {
        Api_Key_Model::generate(self::USER_ID, 'decoy');
        static::__assert_null(Api_Key_Model::find_by_key('rsx_live_this_key_does_not_exist_at_all'));
    }

    public static function test_find_by_key_revoked_is_null()
    {
        $result = Api_Key_Model::generate(self::USER_ID, 'to revoke');
        $result['model']->revoke();

        static::__assert_null(Api_Key_Model::find_by_key($result['key']), 'a revoked key never resolves');
    }

    public static function test_find_by_key_expired_is_null()
    {
        $result = Api_Key_Model::generate(self::USER_ID, 'expired', 'live', null, now()->subMinute());

        static::__assert_null(Api_Key_Model::find_by_key($result['key']), 'a past expiry never resolves');
    }

    public static function test_find_by_key_future_expiry_resolves()
    {
        $result = Api_Key_Model::generate(self::USER_ID, 'still valid', 'live', null, now()->addDay());

        static::__assert_not_null(Api_Key_Model::find_by_key($result['key']), 'a future expiry still resolves');
    }

    // -------------------------------------------------------------------------
    // is_valid()
    // -------------------------------------------------------------------------

    public static function test_is_valid_states()
    {
        $active = Api_Key_Model::generate(self::USER_ID, 'active')['model'];
        static::__assert_true($active->is_valid(), 'a fresh key is valid');

        $revoked = Api_Key_Model::generate(self::USER_ID, 'revoked')['model'];
        $revoked->revoke();
        static::__assert_false($revoked->is_valid(), 'a revoked key is invalid');

        $expired = Api_Key_Model::generate(self::USER_ID, 'expired', 'live', null, now()->subDay())['model'];
        static::__assert_false($expired->is_valid(), 'an expired key is invalid');
    }

    // -------------------------------------------------------------------------
    // scopes
    // -------------------------------------------------------------------------

    public static function test_generate_defaults_to_an_unrestricted_key()
    {
        $model = Api_Key_Model::generate(self::USER_ID, 'unscoped')['model'];

        static::__assert_null($model->scopes, 'no scopes column value is written by default');
        static::__assert_true($model->is_unrestricted());
        static::__assert_equals([], $model->get_scope_rules());
        static::__assert_false($model->has_malformed_scopes());
    }

    public static function test_generate_normalizes_the_scopes_it_is_given()
    {
        $model = Api_Key_Model::generate(
            self::USER_ID,
            'scoped',
            'live',
            null,
            null,
            "  /api/v1/contacts/  \n/api/v1/contacts\n"
        )['model'];

        static::__assert_equals('/api/v1/contacts', $model->scopes);
        static::__assert_false($model->is_unrestricted());
        static::__assert_count(1, $model->get_scope_rules());
    }

    public static function test_generate_throws_and_mints_nothing_on_a_malformed_scope()
    {
        $before = Api_Key_Model::where('user_id', self::USER_ID)->count();

        static::__assert_throws(
            Api_Scope_Validation_Exception::class,
            function () {
                Api_Key_Model::generate(self::USER_ID, 'bad scope', 'live', null, null, '/api/v1/contacts*');
            },
            'a wildcard must be a whole segment'
        );

        static::__assert_equals($before, Api_Key_Model::where('user_id', self::USER_ID)->count(), 'no key was written');
    }

    public static function test_generate_refuses_the_old_rule_language()
    {
        static::__assert_throws(
            Api_Scope_Validation_Exception::class,
            function () {
                Api_Key_Model::generate(self::USER_ID, 'old syntax', 'live', null, null, 'Grant GET /api/v1/contacts');
            },
            'must start with /api/<version>/'
        );
    }

    public static function test_set_scopes_normalizes_and_saves()
    {
        $model = Api_Key_Model::generate(self::USER_ID, 'to scope')['model'];

        $model->set_scopes("/api/v1/contacts/*\n  /api/v1/me/  ");

        $reloaded = Api_Key_Model::find($model->id);
        static::__assert_equals("/api/v1/contacts/*\n/api/v1/me", $reloaded->scopes);
        static::__assert_count(2, $reloaded->get_scope_rules());
    }

    public static function test_set_scopes_null_returns_the_key_to_full_authority()
    {
        $model = Api_Key_Model::generate(self::USER_ID, 'widen', 'live', null, null, '/api/v1/contacts')['model'];

        $model->set_scopes(null);

        static::__assert_null(Api_Key_Model::find($model->id)->scopes);
        static::__assert_true($model->is_unrestricted());
    }

    public static function test_set_scopes_throws_and_writes_nothing_on_a_malformed_scope()
    {
        $model = Api_Key_Model::generate(self::USER_ID, 'keep narrow', 'live', null, null, '/api/v1/contacts')['model'];

        static::__assert_throws(
            Api_Scope_Validation_Exception::class,
            function () use ($model) {
                $model->set_scopes('/nope/v1/contacts');
            },
            'must start with /api/<version>/'
        );

        static::__assert_equals('/api/v1/contacts', Api_Key_Model::find($model->id)->scopes, 'the stored scopes are untouched');
    }

    // -------------------------------------------------------------------------
    // read_only
    // -------------------------------------------------------------------------

    public static function test_generate_defaults_to_a_read_write_key()
    {
        $model = Api_Key_Model::generate(self::USER_ID, 'default access')['model'];

        static::__assert_false((bool) $model->read_only, 'a key is read+write unless asked otherwise');
        static::__assert_false((bool) Api_Key_Model::find($model->id)->read_only, 'and that is what was stored');
    }

    public static function test_generate_stores_the_read_only_flag()
    {
        $model = Api_Key_Model::generate(self::USER_ID, 'read only', 'live', null, null, null, true)['model'];

        static::__assert_true((bool) $model->read_only);
        static::__assert_true((bool) Api_Key_Model::find($model->id)->read_only, 'the flag reached the row');
    }

    public static function test_read_only_is_cast_to_a_boolean()
    {
        // The column is TINYINT(1); a caller asking "is this key read-only" must get a bool
        // back, not a 1, or a strict === comparison at a call site silently answers false.
        $model = Api_Key_Model::find(
            Api_Key_Model::generate(self::USER_ID, 'cast check', 'live', null, null, null, true)['model']->id
        );

        static::__assert_true($model->read_only === true, 'read_only reads back as a real boolean');
    }

    public static function test_read_only_composes_with_scopes_independently()
    {
        // Two axes, neither implying the other: a read-only key may still be unrestricted,
        // and a scoped key may still write. The dispatcher enforces them in that order.
        $model = Api_Key_Model::generate(
            self::USER_ID,
            'read only and scoped',
            'live',
            null,
            null,
            '/api/v1/contacts/*',
            true
        )['model'];

        static::__assert_true((bool) $model->read_only);
        static::__assert_false($model->is_unrestricted(), 'the scopes are unaffected by the flag');
        static::__assert_equals(['/api/v1/contacts/*'], $model->get_scope_rules());
    }

    public static function test_there_is_no_setter_for_read_only()
    {
        // A key's read_only is fixed at mint, exactly as its scopes were until set_scopes()
        // was added for the framework's own use. Narrowing OR WIDENING a credential already
        // in service would change what it can do under the integration holding it, with no
        // deploy and no signal - so the absence of a mutator is the contract, and this test
        // is what stops one being added by reflex.
        $methods = get_class_methods(Api_Key_Model::class);

        foreach ($methods as $method) {
            static::__assert_false(
                str_contains(strtolower($method), 'read_only') && str_starts_with(strtolower($method), 'set'),
                "Api_Key_Model::{$method}() would let a minted key change its read_only"
            );
        }

        static::__assert_false(
            in_array('set_read_only', $methods, true),
            'read_only is settable only through generate()'
        );
    }

    public static function test_has_malformed_scopes_reads_a_hand_edited_row()
    {
        // The only way to get one: every write path validates. Planted with raw SQL exactly
        // as an operator editing the column would produce it.
        $model = Api_Key_Model::generate(self::USER_ID, 'hand edited', 'live', null, null, '/api/v1/me')['model'];

        DB::update('UPDATE _api_keys SET scopes = ? WHERE id = ?', ["/api/v1/me\nGrant GET /api/v1/x", (int) $model->id]);

        $reloaded = Api_Key_Model::find($model->id);

        static::__assert_true($reloaded->has_malformed_scopes());
        static::__assert_false($reloaded->is_unrestricted(), 'a malformed scope still narrows the key');
        static::__assert_equals(['/api/v1/me'], $reloaded->get_scope_rules(), 'only the usable scopes are returned');
    }
}
