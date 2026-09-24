<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sso;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\InvalidStateException;
use RuntimeException;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Socialite_Bridge;
use App\RSpade\Core\Sso\Sso_Failed_Exception;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Lib\Flash\Flash_Alert;

/**
 * Rsx_Sso_Abstract - the federated sign-in engine, written once for BOTH authentication
 * realms.
 *
 * An application never names this class. It talks to one of the two facades that bind it:
 *
 *   Rsx_Sso         the STAFF realm - login_users, _sso_identities, /_sso/ ceremony URLs,
 *                   Session, RsxAuth, Login_History, the sso.* hooks
 *   Rsx_Portal_Sso  the PORTAL realm - portal_users, _portal_sso_identities, the portal's
 *                   own /_sso/ ceremony URLs, Portal_Session, the portal.sso.* hooks
 *
 * Both expose exactly the API below; what differs is the REALM, answered through the hooks
 * at the bottom of this file. The provider REGISTRY (which providers exist, their
 * credentials, their brand marks) and the provider layer (Socialite_Bridge: state, PKCE, the
 * token exchange, the Apple form_post leg) are realm-agnostic and shared: one Google client
 * id serves both realms. Socialite_Bridge, the identity models and the ceremony controllers
 * are implementation - an application asks a facade which providers are live, renders their
 * buttons, and answers one question through one event hook: what should happen to a
 * provider identity that is connected to no local account.
 *
 * A PORTAL CEREMONY CAN NEVER RESOLVE INTO A STAFF SIGN-IN, OR THE REVERSE. The two realms
 * park their ceremony state and pending identities under different session keys, read
 * different identity tables, sign in through different session facades, and fire
 * DIFFERENTLY NAMED hooks - so a staff policy handler (sso.identity.unlinked) never runs for
 * a portal ceremony. That separation is deliberate: a staff handler matching a verified
 * address against login_users must not be able to sign a client in as a staff member.
 *
 * THE DIVISION OF LABOUR IS THE WHOLE DESIGN. The framework owns the CEREMONY - state,
 * PKCE, the token exchange, the throttle, the failure record, the shape of the identity that
 * comes out. The application owns POLICY - whether an unknown Google account may create an
 * account here, which addresses it is allowed to match, what a signed-in user's landing page
 * is. Those are different questions with different answers per product, and the framework
 * has no business guessing either.
 *
 * THE FLOW, end to end, because the ordering is the security property:
 *
 *   1. begin() mints a random state, parks it in a session value with an expiry, and
 *      redirects the browser to the provider. Nothing is authenticated and nothing exists
 *      but a parked string.
 *   2. The provider sends the browser back to the callback with a code and the state.
 *   3. handle_callback() spends the throttle budget FIRST, then compares the returned state
 *      with the parked one under hash_equals and forgets the parked value either way. A
 *      mismatch is the end of it.
 *   4. Only then is the code exchanged. The identity that comes back is looked up in
 *      _sso_identities:
 *        - a LINKED row      -> _complete_login()
 *        - intent was LINK   -> the row is created against the signed-in identity
 *        - UNLINKED          -> parked as pending and handed to the application's
 *                               <realm>identity.unlinked hook, which decides what happens next
 *   5. _complete_login() still runs the local second factor unless the install opted out.
 *
 * A PENDING IDENTITY IS HALF-AUTHENTICATED, and it is treated the way Rsx_Two_Factor treats
 * a passed password: parked as inert data with its own expiry, redeemable only by the one
 * method that knows how. NOTHING IS LOGGED IN while an identity is pending - a provider
 * proved who owns the Google account, which is not yet a statement about who may use this
 * application. link_pending() is the redemption, and it is the application that decides it
 * may happen.
 *
 * SESSION VALUES SURVIVE LOGOUT - a realm's logout clears the session's IDENTITY and not
 * the row, and _session_values hangs off that row. That is what lets begin() park a state
 * for an anonymous visitor (put_value is a writer and mints the session), and what lets
 * _complete_login() hand a pending 2FA challenge across the same seam.
 *
 * ACTIVITY IS CONFIGURED, NOT DERIVED. A provider is live because rsx.sso.providers.<key>.
 * enabled is true and for no other reason - mode, hostname and APP_URL have no bearing. A
 * provider enabled with a missing credential THROWS, naming the literal .env keys, rather
 * than rendering a button that leads to somebody else's error page.
 *
 * IMPERSONATION IS REFUSED by every method that changes what an identity is connected to.
 * An administrator viewing an account must never be able to attach a Google account to it -
 * that is an authentication backdoor wearing a support tool's clothes, and the user whose
 * account it is would have no way to see it happen. Same rule, same reason, as 2FA
 * enrollment.
 *
 * See: php artisan rsx:man sso
 */
abstract class Rsx_Sso_Abstract
{
    /** The five built-in provider keys. They name the BUILT-INS, never the permitted set. */
    public const GOOGLE = 'google';
    public const MICROSOFT = 'microsoft';
    public const FACEBOOK = 'facebook';
    public const APPLE = 'apple';
    public const X = 'x';

    /** A sign-in. The default, and what a login-page button asks for. */
    public const INTENT_LOGIN = 'login';

    /** Connecting a provider to the identity that is ALREADY signed in (a settings screen). */
    public const INTENT_LINK = 'link';

