<?php

namespace App\RSpade\Sys\App\Apidocs;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Tester_Key;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;

/**
 * _Apidocs_Controller - the Ajax endpoints the API reference console calls.
 *
 * The console PAGE is not here: the application declares its own route for it and calls
 * Rsx_Api_Docs::page(). What remains are the two calls the console makes once it is on
 * screen - adopting and dropping the API key whose permissions the listing is drawn for,
 * and minting a short-lived one for a signed-in user who has not made a key yet.
 *
 * PUBLIC at class level, because the console's gate is whatever the application put on ITS
 * route and the framework cannot know what that is. Adopting a key additionally requires a
 * signed-in caller and is throttled (see adopt_tester_key). Adopting affects only the
 * caller's own session and grants nothing: possessing the key is the credential, the key is
 * validated here before it is accepted, and Api_Dispatcher gates every request it is later
 * used for.
 */
#[Auth('public')]
class _Apidocs_Controller extends Rsx_Controller_Abstract
{
    /**
     * Ajax: adopt an API key for this browser session.
     *
     * The plaintext never comes back from the database (only its SHA-256 is stored), so the
     * BROWSER keeps it for the tester's Authorization header. What is stored here is the
     * key's ID, in session-scoped storage, because the SERVER needs to know which key to
     * answer "which endpoints may this caller use" when the page is next built.
     *
     * Callers reload after this returns: the endpoint list is baked into the page at render
     * (Rsx_Api_Docs::rsxapp_data), so a key adopted afterwards changes nothing until
     * the page is built again.
     *
     * A key the API itself would refuse - revoked, expired, unknown, or held by a user who
     * is inactive or has no API access - is rejected here rather than stored and left to fail
     * later at the first request, with one message for all of them.
     *
     * SIGNED-IN CALLERS ONLY, AND THROTTLED. Answering "is this a working key" is a
     * key-validity oracle: a rejected key is a login failure for the caller's address
     * (Login_Throttle), and a locked-out address is refused before any lookup.
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function adopt_tester_key(Request $request, array $params = [])
    {
        $key = trim((string) ($params['key'] ?? ''));

        if ($key === '') {
            Api_Tester_Key::forget();

            return ['adopted' => false, 'prefix' => null];
        }

        try {
            Login_Throttle::require_not_throttled();
        } catch (Auth_Throttled_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, ['key' => $e->getMessage()]);
        }

        $model = Api_Key_Model::find_by_key($key);

        // The same holder checks Rsx_Api_Bearer applies to every API request.
        $holder = $model
            ? User_Model::without_site_scope(fn () => User_Model::find((int) $model->user_id))
            : null;

        if (!$model || !$holder || !$holder->is_active() || !$holder->is_api_access_enabled) {
            Login_Throttle::record_failure();

            return response_error(Ajax::ERROR_VALIDATION, [
                'key' => 'That API key is not valid, or it has been revoked or has expired.',
            ]);
        }

        Api_Tester_Key::adopt($model);

        return ['adopted' => true, 'prefix' => $model->key_prefix];
    }

    /**
     * Ajax: mint a one-hour API key for the caller's own user, for use in the tester.
     *
     * THE PLAINTEXT EXISTS ONLY IN THIS RESPONSE. Api_Key_Model::generate() stores nothing
     * but the SHA-256, so a key not kept by the browser here is gone - there is no second
     * chance to read it, and no endpoint that could hand it back.
     *
     * The key is named "API Tester (temporary)" so it reads for what it is in
     * Settings > API Keys, where it can be revoked before its hour is up.
     *
     * TWO REFUSALS, TWO DIFFERENT ERRORS, and the difference is deliberate:
     *
     *   - No signed-in user at all -> ERROR_AUTH_REQUIRED. The controller is #[Auth('public')]
     *     because the application owns the console's gate, so a genuinely anonymous visitor
     *     can reach this method. There is nobody to mint a key FOR, and the remedy is to sign
     *     in - which is exactly what ERROR_AUTH_REQUIRED tells the client.
     *
     *   - Signed in, but the account has no API access -> ERROR_VALIDATION. This caller IS
     *     authenticated; nothing about the session is missing or expired, and signing in
     *     again changes nothing. The request is simply not a request this account may make,
     *     so it is reported the way any other rejected input is - as a message beside the
     *     control - rather than as an authentication failure that would send a signed-in
     *     user back to a login screen they do not need.
     *
     * @return array{key: string, expires_at: string}
     */
    #[Ajax_Endpoint]
    public static function mint_temporary_key(Request $request, array $params = [])
    {
        $user_id = Session::get_user_id();

        if (!$user_id) {
            return response_auth_required('Sign in to create a temporary API key.');
        }

        if (!Session::has_api_access()) {
            return response_error(
                Ajax::ERROR_VALIDATION,
                'This account is not permitted to use the API.'
            );
        }

        $expires_at = now()->addHour();

        $result = Api_Key_Model::generate(
            $user_id,
            'API Tester (temporary)',
            'live',
            null,
            $expires_at
        );

        return [
            'key' => $result['key'],
            'expires_at' => $expires_at->toIso8601String(),
        ];
    }

    /**
     * Ajax: drop the adopted key. The caller reloads afterwards, for the same reason.
     */
    #[Ajax_Endpoint]
    public static function forget_tester_key(Request $request, array $params = [])
    {
        Api_Tester_Key::forget();

        return ['adopted' => false];
    }
}
