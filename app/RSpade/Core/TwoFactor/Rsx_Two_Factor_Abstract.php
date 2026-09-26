<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Passkeys;
use App\RSpade\Core\TwoFactor\Recovery_Codes;
use App\RSpade\Core\TwoFactor\Totp;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;

/**
 * Rsx_Two_Factor_Abstract - the second-factor and passkey engine, written once for BOTH
 * authentication realms.
 *
 * An application never names this class. It talks to one of the two facades that bind it:
 *
 *   Rsx_Two_Factor         the STAFF realm - login_users, _two_factor_credentials, Session,
 *                          RsxAuth, Login_History
 *   Rsx_Portal_Two_Factor  the PORTAL realm - portal_users, _portal_two_factor_credentials,
 *                          Portal_Session, the portal's own account vocabulary
 *
 * Both expose exactly the API below. What differs between them is the REALM - which table
 * the credentials live in, whose session is read, how an identity is signed in and out, and
 * where an outcome is recorded - and each facade answers those questions through the realm
 * hooks at the bottom of this file. Every public method here is therefore realm-honest by
 * construction: a staff credential cannot satisfy a portal challenge, because the portal
 * realm never reads the staff table, and the two realms' pending values sit under different
 * session keys on the one session row a browser has.
 *
 * Totp, Passkeys, Recovery_Codes and the credential models are implementation, reached only
 * through a facade.
 *
 * THE SECOND-FACTOR LOGIN FLOW, end to end, because the ordering is the security property:
 *
 *   1. The login function verifies the password. The identity is now established but nothing
 *      is recorded and nothing is stamped - it is not yet a login.
 *   2. If is_enabled() is false, the login function signs the identity in and is done.
 *   3. Otherwise it calls begin_challenge($identity), which parks the pending identity in a
 *      session value and SIGNS THE REALM OUT. From here until the second factor is answered
 *      nothing is authenticated.
 *   4. The challenge screen reads challenge_pending() and calls verify_challenge($input).
 *      That is the method that signs the identity in and records the success.
 *
 * WHY STEP 3 SIGNS OUT. Between the password and the second factor the browser holds a
 * HALF-authenticated state, and the only safe representation of half-authenticated is NOT
 * authenticated. If the session stayed signed in while carrying a "needs 2FA" flag, then
 * every surface that has to honour that flag - every route, every Ajax endpoint, every
 * background refresh - is a place the flag can be forgotten, and forgetting it means the
 * second factor was optional. Signing out leaves nothing to forget: the pending state is
 * inert data that only verify_challenge() knows how to redeem.
 *
 * THE PASSWORDLESS FLOW is begin_passkey_login() + verify_passkey_login(): a passkey as the
 * FIRST and only credential, with no password stage and nothing pending beforehand. The
 * authenticator offers a discoverable credential, the assertion identifies the identity (the
 * credential row names its owner), and user verification is REQUIRED - so the passkey is two
 * factors on its own (the device, and the PIN or biometric that unlocked it).
 *
 * A PASSWORDLESS SIGN-IN IS COMPLETE; IT OWES NO FURTHER FACTOR (framework ruling). A
 * user-verified passkey already meets what a second-factor challenge exists to establish, so
 * verify_passkey_login() signs the identity in outright. is_enabled() keeps its one meaning -
 * "this identity holds a confirmed second factor, so a PASSWORD or FEDERATED sign-in owes a
 * challenge" - and the SSO path reads it unchanged: a Google sign-in by an identity holding a
 * passkey still faces the challenge, and may answer it with that passkey.
 *
 * SESSION VALUES SURVIVE A SIGN-OUT. Session::logout() and Portal_Session::logout() clear an
 * IDENTITY; neither deletes the _sessions row, and _session_values rows hang off that row by
 * FK. So a pending challenge written before the sign-out is still readable after it. That is
 * the mechanism the whole flow rests on, and it is why the pending value carries its own
 * expiry rather than relying on the session's.
 *
 * THE CHALLENGE WINDOW IS A SECURITY WINDOW, NOT A TIMEOUT (see the timeout mandate). It
 * does not bound how long any operation may take and nothing fails when it expires - the
 * expired state is a working outcome that says "sign in again". What it bounds is how long
 * a passed-password state stays redeemable, because a half-authenticated identity left
 * live forever is a password that has already been proven waiting on an unattended screen.
 * config('rsx.two_factor.challenge_window_minutes').
 *
 * A TOTP SEED IS ENCRYPTED AT REST, NOT HASHED. A password is verified by hashing the guess,
 * so it never needs to be recoverable; a TOTP seed is recoverable BY DESIGN, because the
 * server has to regenerate the same codes the phone does, and there is no one-way form of it
 * that still works. Encryption is therefore the strongest available posture, and it is worth
 * having: a leaked database dump alone does not let an attacker generate live codes.
 *
 * ENROLLMENT REFUSES WHILE IMPERSONATING, in both realms. Every enrollment and removal method
 * throws while the realm's session is impersonating, because somebody viewing an account -
 * an administrator impersonating a staff user, or a staff member using "View as Client" -
 * must never be able to attach a credential to it. That would be an authentication backdoor
 * wearing a support tool's clothes, and the person whose account it is would have no way to
 * see it happen.
 *
 * See: php artisan rsx:man two_factor
 */