    /**
     * The built-in registry: what ships, what drives it, and what each one needs.
     *
     * 'credentials' maps a config key to the literal .env key an operator would set, which
     * is what a half-configured install is told to fix - "SSO_APPLE_KEY_ID is not set" is
     * actionable in a way "apple is misconfigured" is not.
     *
     * 'extra' names the keys handed to the driver through Socialite_Bridge's setConfig()
     * call: Microsoft's tenant, Apple's three signing coordinates. A key absent from this
     * list never reaches the driver.
     */
    private const BUILTINS = [
        self::GOOGLE => [
            'label' => 'Google',
            'driver' => \Laravel\Socialite\Two\GoogleProvider::class,
            'credentials' => [
                'client_id' => 'SSO_GOOGLE_CLIENT_ID',
                'client_secret' => 'SSO_GOOGLE_CLIENT_SECRET',
            ],
            'extra' => [],
        ],
        self::MICROSOFT => [
            'label' => 'Microsoft',
            'driver' => \SocialiteProviders\Microsoft\Provider::class,
            'credentials' => [
                'client_id' => 'SSO_MICROSOFT_CLIENT_ID',
                'client_secret' => 'SSO_MICROSOFT_CLIENT_SECRET',
            ],
            'extra' => ['tenant'],
        ],
        self::FACEBOOK => [
            'label' => 'Facebook',
            'driver' => \Laravel\Socialite\Two\FacebookProvider::class,
            'credentials' => [
                'client_id' => 'SSO_FACEBOOK_CLIENT_ID',
                'client_secret' => 'SSO_FACEBOOK_CLIENT_SECRET',
            ],
            'extra' => [],
        ],
        self::APPLE => [
            'label' => 'Apple',
            'driver' => \SocialiteProviders\Apple\Provider::class,
            // No client_secret: Apple has none. The secret is an ES256 JWT minted per token
            // exchange from the .p8 key, which is why three extra credentials are required.
            'credentials' => [
                'client_id' => 'SSO_APPLE_CLIENT_ID',
                'team_id' => 'SSO_APPLE_TEAM_ID',
                'key_id' => 'SSO_APPLE_KEY_ID',
                'private_key' => 'SSO_APPLE_PRIVATE_KEY',
            ],
            'extra' => ['team_id', 'key_id', 'private_key'],
        ],
        self::X => [
            'label' => 'X',
            'driver' => \Laravel\Socialite\Two\XProvider::class,
            'credentials' => [
                'client_id' => 'SSO_X_CLIENT_ID',
                'client_secret' => 'SSO_X_CLIENT_SECRET',
            ],
            'extra' => [],
        ],
    ];

    // -------------------------------------------------------------------------
    // Configuration and the roster
    // -------------------------------------------------------------------------

    /**
     * When a ceremony or a pending identity minted right now stops being redeemable.
     *
     * A SECURITY WINDOW, not an operation timeout - see the config block's comment and the
     * timeout mandate. Returned as an ISO string, which is what Session::put_value() expects.
     *
     * @return string
     */
    public static function pending_expires_at(): string
    {
        $minutes = (int) config('rsx.sso.pending_window_minutes');

        if ($minutes < 1) {
            shouldnt_happen('rsx.sso.pending_window_minutes must be at least 1 minute');
        }

        return Rsx_Time::add(Rsx_Time::now_iso(), $minutes * 60);
    }

    /**
     * The path that starts a ceremony for one provider.
     *
     * @param string $key
     * @return string
     */
    public static function begin_path(string $key): string
    {
        return static::base_path() . '/' . $key . '/begin';
    }

    /**
     * The path a provider returns the browser to. THIS is the redirect URI registered in the
     * provider's console, as an absolute URL against the realm's host - one per provider per
     * realm, because each realm's ceremony must finish on the host (and cookie jar) that
     * started it.
     *
     * @param string $key
     * @return string
     */
    public static function callback_path(string $key): string
    {
        return static::base_path() . '/' . $key . '/callback';
    }

    /**
     * The callback as an ABSOLUTE URL on this realm's host - the exact string a provider
     * console must list as an authorized redirect URI, and the one both legs of the token
     * exchange name.
     *
     * @param string $key
     * @return string
     */
    public static function callback_url(string $key): string
    {
        return static::absolute_url(static::callback_path($key));
    }

    /**
     * Every live provider, in the order a login page should render them.
     *
     * WHAT COMES OUT IS PUBLIC BY CONTRACT: a key, a label, a URL to send the browser to,
     * and the brand mark. No client id, no secret, nothing that is not already visible in
     * the authorize URL. That is what makes it safe to hand straight to a template and to
     * window.rsxapp.sso.
     *
     * It THROWS on a half-configured provider rather than skipping it. A button that leads
     * to somebody else's error page is worse than no button, and an operator who set
     * SSO_GOOGLE_ENABLED=true and nothing else needs to hear about it on the first page load,
     * not from a user.
     *
     * EMPTY when the realm itself has federated sign-in switched off (the portal realm's
     * rsx.sso.portal_enabled) - the providers may be live for staff and still offer nothing
     * here.
     *
     * @return array One entry per enabled provider: {key, label, begin_url, icon_svg}.
     */
    public static function enabled_providers(): array
    {
        if (!static::__realm_enabled()) {
            return [];
        }

        $out = [];

        foreach (self::_configured_keys() as $key) {
            $provider = self::_resolve($key);

            if ($provider === null) {
                continue;
            }

            $out[] = [
                'key' => $provider['key'],
                'label' => $provider['label'],
                'begin_url' => self::begin_path($provider['key']),
                'icon_svg' => $provider['icon_svg'],
            ];
        }

        return $out;
    }

