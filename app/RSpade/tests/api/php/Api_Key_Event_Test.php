<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Api\Api_Key_Event;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * AN API KEY'S LIFE IS REPORTED, ONCE, AFTER IT COMMITS.
 *
 * Minting a key fires api_key.created; ending a LIVE key - revoke(), or deleting its row -
 * fires api_key.revoked with how = revoked | deleted. Each payload carries the key model (never
 * the plaintext), its holder, the acting identity, the audit session and the address. Inside a
 * transaction the event waits for the commit and a rollback drops it; ending a key that was
 * already revoked or expired fires nothing; a key minted by rsx:api:key:temp carries
 * is_temporary and still fires.
 *
 * The events are registered with afterCommit(), which only a REAL outermost commit runs, so
 * the class opts out of the per-test transaction and starts from a clean baseline. Handlers are
 * replaced by a recorder (Event_Registry::_set_test_handlers()), so the application's own
 * handlers never run here.
 */
class Api_Key_Event_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    /** The baseline's initial user (users id 1, login_users id 1). */
    private const USER_ID = 1;

    /** @var array<int, array{0: string, 1: array}> [event, payload] in the order fired */
    private static array $fired = [];

    /**
     * Start recording from nothing: setup() and teardown() run once per CLASS, so every test
     * opens with this.
     */
    private static function __record(): void
    {
        static::$fired = [];

        foreach (['api_key.created', 'api_key.revoked'] as $event) {
            Event_Registry::_set_test_handlers($event, [function ($data) use ($event) {
                static::$fired[] = [$event, $data];
            }]);
        }
    }

    public static function teardown(): void
    {
        Event_Registry::_clear_test_handlers();
        static::__reset_session();
    }

    private static function __mint(string $name = 'event test key', ?\Carbon\Carbon $expires_at = null): Api_Key_Model
    {
        return Api_Key_Model::generate(self::USER_ID, $name, 'live', null, $expires_at, "/api/v1/me", true)['model'];
    }

    /**
     * ake-01: a mint fires api_key.created once with the key, its holder, and nobody acting in
     * a command; the payload never carries the plaintext.
     */
    public static function test_a_mint_fires_created_once()
    {
        static::__record();
        $result = Api_Key_Model::generate(self::USER_ID, 'event test key', 'live', null, null, "/api/v1/me", true);

        static::__assert_count(1, static::$fired, 'one mint, one event');
        [$event, $data] = static::$fired[0];
        static::__assert_equals('api_key.created', $event);
        static::__assert_equals((int) $result['model']->id, (int) $data['api_key']->id);
        static::__assert_equals('event test key', $data['api_key']->name);
        static::__assert_true((bool) $data['api_key']->read_only, 'the read-only flag rides the model');
        static::__assert_equals("/api/v1/me", $data['api_key']->scopes, 'the scopes ride the model');
        static::__assert_false((bool) $data['api_key']->is_temporary);
        static::__assert_equals(self::USER_ID, (int) $data['user']->id, 'the holder');
        static::__assert_null($data['actor'], 'nobody is signed in to a command');
        static::__assert_null($data['audit_session_id'], 'a command has no audit session');
        static::__assert_null($data['ip_address'], 'a command has no client address');
        static::__assert_false(array_key_exists('how', $data), 'how belongs to the revoked event');
        static::__assert_false(str_contains(json_encode(array_map(fn ($v) => is_object($v) ? $v->toArray() : $v, $data)), $result['key']), 'never the plaintext');
    }

    /**
     * ake-02: revoke() fires api_key.revoked (how revoked) once; revoking it again, or a save
     * that leaves is_revoked alone, fires nothing.
     */
    public static function test_revoking_a_live_key_fires_once()
    {
        $key = static::__mint();
        static::__record();

        $key->touch_last_used();
        static::__assert_count(0, static::$fired, 'a save that does not end the key fires nothing');

        $key->revoke();
        static::__assert_count(1, static::$fired);
        static::__assert_equals('api_key.revoked', static::$fired[0][0]);
        static::__assert_equals(Api_Key_Event::HOW_REVOKED, static::$fired[0][1]['how']);
        static::__assert_equals((int) $key->id, (int) static::$fired[0][1]['api_key']->id);

        $key->revoke();
        static::__assert_count(1, static::$fired, 'revoking a revoked key fires nothing');
    }

    /**
     * ake-03: deleting a live key's row fires api_key.revoked (how deleted); deleting a revoked
     * one, or ending an expired one, fires nothing.
     */
    public static function test_deleting_a_live_key_fires_and_dead_keys_do_not()
    {
        $live = static::__mint();
        $revoked = static::__mint();
        $revoked->revoke();
        $expired = static::__mint('expired key', now()->addHour());
        DB::table('_api_keys')->where('id', $expired->id)->update(['expires_at' => now()->subMinute()]);
        $expired = Api_Key_Model::find($expired->id);
        static::__record();

        $live->delete();
        static::__assert_count(1, static::$fired);
        static::__assert_equals(Api_Key_Event::HOW_DELETED, static::$fired[0][1]['how']);
        static::__assert_equals('event test key', static::$fired[0][1]['api_key']->name, 'the deleted key still names itself');

        $revoked->delete();
        $expired->revoke();
        static::__assert_count(1, static::$fired, 'a key that already authenticated nothing fires nothing');
    }

    /**
     * ake-04: inside a transaction the event waits for the commit; a rollback drops it.
     */
    public static function test_the_event_fires_after_the_commit_and_never_on_a_rollback()
    {
        static::__record();

        DB::transaction(function () {
            static::__mint();
            static::__assert_count(0, static::$fired, 'nothing fires before the commit');
        });
        static::__assert_count(1, static::$fired, 'fired once the transaction committed');

        DB::beginTransaction();
        static::__mint('rolled back');
        DB::rollBack();

        static::__assert_count(1, static::$fired, 'a rolled-back mint fires nothing');
    }

    /**
     * ake-05: the actor is the signed-in identity.
     */
    public static function test_the_actor_is_the_signed_in_identity()
    {
        static::__record();
        Session::set_login_user_id(1, false);

        static::__mint();

        static::__assert_instance_of(Login_User_Model::class, static::$fired[0][1]['actor']);
        static::__assert_equals(1, (int) static::$fired[0][1]['actor']->id);
    }

    /**
     * ake-06: rsx:api:key:temp marks its key temporary, and the event still fires, flagged;
     * rsx:api:key:create does not.
     */
    public static function test_the_temp_command_marks_its_key_and_still_fires()
    {
        static::__record();

        $code = Artisan::call('rsx:api:key:temp', ['--user' => (string) self::USER_ID, '--json' => true]);
        $output = json_decode(Artisan::output(), true);
        static::__assert_equals(0, $code);
        static::__assert_true($output['data']['api_key']['is_temporary'], 'the JSON reports the flag');

        $code = Artisan::call('rsx:api:key:create', ['--user' => (string) self::USER_ID, '--json' => true]);
        static::__assert_equals(0, $code, Artisan::output());

        static::__assert_count(2, static::$fired, 'both mints fire');
        static::__assert_true((bool) static::$fired[0][1]['api_key']->is_temporary, 'temp is flagged');
        static::__assert_false((bool) static::$fired[1][1]['api_key']->is_temporary, 'create is not');
    }
}
