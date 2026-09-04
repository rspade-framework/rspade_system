<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sso;

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Laravel\Socialite\Facades\Socialite;
use ReflectionProperty;
use SocialiteProviders\Manager\ConfigSocialite_Provider_Config;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Sso\Sso_Failed_Exception;

/**
 * Socialite_Bridge - the ONLY class in RSpade that knows laravel/socialite exists.
 *
 * Everything above it speaks in RSpade terms: a provider key, a state string, and a
 * normalized identity array. That boundary is what lets the engine underneath change - or a
 * provider's quirks be absorbed - without a single call site moving, and it is why Rsx_Sso
 * never returns a Socialite object to anybody.
 *
 * ============================================================================
 * THE SPIKE (2026-09-04) - WHY THIS CLASS LOOKS THE WAY IT DOES
 * ============================================================================
 *
 * RSpade HAS NO LARAVEL SESSION. StartSession is not in the HTTP kernel, there is no
 * config/session.php, and $request->session() therefore THROWS "Session store not set on
 * request." Socialite was written against a framework that always has one, so every place it
 * reaches for the session had to be accounted for before a line of this was written. The
 * findings, all verified offline against laravel/socialite v5.31.0:
 *
 *  1. ->stateless() removes Socialite's state handling completely. redirect() writes no
 *     'state' to the session and user() skips hasInvalidState() outright. RSpade mints its
 *     own state (random_hash), parks it in _session_values, passes it in the authorize URL
 *     with ->with(['state' => ...]) and verifies it itself in Rsx_Sso::handle_callback().
 *     That is strictly better than delegating: the value lives in OUR storage with OUR
 *     expiry, and the check is ours to read.
 *
 *  2. PKCE IS NOT COVERED BY ->stateless(). getCodeVerifier/getCodeChallenge and
 *     getTokenFields() reach for $request->session() UNCONDITIONALLY - stateless or not.
 *     Under RSpade a stateless PKCE provider throws before it can build a URL. Verified: X
 *     stateless raised "Session store not set on request."
 *
 *  3. AND A PKCE PROVIDER CANNOT USE ->stateless() ANYWAY. TwitterProvider::getCodeFields()
 *     overwrites state with the LITERAL STRING 'state' when isStateless() - after merging
 *     ->with(), so it cannot be overridden - because X rejects a request with no state at
 *     all. A stateless X sign-in would round-trip a constant, which is no binding.
 *
 *     So a PKCE provider runs NON-STATELESS behind an in-memory Store (ArraySessionHandler,
 *     never persisted, never a cookie): redirect() mints its own state and verifier into
 *     that store, RSpade LIFTS both out and parks them in _session_values exactly as it
 *     parks its own state, and the callback SEEDS a fresh store from the parked values
 *     before calling user(). Socialite's own hash_equals then runs as its author intended,
 *     on top of the check Rsx_Sso already performed. The parked-state contract above it does
 *     not move - only where the state was minted changes.
 *
 *  4. APPLE HAS NO STATIC CLIENT SECRET. socialiteproviders/apple mints an ES256 JWT from
 *     the .p8 key on every token exchange - entirely OFFLINE, no network, verified. But
 *     AppleToken::generate() reads config('services.apple.team_id' / '.client_id' / '.key_id')
 *     from LARAVEL CONFIG rather than from the provider's own config object, so those three
 *     values are pushed into the runtime config before an Apple driver is built. That is not
 *     a resurrection of the deleted services.php blocks: nothing is declared in a file,
 *     rsx.sso.providers.apple remains the single source, and the values exist only for the
 *     duration of the request that mints the secret.
 *
 *  5. buildProvider() DOES NOT CALL setConfig(). Socialite's own drivers take everything
 *     through the constructor, but a socialiteproviders adapter reads its extra keys
 *     (Microsoft's tenant, Apple's private_key) through the ConfigTrait, which is only
 *     populated by setConfig(). So every provider that has the method gets one, built from
 *     the extra keys in the config entry. This is also what makes the custom-provider seam
 *     work with no listener and no SocialiteWasCalled event: RSpade never calls
 *     Socialite::driver() by name, so nothing needs to be registered under one.
 *
 * NO TIMEOUT IS SET ON ANY OF IT. The token exchange is an outbound HTTP call to somebody
 * else's server and takes as long as it takes; a cap here would convert a slow provider into
 * a failed sign-in, and the user can already abandon the tab.
 *
 * See: php artisan rsx:man sso
 */