    /**
     * Is at least one provider live IN THIS REALM? The question a login page asks before
     * rendering a divider and a row of buttons it might not need.
     *
     * @return bool
     */
    public static function is_enabled(): bool
    {
        return static::enabled_providers() !== [];
    }

    /**
     * One live provider, fully resolved, or a throw.
     *
     * The throw is deliberate and the controller turns it into a 404: an unknown key and a
     * disabled key are the same answer to the outside world, because "microsoft is
     * configured here but switched off" is a fact about the install that a stranger poking
     * at /_sso/ URLs has no reason to learn.
     *
     * @param string $key
     * @return array {key, label, driver, client_id, client_secret, extra, icon_svg}
     * @throws RuntimeException When the key is unknown, disabled, or half-configured.
     */
    public static function provider(string $key): array
    {
        $provider = self::_resolve($key);

        if ($provider === null) {
            throw new RuntimeException('There is no enabled SSO provider named "' . $key . '".');
        }

        return $provider;
    }

    // -------------------------------------------------------------------------
    // The ceremony (called by Rsx_Sso_Controller, not by applications)
    // -------------------------------------------------------------------------

    /**
     * Start a sign-in: park the state and send the browser to the provider.
     *
     * THE STATE IS PARKED BEFORE THE REDIRECT and the redirect carries it, which is the
     * whole CSRF story of an OAuth flow: a callback nobody started supplies a state nobody
     * parked. Session::put_value() is a writer, so an anonymous visitor gets a session row
     * here - which is correct and is the only way a value can be parked for them at all.
     *
     * The parked value carries the provider key as well as the state, so a response from
     * Google cannot redeem a ceremony that was started for Microsoft.
     *
     * @param string $key The provider to sign in with.
     * @param string $intent INTENT_LOGIN, or INTENT_LINK to connect to the signed-in identity.
     * @return RedirectResponse
     * @throws RuntimeException When the provider is unknown, disabled or half-configured.
     */
    public static function begin(string $key, string $intent = self::INTENT_LOGIN): RedirectResponse
    {
        if (!static::__realm_enabled()) {
            throw new RuntimeException('Federated sign-in is not enabled for this realm.');
        }

        $provider = self::provider($key);

        if ($intent === self::INTENT_LINK) {
            static::_linking_identity();
        }

        $begun = Socialite_Bridge::begin($provider, random_hash(32), static::callback_url($provider['key']));

        Session::put_value(
            static::STATE_KEY,
            [
                'state' => $begun['state'],
                'provider' => $provider['key'],
                'intent' => $intent === self::INTENT_LINK ? self::INTENT_LINK : self::INTENT_LOGIN,
                'code_verifier' => $begun['code_verifier'],
            ],
            static::pending_expires_at()
        );

        return new RedirectResponse($begun['url']);
    }

    /**
     * Finish a sign-in. Every path out of here is a redirect - this is a browser navigation,
     * not an endpoint, and there is nowhere to render an error.
     *
     * THE ORDER IS THE CONTRACT:
     *
     *  1. Login_Throttle::require_not_throttled() is the FIRST statement and it THROWS. A
     *     callback is a login attempt like any other and spends the same budget; the throw
     *     is let through untouched because "we did not check" is a different answer from
     *     "that did not work".
     *  2. The parked ceremony is read and FORGOTTEN IMMEDIATELY, before anything is
     *     verified. A state is single-use by construction: whatever happens next, this state
     *     cannot be replayed.
     *  3. The state is compared with hash_equals, and the provider key with it.
     *  4. Only then is the code exchanged with the provider.
     *  5. Any failure is recorded ONCE through the realm - Login_History::record_failure()
     *     with STATUS_FAILED_SSO for staff (which ALREADY feeds Login_Throttle), the throttle
     *     directly for the portal (whose outcomes have no history store). Never both: one
     *     failure counted twice halves the real budget, and the halving would only ever be
     *     discovered by a user locked out early.
     *
     * @param string $key
     * @param Request $request The callback request.
     * @return RedirectResponse
     * @throws \App\RSpade\Core\Auth\Auth_Throttled_Exception When this client IP is locked out.
     * @throws RuntimeException When the provider is unknown, disabled or half-configured.
     */
    public static function handle_callback(string $key, Request $request): RedirectResponse
    {
        Login_Throttle::require_not_throttled();

        $provider = self::provider($key);

        $parked = Session::get_value(static::STATE_KEY);
        Session::forget_value(static::STATE_KEY);

        if (!static::__realm_enabled()) {
            return static::_fail(null, 'We could not complete that sign-in. Please try again.', 'realm disabled');
        }

        if (!self::_state_matches($parked, $key, (string) $request->input('state', ''))) {
            return static::_fail(null, 'That sign-in took too long. Please try again.', 'state mismatch or expired');
        }

        try {
            $identity = Socialite_Bridge::identity($provider, $request, $parked, static::callback_url($provider['key']));
        } catch (InvalidStateException | GuzzleException | Sso_Failed_Exception $e) {
            // The three EXPECTED failures of an outbound ceremony: the provider refused the
            // code, the network did not cooperate, or what came back was not usable. Nothing
            // else is swallowed - a misconfiguration still bubbles as itself.
            return static::_fail(null, 'We could not complete that sign-in. Please try again.', $e->getMessage());
        }

        $row = static::_find_identity($identity['provider_key'], $identity['provider_user_key']);

        if (($parked['intent'] ?? self::INTENT_LOGIN) === self::INTENT_LINK) {
            return static::_finish_link($identity, $row);
        }

        if ($row !== null) {
            return static::_finish_login($row);
        }

        // UNLINKED: the provider proved who owns the account, and nothing here knows who
        // that is. The identity is parked half-authenticated and the APPLICATION decides.
        Session::put_value(static::PENDING_KEY, $identity, static::pending_expires_at());

        $destination = Rsx::trigger_resolve(static::__hook('identity.unlinked'), $identity);

        if (!is_string($destination) || $destination === '') {
            // FAIL CLOSED. No handler, or every handler declined: an application that has
            // not said what an unknown provider identity means does not get one invented for
            // it, because the invention would be "create an account", and that is the one
            // decision a framework must never make on an application's behalf.
            static::abandon_pending();

            return static::_fail(
                $identity['email'],
                'No account is connected to this sign-in.',
                static::__hook('identity.unlinked') . ' declined'
            );
        }

        return new RedirectResponse($destination);
    }

