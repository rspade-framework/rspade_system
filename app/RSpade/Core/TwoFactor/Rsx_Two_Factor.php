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
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Passkeys;
use App\RSpade\Core\TwoFactor\Recovery_Codes;
use App\RSpade\Core\TwoFactor\Totp;
use App\RSpade\Core\TwoFactor\Two_Factor_Credential_Model;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;

/**
 * Rsx_Two_Factor - THE second-factor facade, and the only class in this subsystem
 * application code touches.
 *
 * Totp, Passkeys, Recovery_Codes and Two_Factor_Credential_Model are implementation. An
 * application asks THIS class whether an identity has a second factor, enrolls one, and
 * runs the login challenge; it never reaches past it. That boundary is what lets the
 * storage shape, the library behind passkeys and the encryption of a seed change without a
 * single call site moving.
 *
 * THE LOGIN FLOW, end to end, because the ordering is the security property:
 *
 *   1. The login function verifies the password with RsxAuth::attempt(record: false,
 *      touch_last_login: false). The identity is now established but nothing is recorded
 *      and nothing is stamped - it is not yet a login.
 *   2. If is_enabled() is false, the login function records its own success and is done.
 *   3. Otherwise it calls begin_challenge($login_user), which parks the pending identity in
 *      a session value and LOGS THE SESSION BACK OUT. From here until the second factor is
 *      answered nothing is authenticated.
 *   4. The challenge screen reads challenge_pending() and calls verify_challenge($input).
 *      That is the method that logs the user in and records the success.
 *
 * WHY STEP 3 LOGS OUT. Between the password and the second factor the browser holds a
 * HALF-authenticated state, and the only safe representation of half-authenticated is NOT
 * authenticated. If the session stayed logged in while carrying a "needs 2FA" flag, then
 * every surface that has to honour that flag - every route, every Ajax endpoint, every
 * background refresh - is a place the flag can be forgotten, and forgetting it means the
 * second factor was optional. Logging out leaves nothing to forget: the pending state is
 * inert data that only verify_challenge() knows how to redeem.
 *
 * SESSION VALUES SURVIVE LOGOUT. Session::logout() clears the session's IDENTITY; it does
 * not delete the _sessions row, and _session_values rows hang off that row by FK. So the
 * pending challenge written before the logout is still readable after it. That is the
 * mechanism the whole flow rests on, and it is why the pending value carries its own
 * expiry rather than relying on the session's.
 *
 * THE CHALLENGE WINDOW IS A SECURITY WINDOW, NOT A TIMEOUT (see the timeout mandate). It
 * does not bound how long any operation may take and nothing fails when it expires - the
 * expired state is a working outcome that says "sign in again". What it bounds is how long
 * a passed-password state stays redeemable, because a half-authenticated identity left
 * live forever is a password that has already been proven waiting on an unattended screen.
 * config('rsx.two_factor.challenge_window_minutes').
 *
 * A TOTP SEED IS ENCRYPTED AT REST, NOT HASHED, and this is the first deliberate use of
 * Crypt in the framework. A password is verified by hashing the guess, so it never needs to
 * be recoverable; a TOTP seed is recoverable BY DESIGN, because the server has to
 * regenerate the same codes the phone does, and there is no one-way form of it that still
 * works. Encryption is therefore the strongest available posture, and it is worth having:
 * it means a leaked database dump alone does not let an attacker generate live codes.
 *
 * ENROLLMENT REFUSES WHILE IMPERSONATING. Every enrollment method throws when
 * Session::is_impersonating(), because an administrator viewing an account must never be
 * able to attach a second factor to it - that would be an authentication backdoor wearing
 * a support tool's clothes, and the user whose account it is would have no way to see it
 * happen.
 *
 * See: php artisan rsx:man two_factor
 */
class Rsx_Two_Factor
{
    /**
     * Session value key holding the identity that has passed its password and is waiting on
     * a second factor.
     */
    public const CHALLENGE_KEY = 'two_factor.challenge';

