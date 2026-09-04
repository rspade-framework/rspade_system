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
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Socialite_Bridge;
use App\RSpade\Core\Sso\Sso_Failed_Exception;
use App\RSpade\Core\Sso\Sso_Identity_Model;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Lib\Flash\Flash_Alert;

/**
 * Rsx_Sso - THE federated sign-in facade, and the only class in this subsystem application
 * code touches.
 *
 * Socialite_Bridge, Sso_Identity_Model and Rsx_Sso_Controller are implementation. An
 * application asks THIS class which providers are live, renders their buttons, and answers
 * one question through one event hook: what should happen to a provider identity that is
 * connected to no local account. It never reaches past it, and it never sees a Socialite
 * object.
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
 *        - UNLINKED          -> parked as sso.pending and handed to the application's
 *                               sso.identity.unlinked hook, which decides what happens next
 *   5. _complete_login() still runs the local second factor unless the install opted out.
 *
 * A PENDING IDENTITY IS HALF-AUTHENTICATED, and it is treated the way Rsx_Two_Factor treats
 * a passed password: parked as inert data with its own expiry, redeemable only by the one
 * method that knows how. NOTHING IS LOGGED IN while an identity is pending - a provider
 * proved who owns the Google account, which is not yet a statement about who may use this
 * application. link_pending() is the redemption, and it is the application that decides it
 * may happen.
 *
 * SESSION VALUES SURVIVE LOGOUT - Session::logout() clears the session's IDENTITY and not
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
class Rsx_Sso
{
    /** The five built-in provider keys. They name the BUILT-INS, never the permitted set. */
    public const GOOGLE = 'google';
    public const MICROSOFT = 'microsoft';
    public const FACEBOOK = 'facebook';
    public const APPLE = 'apple';
    public const X = 'x';

    /** Session value holding the in-flight ceremony: {state, provider, intent, code_verifier}. */
    public const STATE_KEY = 'sso.state';

    /** Session value holding a proven provider identity that is connected to no local account. */
    public const PENDING_KEY = 'sso.pending';

    /** A sign-in. The default, and what a login-page button asks for. */
    public const INTENT_LOGIN = 'login';

    /** Connecting a provider to the identity that is ALREADY signed in (a settings screen). */
    public const INTENT_LINK = 'link';

    /** The path prefix every ceremony URL is built from. Rsx_Sso_Controller routes it. */
    public const BASE_PATH = '/_sso';

    /**
     * Apple's callback, spelled out as a constant because Rsx_Csrf::enforce() exempts this
     * exact path - Sign in with Apple returns its authorization as a cross-site form POST.
     * A constant and not a computed string: an exemption list must be readable as a list.
     */
    public const APPLE_CALLBACK_PATH = '/_sso/apple/callback';

    /**
     * Where a failed ceremony sends the browser.
     *
     * The same literal the framework's own login_redirect configuration defaults to
     * (rsx.login_redirect.excluded_prefixes), and the path the starter template's login page
     * lives at. An application that moves its login page elsewhere is expected to move it
     * with a route, not to teach the framework a second spelling.
     */
    private const LOGIN_PATH = '/login';

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
        return self::BASE_PATH . '/' . $key . '/begin';
    }

    /**
     * The path a provider returns the browser to. THIS is the redirect URI registered in the
     * provider's console, as an absolute URL against APP_URL.
     *
     * @param string $key
     * @return string
     */
    public static function callback_path(string $key): string
    {
        return self::BASE_PATH . '/' . $key . '/callback';
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
     * @return array One entry per enabled provider: {key, label, begin_url, icon_svg}.
     */
    public static function enabled_providers(): array
    {
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
     * Is at least one provider live? The question a login page asks before rendering a
     * divider and a row of buttons it might not need.
     *
     * @return bool
     */
    public static function is_enabled(): bool
    {
        return self::enabled_providers() !== [];
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
        $provider = self::provider($key);

        if ($intent === self::INTENT_LINK) {
            self::_linking_identity();
        }

        $begun = Socialite_Bridge::begin($provider, random_hash(32));

        Session::put_value(
            self::STATE_KEY,
            [
                'state' => $begun['state'],
                'provider' => $provider['key'],
                'intent' => $intent === self::INTENT_LINK ? self::INTENT_LINK : self::INTENT_LOGIN,
                'code_verifier' => $begun['code_verifier'],
            ],
            self::pending_expires_at()
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
     *  5. Any failure is recorded through Login_History::record_failure() with
     *     STATUS_FAILED_SSO - which ALREADY feeds Login_Throttle.
     *     Login_Throttle::record_failure() is NEVER called here: it would count the same
     *     failure twice and halve the real budget, and the halving would only ever be
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

        $parked = Session::get_value(self::STATE_KEY);
        Session::forget_value(self::STATE_KEY);

        if (!self::_state_matches($parked, $key, (string) $request->input('state', ''))) {
            return self::_fail(null, 'That sign-in took too long. Please try again.', 'state mismatch or expired');
        }

        try {
            $identity = Socialite_Bridge::identity($provider, $request, $parked);
        } catch (InvalidStateException | GuzzleException | Sso_Failed_Exception $e) {
            // The three EXPECTED failures of an outbound ceremony: the provider refused the
            // code, the network did not cooperate, or what came back was not usable. Nothing
            // else is swallowed - a misconfiguration still bubbles as itself.
            return self::_fail(null, 'We could not complete that sign-in. Please try again.', $e->getMessage());
        }

        $row = self::_find_identity($identity['provider_key'], $identity['provider_user_key']);

        if (($parked['intent'] ?? self::INTENT_LOGIN) === self::INTENT_LINK) {
            return self::_finish_link($identity, $row);
        }

        if ($row !== null) {
            return self::_finish_login($row);
        }

        // UNLINKED: the provider proved who owns the account, and nothing here knows who
        // that is. The identity is parked half-authenticated and the APPLICATION decides.
        Session::put_value(self::PENDING_KEY, $identity, self::pending_expires_at());

        $destination = Rsx::trigger_resolve('sso.identity.unlinked', $identity);

        if (!is_string($destination) || $destination === '') {
            // FAIL CLOSED. No handler, or every handler declined: an application that has
            // not said what an unknown provider identity means does not get one invented for
            // it, because the invention would be "create an account", and that is the one
            // decision a framework must never make on an application's behalf.
            self::abandon_pending();

            return self::_fail(
                $identity['email'],
                'No account is connected to this sign-in.',
                'sso.identity.unlinked declined'
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
        $pending = Session::get_value(self::PENDING_KEY);

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
     * @param Login_User_Model|int $login_user The account to connect it to.
     * @return Sso_Identity_Model
     * @throws Sso_Failed_Exception When nothing is pending, or it belongs to somebody else.
     */
    public static function link_pending(Login_User_Model|int $login_user): Sso_Identity_Model
    {
        $pending = self::pending();

        if ($pending === null) {
            throw new Sso_Failed_Exception('That sign-in took too long. Please try again.');
        }

        $row = self::_link(self::_resolve_id($login_user), $pending);

        Session::forget_value(self::PENDING_KEY);

        return $row;
    }

    /**
     * Connect the pending identity to an account and sign that account in.
     *
     * The whole of what an application's sso.identity.unlinked handler does once it has
     * decided the answer is yes.
     *
     * @param Login_User_Model $login_user
     * @return string The URL the browser should be sent to.
     * @throws Sso_Failed_Exception When nothing is pending, it belongs to somebody else, or
     *                              the sso.login.authorize gate denied the sign-in.
     */
    public static function consume_pending_and_login(Login_User_Model $login_user): string
    {
        $row = self::link_pending($login_user);

        return self::_complete_login($login_user, $row);
    }

    /**
     * Discard the pending identity - the user pressed cancel, or the flow is done.
     *
     * @return void
     */
    public static function abandon_pending(): void
    {
        Session::forget_value(self::PENDING_KEY);
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
     * @param int|Login_User_Model $login_user
     * @return array One row per connection, oldest first.
     */
    public static function identities_list(int|Login_User_Model $login_user): array
    {
        $rows = Sso_Identity_Model::where('login_user_id', self::_resolve_id($login_user))
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
     * @param int|Login_User_Model $login_user
     * @param int $identity_id The _sso_identities row id.
     * @return void
     * @throws RuntimeException While impersonating.
     */
    public static function unlink(int|Login_User_Model $login_user, int $identity_id): void
    {
        self::_refuse_impersonation();

        Sso_Identity_Model::where('login_user_id', self::_resolve_id($login_user))
            ->where('id', $identity_id)
            ->delete();
    }

    /**
     * Disconnect every provider account this identity holds.
     *
     * The operator path (rsx:users:sso:unlink --all) and what an account deletion runs. It
     * does NOT refuse while impersonating, because its caller is a shell, which holds a
     * strictly higher privilege than any session and has no impersonation question to ask.
     *
     * @param int|Login_User_Model $login_user
     * @return void
     */
    public static function unlink_all(int|Login_User_Model $login_user): void
    {
        Sso_Identity_Model::where('login_user_id', self::_resolve_id($login_user))->delete();
    }

    // -------------------------------------------------------------------------
    // Ceremony internals
    // -------------------------------------------------------------------------

    /**
     * Sign in an identity that a provider has just authenticated.
     *
     * THREE THINGS HAPPEN HERE AND THE ORDER MATTERS:
     *
     *  1. The sso.login.authorize GATE runs. It is where an application enforces its own
     *     account vocabulary - suspended, unactivated, not yet approved - exactly as its
     *     password login function does, because a federated sign-in must not be a way around
     *     the checks a password sign-in performs. An OPEN default (no handlers = permitted)
     *     is correct: the framework has no account states of its own to enforce. A handler
     *     denies by returning anything but true, and a returned STRING is shown to the user.
     *  2. The LOCAL SECOND FACTOR still runs unless rsx.sso.skip_two_factor says otherwise.
     *     The verify URL is resolved BEFORE begin_challenge() is called, because
     *     begin_challenge() logs the session out - resolving afterwards and failing would
     *     leave a browser logged out, holding a pending challenge, with nowhere to answer it.
     *  3. Otherwise the identity is logged in, the success is recorded (RsxAuth::login()
     *     records nothing by design), and the link is stamped.
     *
     * @param Login_User_Model $login_user
     * @param Sso_Identity_Model $identity The link that was used.
     * @return string The URL the browser should be sent to.
     * @throws Sso_Failed_Exception When the gate denied the sign-in.
     */
    private static function _complete_login(Login_User_Model $login_user, Sso_Identity_Model $identity): string
    {
        $gate = Rsx::trigger_gate('sso.login.authorize', [
            'login_user' => $login_user,
            'identity' => $identity,
        ]);

        if ($gate !== true) {
            Login_History::record_failure(
                (string) $login_user->email,
                Login_History::STATUS_FAILED_SSO,
                'sso.login.authorize denied',
                (int) $login_user->id
            );

            throw new Sso_Failed_Exception(
                is_string($gate) && trim($gate) !== ''
                    ? $gate
                    : 'That account cannot be signed in to right now.'
            );
        }

        // Stamped on the LINK, which is a different question from login_users.last_login:
        // "when was this connection last used", which is what a Connected Accounts row shows.
        $identity->last_login_at = Rsx_Time::now_iso();
        $identity->save();

        if (Rsx_Two_Factor::is_enabled($login_user) && !config('rsx.sso.skip_two_factor')) {
            $verify_url = Rsx::trigger_resolve('sso.two_factor.verify_url', ['login_user' => $login_user]);

            if (!is_string($verify_url) || $verify_url === '') {
                // An install whose identities have second factors, with no page to answer
                // one on, is misconfigured in a way no user can act on. Loudly, BEFORE the
                // logout that begin_challenge() performs.
                shouldnt_happen(
                    'An identity with a second factor signed in through SSO, but no '
                    . 'sso.two_factor.verify_url handler said where to answer the challenge.'
                );
            }

            Rsx_Two_Factor::begin_challenge($login_user);

            return $verify_url;
        }

        RsxAuth::login($login_user);
        Login_History::record_success((int) $login_user->id, (string) $login_user->email);

        $destination = Rsx::trigger_resolve('sso.login.destination', ['login_user' => $login_user]);

        return is_string($destination) && $destination !== '' ? $destination : '/';
    }

    /**
     * The linked-row branch of handle_callback().
     *
     * @param Sso_Identity_Model $row
     * @return RedirectResponse
     */
    private static function _finish_login(Sso_Identity_Model $row): RedirectResponse
    {
        $login_user = Login_User_Model::where('id', $row->login_user_id)->first();

        if ($login_user === null) {
            // The identity was deleted between the redirect and the callback, or the row is
            // pointing at nothing. Either way there is nothing to sign in to.
            return self::_fail(null, 'We could not complete that sign-in. Please try again.', 'link points at no identity');
        }

        try {
            $destination = self::_complete_login($login_user, $row);
        } catch (Sso_Failed_Exception $e) {
            // NOT RECORDED AGAIN. _complete_login() already wrote the failure for the gate
            // denial, and Login_History::record_failure() feeds Login_Throttle - recording
            // here as well would count one refusal twice and halve the real budget.
            return self::_fail((string) $login_user->email, $e->getMessage(), null, false);
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
     * @param Sso_Identity_Model|null $row An existing link for it, if any.
     * @return RedirectResponse
     */
    private static function _finish_link(array $identity, ?Sso_Identity_Model $row): RedirectResponse
    {
        try {
            $login_user = self::_linking_identity();
            self::_link((int) $login_user->id, $identity);
        } catch (RuntimeException $e) {
            Flash_Alert::error(
                $e instanceof Sso_Failed_Exception
                    ? $e->getMessage()
                    : 'That account could not be connected.'
            );

            return new RedirectResponse(self::LOGIN_PATH);
        }

        Flash_Alert::success('Connected.');

        $destination = Rsx::trigger_resolve('sso.link.destination', ['login_user' => $login_user]);

        return new RedirectResponse(is_string($destination) && $destination !== '' ? $destination : '/');
    }

    /**
     * Write (or refresh) the link between one local identity and one provider account.
     *
     * @param int $login_user_id
     * @param array $identity The normalized identity.
     * @return Sso_Identity_Model
     * @throws Sso_Failed_Exception When it is already connected to a different identity.
     */
    private static function _link(int $login_user_id, array $identity): Sso_Identity_Model
    {
        $row = self::_find_identity($identity['provider_key'], $identity['provider_user_key']);

        if ($row !== null && (int) $row->login_user_id !== $login_user_id) {
            throw new Sso_Failed_Exception('That account is already connected to a different sign-in.');
        }

        if ($row === null) {
            $row = new Sso_Identity_Model();
            $row->login_user_id = $login_user_id;
            $row->provider_key = $identity['provider_key'];
            $row->provider_user_key = $identity['provider_user_key'];
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
     * code that produced it - a denied sso.login.authorize gate. Recording it twice would
     * count one refusal twice against Login_Throttle, because record_failure() feeds the
     * throttle, and halving a user's real budget is the kind of bug only a locked-out user
     * ever finds.
     *
     * @param string|null $email The address attempted, when one is known.
     * @param string $message The user-safe sentence.
     * @param string|null $reason Detail for the log.
     * @param bool $record Whether this call owns the failure record.
     * @return RedirectResponse
     */
    private static function _fail(
        ?string $email,
        string $message,
        ?string $reason = null,
        bool $record = true
    ): RedirectResponse {
        if ($record) {
            Login_History::record_failure((string) $email, Login_History::STATUS_FAILED_SSO, $reason);
        }

        Flash_Alert::error($message);

        return new RedirectResponse(self::LOGIN_PATH);
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
     * @param string $provider_key
     * @param string $provider_user_key
     * @return Sso_Identity_Model|null
     */
    private static function _find_identity(string $provider_key, string $provider_user_key): ?Sso_Identity_Model
    {
        return Sso_Identity_Model::where('provider_key', $provider_key)
            ->where('provider_user_key', $provider_user_key)
            ->first();
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
     * Linking always operates on the SIGNED-IN identity and never on one named by an
     * argument, and it refuses while impersonating: "connect my Google account to that
     * account over there" is not an operation this subsystem offers.
     *
     * @return Login_User_Model
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     */
    private static function _linking_identity(): Login_User_Model
    {
        self::_refuse_impersonation();

        $login_user = Session::get_login_user();

        if ($login_user === null) {
            throw new RuntimeException('Connecting a sign-in provider requires a signed-in identity.');
        }

        return $login_user;
    }

    /**
     * Refuse any change to what an identity is connected to, while impersonating.
     *
     * @return void
     * @throws RuntimeException While impersonating.
     */
    private static function _refuse_impersonation(): void
    {
        if (Session::is_impersonating()) {
            throw new RuntimeException(
                'Connected accounts cannot be changed while impersonating another user.'
            );
        }
    }

    /**
     * A login_users id from either spelling of the argument.
     *
     * @param int|Login_User_Model $login_user
     * @return int
     */
    private static function _resolve_id(int|Login_User_Model $login_user): int
    {
        return $login_user instanceof Login_User_Model ? (int) $login_user->id : $login_user;
    }
}