    // -------------------------------------------------------------------------
    // The pending identity (what the application's hook and pages consume)
    // -------------------------------------------------------------------------

    /**
     * The provider identity waiting to be connected to an account, or null.
     *
     * An EXPIRED pending identity reads as null, because Session::get_value() filters on the
     * expiry rather than trusting a sweeper - so "expired" and "never existed" are the same
     * answer, which is the only answer a finish-registration screen needs.
     *
     * It is safe to render: an email the provider asserted, a name and an avatar. It carries
     * no token and confers nothing on its own.
     *
     * @return array|null {provider_key, provider_user_key, email, email_verified, name, avatar_url}
     */
    public static function pending(): ?array
    {
        $pending = Session::get_value(static::PENDING_KEY);

        if (!is_array($pending) || !isset($pending['provider_key'], $pending['provider_user_key'])) {
            return null;
        }

        return $pending;
    }

    /**
     * Connect the pending identity to a local account, and consume it.
     *
     * THE REFUSAL IS THE POINT. A provider account already connected to a DIFFERENT identity
     * is refused, because the alternative is a second row that makes "sign in with Google"
     * resolve to whichever one is read first - an account takeover with no password
     * involved. The unique index on (provider_key, provider_user_key) enforces the same rule
     * one layer down, so the refusal holds under a race too.
     *
     * Re-linking to the SAME identity is not an error: it refreshes the snapshot and returns
     * the existing row, which is what a user who disconnected and reconnected expects.
     *
     * @param Rsx_Model_Abstract|int $identity The account to connect it to - an identity of
     *                                          this realm, or its id.
     * @return Rsx_Model_Abstract The link row.
     * @throws Sso_Failed_Exception When nothing is pending, or it belongs to somebody else.
     */
    public static function link_pending(Rsx_Model_Abstract|int $identity): Rsx_Model_Abstract
    {
        $pending = static::pending();

        if ($pending === null) {
            throw new Sso_Failed_Exception('That sign-in took too long. Please try again.');
        }

        $row = static::_link(static::_resolve_id($identity), $pending);

        Session::forget_value(static::PENDING_KEY);

        return $row;
    }

    /**
     * Connect the pending identity to an account and sign that account in.
     *
     * The whole of what an application's identity.unlinked handler does once it has decided
     * the answer is yes.
     *
     * @param Rsx_Model_Abstract $identity An identity of this realm.
     * @return string The URL the browser should be sent to.
     * @throws Sso_Failed_Exception When nothing is pending, it belongs to somebody else, or
     *                              the realm's login.authorize gate denied the sign-in.
     */
    public static function consume_pending_and_login(Rsx_Model_Abstract $identity): string
    {
        static::_resolve_id($identity);

        $row = static::link_pending($identity);

        return static::_complete_login($identity, $row);
    }

    /**
     * Discard the pending identity - the user pressed cancel, or the flow is done.
     *
     * @return void
     */
    public static function abandon_pending(): void
    {
        Session::forget_value(static::PENDING_KEY);
    }

    // -------------------------------------------------------------------------
    // Account management
    // -------------------------------------------------------------------------

    /**
     * One identity's connected accounts, as METADATA ONLY.
     *
     * Built for a settings screen, which means it reaches the browser, and there is nothing
     * on this table a browser has any business holding beyond what it already knows: which
     * providers are connected, under which address, and when each was last used.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return array One row per connection, oldest first.
     */
    public static function identities_list(int|Rsx_Model_Abstract $identity): array
    {
        $model = static::_link_model();

        $rows = $model::where(static::_owner_column(), static::_resolve_id($identity))
            ->orderBy('id')
            ->result_set();

        $out = [];

        foreach ($rows as $row) {
            $provider = self::_resolve((string) $row->provider_key);

            $out[] = [
                'id' => (int) $row->id,
                'provider_key' => (string) $row->provider_key,
                // A connection to a provider that has since been switched off is still shown
                // and still disconnectable - hiding it would leave the user unable to remove
                // something that is really there. The key stands in for the label.
                'provider_label' => $provider['label'] ?? (string) $row->provider_key,
                'email' => $row->email,
                'name' => $row->name,
                'last_login_at' => $row->last_login_at,
                'created_at' => $row->created_at,
            ];
        }

        return $out;
    }