class Socialite_Bridge
{
    /**
     * The in-memory session name. It never reaches a cookie, a file or a database - the
     * store exists for the length of one call, purely so Socialite's PKCE helpers have
     * somewhere to write. The name only shows up in Socialite's own internals.
     */
    private const SHIM_SESSION_NAME = 'rsx_sso_shim';

    /**
     * Start a ceremony: the URL to send the browser to, and whatever has to be parked to
     * finish it.
     *
     * The returned 'state' is AUTHORITATIVE and may not be the one passed in - a PKCE
     * provider mints its own (finding 3 above), and the caller parks what comes back rather
     * than what it offered.
     *
     * @param array $provider A resolved entry from Rsx_Sso::provider().
     * @param string $state The state RSpade minted for this ceremony.
     * @return array {url, state, code_verifier} - code_verifier null for a non-PKCE provider.
     */
    public static function begin(array $provider, string $state): array
    {
        $request = Request::create(rsx_absolute_url(Rsx_Sso::callback_path($provider['key'])), 'GET');
        $driver = self::_build($provider, $request);

        if (!self::_uses_pkce($driver)) {
            $url = $driver->stateless()->with(['state' => $state])->redirect()->getTargetUrl();

            return [
                'url' => $url,
                'state' => $state,
                'code_verifier' => null,
            ];
        }

        $store = new Store(self::SHIM_SESSION_NAME, new ArraySessionHandler(0));
        $request->setLaravelSession($store);

        $url = $driver->redirect()->getTargetUrl();

        return [
            'url' => $url,
            'state' => (string) $store->get('state'),
            'code_verifier' => (string) $store->get('code_verifier'),
        ];
    }

