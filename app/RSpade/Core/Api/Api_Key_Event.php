<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Api_Key_Event - the two events that report an API key's life: it was created, or it stopped
 * working because somebody ended it.
 *
 * THE EVENTS. Fired from Api_Key_Model's own hooks (Api_Key_Model_Abstract::booted()), so no
 * path that mints or ends a key can forget them - the Settings screen, an administrator, the
 * command line, the /apidocs tester:
 *
 *   api_key.created    a key row was inserted (Api_Key_Model::generate())
 *   api_key.revoked    a LIVE key (not revoked, not expired) was revoked (is_revoked set), or
 *                      its row was deleted (rsx:api:key:delete --purge)
 *
 * with {api_key, user, actor, audit_session_id, ip_address}, and for api_key.revoked also
 * {how}: HOW_REVOKED or HOW_DELETED. api_key is the model - never the plaintext, which exists
 * only in generate()'s return; read name, key_prefix, scopes, read_only, expires_at and
 * is_temporary off it. user is the holder (the users row the key acts as), null if that row
 * is gone. A key reaching its expires_at fires nothing: nothing is written, and the expiry was
 * chosen at mint.
 *
 * TEMPORARY KEYS FIRE TOO. A key minted by rsx:api:key:temp carries is_temporary = 1; the
 * events report it like any other so an auditor may record it, and a handler that announces
 * keys to their holder skips it on that flag.
 *
 * AFTER COMMIT. Registered with the key's connection's afterCommit(): inside a transaction (a
 * #[Writer], a Site_Lock::write() section, DB::transaction()) the event fires once that
 * transaction has committed and never if it rolls back; with none open it fires at once. A
 * handler that throws after a commit is reported, never answered - the key stands. A handler
 * writing site-scoped rows dispatches a task: it may run under another site's lock, or none.
 *
 * A write that bypasses the model (raw SQL, ->raw_bulk()) fires nothing.
 *
 * See: php artisan rsx:man external_api (API KEYS, KEY EVENTS), rsx:man event_hooks
 */
class Api_Key_Event
{
    /** The key was revoked: is_revoked set, the row kept (its request log still names it). */
    public const HOW_REVOKED = 'revoked';

    /** The key's row was deleted while it still worked (rsx:api:key:delete --purge). */
    public const HOW_DELETED = 'deleted';

    /**
     * Arrange api_key.created for a key just inserted.
     *
     * FRAMEWORK INTERNAL - called from Api_Key_Model_Abstract's `created` hook.
     */
    public static function _after_created(Api_Key_Model $api_key): void
    {
        static::__after_commit($api_key, 'api_key.created', []);
    }

    /**
     * Arrange api_key.revoked for a key a save or a delete just ended, if it was LIVE before
     * that write: not already revoked and not past its expiry. Ending a key that already
     * authenticated nothing is not news to anybody.
     *
     * FRAMEWORK INTERNAL - called from Api_Key_Model_Abstract's `updated` and `deleted` hooks.
     *
     * @param string $how HOW_REVOKED | HOW_DELETED
     */
    public static function _after_ended(Api_Key_Model $api_key, string $how): void
    {
        if ((bool) $api_key->getOriginal('is_revoked')) {
            return;
        }

        $expires_at = $api_key->getOriginal('expires_at');
        if ($expires_at !== null && $expires_at->isPast()) {
            return;
        }

        static::__after_commit($api_key, 'api_key.revoked', ['how' => $how]);
    }

    /**
     * The acting identity and the address are read NOW, at the write; the holder and the audit
     * session are resolved when the event fires, after the commit, so a rolled-back write never
     * mints an audit session.
     */
    private static function __after_commit(Api_Key_Model $api_key, string $event, array $extra): void
    {
        $payload = [
            'api_key' => $api_key,
            'user' => null,
            'actor' => Session::_acting_identity(),
            'audit_session_id' => null,
            'ip_address' => Session::get_client_ip(),
        ] + $extra;

        $api_key->getConnection()->afterCommit(function () use ($event, $payload) {
            // The holder's users row is site-scoped and the event may fire with no site declared
            // (a command); one row by primary key, read without the scope.
            $user_id = (int) $payload['api_key']->user_id;
            $payload['user'] = User_Model::without_site_scope(fn () => User_Model::find($user_id));
            $payload['audit_session_id'] = Session::get_audit_session_id();

            Rsx::trigger_action($event, $payload);
        });
    }
}