    /**
     * Disconnect one provider account.
     *
     * Removing a row that is not this identity's is a no-op, not an error - a stale settings
     * screen naming a connection that has already gone is a race, not an attack, and the
     * outcome the caller wanted is the outcome they get.
     *
     * IT DOES NOT CHECK THAT A PASSWORD EXISTS. Whether an account may be left with no way
     * to sign in is application vocabulary - some products require a password, some are
     * SSO-only, some accept being locked out as the user's choice - and the framework has no
     * grounds for an opinion. An application that cares checks before calling this.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @param int $link_id The link row id.
     * @return void
     * @throws RuntimeException While impersonating.
     */
    public static function unlink(int|Rsx_Model_Abstract $identity, int $link_id): void
    {
        static::_refuse_impersonation();

        $model = static::_link_model();

        $model::where(static::_owner_column(), static::_resolve_id($identity))
            ->where('id', $link_id)
            ->delete();
    }

    /**
     * Disconnect every provider account this identity holds.
     *
     * The operator path (rsx:users:sso:unlink --all) and what an account deletion runs. It
     * does NOT refuse while impersonating, because its caller is a shell, which holds a
     * strictly higher privilege than any session and has no impersonation question to ask.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return void
     */
    public static function unlink_all(int|Rsx_Model_Abstract $identity): void
    {
        $model = static::_link_model();

        $model::where(static::_owner_column(), static::_resolve_id($identity))->delete();
    }

    // -------------------------------------------------------------------------
    // Ceremony internals
    // -------------------------------------------------------------------------

    /**
     * Sign in an identity that a provider has just authenticated.
     *
     * THREE THINGS HAPPEN HERE AND THE ORDER MATTERS:
     *
     *  1. The realm's login.authorize GATE runs. It is where an application enforces its own
     *     account vocabulary - suspended, unactivated, not yet approved - exactly as its
     *     password login function does, because a federated sign-in must not be a way around
     *     the checks a password sign-in performs. An OPEN default (no handlers = permitted)
     *     is correct: the framework has no account states of its own to enforce beyond the
     *     realm's admission rule in step 3. A handler denies by returning anything but true,
     *     and a returned STRING is shown to the user.
     *  2. The realm's LOCAL SECOND FACTOR still runs unless rsx.sso.skip_two_factor says
     *     otherwise. The verify URL is resolved BEFORE begin_challenge() is called, because
     *     begin_challenge() signs the realm out - resolving afterwards and failing would
     *     leave a browser signed out, holding a pending challenge, with nowhere to answer it.
     *  3. Otherwise the realm signs the identity in, the success is recorded, and the link is
     *     stamped. The sign-in REFUSES an identity the realm will not admit (a staff identity
     *     holding no enabled site membership; a portal user can_login() rejects, or of
     *     another site); that refusal is recorded STATUS_FAILED_DISABLED and denied exactly
     *     as the gate above denies.
     *
     * @param Rsx_Model_Abstract $identity
     * @param Rsx_Model_Abstract $link The link that was used.
     * @return string The URL the browser should be sent to.
     * @throws Sso_Failed_Exception When the gate denied the sign-in, or the realm refused it.
     */
    protected static function _complete_login(Rsx_Model_Abstract $identity, Rsx_Model_Abstract $link): string
    {
        $email = (string) $identity->email;
        $identity_id = (int) $identity->id;
        $payload_key = static::__payload_key();

        $gate = Rsx::trigger_gate(static::__hook('login.authorize'), [
            $payload_key => $identity,
            'identity' => $link,
        ]);

        if ($gate !== true) {
            static::__record_failure($email, static::__hook('login.authorize') . ' denied', $identity_id);

            throw new Sso_Failed_Exception(
                is_string($gate) && trim($gate) !== ''
                    ? $gate
                    : 'That account cannot be signed in to right now.'
            );
        }

        // Stamped on the LINK, which is a different question from the identity's last_login:
        // "when was this connection last used", which is what a Connected Accounts row shows.
        $link->last_login_at = Rsx_Time::now_iso();
        $link->save();

        $two_factor = static::__two_factor();

        if ($two_factor::is_enabled($identity) && !config('rsx.sso.skip_two_factor')) {
            $verify_url = Rsx::trigger_resolve(static::__hook('two_factor.verify_url'), [$payload_key => $identity]);

            if (!is_string($verify_url) || $verify_url === '') {
                // An install whose identities have second factors, with no page to answer
                // one on, is misconfigured in a way no user can act on. Loudly, BEFORE the
                // sign-out that begin_challenge() performs.
                shouldnt_happen(
                    'An identity with a second factor signed in through SSO, but no '
                    . static::__hook('two_factor.verify_url') . ' handler said where to answer the challenge.'
                );
            }

            $two_factor::begin_challenge($identity);

            return $verify_url;
        }

        // A provider proving who somebody is does not make them admissible, so the realm's
        // refusal is spelled exactly the way the authorize gate spells a denial.
        if (!static::__sign_in($identity)) {
            static::__record_failure($email, 'realm refused the identity', $identity_id, true);

            throw new Sso_Failed_Exception('That account cannot be signed in to right now.');
        }

        static::__record_success($identity);

        $destination = Rsx::trigger_resolve(static::__hook('login.destination'), [$payload_key => $identity]);

        return is_string($destination) && $destination !== '' ? $destination : static::__home_path();
    }