    /**
     * Session value key holding an in-flight TOTP seed, before it has been confirmed.
     *
     * PENDING, not stored on a row: a seed nobody has proved they can generate codes from
     * is not a credential. Keeping it out of the table means an abandoned enrollment leaves
     * nothing behind that a later query has to remember to filter out.
     */
    public const TOTP_PENDING_KEY = 'two_factor.totp_pending';

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
     * @param int|Login_User_Model $login_user
     * @return bool
     */
    public static function is_enabled(int|Login_User_Model $login_user): bool
    {
        $login_user_id = self::_resolve_id($login_user);

        return Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->whereIn('type_id', Two_Factor_Credential_Model::factor_types())
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    /**
     * This identity's factors, as METADATA ONLY - never a secret, a seed, a hash or a
     * public key.
     *
     * That restriction is the method's contract and not an oversight. Its output is built
     * for a settings screen, which means it reaches the browser, and there is no column on
     * this table a browser has any business holding. A caller that believes it needs the
     * secret is a caller that belongs inside this class.
     *
     * Recovery codes are excluded - they are a COUNT, not a list, and
     * recovery_codes_remaining() is where that count lives.
     *
     * @param int|Login_User_Model $login_user
     * @return array One row per factor, newest confirmation last.
     */
    public static function list_credentials(int|Login_User_Model $login_user): array
    {
        $login_user_id = self::_resolve_id($login_user);

        $rows = Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->whereIn('type_id', Two_Factor_Credential_Model::factor_types())
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
     * @param int|Login_User_Model $login_user
     * @return int
     */
    public static function recovery_codes_remaining(int|Login_User_Model $login_user): int
    {
        return Recovery_Codes::remaining(self::_resolve_id($login_user));
    }

    /**
     * Does this identity hold a confirmed authenticator-app credential?
     *
     * The one predicate behind "is a TOTP seed already enrolled", asked by the operator
     * commands before they mint one and by cli_setup_totp() before it writes. A boolean and
     * not a row: nothing outside this class has any business holding a seed.
     *
     * @param int|Login_User_Model $login_user
     * @return bool
     */
    public static function has_confirmed_totp(int|Login_User_Model $login_user): bool
    {
        return self::_has_confirmed(self::_resolve_id($login_user), Two_Factor_Credential_Model::TYPE_TOTP);
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
     * @param Login_User_Model $login_user The identity to arm.
     * @param string|null $label What the credential is called in the user's settings.
     * @return array {secret, otpauth_uri, recovery_codes}
     * @throws RuntimeException When the identity already holds a confirmed TOTP credential.
     */
    public static function cli_setup_totp(Login_User_Model $login_user, ?string $label = null): array
    {
        if (self::has_confirmed_totp($login_user)) {
            throw new RuntimeException(
                'That identity already holds a confirmed authenticator-app credential.'
            );
        }

        $secret = Totp::generate_secret();
        $uri = Totp::provisioning_uri($secret, (string) $login_user->email, self::issuer());

        $row = new Two_Factor_Credential_Model();
        $row->login_user_id = $login_user->id;
        $row->type_id = Two_Factor_Credential_Model::TYPE_TOTP;
        $row->label = self::_clean_label($label) ?? 'CLI setup';
        $row->secret = Crypt::encryptString($secret);
        $row->counter = 0;
        $row->confirmed_at = Rsx_Time::now_iso();
        $row->save();

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for((int) $login_user->id, $codes);

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
     * @param int|Login_User_Model $login_user
     * @return array One row per factor: {id, type_id, type_id__label, label, confirmed_at,
     *               last_used_at, counter, secret, otpauth_uri} - the last two null for a
     *               passkey.
     */
    public static function cli_dump_credentials(int|Login_User_Model $login_user): array
    {
        $login_user_id = self::_resolve_id($login_user);

        $email = (string) (Login_User_Model::where('id', $login_user_id)->value('email') ?? '');

        $rows = Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->whereIn('type_id', Two_Factor_Credential_Model::factor_types())
            ->orderBy('id')
            ->result_set();

        $out = [];

        foreach ($rows as $row) {
            $secret = null;
            $uri = null;

            if ((int) $row->type_id === Two_Factor_Credential_Model::TYPE_TOTP && $row->secret !== null) {
                $secret = Crypt::decryptString($row->secret);
                $uri = Totp::provisioning_uri($secret, $email, self::issuer());
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
     * left they are not a recovery path, they are a set of bearer tokens that log somebody
     * in with no second step at all. Leaving them behind would mean a user who removed
     * their last factor still has ten credentials they have forgotten about written on a
     * piece of paper somewhere.
     *
     * Removing a credential that is not this identity's is a no-op, not an error - a stale
     * settings screen naming a row that has already gone is a race, not an attack, and the
     * outcome the caller wanted is the outcome they get.
     *
     * @param int|Login_User_Model $login_user
     * @param int $credential_id The _two_factor_credentials row id.
     * @return void
     */
    public static function remove_credential(int|Login_User_Model $login_user, int $credential_id): void
    {
        $login_user_id = self::_resolve_id($login_user);

        Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->whereIn('type_id', Two_Factor_Credential_Model::factor_types())
            ->where('id', $credential_id)
            ->delete();

        if (self::is_enabled($login_user_id)) {
            return;
        }

        Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_RECOVERY_CODE)
            ->delete();
    }

    /**
     * Remove every second-factor credential this identity holds, recovery codes included.
     *
     * The administrative unlock, and the thing an account deletion runs. It leaves the
     * identity able to sign in with a password alone, so a caller is expected to have
     * already decided that is acceptable.
     *
     * @param int|Login_User_Model $login_user
     * @return void
     */
    public static function remove_all(int|Login_User_Model $login_user): void
    {
        Two_Factor_Credential_Model::where('login_user_id', self::_resolve_id($login_user))->delete();
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
        $login_user = self::_enrolling_identity();

        $secret = Totp::generate_secret();
        $uri = Totp::provisioning_uri($secret, (string) $login_user->email, self::issuer());

        Session::put_value(self::TOTP_PENDING_KEY, $secret, self::challenge_expires_at());

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => self::_qr_svg($uri),
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
     * last_accepted_timestep is 0 because the credential is brand new and has spent
     * nothing; the timestep this confirmation consumes is then persisted, so the code the
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
        $login_user = self::_enrolling_identity();

        $secret = Session::get_value(self::TOTP_PENDING_KEY);

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

        $row = new Two_Factor_Credential_Model();
        $row->login_user_id = $login_user->id;
        $row->type_id = Two_Factor_Credential_Model::TYPE_TOTP;
        $row->label = 'Authenticator app';
        $row->secret = Crypt::encryptString($secret);
        $row->counter = $timestep;
        $row->confirmed_at = Rsx_Time::now_iso();
        $row->save();

        Session::forget_value(self::TOTP_PENDING_KEY);

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for((int) $login_user->id, $codes);

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
        return Passkeys::registration_options(self::_enrolling_identity());
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
        $login_user = self::_enrolling_identity();

        $verified = Passkeys::verify_registration($attestation);

        $had_codes = Recovery_Codes::remaining((int) $login_user->id) > 0;

        $row = new Two_Factor_Credential_Model();
        $row->login_user_id = $login_user->id;
        $row->type_id = Two_Factor_Credential_Model::TYPE_PASSKEY;
        $row->label = self::_clean_label($label) ?? 'Passkey';
        $row->secret = $verified['public_key'];
        $row->credential_key = $verified['credential_key'];
        $row->counter = $verified['sign_count'];
        $row->confirmed_at = Rsx_Time::now_iso();
        $row->save();

        if ($had_codes) {
            return null;
        }

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for((int) $login_user->id, $codes);

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
        $login_user = self::_enrolling_identity();

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for((int) $login_user->id, $codes);

        return $codes;
    }

    // -------------------------------------------------------------------------
    // The login challenge
    // -------------------------------------------------------------------------

    /**
     * Park an identity that has passed its password, and log the session out.
     *
     * The order is deliberate: the pending value is WRITTEN FIRST and the logout follows.
     * Session::put_value() is a writer, so it establishes the session row the value hangs
     * off; logging out afterwards clears the row's identity but not the row, so the value
     * survives. Doing it the other way round would park the value on a session the caller
     * has already abandoned.
     *
     * The email is stored alongside the id because verify_challenge() has to record a
     * login-history outcome against the address that was ATTEMPTED, which is a question
     * about this attempt rather than about the identity's current state.
     *
     * @param Login_User_Model $login_user The identity whose password just verified.
     * @return void
     */
    public static function begin_challenge(Login_User_Model $login_user): void
    {
        Session::put_value(
            self::CHALLENGE_KEY,
            [
                'login_user_id' => (int) $login_user->id,
                'email' => (string) $login_user->email,
            ],
            self::challenge_expires_at()
        );

        Session::logout();
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
        $pending = self::_pending_challenge();

        if ($pending === null) {
            return null;
        }

        $login_user_id = $pending['login_user_id'];

        return [
            'email_masked' => self::_mask_email($pending['email']),
            'has_totp' => self::_has_confirmed($login_user_id, Two_Factor_Credential_Model::TYPE_TOTP),
            'has_passkey' => self::_has_confirmed($login_user_id, Two_Factor_Credential_Model::TYPE_PASSKEY),
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
        $pending = self::_pending_challenge();

        if ($pending === null) {
            throw new Two_Factor_Failed_Exception('Your verification window has expired. Please sign in again.');
        }

        return Passkeys::assertion_options($pending['login_user_id']);
    }

    /**
     * Answer the challenge. On success the caller is LOGGED IN and the success is recorded.
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
     *  4. A failure is recorded through Login_History::record_failure() with
     *     STATUS_FAILED_2FA - which ALREADY feeds Login_Throttle. Login_Throttle::
     *     record_failure() is NEVER called here: it would count the same failure twice and
     *     halve the real budget, and the halving would only be discovered by a user locked
     *     out early.
     *  5. On success the pending value is forgotten FIRST, then RsxAuth::login() stamps
     *     last_login, then the success is recorded. login() records nothing itself, by
     *     design, so this is the method that owns the history row.
     *
     * @param array $input {assertion: array} or {code: string}.
     * @return Login_User_Model The identity now signed in.
     * @throws \App\RSpade\Core\Auth\Auth_Throttled_Exception When the client IP is locked out.
     * @throws Two_Factor_Failed_Exception When the window has closed or the answer is wrong.
     */
    public static function verify_challenge(array $input): Login_User_Model
    {
        Login_Throttle::require_not_throttled();

        $pending = self::_pending_challenge();

        if ($pending === null) {
            throw new Two_Factor_Failed_Exception('Your verification window has expired. Please sign in again.');
        }

        $login_user_id = $pending['login_user_id'];
        $email = $pending['email'];

        $login_user = Login_User_Model::where('id', $login_user_id)->first();

        if ($login_user === null) {
            // The identity was deleted between the password and the second factor. Nothing
            // to log in to, and nothing the person at the keyboard can do about it.
            self::abandon_challenge();

            throw new Two_Factor_Failed_Exception('Your verification window has expired. Please sign in again.');
        }

        $verified = false;

        if (isset($input['assertion']) && is_array($input['assertion'])) {
            $verified = self::_try_assertion($input['assertion'], $login_user_id);
        } elseif (isset($input['code']) && is_string($input['code'])) {
            $verified = self::_try_code($input['code'], $login_user_id);
        }

        if (!$verified) {
            Login_History::record_failure($email, Login_History::STATUS_FAILED_2FA, null, $login_user_id);

            throw new Two_Factor_Failed_Exception('That code is not valid.');
        }

        self::abandon_challenge();

        RsxAuth::login($login_user);
        Login_History::record_success($login_user_id, $email);

        return $login_user;
    }

    /**
     * Discard the pending challenge - the user pressed cancel, or the flow is done.
     *
     * @return void
     */
    public static function abandon_challenge(): void
    {
        Session::forget_value(self::CHALLENGE_KEY);
    }

    // -------------------------------------------------------------------------
    // Verification internals
    // -------------------------------------------------------------------------

    /**
     * Try a passkey assertion, and confirm it belongs to the identity that is pending.
     *
     * THE OWNERSHIP CHECK IS NOT REDUNDANT. verify_assertion() proves the signature came
     * from a credential this server issued; it does not prove that credential belongs to
     * the account whose password was just entered. Without this comparison anybody holding
     * any valid passkey could complete anybody else's challenge.
     *
     * @param array $assertion
     * @param int $login_user_id The pending identity.
     * @return bool
     */
    private static function _try_assertion(array $assertion, int $login_user_id): bool
    {
        try {
            $credential = Passkeys::verify_assertion($assertion);
        } catch (Two_Factor_Failed_Exception | \lbuchs\WebAuthn\WebAuthnException $e) {
            // Caught NARROWLY and only to turn a verification verdict into this method's
            // boolean, so the single failure-recording path in verify_challenge() handles
            // it like every other wrong answer. Nothing else is swallowed.
            return false;
        }

        return (int) $credential->login_user_id === $login_user_id;
    }

    /**
     * Try a typed code: every confirmed TOTP credential first, then the recovery codes.
     *
     * A successful TOTP verification PERSISTS the accepted timestep into counter before
     * returning, which is what makes the code single-use - see Totp::verify().
     *
     * @param string $code
     * @param int $login_user_id
     * @return bool
     */
    private static function _try_code(string $code, int $login_user_id): bool
    {
        $rows = Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', Two_Factor_Credential_Model::TYPE_TOTP)
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

        return Recovery_Codes::consume($login_user_id, $code);
    }

    /**
     * The pending challenge, validated into shape, or null.
     *
     * @return array|null {login_user_id, email}
     */
    private static function _pending_challenge(): ?array
    {
        $pending = Session::get_value(self::CHALLENGE_KEY);

        if (!is_array($pending) || !isset($pending['login_user_id'], $pending['email'])) {
            return null;
        }

        return [
            'login_user_id' => (int) $pending['login_user_id'],
            'email' => (string) $pending['email'],
        ];
    }

    /**
     * Does this identity hold a confirmed credential of this type?
     *
     * @param int $login_user_id
     * @param int $type_id
     * @return bool
     */
    private static function _has_confirmed(int $login_user_id, int $type_id): bool
    {
        return Two_Factor_Credential_Model::where('login_user_id', $login_user_id)
            ->where('type_id', $type_id)
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The identity that may enroll a factor right now.
     *
     * Enrollment always operates on the SIGNED-IN identity and never on one named by an
     * argument: "add a second factor to that account over there" is not an operation this
     * subsystem offers, because there is no way for the person it would affect to see it
     * happen.
     *
     * @return Login_User_Model
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     */
    private static function _enrolling_identity(): Login_User_Model
    {
        if (Session::is_impersonating()) {
            throw new RuntimeException('Two-factor enrollment is not available while impersonating another user.');
        }

        $login_user = Session::get_login_user();

        if ($login_user === null) {
            throw new RuntimeException('Two-factor enrollment requires a signed-in identity.');
        }

        return $login_user;
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
    private static function _mask_email(string $email): string
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
    private static function _clean_label(?string $label): ?string
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
    private static function _qr_svg(string $uri): string
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
}