abstract class Rsx_Two_Factor_Abstract
{
    /**
     * Pixel size of the rendered QR code. A layout number, not a security one - the SVG is
     * vector and the host element scales it.
     */
    private const QR_SIZE = 240;

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * The issuer label an authenticator app files the account under.
     *
     * config('rsx.two_factor.issuer'), or the application hostname when that is null. The
     * hostname is the right default because it is what the user actually typed to get here
     * - "example.com: alice@example.com" is a heading they will recognise in a list of
     * twenty accounts, where a generic product name may not be.
     *
     * A colon is stripped: the Key URI Format uses one to separate issuer from account, so
     * an issuer containing one produces a label the app parses wrongly.
     *
     * @return string
     */
    public static function issuer(): string
    {
        $issuer = config('rsx.two_factor.issuer');

        if (!is_string($issuer) || trim($issuer) === '') {
            $issuer = Rsx::get_hostname();
        }

        return trim(str_replace(':', ' ', $issuer));
    }

    /**
     * When a challenge or an in-flight ceremony minted right now stops being redeemable.
     *
     * A SECURITY WINDOW, not an operation timeout - see the class docblock. Returned as an
     * ISO string, which is what Session::put_value() expects and what the column stores.
     *
     * @return string
     */
    public static function challenge_expires_at(): string
    {
        $minutes = (int) config('rsx.two_factor.challenge_window_minutes');

        if ($minutes < 1) {
            shouldnt_happen('rsx.two_factor.challenge_window_minutes must be at least 1 minute');
        }

        return Rsx_Time::add(Rsx_Time::now_iso(), $minutes * 60);
    }

    // -------------------------------------------------------------------------
    // Reading an identity's factors
    // -------------------------------------------------------------------------

    /**
     * Does this identity have a second factor?
     *
     * True when at least one CONFIRMED TOTP or passkey credential exists. Recovery codes
     * are excluded on purpose: they are the way back in when a factor is lost, and an
     * identity holding only recovery codes has no second factor to be challenged for.
     *
     * This is the question a PASSWORD or FEDERATED sign-in asks. A passwordless passkey
     * sign-in does not ask it - see the class docblock.
     *
     * @param int|Rsx_Model_Abstract $identity An identity of this realm, or its id.
     * @return bool
     */
    public static function is_enabled(int|Rsx_Model_Abstract $identity): bool
    {
        $model = static::_credential_model();

        return $model::where(static::_owner_column(), static::__resolve_id($identity))
            ->whereIn('type_id', $model::factor_types())
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    /**
     * Does this identity hold a confirmed passkey?
     *
     * The question a "sign in with a passkey" prompt or a settings screen asks. Not the same
     * question as is_enabled(): an identity holding only an authenticator app has a second
     * factor and no passkey.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return bool
     */
    public static function has_passkey(int|Rsx_Model_Abstract $identity): bool
    {
        $model = static::_credential_model();

        return static::__has_confirmed(static::__resolve_id($identity), $model::TYPE_PASSKEY);
    }

    /**
     * This identity's factors, as METADATA ONLY - never a secret, a seed, a hash or a
     * public key.
     *
     * That restriction is the method's contract and not an oversight. Its output is built
     * for a settings screen, which means it reaches the browser, and there is no column on
     * the credential table a browser has any business holding. A caller that believes it
     * needs the secret is a caller that belongs inside this class.
     *
     * Recovery codes are excluded - they are a COUNT, not a list, and
     * recovery_codes_remaining() is where that count lives.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return array One row per factor, newest confirmation last.
     */
    public static function list_credentials(int|Rsx_Model_Abstract $identity): array
    {
        $model = static::_credential_model();

        $rows = $model::where(static::_owner_column(), static::__resolve_id($identity))
            ->whereIn('type_id', $model::factor_types())
            ->whereNotNull('confirmed_at')
            ->orderBy('confirmed_at')
            ->result_set();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'type_id' => (int) $row->type_id,
                'type_id__label' => $row->type_id__label,
                'label' => $row->label,
                'confirmed_at' => $row->confirmed_at,
                'last_used_at' => $row->last_used_at,
            ];
        }