    /**
     * The linked-row branch of handle_callback().
     *
     * @param Rsx_Model_Abstract $row
     * @return RedirectResponse
     */
    protected static function _finish_login(Rsx_Model_Abstract $row): RedirectResponse
    {
        $identity = static::__find_identity((int) $row->{static::_owner_column()});

        if ($identity === null) {
            // The identity was deleted between the redirect and the callback, or the row is
            // pointing at nothing. Either way there is nothing to sign in to.
            return static::_fail(null, 'We could not complete that sign-in. Please try again.', 'link points at no identity');
        }

        try {
            $destination = static::_complete_login($identity, $row);
        } catch (Sso_Failed_Exception $e) {
            // NOT RECORDED AGAIN. _complete_login() already recorded the failure for the
            // denial, and that record feeds Login_Throttle - recording here as well would
            // count one refusal twice and halve the real budget.
            return static::_fail((string) $identity->email, $e->getMessage(), null, false);
        }

        return new RedirectResponse($destination);
    }

    /**
     * The intent=link branch of handle_callback(): connect this provider account to the
     * identity that is already signed in.
     *
     * The signed-in identity is read HERE and not carried in the parked state, so a session
     * that changed identity mid-ceremony connects the account to whoever is signed in NOW -
     * or to nobody, if the session signed out while the browser was at the provider.
     *
     * @param array $identity The normalized identity.
     * @param Rsx_Model_Abstract|null $row An existing link for it, if any.
     * @return RedirectResponse
     */
    protected static function _finish_link(array $identity, ?Rsx_Model_Abstract $row): RedirectResponse
    {
        try {
            $signed_in = static::_linking_identity();
            static::_link((int) $signed_in->id, $identity);
        } catch (RuntimeException $e) {
            Flash_Alert::error(
                $e instanceof Sso_Failed_Exception
                    ? $e->getMessage()
                    : 'That account could not be connected.'
            );

            return new RedirectResponse(static::__login_path());
        }

        Flash_Alert::success('Connected.');

        $destination = Rsx::trigger_resolve(static::__hook('link.destination'), [static::__payload_key() => $signed_in]);

        return new RedirectResponse(is_string($destination) && $destination !== '' ? $destination : static::__home_path());
    }

    /**
     * Write (or refresh) the link between one local identity and one provider account.
     *
     * @param int $owner_id The identity of this realm to connect it to.
     * @param array $identity The normalized identity.
     * @return Rsx_Model_Abstract The link row.
     * @throws Sso_Failed_Exception When it is already connected to a different identity.
     */
    protected static function _link(int $owner_id, array $identity): Rsx_Model_Abstract
    {
        $owner = static::_owner_column();
        $row = static::_find_identity($identity['provider_key'], $identity['provider_user_key']);

        if ($row !== null && (int) $row->$owner !== $owner_id) {
            throw new Sso_Failed_Exception('That account is already connected to a different sign-in.');
        }

        if ($row === null) {
            $model = static::_link_model();

            $row = new $model();
            $row->$owner = $owner_id;
            $row->provider_key = $identity['provider_key'];
            $row->provider_user_key = $identity['provider_user_key'];

            static::__fill_new_link($row, $owner_id);
        }

        // Refreshed on every link: the snapshot is what a settings screen shows, and a stale
        // one is worse than none.
        $row->email = $identity['email'];
        $row->name = $identity['name'];
        $row->avatar_url = $identity['avatar_url'];
        $row->save();

        return $row;
    }

    /**
     * One failure, handled the one way: recorded, flashed, and sent back to the login page.
     *
     * $reason is for the LOG and never for the screen - the user gets the sentence they were
     * given, which never says which check failed. See Sso_Failed_Exception.
     *
     * $record is false in the ONE case where the failure has already been recorded by the
     * code that produced it - a denied login.authorize gate or a realm refusal. Recording it
     * twice would count one refusal twice against Login_Throttle, because every realm's
     * failure record feeds the throttle, and halving a user's real budget is the kind of bug
     * only a locked-out user ever finds.
     *
     * @param string|null $email The address attempted, when one is known.
     * @param string $message The user-safe sentence.
     * @param string|null $reason Detail for the log.
     * @param bool $record Whether this call owns the failure record.
     * @return RedirectResponse
     */
    protected static function _fail(
        ?string $email,
        string $message,
        ?string $reason = null,
        bool $record = true
    ): RedirectResponse {
        if ($record) {
            static::__record_failure((string) $email, $reason, null);
        }

        Flash_Alert::error($message);

        return new RedirectResponse(static::__login_path());
    }

    /**
     * Does the returned state redeem the parked ceremony?
     *
     * hash_equals and not ===, for the same reason every other token comparison in the
     * framework uses it. The PROVIDER is compared too: a response from Google must not be
     * able to redeem a ceremony that was started for Microsoft.
     *
     * @param mixed $parked
     * @param string $key
     * @param string $returned_state
     * @return bool
     */
    private static function _state_matches($parked, string $key, string $returned_state): bool
    {
        if (!is_array($parked) || !isset($parked['state'], $parked['provider'])) {
            return false;
        }

        if ($parked['provider'] !== $key || $returned_state === '') {
            return false;
        }

        return hash_equals((string) $parked['state'], $returned_state);
    }