    /**
     * Finish a ceremony: exchange the code and return the identity the provider asserted.
     *
     * NOTHING IS CAUGHT HERE. A refused code, an unreachable provider, a state Socialite
     * itself rejects - all of it bubbles to Rsx_Sso::handle_callback(), which owns the one
     * failure path (record, flash, redirect) and is the only place that knows what a failure
     * means to the user.
     *
     * @param array $provider A resolved entry from Rsx_Sso::provider().
     * @param Request $request The callback request, carrying code and state.
     * @param array $parked The value Rsx_Sso parked at begin() - {state, code_verifier}.
     * @return array The normalized identity - see _normalize().
     */
    public static function identity(array $provider, Request $request, array $parked): array
    {
        // The request is CLONED before a session store is attached to it. The incoming
        // request belongs to the dispatcher and is read by everything downstream; giving it
        // a session it never had is not this class's business.
        $sso_request = clone $request;
        $driver = self::_build($provider, $sso_request);

        if (!self::_uses_pkce($driver)) {
            return self::_normalize($provider['key'], $driver->stateless()->user());
        }

        $store = new Store(self::SHIM_SESSION_NAME, new ArraySessionHandler(0));
        $store->put('state', $parked['state'] ?? '');
        $store->put('code_verifier', $parked['code_verifier'] ?? '');
        $sso_request->setLaravelSession($store);

        return self::_normalize($provider['key'], $driver->user());
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Construct the Socialite driver for one resolved provider entry.
     *
     * @param array $provider
     * @param Request $request
     * @return \Laravel\Socialite\Two\AbstractProvider
     */
    private static function _build(array $provider, Request $request)
    {
        $redirect_url = rsx_absolute_url(Rsx_Sso::callback_path($provider['key']));
        $client_id = (string) $provider['client_id'];
        $client_secret = (string) ($provider['client_secret'] ?? '');

        if ($provider['key'] === Rsx_Sso::APPLE) {
            self::_apply_apple_runtime_config($provider, $client_id);
        }

        $driver = Socialite::buildProvider($provider['driver'], [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect' => $redirect_url,
        ]);

        // Finding 5: the extra keys only reach a socialiteproviders adapter this way.
        if (method_exists($driver, 'setConfig')) {
            $driver->setConfig(new Socialite_Provider_Config(
                $client_id,
                $client_secret,
                $redirect_url,
                $provider['extra']
            ));
        }

        $driver->setRequest($request);

        return $driver;
    }

    /**
     * Push Apple's three signing coordinates into the runtime config.
     *
     * Finding 4: AppleToken::generate() reads them from config('services.apple.*') rather
     * than from the provider's own config object, so the secret cannot be minted without
     * them. They are set per request and declared in no file - rsx.sso.providers.apple stays
     * the single source.
     *
     * @param array $provider
     * @param string $client_id The Services ID.
     * @return void
     */
    private static function _apply_apple_runtime_config(array $provider, string $client_id): void
    {
        config()->set('services.apple.client_id', $client_id);
        config()->set('services.apple.team_id', (string) ($provider['extra']['team_id'] ?? ''));
        config()->set('services.apple.key_id', (string) ($provider['extra']['key_id'] ?? ''));
    }

    /**
     * Does this driver use PKCE?
     *
     * Read off the driver rather than off a list of provider keys, so a downstream PKCE
     * provider installed through rsx.sso.custom gets the shim without RSpade having heard of
     * it. usesPKCE() is protected, which is Socialite's business - the property it reads is
     * the fact we need.
     *
     * @param object $driver
     * @return bool
     */
    private static function _uses_pkce(object $driver): bool
    {
        if (!property_exists($driver, 'usesPKCE')) {
            return false;
        }

        $property = new ReflectionProperty($driver, 'usesPKCE');
        $property->setAccessible(true);

        return (bool) $property->getValue($driver);
    }

    /**
     * One provider's user object, reduced to the six fields RSpade speaks in.
     *
     * EVERY FIELD BUT THE FIRST TWO MAY BE NULL, and an application's policy hook has to be
     * written for that:
     *   - X returns no email unless the application was approved for the users.email scope.
     *   - Facebook's email scope is one the user may decline.
     *   - Apple returns a NAME only on the very first authorization, never again.
     *
     * email_verified IS A CLAIM, NOT AN INFERENCE. It is true only where the provider
     * explicitly asserts it in the raw payload - Google and Apple do; Microsoft, Facebook
     * and X do not assert anything of the kind, so an identity from those three reports
     * false and any policy that matches an existing account by verified address will decline
     * it. That is the correct outcome: matching an unverified third-party address onto a
     * local account is the account-takeover path this flag exists to close.
     *
     * @param string $key The provider key.
     * @param \Laravel\Socialite\Two\User $user
     * @return array {provider_key, provider_user_key, email, email_verified, name, avatar_url}
     */
    private static function _normalize(string $key, $user): array
    {
        $raw = $user->getRaw();

        $provider_user_key = (string) $user->getId();

        if ($provider_user_key === '') {
            throw new Sso_Failed_Exception('We could not complete that sign-in. Please try again.');
        }

        $email = $user->getEmail();
        $name = $user->getName();

        if ($name === null || trim((string) $name) === '') {
            $name = $user->getNickname();
        }

        return [
            'provider_key' => $key,
            'provider_user_key' => $provider_user_key,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'email_verified' => self::_asserted_email_verified($raw),
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
            'avatar_url' => is_string($user->getAvatar()) && $user->getAvatar() !== '' ? $user->getAvatar() : null,
        ];
    }

    /**
     * Did the provider ASSERT that the address is verified?
     *
     * Apple sends the claim as the STRING "true" inside a JWT, Google as a JSON boolean, so
     * both spellings are read and everything else is false. Absence is false, never
     * "probably" - see the warning in _normalize().
     *
     * @param array $raw
     * @return bool
     */
    private static function _asserted_email_verified(array $raw): bool
    {
        $claim = $raw['email_verified'] ?? null;

        if (is_bool($claim)) {
            return $claim;
        }

        return is_string($claim) && strtolower($claim) === 'true';
    }
}