        return $out;
    }

    /**
     * How many unspent recovery codes this identity holds.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return int
     */
    public static function recovery_codes_remaining(int|Rsx_Model_Abstract $identity): int
    {
        return Recovery_Codes::remaining(static::class, static::__resolve_id($identity));
    }

    /**
     * Does this identity hold a confirmed authenticator-app credential?
     *
     * The one predicate behind "is a TOTP seed already enrolled", asked by the operator
     * commands before they mint one and by cli_setup_totp() before it writes. A boolean and
     * not a row: nothing outside this class has any business holding a seed.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return bool
     */
    public static function has_confirmed_totp(int|Rsx_Model_Abstract $identity): bool
    {
        $model = static::_credential_model();

        return static::__has_confirmed(static::__resolve_id($identity), $model::TYPE_TOTP);
    }

    // -------------------------------------------------------------------------
    // The operator path (rsx:users:2fa:*)
    // -------------------------------------------------------------------------

    /**
     * Enroll an authenticator app for a NAMED identity, from a shell, with no ceremony.
     *
     * THIS IS THE OPERATOR PATH AND IT IS DELIBERATELY NOT THE ENROLLMENT PATH. Every method
     * in the enrollment section operates on the SIGNED-IN identity and refuses while
     * impersonating, because "add a second factor to that account over there" is not an
     * operation a web session may perform. That reasoning does not reach here: this runs as
     * whoever holds shell access on the box, which is a strictly higher privilege than any
     * identity in the application, and there is no session to consult and no impersonation
     * question to ask. It is the bootstrap tool (an operator arming the first account) and
     * the recovery tool (an operator whose user has lost their phone and their code sheet).
     *
     * NO PROOF IS REQUIRED, so the row is written CONFIRMED immediately - the operator is
     * handed the seed and reads it back to the user, and there is no browser to type a live
     * code into. counter is 0 because nothing has been spent, which leaves the code that is
     * live right now usable.
     *
     * The seed AND the recovery codes are returned in plaintext, and this is the only moment
     * either exists in that form: the seed is encrypted on the row and the codes are bcrypt
     * hashed. The recovery set REPLACES whatever the identity held.
     *
     * The caller decides whether stacking a second seed is acceptable - has_confirmed_totp()
     * is the question, and this method refuses rather than silently enrolling a second one.
     *
     * @param Rsx_Model_Abstract $identity The identity to arm.
     * @param string|null $label What the credential is called in the user's settings.
     * @return array {secret, otpauth_uri, recovery_codes}
     * @throws RuntimeException When the identity already holds a confirmed TOTP credential.
     */
    public static function cli_setup_totp(Rsx_Model_Abstract $identity, ?string $label = null): array
    {
        $identity_id = static::__resolve_id($identity);

        if (static::has_confirmed_totp($identity_id)) {
            throw new RuntimeException(
                'That identity already holds a confirmed authenticator-app credential.'
            );
        }

        $model = static::_credential_model();
        $owner = static::_owner_column();

        $secret = Totp::generate_secret();
        $uri = Totp::provisioning_uri($secret, (string) $identity->email, static::issuer());

        $row = new $model();
        $row->$owner = $identity_id;
        $row->type_id = $model::TYPE_TOTP;
        $row->label = static::__clean_label($label) ?? 'CLI setup';
        $row->secret = Crypt::encryptString($secret);
        $row->counter = 0;
        $row->confirmed_at = Rsx_Time::now_iso();
        $row->save();

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for(static::class, $identity_id, $codes);

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'recovery_codes' => $codes,
        ];
    }

    /**
     * Every factor this identity holds, WITH the TOTP seeds decrypted.
     *
     * The deliberate opposite of list_credentials(), which is metadata-only because its
     * output reaches a browser. This output reaches a terminal that already has shell access
     * to the box and to the encryption key, so withholding the seed from it would protect
     * nothing while removing the one escape hatch an operator has when a user's phone is
     * gone and the QR code cannot be rescanned. NEVER call it from a request path.
     *
     * Unconfirmed rows are included and report a null confirmed_at: the operator is asking
     * what is actually on the table, not what a login challenge would accept.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return array One row per factor: {id, type_id, type_id__label, label, confirmed_at,
     *               last_used_at, counter, secret, otpauth_uri} - the last two null for a
     *               passkey.
     */
    public static function cli_dump_credentials(int|Rsx_Model_Abstract $identity): array
    {
        $identity_id = static::__resolve_id($identity);
        $model = static::_credential_model();
        $identity_model = static::_identity_model();

        $email = (string) ($identity_model::where('id', $identity_id)->value('email') ?? '');

        $rows = $model::where(static::_owner_column(), $identity_id)
            ->whereIn('type_id', $model::factor_types())
            ->orderBy('id')
            ->result_set();

        $out = [];

        foreach ($rows as $row) {
            $secret = null;
            $uri = null;

            if ((int) $row->type_id === $model::TYPE_TOTP && $row->secret !== null) {
                $secret = Crypt::decryptString($row->secret);
                $uri = Totp::provisioning_uri($secret, $email, static::issuer());
            }

            $out[] = [
                'id' => (int) $row->id,
                'type_id' => (int) $row->type_id,
                'type_id__label' => $row->type_id__label,
                'label' => $row->label,
                'confirmed_at' => $row->confirmed_at,
                'last_used_at' => $row->last_used_at,
                'counter' => (int) $row->counter,
                'secret' => $secret,
                'otpauth_uri' => $uri,
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Removing factors
    // -------------------------------------------------------------------------

    /**
     * Remove one factor, and the recovery codes with it if it was the last one.
     *
     * THE CASCADE IS THE POINT. Recovery codes exist to recover a factor; with no factor
     * left they are not a recovery path, they are a set of bearer tokens that sign somebody
     * in with no second step at all. Leaving them behind would mean a user who removed
     * their last factor still has ten credentials they have forgotten about written on a
     * piece of paper somewhere.
     *
     * Removing a credential that is not this identity's is a no-op, not an error - a stale
     * settings screen naming a row that has already gone is a race, not an attack, and the
     * outcome the caller wanted is the outcome they get.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @param int $credential_id The credential row id.
     * @return void
     */
    public static function remove_credential(int|Rsx_Model_Abstract $identity, int $credential_id): void
    {
        $identity_id = static::__resolve_id($identity);
        $model = static::_credential_model();
        $owner = static::_owner_column();

        $model::where($owner, $identity_id)
            ->whereIn('type_id', $model::factor_types())
            ->where('id', $credential_id)
            ->delete();

        if (static::is_enabled($identity_id)) {
            return;
        }

        $model::where($owner, $identity_id)
            ->where('type_id', $model::TYPE_RECOVERY_CODE)
            ->delete();
    }

    /**
     * Remove every credential this identity holds, recovery codes included.
     *
     * The administrative unlock, and the thing an account deletion runs. It leaves the
     * identity able to sign in with a password alone, so a caller is expected to have
     * already decided that is acceptable.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return void
     */
    public static function remove_all(int|Rsx_Model_Abstract $identity): void
    {
        $model = static::_credential_model();

        $model::where(static::_owner_column(), static::__resolve_id($identity))->delete();
    }

    // -------------------------------------------------------------------------
    // Enrollment - TOTP
    // -------------------------------------------------------------------------

    /**
     * Begin enrolling an authenticator app: mint a seed, park it, and render the QR code.
     *
     * The seed is returned in plaintext because it HAS to be - the user is about to type it
     * into their phone, or photograph the QR code that encodes it. It is parked in a
     * session value rather than written to a row, so an enrollment the user walks away from
     * leaves nothing behind. Nothing is confirmed until confirm_totp_enrollment() sees a
     * live code.
     *
     * @return array {secret, otpauth_uri, qr_svg}
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     */
    public static function begin_totp_enrollment(): array
    {
        $identity = static::__enrolling_identity();

        $secret = Totp::generate_secret();
        $uri = Totp::provisioning_uri($secret, (string) $identity->email, static::issuer());

        Session::put_value(static::TOTP_PENDING_KEY, $secret, static::challenge_expires_at());

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => static::__qr_svg($uri),
        ];
    }

    /**
     * Finish enrolling an authenticator app by proving a live code, and mint the recovery
     * codes that back it up.
     *
     * THE PROOF IS THE WHOLE CEREMONY. Storing a seed the user never demonstrated would
     * hand out a second factor that locks them out on their next login - a mistyped seed,
     * a phone with a wrong clock, an app that never saved it. One correct code settles all
     * three.
     *
     * The timestep this confirmation consumes is persisted into counter, so the code the
     * user just typed cannot immediately be replayed against the login challenge.
     *
     * The returned plaintext codes are the ONLY time they exist. See Recovery_Codes.
     *
     * @param string $code The code from the authenticator app.
     * @return array The plaintext recovery codes.
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     * @throws Two_Factor_Failed_Exception When the enrollment expired or the code is wrong.
     */
    public static function confirm_totp_enrollment(string $code): array
    {
        $identity = static::__enrolling_identity();

        $secret = Session::get_value(static::TOTP_PENDING_KEY);

        if (!is_string($secret) || $secret === '') {
            throw new Two_Factor_Failed_Exception('That setup has expired. Please start again.');
        }

        $timestep = Totp::verify($secret, $code, 0);

        if ($timestep === false) {
            // The pending seed is deliberately NOT forgotten: a mistyped code is the normal
            // case, and making the user rescan the QR code for a typo would be hostile.
            // The window on the session value is what bounds the retries.
            throw new Two_Factor_Failed_Exception('That code is not valid. Please try again.');
        }

        $model = static::_credential_model();
        $owner = static::_owner_column();

        $row = new $model();
        $row->$owner = (int) $identity->id;
        $row->type_id = $model::TYPE_TOTP;
        $row->label = 'Authenticator app';
        $row->secret = Crypt::encryptString($secret);
        $row->counter = $timestep;
        $row->confirmed_at = Rsx_Time::now_iso();
        $row->save();

        Session::forget_value(static::TOTP_PENDING_KEY);

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for(static::class, (int) $identity->id, $codes);

        return $codes;
    }

    // -------------------------------------------------------------------------
    // Enrollment - passkeys
    // -------------------------------------------------------------------------

    /**
     * Begin registering a passkey: the args for navigator.credentials.create().
     *
     * @return array
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     */
    public static function begin_passkey_registration(): array
    {
        return Passkeys::registration_options(static::class, static::__enrolling_identity());
    }

    /**
     * Finish registering a passkey.
     *
     * Recovery codes are minted here ONLY IF the identity has none - a user whose first
     * factor is a passkey needs the same way back in as one who started with an
     * authenticator app, and a user adding a second passkey must not have the codes they
     * already wrote down silently invalidated. That is why this returns array|null rather
     * than always an array: null means "nothing new to show", and the UI reveals the code
     * sheet only when there is one.
     *
     * @param array $attestation The browser's attestation response.
     * @param string|null $label What the user calls this key, shown in their settings.
     * @return array|null Freshly minted recovery codes, or null if the identity had some.
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     * @throws Two_Factor_Failed_Exception When the ceremony is stale or malformed.
     */
    public static function confirm_passkey_registration(array $attestation, ?string $label): ?array
    {
        $identity = static::__enrolling_identity();
        $identity_id = (int) $identity->id;

        $verified = Passkeys::verify_registration(static::class, $attestation);

        $had_codes = Recovery_Codes::remaining(static::class, $identity_id) > 0;

        $model = static::_credential_model();
        $owner = static::_owner_column();

        $row = new $model();
        $row->$owner = $identity_id;
        $row->type_id = $model::TYPE_PASSKEY;
        $row->label = static::__clean_label($label) ?? 'Passkey';
        $row->secret = $verified['public_key'];
        $row->credential_key = $verified['credential_key'];
        $row->counter = $verified['sign_count'];
        $row->confirmed_at = Rsx_Time::now_iso();
        $row->save();

        if ($had_codes) {
            return null;
        }

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for(static::class, $identity_id, $codes);

        return $codes;
    }

    // -------------------------------------------------------------------------
    // Recovery codes
    // -------------------------------------------------------------------------

    /**
     * Replace this identity's recovery codes and return the new plaintext set.
     *
     * The previous set stops working the moment this returns - that is the point of it,
     * and it is what a user who thinks their codes were seen is asking for.
     *
     * @return array The plaintext codes.
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     */
    public static function regenerate_recovery_codes(): array
    {
        $identity = static::__enrolling_identity();

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for(static::class, (int) $identity->id, $codes);

        return $codes;
    }

    // -------------------------------------------------------------------------
    // The second-factor challenge
    // -------------------------------------------------------------------------

    /**
     * Park an identity that has passed its password, and sign the realm out.
     *
     * The order is deliberate: the pending value is WRITTEN FIRST and the sign-out follows.
     * Session::put_value() is a writer, so it establishes the session row the value hangs
     * off; signing out afterwards clears the row's identity but not the row, so the value
     * survives. Doing it the other way round would park the value on a session the caller
     * has already abandoned.
     *
     * The email is stored alongside the id because verify_challenge() has to record the
     * outcome against the address that was ATTEMPTED, which is a question about this attempt
     * rather than about the identity's current state.
     *
     * @param Rsx_Model_Abstract $identity The identity whose password just verified.
     * @return void
     */
    public static function begin_challenge(Rsx_Model_Abstract $identity): void
    {
        Session::put_value(
            static::CHALLENGE_KEY,
            [
                'identity_id' => static::__resolve_id($identity),
                'email' => (string) $identity->email,
            ],
            static::challenge_expires_at()
        );

        static::__sign_out();
    }

    /**
     * What the challenge screen needs to render itself, or null when there is nothing
     * pending.
     *
     * An EXPIRED challenge reads as null, because Session::get_value() filters on the
     * expiry rather than trusting a sweeper - so "expired" and "never existed" are the same
     * answer here, which is the only answer a challenge screen needs.
     *
     * The email is returned MASKED. The screen has to show the user which account they are
     * signing in to, but the page is reachable by anyone holding the session cookie and the
     * full address is not theirs to read.
     *
     * @return array|null {email_masked, has_totp, has_passkey}
     */
    public static function challenge_pending(): ?array
    {
        $pending = static::__pending_challenge();

        if ($pending === null) {
            return null;
        }

        $model = static::_credential_model();
        $identity_id = $pending['identity_id'];

        return [
            'email_masked' => static::__mask_email($pending['email']),
            'has_totp' => static::__has_confirmed($identity_id, $model::TYPE_TOTP),
            'has_passkey' => static::__has_confirmed($identity_id, $model::TYPE_PASSKEY),
        ];
    }

    /**
     * The args for navigator.credentials.get() for the pending identity.
     *
     * @return array
     * @throws Two_Factor_Failed_Exception When nothing is pending.
     */
    public static function challenge_passkey_options(): array
    {
        $pending = static::__pending_challenge();

        if ($pending === null) {
            throw new Two_Factor_Failed_Exception('Your verification window has expired. Please sign in again.');
        }

        return Passkeys::assertion_options(static::class, $pending['identity_id']);
    }

    /**
     * Answer the challenge. On success the caller is SIGNED IN and the success is recorded.
     *
     * THE ORDER OF THIS METHOD IS ITS CONTRACT:
     *
     *  1. Login_Throttle::require_not_throttled() is the FIRST statement, and it THROWS
     *     Auth_Throttled_Exception rather than returning false. An unthrottled second
     *     factor is a six-digit guessing oracle - a million codes, three of them live at
     *     any moment - so the budget has to be spent before any work happens. The throw is
     *     let through untouched: "we did not check" is a different answer from "that was
     *     wrong", and a login function must be able to say so.
     *  2. The pending identity is loaded, or the window has closed.
     *  3. A passkey assertion is tried when one was offered; otherwise a typed code is
     *     tried against every confirmed TOTP credential and then against the recovery
     *     codes. Recovery LAST, so a string that is a live TOTP code never burns a
     *     recovery code.
     *  4. A failure is recorded ONCE, through the realm (Login_History::record_failure()
     *     with STATUS_FAILED_2FA for staff, which already feeds Login_Throttle; the throttle
     *     directly for the portal, whose outcomes have no history store). Never both: one
     *     failure counted twice halves the real budget, and the halving would only be
     *     discovered by a user locked out early.
     *  5. On success the pending value is forgotten FIRST, then the realm signs the identity
     *     in, then the success is recorded.
     *  6. The sign-in REFUSES an identity the realm will not admit - a staff identity holding
     *     no active site membership (users.is_enabled + sites.is_enabled), a portal user the portal's account
     *     vocabulary rejects (Portal_User_Model::can_login(), or a user of another site). A
     *     correct code from such an identity is recorded STATUS_FAILED_DISABLED and then fails
     *     with the wrong-code message, so the two are indistinguishable from the outside.
     *
     * @param array $input {assertion: array} or {code: string}.
     * @return Rsx_Model_Abstract The identity now signed in (Login_User_Model for staff,
     *                            Portal_User_Model for the portal).
     * @throws \App\RSpade\Core\Auth\Auth_Throttled_Exception When the client IP is locked out.
     * @throws Two_Factor_Failed_Exception When the window has closed, the answer is wrong, or the
     *         realm will not admit the identity.
     */
    public static function verify_challenge(array $input): Rsx_Model_Abstract
    {
        Login_Throttle::require_not_throttled();

        $pending = static::__pending_challenge();

        if ($pending === null) {
            throw new Two_Factor_Failed_Exception('Your verification window has expired. Please sign in again.');
        }

        $identity_id = $pending['identity_id'];
        $email = $pending['email'];

        $identity = static::__find_identity($identity_id);

        if ($identity === null) {
            // The identity was deleted between the password and the second factor. Nothing
            // to sign in to, and nothing the person at the keyboard can do about it.
            static::abandon_challenge();

            throw new Two_Factor_Failed_Exception('Your verification window has expired. Please sign in again.');
        }

        $verified = false;

        if (isset($input['assertion']) && is_array($input['assertion'])) {
            $verified = static::__try_assertion($input['assertion'], $identity_id);
        } elseif (isset($input['code']) && is_string($input['code'])) {
            $verified = static::__try_code($input['code'], $identity_id);
        }

        if (!$verified) {
            static::__record_failure($email, Login_History::STATUS_FAILED_2FA, $identity_id);

            throw new Two_Factor_Failed_Exception('That code is not valid.');
        }

        static::abandon_challenge();

        // The second factor was answered correctly, and the realm still will not admit the
        // identity - so the sign-in is refused with the same message a wrong code gets. The
        // realm's admission rule is not something to explain to whoever is typing; the audit
        // trail carries the real classification.
        if (!static::__sign_in($identity)) {
            static::__record_failure($email, Login_History::STATUS_FAILED_DISABLED, $identity_id);

            throw new Two_Factor_Failed_Exception('That code is not valid.');
        }

        static::__record_success($identity, $email);

        return $identity;
    }

    /**
     * Discard the pending challenge - the user pressed cancel, or the flow is done.
     *
     * @return void
     */
    public static function abandon_challenge(): void
    {
        Session::forget_value(static::CHALLENGE_KEY);
    }

    // -------------------------------------------------------------------------
    // Passwordless sign-in
    // -------------------------------------------------------------------------

    /**
     * Begin a PASSWORDLESS sign-in: the args for navigator.credentials.get(), with no
     * identity named.
     *
     * Callable by an anonymous session - it is the first step of signing in. Nothing about
     * any identity is read or revealed: there is no allowCredentials list, so the
     * authenticator offers whatever discoverable credential it holds for this relying party,
     * and the challenge parks under the realm's PASSKEY_LOGIN_CHALLENGE_KEY, where an
     * in-flight second-factor challenge cannot overwrite it or be overwritten by it.
     *
     * @return array JSON-safe request args.
     */
    public static function begin_passkey_login(): array
    {
        return Passkeys::discoverable_assertion_options(static::class);
    }

    /**
     * Verify a passwordless assertion and SIGN IN the identity that answered.
     *
     * The same guarantees verify_challenge() gives, in the same order and for the same
     * reasons:
     *
     *  1. Login_Throttle::require_not_throttled() is the FIRST statement and THROWS. This
     *     endpoint is reachable by anyone, so it spends the same budget a password does.
     *  2. The assertion is verified in the REALM's table with user verification REQUIRED
     *     (see Passkeys::discoverable_assertion_options()). The identity comes from the
     *     credential row, never from anything the client said.
     *  3. A failure is recorded ONCE through the realm - STATUS_FAILED_PASSKEY - and answers
     *     with one sentence whatever went wrong, so the response never says whether a key
     *     exists, belongs to anybody, or merely failed to verify.
     *  4. Any pending second-factor challenge is forgotten: this sign-in supersedes it.
     *  5. The realm signs the identity in, refusing one it will not admit (no enabled site
     *     membership for staff; can_login() or another site for the portal). That refusal is
     *     recorded STATUS_FAILED_DISABLED and answers exactly like a failed assertion.
     *  6. The success is recorded.
     *
     * NO SECOND FACTOR FOLLOWS. A user-verified passkey is a complete sign-in - see the class
     * docblock for the ruling and what it means for is_enabled() and SSO.
     *
     * @param array $assertion {id, clientDataJSON, authenticatorData, signature}, base64url.
     * @return Rsx_Model_Abstract The identity now signed in (Login_User_Model for staff,
     *                            Portal_User_Model for the portal).
     * @throws \App\RSpade\Core\Auth\Auth_Throttled_Exception When the client IP is locked out.
     * @throws Two_Factor_Failed_Exception When the assertion does not sign anybody in.
     */
    public static function verify_passkey_login(array $assertion): Rsx_Model_Abstract
    {
        Login_Throttle::require_not_throttled();

        $refusal = 'That passkey could not sign you in.';

        try {
            $credential = Passkeys::verify_assertion(static::class, $assertion, true);
        } catch (Two_Factor_Failed_Exception | \lbuchs\WebAuthn\WebAuthnException $e) {
            // Caught NARROWLY: these are the verdicts of a verification, and each becomes the
            // one recorded failure and the one sentence. Nothing else is swallowed.
            static::__record_failure('', Login_History::STATUS_FAILED_PASSKEY, null, $e->getMessage());

            throw new Two_Factor_Failed_Exception($refusal);
        }

        $identity_id = (int) $credential->{static::_owner_column()};
        $identity = static::__find_identity($identity_id);

        if ($identity === null) {
            static::__record_failure('', Login_History::STATUS_FAILED_PASSKEY, $identity_id, 'credential names no identity');

            throw new Two_Factor_Failed_Exception($refusal);
        }

        $email = (string) $identity->email;

        static::abandon_challenge();

        if (!static::__sign_in($identity)) {
            static::__record_failure($email, Login_History::STATUS_FAILED_DISABLED, $identity_id);

            throw new Two_Factor_Failed_Exception($refusal);
        }

        static::__record_success($identity, $email);

        return $identity;
    }

    // -------------------------------------------------------------------------
    // Verification internals
    // -------------------------------------------------------------------------

    /**
     * Try a passkey assertion, and confirm it belongs to the identity that is pending.
     *
     * THE OWNERSHIP CHECK IS NOT REDUNDANT. verify_assertion() proves the signature came
     * from a credential this realm issued; it does not prove that credential belongs to
     * the account whose password was just entered. Without this comparison anybody holding
     * any valid passkey could complete anybody else's challenge.
     *
     * @param array $assertion
     * @param int $identity_id The pending identity.
     * @return bool
     */
    protected static function __try_assertion(array $assertion, int $identity_id): bool
    {
        try {
            $credential = Passkeys::verify_assertion(static::class, $assertion);
        } catch (Two_Factor_Failed_Exception | \lbuchs\WebAuthn\WebAuthnException $e) {
            // Caught NARROWLY and only to turn a verification verdict into this method's
            // boolean, so the single failure-recording path in verify_challenge() handles
            // it like every other wrong answer. Nothing else is swallowed.
            return false;
        }

        return (int) $credential->{static::_owner_column()} === $identity_id;
    }

    /**
     * Try a typed code: every confirmed TOTP credential first, then the recovery codes.
     *
     * A successful TOTP verification PERSISTS the accepted timestep into counter before
     * returning, which is what makes the code single-use - see Totp::verify().
     *
     * @param string $code
     * @param int $identity_id
     * @return bool
     */
    protected static function __try_code(string $code, int $identity_id): bool
    {
        $model = static::_credential_model();

        $rows = $model::where(static::_owner_column(), $identity_id)
            ->where('type_id', $model::TYPE_TOTP)
            ->whereNotNull('confirmed_at')
            ->result_set();

        foreach ($rows as $row) {
            if ($row->secret === null) {
                continue;
            }

            $timestep = Totp::verify(Crypt::decryptString($row->secret), $code, (int) $row->counter);

            if ($timestep === false) {
                continue;
            }

            $row->counter = $timestep;
            $row->last_used_at = Rsx_Time::now_iso();
            $row->save();

            return true;
        }

        return Recovery_Codes::consume(static::class, $identity_id, $code);
    }

    /**
     * The pending challenge, validated into shape, or null.
     *
     * @return array|null {identity_id, email}
     */
    protected static function __pending_challenge(): ?array
    {
        $pending = Session::get_value(static::CHALLENGE_KEY);

        if (!is_array($pending) || !isset($pending['identity_id'], $pending['email'])) {
            return null;
        }

        return [
            'identity_id' => (int) $pending['identity_id'],
            'email' => (string) $pending['email'],
        ];
    }

    /**
     * Does this identity hold a confirmed credential of this type?
     *
     * @param int $identity_id
     * @param int $type_id
     * @return bool
     */
    protected static function __has_confirmed(int $identity_id, int $type_id): bool
    {
        $model = static::_credential_model();

        return $model::where(static::_owner_column(), $identity_id)
            ->where('type_id', $type_id)
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The identity that may enroll a credential right now.
     *
     * Enrollment always operates on the SIGNED-IN identity of this realm and never on one
     * named by an argument: "add a credential to that account over there" is not an
     * operation this subsystem offers, because there is no way for the person it would
     * affect to see it happen.
     *
     * @return Rsx_Model_Abstract
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     */
    protected static function __enrolling_identity(): Rsx_Model_Abstract
    {
        if (static::__is_impersonating()) {
            throw new RuntimeException('Two-factor enrollment is not available while impersonating another user.');
        }

        $identity = static::__signed_in_identity();

        if ($identity === null) {
            throw new RuntimeException('Two-factor enrollment requires a signed-in identity.');
        }

        return $identity;
    }

    /**
     * An identity id from either spelling of the argument - and a REFUSAL of an identity
     * from the other realm.
     *
     * The type check is what keeps the two facades honest at the call site: handing a
     * Portal_User_Model to Rsx_Two_Factor would otherwise read a portal user's id as a staff
     * login id, which is a different person.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return int
     */
    protected static function __resolve_id(int|Rsx_Model_Abstract $identity): int
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

    /**
     * An address with its local part reduced to first and last character.
     *
     * Enough for the account holder to recognise their own address, not enough for somebody
     * who found the browser open to learn one they did not already know. The domain is left
     * intact: it is rarely the secret and hiding it makes the screen unreadable.
     *
     * @param string $email
     * @return string
     */
    protected static function __mask_email(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at < 1) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        if (strlen($local) <= 2) {
            return str_repeat('*', strlen($local)) . $domain;
        }

        return $local[0] . str_repeat('*', strlen($local) - 2) . $local[strlen($local) - 1] . $domain;
    }

    /**
     * A user-supplied credential label, trimmed to what the column holds, or null.
     *
     * @param string|null $label
     * @return string|null
     */
    protected static function __clean_label(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);

        if ($label === '') {
            return null;
        }

        return mb_substr($label, 0, 100);
    }

    /**
     * The provisioning URI as an inline-embeddable SVG.
     *
     * The XML declaration the renderer emits is stripped: the string is destined for a
     * jqhtml template, where it is embedded INSIDE an existing HTML document, and an
     * "<?xml ...?>" prologue partway down a page is invalid markup.
     *
     * @param string $uri
     * @return string An SVG document starting at <svg.
     */
    protected static function __qr_svg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::QR_SIZE, 1),
            new SvgImageBackEnd()
        ));

        $svg = $writer->writeString($uri);

        $start = strpos($svg, '<svg');

        if ($start === false) {
            shouldnt_happen('The QR renderer produced no <svg> element');
        }

        return substr($svg, $start);
    }

    // -------------------------------------------------------------------------
    // THE REALM - what each facade answers
    // -------------------------------------------------------------------------
    //
    // Each facade also declares four session value keys as class constants, read here
    // through static:: - CHALLENGE_KEY (the pending second-factor challenge),
    // TOTP_PENDING_KEY (an in-flight authenticator-app seed), WEBAUTHN_CHALLENGE_KEY (a
    // registration or second-factor assertion ceremony) and PASSKEY_LOGIN_CHALLENGE_KEY (a
    // passwordless ceremony). The two realms' keys differ, because both realms' values can
    // sit on the one session row a browser has at the same moment.

    /**
     * The credential model class this realm stores rows in.
     *
     * Framework-internal; Passkeys and Recovery_Codes read it.
     *
     * @return string
     */
    abstract public static function _credential_model(): string;

    /**
     * The credential table's owner column (login_user_id, portal_user_id).
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
     * The WebAuthn relying party id this realm's passkeys are bound to: a bare hostname.
     *
     * @return string
     */
    abstract public static function _relying_party_id(): string;

    /**
     * The WebAuthn user handle for one identity - see Passkeys::registration_options() for
     * why the two realms' handles must never collide.
     *
     * @param int $identity_id
     * @return string
     */
    abstract public static function _user_handle(int $identity_id): string;

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
     * Sign an identity in to this realm, or refuse one the realm will not admit.
     *
     * @param Rsx_Model_Abstract $identity
     * @return bool False when the realm refuses the identity.
     */
    abstract protected static function __sign_in(Rsx_Model_Abstract $identity): bool;

    /**
     * Sign this realm out, leaving the session row (and its values) in place.
     *
     * @return void
     */
    abstract protected static function __sign_out(): void;

    /**
     * Record a completed sign-in.
     *
     * @param Rsx_Model_Abstract $identity
     * @param string $email The address the sign-in was for.
     * @return void
     */
    abstract protected static function __record_success(Rsx_Model_Abstract $identity, string $email): void;

    /**
     * Record one failed attempt - exactly once, and in a way that feeds Login_Throttle.
     *
     * @param string $email The address attempted, '' when none is known.
     * @param string $status A Login_History::STATUS_FAILED_* constant.
     * @param int|null $identity_id The identity, when known.
     * @param string|null $reason Detail for the log, never for the screen.
     * @return void
     */
    abstract protected static function __record_failure(
        string $email,
        string $status,
        ?int $identity_id,
        ?string $reason = null
    ): void;
}