    /**
     * The link for one provider account, or null.
     *
     * Scoped by the realm (the portal realm narrows it to the declared site).
     *
     * @param string $provider_key
     * @param string $provider_user_key
     * @return Rsx_Model_Abstract|null
     */
    protected static function _find_identity(string $provider_key, string $provider_user_key): ?Rsx_Model_Abstract
    {
        $model = static::_link_model();

        $query = $model::where('provider_key', $provider_key)
            ->where('provider_user_key', $provider_user_key);

        return static::__scope_links($query)->first();
    }

    // -------------------------------------------------------------------------
    // Configuration internals
    // -------------------------------------------------------------------------

    /**
     * Every provider key this install has configured, built-ins first, in registry order.
     *
     * @return array
     */
    private static function _configured_keys(): array
    {
        $custom = config('rsx.sso.custom');

        return array_merge(
            array_keys(self::BUILTINS),
            is_array($custom) ? array_keys($custom) : []
        );
    }

    /**
     * Resolve one provider key into everything needed to drive it, or null when it is
     * unknown or switched off.
     *
     * @param string $key
     * @return array|null {key, label, driver, client_id, client_secret, extra, icon_svg}
     * @throws RuntimeException When the provider is enabled but a credential is missing.
     */
    private static function _resolve(string $key): ?array
    {
        $builtin = self::BUILTINS[$key] ?? null;
        $config = $builtin !== null
            ? config('rsx.sso.providers.' . $key)
            : config('rsx.sso.custom.' . $key);

        if (!is_array($config) || !($config['enabled'] ?? false)) {
            return null;
        }

        $definition = $builtin ?? self::_custom_definition($key, $config);

        $missing = [];

        foreach ($definition['credentials'] as $config_key => $env_key) {
            if (trim((string) ($config[$config_key] ?? '')) === '') {
                $missing[] = $env_key;
            }
        }

        if ($missing !== []) {
            // The Turnstile pattern: a half-configured enable is a loud error naming the
            // literal keys an operator would set, never a silently-skipped button.
            throw new RuntimeException(
                'SSO provider "' . $key . '" is enabled but ' . implode(' and ', $missing)
                . ' ' . (count($missing) === 1 ? 'is' : 'are') . ' not set. Set '
                . (count($missing) === 1 ? 'it' : 'them') . ' in .env, or disable the provider.'
            );
        }

        $extra = [];

        foreach ($definition['extra'] as $extra_key) {
            $extra[$extra_key] = $config[$extra_key] ?? null;
        }

        return [
            'key' => $key,
            'label' => (string) ($config['label'] ?? $definition['label']),
            'driver' => $definition['driver'],
            'client_id' => (string) $config['client_id'],
            'client_secret' => (string) ($config['client_secret'] ?? ''),
            'extra' => $extra,
            'icon_svg' => self::_icon_svg($key, $config),
        ];
    }

    /**
     * A custom provider's definition, derived from its config entry.
     *
     * A custom entry declares its own driver class and label, and EVERY key in it that is
     * not framework vocabulary is passed through to the adapter - which is how an Okta base
     * URL or a Keycloak realm reaches a driver the framework has never heard of.
     *
     * @param string $key
     * @param array $config
     * @return array
     * @throws RuntimeException When the entry names no provider class, or one that is absent.
     */
    private static function _custom_definition(string $key, array $config): array
    {
        $driver = (string) ($config['provider'] ?? '');

        if ($driver === '' || !class_exists($driver)) {
            throw new RuntimeException(
                'Custom SSO provider "' . $key . '" must name an installed Socialite provider '
                . 'class in rsx.sso.custom.' . $key . '.provider'
                . ($driver === '' ? '.' : ' - "' . $driver . '" does not exist.')
            );
        }

        $reserved = ['enabled', 'provider', 'label', 'icon_svg', 'icon_file', 'client_id', 'client_secret'];

        return [
            'label' => (string) ($config['label'] ?? ucfirst($key)),
            'driver' => $driver,
            'credentials' => [
                'client_id' => 'rsx.sso.custom.' . $key . '.client_id',
                'client_secret' => 'rsx.sso.custom.' . $key . '.client_secret',
            ],
            'extra' => array_values(array_diff(array_keys($config), $reserved)),
        ];
    }

