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
use Illuminate\Support\Facades\DB;
use RuntimeException;
use lbuchs\WebAuthn\WebAuthnException;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Cache\Rsx_Counter;
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
 * WHAT A CHALLENGE ACCEPTS IS THE LOGIN FUNCTION'S DECISION. begin_challenge($identity,
 * $accepts) names the kinds of answer this one challenge takes - ANSWER_TOTP,
 * ANSWER_PASSKEY, ANSWER_RECOVERY_CODE, ANSWER_ISSUED_CODE - and verify_challenge() refuses
 * every other kind. The framework never decides it: whether a sign-in owes a second step at
 * all, and which steps satisfy it, is application policy, and the list is how the policy
 * reaches the one place it has to be enforced. Omitted, a challenge accepts what the
 * identity holds (its confirmed factors and any unspent recovery code).
 *
 * AN ISSUED CODE is a one-time code the FRAMEWORK generates and the APPLICATION delivers:
 * issue_code() mints six random digits, keeps only a keyed hash of them in the pending
 * challenge (a later code replaces an earlier one) and returns the digits to the caller,
 * who sends them however the application chooses - an email of the SECURITY category, an
 * SMS, a phone call. Verification is the same verify_challenge(), under the same throttle,
 * the same two attempt caps and the same recording as a TOTP code. The code lives exactly
 * as long as its challenge.
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
 * config('rsx.two_factor.challenge_window_minutes'). An in-flight WEBAUTHN ceremony's challenge
 * has its own, shorter window, derived from the ceremony timeout the browser is sent
 * (Passkeys::challenge_window_seconds()).
 *
 * PASSKEY ENROLLMENT CEREMONIES ARE RECORDED - begun, enrolled, failed, abandoned - through
 * the realm (__record_passkey_enrollment(): _login_history rows for staff, log lines for the
 * portal). A browser that gives up on a ceremony tells the server nothing, so "begun" is
 * written when the options are issued and a marker (the realm's PASSKEY_ENROLLMENT_KEY
 * session value, expiring with the challenge) is parked beside the challenge. The marker is
 * CLAIMED BY DELETION by exactly one of: the confirmation (enrolled / failed), the next begin
 * in the same browser (abandoned - superseded or expired), or the hourly
 * Session_Values_Cleanup_Service sweep once it has expired (abandoned) - so each ceremony
 * gets exactly one outcome. A marker lost with its whole session (session expiry, an
 * operator deleting the row) records no outcome; the begun row still stands.
 *
 * TWO ATTEMPT CAPS BOUND GUESSING, independent of the per-IP login throttle (which an
 * attacker defeats by rotating addresses). Every wrong answer to a challenge counts against
 * the CHALLENGE (challenge_max_failures: the answer that reaches it destroys the parked
 * challenge, so the password must be entered again) and against the IDENTITY
 * (identity_max_failures inside identity_failure_window_minutes, across all challenges and
 * addresses: once reached, verification is refused for that identity, a correct answer
 * included, until the window closes or clear_failures() runs). Both counters live in the
 * transient-counter store (Rsx_Counter), keyed by realm, so a staff identity and a portal
 * user with the same id never share one. config('rsx.two_factor.*').
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

    /** A challenge answer kind: a code from a confirmed authenticator app. */
    public const ANSWER_TOTP = 'totp';

    /** A challenge answer kind: an assertion from one of the identity's passkeys. */
    public const ANSWER_PASSKEY = 'passkey';

    /** A challenge answer kind: one of the identity's unspent recovery codes. */
    public const ANSWER_RECOVERY_CODE = 'recovery_code';

    /** A challenge answer kind: the code issue_code() minted for this challenge. */
    public const ANSWER_ISSUED_CODE = 'issued_code';

    /** Every answer kind, in the order the challenge screen offers them. */
    public const ANSWERS = [
        self::ANSWER_ISSUED_CODE,
        self::ANSWER_TOTP,
        self::ANSWER_PASSKEY,
        self::ANSWER_RECOVERY_CODE,
    ];

    /** Digits in an issued code. */
    private const ISSUED_CODE_DIGITS = 6;

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

    /**
     * Wrong answers one parked challenge survives (config rsx.two_factor.challenge_max_failures).
     *
     * @return int
     */
    public static function challenge_max_failures(): int
    {
        $max = (int) config('rsx.two_factor.challenge_max_failures');

        if ($max < 1) {
            shouldnt_happen('rsx.two_factor.challenge_max_failures must be at least 1');
        }

        return $max;
    }

    /**
     * Wrong answers one identity may give inside the failure window
     * (config rsx.two_factor.identity_max_failures).
     *
     * @return int
     */
    public static function identity_max_failures(): int
    {
        $max = (int) config('rsx.two_factor.identity_max_failures');

        if ($max < 1) {
            shouldnt_happen('rsx.two_factor.identity_max_failures must be at least 1');
        }

        return $max;
    }

    /**
     * The identity failure window in seconds - a SECURITY WINDOW bounding the guessing rate
     * (config rsx.two_factor.identity_failure_window_minutes; see its comment).
     *
     * @return int
     */
    public static function identity_failure_window_seconds(): int
    {
        $minutes = (int) config('rsx.two_factor.identity_failure_window_minutes');

        if ($minutes < 1) {
            shouldnt_happen('rsx.two_factor.identity_failure_window_minutes must be at least 1 minute');
        }

        return $minutes * 60;
    }

    /**
     * Is this identity refused second-factor verification because it has spent its
     * failure budget?
     *
     * @param int|Rsx_Model_Abstract $identity An identity of this realm, or its id.
     * @return bool
     */
    public static function is_locked(int|Rsx_Model_Abstract $identity): bool
    {
        return Rsx_Counter::get(static::__identity_failure_key(static::__resolve_id($identity)))
            >= static::identity_max_failures();
    }

    /**
     * Clear an identity's second-factor failure count, lifting a lock. The operator's
     * release for a user who locked themselves out (rsx:users:2fa:unlock), and what a
     * correct answer does on its own.
     *
     * @param int|Rsx_Model_Abstract $identity An identity of this realm, or its id.
     * @return void
     */
    public static function clear_failures(int|Rsx_Model_Abstract $identity): void
    {
        Rsx_Counter::reset(static::__identity_failure_key(static::__resolve_id($identity)));
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
        $identity = static::__enrolling_identity();

        // An earlier enrollment in this browser that never reached its confirmation is over:
        // the options below replace its challenge. Record it before its marker is replaced.
        $previous = static::__take_passkey_enrollment();

        if ($previous !== null) {
            static::__record_enrollment_from_marker(
                $previous['marker'],
                Login_History::STATUS_PASSKEY_ENROLL_ABANDONED,
                $previous['expired']
                    ? 'Never confirmed; the challenge expired'
                    : 'Superseded by a new enrollment attempt before it was confirmed'
            );
        }

        $options = Passkeys::registration_options(static::class, $identity);

        $client_context = Login_History::client_context();

        Session::put_value(
            static::PASSKEY_ENROLLMENT_KEY,
            [
                'identity_id' => (int) $identity->id,
                'email' => (string) $identity->email,
                'begun_at' => Rsx_Time::now_iso(),
                'ip_address' => $client_context['ip_address'],
                'user_agent' => $client_context['user_agent'],
            ],
            Passkeys::challenge_expires_at()
        );

        static::__record_passkey_enrollment(
            (int) $identity->id,
            (string) $identity->email,
            Login_History::STATUS_PASSKEY_ENROLL_BEGUN,
            null,
            $client_context
        );

        return $options;
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

        $pending = static::__take_passkey_enrollment();

        try {
            $verified = Passkeys::verify_registration(static::class, $attestation);
        } catch (Two_Factor_Failed_Exception | WebAuthnException $e) {
            // Recorded, then rethrown untouched: the caller's error handling is unchanged.
            // A confirmation arriving after the window is an ABANDONED ceremony (the browser
            // took longer than the server would wait), not a refused attestation.
            if ($pending !== null && $pending['expired']) {
                static::__record_enrollment_from_marker(
                    $pending['marker'],
                    Login_History::STATUS_PASSKEY_ENROLL_ABANDONED,
                    'Confirmation arrived after the challenge expired'
                );
            } else {
                static::__record_passkey_enrollment(
                    $identity_id,
                    (string) $identity->email,
                    Login_History::STATUS_PASSKEY_ENROLL_FAILED,
                    $e->getMessage(),
                    Login_History::client_context()
                );
            }

            throw $e;
        }

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

        static::__record_passkey_enrollment(
            $identity_id,
            (string) $identity->email,
            Login_History::STATUS_PASSKEY_ENROLLED,
            null,
            Login_History::client_context()
        );

        if ($had_codes) {
            return null;
        }

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for(static::class, $identity_id, $codes);

        return $codes;
    }

    /**
     * Record every EXPIRED, never-confirmed passkey enrollment of this realm as ABANDONED,
     * and delete its marker. Returns how many were recorded.
     *
     * Called by Session_Values_Cleanup_Service::cleanup_expired_values() BEFORE its generic
     * delete of expired session values - otherwise the generic delete would take the
     * markers and the outcome would never be written. Every expired marker is processed,
     * a keyset page at a time; each is claimed by deleting it, so a begin racing the sweep
     * for the same marker records it once, not twice.
     *
     * @return int
     */
    public static function record_expired_passkey_enrollments(): int
    {
        $now = Rsx_Time::to_database(Rsx_Time::now_iso());
        $recorded = 0;

        $rows = DB::table('_session_values')
            ->select(['id', 'value'])
            ->where('value_key', static::PASSKEY_ENROLLMENT_KEY)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->lazyById();

        foreach ($rows as $row) {
            if (DB::table('_session_values')->where('id', $row->id)->delete() !== 1) {
                continue;
            }

            static::__record_enrollment_from_marker(
                static::__decode_enrollment_marker($row->value),
                Login_History::STATUS_PASSKEY_ENROLL_ABANDONED,
                'Never confirmed; the challenge expired'
            );

            $recorded++;
        }

        return $recorded;
    }

    /**
     * Claim this browser's passkey enrollment marker, EXPIRED OR NOT, deleting it.
     *
     * Read straight from _session_values rather than through Session::get_value(), which
     * hides an expired value by design - and an expired marker is exactly the abandoned
     * ceremony this exists to record. The marker is claimed by its DELETE: a caller whose
     * delete removed nothing lost a race (to the sweep) and gets null, so an outcome is
     * recorded once.
     *
     * @return array|null {marker: array, expired: bool}, or null when none is parked
     */
    protected static function __take_passkey_enrollment(): ?array
    {
        if (!Session::has_session()) {
            return null;
        }

        $row = DB::table('_session_values')
            ->where('session_id', Session::get_session_id())
            ->where('value_key', static::PASSKEY_ENROLLMENT_KEY)
            ->first();

        if ($row === null) {
            return null;
        }

        if (DB::table('_session_values')->where('id', $row->id)->delete() !== 1) {
            return null;
        }

        return [
            'marker' => static::__decode_enrollment_marker($row->value),
            'expired' => $row->expires_at !== null && Rsx_Time::is_past($row->expires_at),
        ];
    }

    /**
     * A stored enrollment marker, decoded. Only begin_passkey_registration() writes one, so
     * anything else is a broken invariant.
     *
     * @param string|null $json
     * @return array
     */
    protected static function __decode_enrollment_marker(?string $json): array
    {
        $marker = json_decode((string) $json, true);

        if (!is_array($marker) || !isset($marker['identity_id'], $marker['email'], $marker['begun_at'])) {
            shouldnt_happen('A passkey enrollment marker is malformed: ' . (string) $json);
        }

        return $marker;
    }

    /**
     * Record an outcome for the ceremony a marker describes, against the identity and the
     * browser that BEGAN it - the recorder may be another request or the hourly sweep.
     *
     * @param array $marker
     * @param string $status A Login_History::STATUS_PASSKEY_ENROLL* constant.
     * @param string $reason
     * @return void
     */
    protected static function __record_enrollment_from_marker(array $marker, string $status, string $reason): void
    {
        static::__record_passkey_enrollment(
            (int) $marker['identity_id'],
            (string) $marker['email'],
            $status,
            $reason . ' (begun ' . $marker['begun_at'] . ')',
            [
                'ip_address' => $marker['ip_address'] ?? null,
                'user_agent' => $marker['user_agent'] ?? null,
            ]
        );
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
     * $accepts names the kinds of answer this challenge takes (the ANSWER_* constants) and is
     * enforced by verify_challenge(). Null accepts what the identity holds, evaluated when
     * the challenge is answered. An explicit list must hold at least one kind this identity
     * can actually answer with - an issued code always can - or the call throws: a challenge
     * nobody can answer is a login function bug, and it is caught here, before the sign-out,
     * rather than on a screen with no way forward.
     *
     * @param Rsx_Model_Abstract $identity The identity whose password just verified.
     * @param array|null $accepts The ANSWER_* kinds this challenge accepts; null = what the
     *                            identity holds.
     * @return void
     * @throws \InvalidArgumentException On an unknown kind, an empty list, or a list the
     *         identity cannot answer.
     */
    public static function begin_challenge(Rsx_Model_Abstract $identity, ?array $accepts = null): void
    {
        $identity_id = static::__resolve_id($identity);

        if ($accepts !== null) {
            $accepts = static::__validate_accepts($accepts, $identity_id);
        }

        $expires_at = static::challenge_expires_at();

        Session::put_value(
            static::CHALLENGE_KEY,
            [
                'identity_id' => $identity_id,
                'email' => (string) $identity->email,
                // Names this challenge's own failure counter, so a fresh challenge (a fresh
                // password entry) starts its count at zero.
                'challenge_id' => random_hash(32),
                'accepts' => $accepts,
                // issue_code() rewrites the value and must keep the window it was given.
                'expires_at' => $expires_at,
                'code_hash' => null,
                'codes_issued' => 0,
            ],
            $expires_at
        );

        static::__sign_out();
    }

    /**
     * Mint a one-time code for the pending challenge and return it, for the application to
     * deliver.
     *
     * Six random digits. Only a keyed hash is kept, in the pending challenge, and a later
     * code REPLACES an earlier one - so "send me another code" leaves exactly one live
     * answer. The code lives as long as the challenge does (rsx.two_factor.
     * challenge_window_minutes). Every code issued is counted (challenge_pending()'s
     * codes_issued), so the application can cap resends however it likes; the attempt caps
     * on WRONG answers are the framework's and apply here exactly as they do to TOTP.
     *
     * Delivery is the caller's: an email declared CATEGORY = SECURITY (which reaches an
     * address on the site block list and ignores the recipient opt-out), an SMS, anything.
     *
     * @return string The six digits, zero-padded.
     * @throws Two_Factor_Failed_Exception When nothing is pending (the window has closed).
     * @throws \RuntimeException When the pending challenge does not accept ANSWER_ISSUED_CODE.
     */
    public static function issue_code(): string
    {
        $pending = static::__pending_challenge();

        if ($pending === null) {
            throw new Two_Factor_Failed_Exception('Your verification window has expired. Please sign in again.');
        }

        if (!in_array(self::ANSWER_ISSUED_CODE, $pending['accepts'] ?? [], true)) {
            throw new RuntimeException(
                static::class . '::issue_code() was called for a challenge that does not accept an issued code. '
                . 'Pass ' . static::class . '::ANSWER_ISSUED_CODE in begin_challenge()\'s $accepts.'
            );
        }

        $code = str_pad((string) random_int(0, (10 ** self::ISSUED_CODE_DIGITS) - 1), self::ISSUED_CODE_DIGITS, '0', STR_PAD_LEFT);

        $raw = Session::get_value(static::CHALLENGE_KEY);
        $raw['code_hash'] = static::__issued_code_hash($pending['challenge_id'], $code);
        $raw['codes_issued'] = $pending['codes_issued'] + 1;

        Session::put_value(static::CHALLENGE_KEY, $raw, $pending['expires_at']);

        return $code;
    }

    /**
     * What the challenge screen needs to render itself, or null when there is nothing
     * pending.
     *
     * An EXPIRED challenge reads as null, because Session::get_value() filters on the
     * expiry rather than trusting a sweeper - so "expired" and "never existed" are the same
     * answer here, which is the only answer a challenge screen needs.
     *
     * The email is returned MASKED unless rsx.two_factor.challenge_shows_full_email says
     * otherwise. The screen has to show the user which account they are signing in to, but
     * the page is reachable by anyone holding the session cookie and the full address is not
     * necessarily theirs to read; `email` is null unless the application opted in.
     *
     * accepts is the list of answer kinds this challenge takes AND this identity can give
     * (an issued code always can). has_totp, has_passkey and has_recovery_codes are the same
     * answer per kind - what the screen may ASK for. codes_issued counts issue_code() calls,
     * for an application that caps resends.
     *
     * @return array|null {email, email_masked, accepts, has_totp, has_passkey,
     *                    has_recovery_codes, has_issued_code, codes_issued}
     */
    public static function challenge_pending(): ?array
    {
        $pending = static::__pending_challenge();

        if ($pending === null) {
            return null;
        }

        $accepts = static::__answerable($pending);

        return [
            'email' => config('rsx.two_factor.challenge_shows_full_email') ? $pending['email'] : null,
            'email_masked' => static::__mask_email($pending['email']),
            'accepts' => $accepts,
            'has_totp' => in_array(self::ANSWER_TOTP, $accepts, true),
            'has_passkey' => in_array(self::ANSWER_PASSKEY, $accepts, true),
            'has_recovery_codes' => in_array(self::ANSWER_RECOVERY_CODE, $accepts, true),
            'has_issued_code' => in_array(self::ANSWER_ISSUED_CODE, $accepts, true),
            'codes_issued' => $pending['codes_issued'],
        ];
    }

    /**
     * The identity the pending challenge is for, or null when nothing is pending.
     *
     * SERVER-SIDE ONLY, for the application code that delivers an issued code: the resend
     * endpoint has to know whose address to send to, and the session is signed out. It is
     * never handed to the browser - the challenge screen reads challenge_pending(), which
     * masks the address. Holding this identity proves nothing about the person asking;
     * only verify_challenge() signs it in.
     *
     * @return Rsx_Model_Abstract|null
     */
    public static function pending_identity(): ?Rsx_Model_Abstract
    {
        $pending = static::__pending_challenge();

        return $pending === null ? null : static::__find_identity($pending['identity_id']);
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

        // The application's explicit policy is enforced here as at verification. With no
        // list, the options are issued as before; verify_challenge() refuses an assertion
        // from a credential the identity does not hold.
        if ($pending['accepts'] !== null && !in_array(self::ANSWER_PASSKEY, $pending['accepts'], true)) {
            throw new Two_Factor_Failed_Exception('This sign-in cannot be completed with a passkey.');
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
     *  2b. An identity that has spent its failure budget (is_locked()) is refused before
     *     anything is tried, and its challenge is discarded - a correct answer would
     *     otherwise make the lock an oracle.
     *  3. Only the kinds the challenge ACCEPTS are tried. A passkey assertion is tried when
     *     one was offered; otherwise a typed code is tried against the issued code, then
     *     every confirmed TOTP credential, then the recovery codes. Recovery LAST, so a
     *     string that is a live TOTP code never burns a recovery code. An answer of a kind
     *     the challenge does not accept is a wrong answer.
     *  4. A failure is recorded ONCE, through the realm (Login_History::record_failure()
     *     with STATUS_FAILED_2FA for staff, which already feeds Login_Throttle; the throttle
     *     directly for the portal, whose outcomes have no history store). Never both: one
     *     failure counted twice halves the real budget, and the halving would only be
     *     discovered by a user locked out early. The failure also counts once against
     *     the challenge and once against the identity (the attempt caps, independent of
     *     IP); the answer that spends the challenge's budget destroys the challenge.
     *  5. On success the pending value is forgotten FIRST, the identity's failure count is
     *     cleared, then the realm signs the identity
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
     *         realm will not admit the identity. reason() says which: REASON_WINDOW_EXPIRED,
     *         REASON_WRONG_ANSWER, REASON_CHALLENGE_SPENT (the wrong answer that was the
     *         challenge's last - the realm's `challenge.spent` event fires with the identity
     *         just before it is thrown) or REASON_IDENTITY_LOCKED.
     */
    public static function verify_challenge(array $input): Rsx_Model_Abstract
    {
        Login_Throttle::require_not_throttled();

        $pending = static::__pending_challenge();

        if ($pending === null) {
            throw new Two_Factor_Failed_Exception(
                'Your verification window has expired. Please sign in again.',
                Two_Factor_Failed_Exception::REASON_WINDOW_EXPIRED
            );
        }

        $identity_id = $pending['identity_id'];
        $email = $pending['email'];

        $identity = static::__find_identity($identity_id);

        if ($identity === null) {
            // The identity was deleted between the password and the second factor. Nothing
            // to sign in to, and nothing the person at the keyboard can do about it.
            static::abandon_challenge();

            throw new Two_Factor_Failed_Exception(
                'Your verification window has expired. Please sign in again.',
                Two_Factor_Failed_Exception::REASON_WINDOW_EXPIRED
            );
        }

        if (static::is_locked($identity_id)) {
            static::abandon_challenge();

            throw new Two_Factor_Failed_Exception(
                'Too many incorrect codes have been entered for this account. Please try again later.',
                Two_Factor_Failed_Exception::REASON_IDENTITY_LOCKED
            );
        }

        $verified = false;

        $accepts = static::__answerable($pending);

        if (isset($input['assertion']) && is_array($input['assertion'])) {
            $verified = in_array(self::ANSWER_PASSKEY, $accepts, true)
                && static::__try_assertion($input['assertion'], $identity_id);
        } elseif (isset($input['code']) && is_string($input['code'])) {
            $verified = static::__try_code($input['code'], $pending, $accepts);
        }

        if (!$verified) {
            static::__record_failure($email, Login_History::STATUS_FAILED_2FA, $identity_id);

            Rsx_Counter::increment(static::__identity_failure_key($identity_id), static::identity_failure_window_seconds());

            // The counter only has to outlive the challenge it counts, which lives exactly
            // the challenge window.
            $challenge_failures = Rsx_Counter::increment(
                static::__challenge_failure_key($pending['challenge_id']),
                (int) config('rsx.two_factor.challenge_window_minutes') * 60
            );

            if ($challenge_failures >= static::challenge_max_failures()) {
                static::abandon_challenge();

                // SOMEBODY WHO KNOWS THE PASSWORD FAILED THE SECOND FACTOR, REPEATEDLY. The
                // caps make guessing slow; being noticed is what makes it hopeless, and this
                // is the moment to notice. The identity travels with the event because the
                // challenge that named it is already gone.
                Rsx::trigger_action(static::__event('challenge.spent'), [
                    'identity' => $identity,
                    'email' => $email,
                    'failures' => $challenge_failures,
                ]);

                throw new Two_Factor_Failed_Exception(
                    'Too many incorrect codes. Please sign in again.',
                    Two_Factor_Failed_Exception::REASON_CHALLENGE_SPENT
                );
            }

            throw new Two_Factor_Failed_Exception('That code is not valid.', Two_Factor_Failed_Exception::REASON_WRONG_ANSWER);
        }

        static::abandon_challenge();
        static::clear_failures($identity_id);

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
    protected static function __try_code(string $code, array $pending, array $accepts): bool
    {
        $identity_id = $pending['identity_id'];
        $code = trim($code);

        if (in_array(self::ANSWER_ISSUED_CODE, $accepts, true)
            && $pending['code_hash'] !== null
            && hash_equals($pending['code_hash'], static::__issued_code_hash($pending['challenge_id'], $code))
        ) {
            return true;
        }

        if (in_array(self::ANSWER_TOTP, $accepts, true) && static::__try_totp($code, $identity_id)) {
            return true;
        }

        if (in_array(self::ANSWER_RECOVERY_CODE, $accepts, true)) {
            return Recovery_Codes::consume(static::class, $identity_id, $code);
        }

        return false;
    }

    /**
     * Try a typed code against every confirmed TOTP credential, advancing the one it matches.
     *
     * @param string $code
     * @param int $identity_id
     * @return bool
     */
    protected static function __try_totp(string $code, int $identity_id): bool
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

        return false;
    }

    /**
     * The pending challenge, validated into shape, or null.
     *
     * @return array|null {identity_id, email, challenge_id, accepts (array|null), expires_at,
     *                    code_hash (string|null), codes_issued}
     */
    protected static function __pending_challenge(): ?array
    {
        $pending = Session::get_value(static::CHALLENGE_KEY);

        if (!is_array($pending) || !isset($pending['identity_id'], $pending['email'], $pending['challenge_id'], $pending['expires_at'])) {
            return null;
        }

        return [
            'identity_id' => (int) $pending['identity_id'],
            'email' => (string) $pending['email'],
            'challenge_id' => (string) $pending['challenge_id'],
            'accepts' => is_array($pending['accepts'] ?? null) ? array_values($pending['accepts']) : null,
            'expires_at' => (string) $pending['expires_at'],
            'code_hash' => is_string($pending['code_hash'] ?? null) ? $pending['code_hash'] : null,
            'codes_issued' => (int) ($pending['codes_issued'] ?? 0),
        ];
    }

    /**
     * The answer kinds the pending challenge accepts AND the identity can give, in ANSWERS
     * order. A null accepts list means everything the identity holds; an issued code is
     * answerable whenever it is accepted.
     *
     * @param array $pending From __pending_challenge().
     * @return array
     */
    protected static function __answerable(array $pending): array
    {
        $identity_id = $pending['identity_id'];
        $model = static::_credential_model();
        $holds = [
            self::ANSWER_ISSUED_CODE => true,
            self::ANSWER_TOTP => static::__has_confirmed($identity_id, $model::TYPE_TOTP),
            self::ANSWER_PASSKEY => static::__has_confirmed($identity_id, $model::TYPE_PASSKEY),
            self::ANSWER_RECOVERY_CODE => Recovery_Codes::remaining(static::class, $identity_id) > 0,
        ];

        $accepts = $pending['accepts'] ?? array_values(array_diff(self::ANSWERS, [self::ANSWER_ISSUED_CODE]));

        return array_values(array_filter(
            self::ANSWERS,
            fn (string $kind) => in_array($kind, $accepts, true) && $holds[$kind]
        ));
    }

    /**
     * Validate begin_challenge()'s $accepts: known kinds, at least one, and at least one
     * this identity can answer with.
     *
     * @param array $accepts
     * @param int $identity_id
     * @return array The list, de-duplicated.
     */
    protected static function __validate_accepts(array $accepts, int $identity_id): array
    {
        $accepts = array_values(array_unique($accepts));

        if ($accepts === []) {
            throw new \InvalidArgumentException('begin_challenge() was given an empty $accepts list - a challenge must accept at least one kind of answer.');
        }

        foreach ($accepts as $kind) {
            if (!in_array($kind, self::ANSWERS, true)) {
                throw new \InvalidArgumentException(
                    'begin_challenge() was given an unknown answer kind ' . var_export($kind, true)
                    . '. Use the ' . static::class . '::ANSWER_* constants.'
                );
            }
        }

        $answerable = static::__answerable(['identity_id' => $identity_id, 'accepts' => $accepts]);

        if ($answerable === []) {
            throw new \InvalidArgumentException(
                'begin_challenge() was given $accepts [' . implode(', ', $accepts) . '], and identity '
                . $identity_id . ' holds none of them - nobody could answer this challenge.'
            );
        }

        return $accepts;
    }

    /**
     * The keyed hash an issued code is kept as. Keyed by the application key and salted by
     * the challenge, so a copy of the session store alone does not reveal the six digits.
     *
     * @param string $challenge_id
     * @param string $code
     * @return string
     */
    protected static function __issued_code_hash(string $challenge_id, string $code): string
    {
        return hash_hmac('sha256', $challenge_id . ':' . $code, (string) config('app.key'));
    }

    /**
     * The transient counter of one identity's wrong answers, per realm.
     *
     * @param int $identity_id
     * @return string
     */
    protected static function __identity_failure_key(int $identity_id): string
    {
        return static::CHALLENGE_KEY . ':failures:identity:' . $identity_id;
    }

    /**
     * The transient counter of one parked challenge's wrong answers, per realm.
     *
     * @param string $challenge_id
     * @return string
     */
    protected static function __challenge_failure_key(string $challenge_id): string
    {
        return static::CHALLENGE_KEY . ':failures:challenge:' . $challenge_id;
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
    // Each facade also declares five session value keys as class constants, read here
    // through static:: - CHALLENGE_KEY (the pending second-factor challenge),
    // TOTP_PENDING_KEY (an in-flight authenticator-app seed), WEBAUTHN_CHALLENGE_KEY (a
    // registration or second-factor assertion ceremony), PASSKEY_LOGIN_CHALLENGE_KEY (a
    // passwordless ceremony) and PASSKEY_ENROLLMENT_KEY (the marker of a begun, unconfirmed
    // passkey enrollment - see the class docblock). The two realms' keys differ, because both realms' values can
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
     * The realm's name for one of this engine's events: 'two_factor.<name>' for staff,
     * 'portal.two_factor.<name>' for the portal, so a handler for one realm never hears the
     * other's.
     */
    abstract protected static function __event(string $name): string;

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

    /**
     * Record one passkey enrollment ceremony outcome where a support engineer can read it.
     * Never feeds the throttle - an enrollment is not a sign-in attempt.
     *
     * @param int $identity_id The identity that enrolled (or tried to).
     * @param string $email Its email address.
     * @param string $status A Login_History::STATUS_PASSKEY_ENROLL* constant.
     * @param string|null $reason Why it failed or was abandoned; null otherwise.
     * @param array $client_context {ip_address, user_agent} of the browser that began it.
     * @return void
     */
    abstract protected static function __record_passkey_enrollment(
        int $identity_id,
        string $email,
        string $status,
        ?string $reason,
        array $client_context
    ): void;
}
