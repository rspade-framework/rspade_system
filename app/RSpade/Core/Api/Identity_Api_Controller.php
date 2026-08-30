<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Session\Session;

/**
 * Identity_Api_Controller - GET /api/v1/me
 *
 * The one endpoint every RSpade API has, shipped by the framework rather than written per
 * app. It answers the question a client asks FIRST and asks OFTEN: "is this key good, and
 * who am I with it?"
 *
 * WHY THE FRAMEWORK OWNS IT. A key-validation call has exactly one sensible shape, and every
 * integration needs it - to check a key at setup, to fail fast on a revoked one, to show
 * "connected as" in a UI. Leaving it to each app would mean every RSpade API grew a slightly
 * different one at a slightly different path, and no generic tooling could rely on any of
 * them. This is a CONTRACT: the path, the verb and the field names are stable across every
 * install, so a client can be written once.
 *
 * /me rather than /whoami or /auth/verify: it is the conventional spelling (GitHub, Stripe,
 * Slack all use /me or /user for exactly this), so it is the one an integrator guesses.
 *
 * IT REPORTS, IT DOES NOT AUTHORIZE. Reaching this endpoint at all IS the validation - an
 * absent, malformed, revoked or expired key is a 401 from Api_Dispatcher before any of this
 * runs. A 200 means the key is good.
 *
 * DELIBERATELY THIN. Identity and the key's own expiry, nothing else: it is the endpoint a
 * client hits on a timer, and anything expensive added here is paid for on every heartbeat
 * of every integration. It exposes nothing a caller does not already hold - they sent the
 * key, so telling them whose it is and when it dies leaks nothing.
 */
#[Auth('is_logged_in')]
class Identity_Api_Controller extends Rsx_Api_Controller_Abstract
{
    /**
     * Identify the caller behind the presented API key.
     *
     * @api-response
     * {
     *   "user_id": 1,
     *   "email": "integrations@example.com",
     *   "site_id": 1,
     *   "key": {
     *     "name": "Billing sync",
     *     "prefix": "rsx_live_ab12...",
     *     "expires_at": "2027-01-01T00:00:00.000Z",
     *     "last_used_at": "2026-08-25T14:02:11.418Z",
     *     "scopes": "Grant GET /api/v1/billing/**"
     *   }
     * }
     */
    #[Api_Endpoint('/api/v1/me', methods: ['GET'])]
    public static function me(Request $request, array $params = [])
    {
        $user = Session::get_user();
        $key = Api_Dispatcher::current_key();

        return [
            // The SITE-SCOPED user, which is the id every other endpoint's records hang off -
            // not the cross-site login identity, which a caller has no use for.
            'user_id' => (int) Session::get_user_id(),
            'email' => $user ? $user->email : null,
            'site_id' => (int) Session::get_site_id(),

            // The credential itself. expires_at is null for a key that never expires, which
            // a client must read as "no expiry" rather than as "unknown".
            'key' => [
                'name' => $key ? $key->name : null,
                'prefix' => $key ? $key->key_prefix : null,
                'expires_at' => $key ? $key->expires_at : null,
                'last_used_at' => $key ? $key->last_used_at : null,

                // The key's own scope rules, canonical text, or null when the key carries
                // its holder's full authority. A client that gets a 403 insufficient_scope
                // can read here exactly what it IS allowed to call, without an operator
                // having to describe the key over chat - and it tells nobody anything they
                // do not already hold, since they sent the key.
                'scopes' => $key ? $key->scopes : null,
            ],
        ];
    }
}