    /**
     * The brand mark for one provider, as inline SVG.
     *
     * INLINE and not a URL because the framework serves no static assets - there is no
     * /_sso/icons/google.svg to link to, and there is not going to be. A custom provider
     * supplies its own with 'icon_svg' (the markup) or 'icon_file' (a path to read).
     *
     * A missing file is an EMPTY STRING and not an error: a button with a label and no mark
     * is a working button, and an install should not fail because somebody moved an icon.
     *
     * @param string $key
     * @param array $config
     * @return string
     */
    private static function _icon_svg(string $key, array $config): string
    {
        if (isset($config['icon_svg']) && is_string($config['icon_svg'])) {
            return $config['icon_svg'];
        }

        $path = isset($config['icon_file']) && is_string($config['icon_file'])
            ? $config['icon_file']
            : __DIR__ . '/resource/icons/' . $key . '.svg';

        if (!is_file($path)) {
            return '';
        }

        return trim((string) file_get_contents($path));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The identity that may connect a provider account right now.
     *
     * Linking always operates on the SIGNED-IN identity of this realm and never on one named
     * by an argument, and it refuses while impersonating: "connect my Google account to that
     * account over there" is not an operation this subsystem offers.
     *
     * @return Rsx_Model_Abstract
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     */
    protected static function _linking_identity(): Rsx_Model_Abstract
    {
        static::_refuse_impersonation();

        $identity = static::__signed_in_identity();

        if ($identity === null) {
            throw new RuntimeException('Connecting a sign-in provider requires a signed-in identity.');
        }

        return $identity;
    }

    /**
     * Refuse any change to what an identity is connected to, while impersonating.
     *
     * @return void
     * @throws RuntimeException While impersonating.
     */
    protected static function _refuse_impersonation(): void
    {
        if (static::__is_impersonating()) {
            throw new RuntimeException(
                'Connected accounts cannot be changed while impersonating another user.'
            );
        }
    }

    /**
     * An identity id from either spelling of the argument - and a REFUSAL of an identity
     * from the other realm, which would otherwise read a portal user's id as a staff login
     * id (a different person).
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return int
     */
    protected static function _resolve_id(int|Rsx_Model_Abstract $identity): int
    {
        if (is_int($identity)) {
            return $identity;
        }

        $expected = static::_identity_model();

        if (!($identity instanceof $expected)) {
            throw new RuntimeException(
                class_basename(static::class) . ' operates on ' . class_basename($expected)
                . ', and was handed a ' . class_basename($identity) . '.'
            );
        }

        return (int) $identity->id;
    }

    // -------------------------------------------------------------------------
    // THE REALM - what each facade answers
    // -------------------------------------------------------------------------
    //
    // Each facade also declares STATE_KEY (the in-flight ceremony) and PENDING_KEY (a proven
    // but unconnected identity) as class constants, read here through static::. The two
    // realms' keys differ, because both realms' values can sit on the one session row a
    // browser has at the same moment.

    /**
     * The path every ceremony URL of this realm is built from.
     *
     * @return string
     */
    abstract public static function base_path(): string;

    /**
     * One of this realm's paths as an absolute URL on the host that serves the realm.
     *
     * @param string $path
     * @return string
     */
    abstract public static function absolute_url(string $path): string;

    /**
     * The link model class this realm stores connections in.
     *
     * @return string
     */
    abstract public static function _link_model(): string;

    /**
     * The link table's owner column (login_user_id, portal_user_id).
     *
     * @return string
     */
    abstract public static function _owner_column(): string;

    /**
     * The identity model class this realm signs in.
     *
     * @return string
     */
    abstract public static function _identity_model(): string;

    /**
     * Whether federated sign-in is switched on for this realm at all.
     *
     * @return bool
     */
    abstract protected static function __realm_enabled(): bool;

    /**
     * The full name of one of this realm's event hooks - 'sso.' . $name for staff,
     * 'portal.sso.' . $name for the portal. DISTINCT NAMES, never one name with a realm
     * field: a staff policy handler must never find itself governing the other realm.
     *
     * @param string $name e.g. 'identity.unlinked'
     * @return string
     */
    abstract protected static function __hook(string $name): string;

    /**
     * The key the identity travels under in a hook payload ('login_user', 'portal_user').
     *
     * @return string
     */
    abstract protected static function __payload_key(): string;

    /**
     * The realm's second-factor facade class.
     *
     * @return string
     */
    abstract protected static function __two_factor(): string;

    /**
     * Where a failed ceremony sends the browser: the realm's login page.
     *
     * @return string
     */
    abstract protected static function __login_path(): string;

    /**
     * Where a completed ceremony lands when no destination hook answers.
     *
     * @return string
     */
    abstract protected static function __home_path(): string;

    /**
     * The identity signed in to this realm on the current session, or null.
     *
     * @return Rsx_Model_Abstract|null
     */
    abstract protected static function __signed_in_identity(): ?Rsx_Model_Abstract;

    /**
     * Is the current session viewing this realm as somebody else?
     *
     * @return bool
     */
    abstract protected static function __is_impersonating(): bool;

    /**
     * One identity of this realm by id, or null - scoped to what the realm can sign in.
     *
     * @param int $identity_id
     * @return Rsx_Model_Abstract|null
     */
    abstract protected static function __find_identity(int $identity_id): ?Rsx_Model_Abstract;

    /**
     * Narrow a link query to what this realm may see (the portal: the declared site).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    abstract protected static function __scope_links($query);

    /**
     * Fill whatever a new link row needs beyond owner and provider (the portal: site_id).
     *
     * @param Rsx_Model_Abstract $row
     * @param int $owner_id
     * @return void
     */
    abstract protected static function __fill_new_link(Rsx_Model_Abstract $row, int $owner_id): void;

    /**
     * Sign an identity in to this realm, or refuse one the realm will not admit.
     *
     * @param Rsx_Model_Abstract $identity
     * @return bool False when the realm refuses the identity.
     */
    abstract protected static function __sign_in(Rsx_Model_Abstract $identity): bool;

    /**
     * Record a completed federated sign-in.
     *
     * @param Rsx_Model_Abstract $identity
     * @return void
     */
    abstract protected static function __record_success(Rsx_Model_Abstract $identity): void;

    /**
     * Record one failed ceremony - exactly once, and in a way that feeds Login_Throttle.
     *
     * @param string $email The address attempted, '' when none is known.
     * @param string|null $reason Detail for the log, never for the screen.
     * @param int|null $identity_id The identity, when known.
     * @param bool $refused True when the realm refused an otherwise verified identity (staff
     *                      records that as STATUS_FAILED_DISABLED rather than FAILED_SSO).
     * @return void
     */
    abstract protected static function __record_failure(
        string $email,
        ?string $reason,
        ?int $identity_id,
        bool $refused = false
    ): void;
}
