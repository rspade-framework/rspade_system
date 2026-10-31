<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use lbuchs\WebAuthn\WebAuthnException;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Qr\Rsx_Qr_Code;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Rsx_Signed_Url;
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
 * issue_code() returns the identity's live six digits (minting them when it has none - see
 * AN ISSUED CODE BELONGS TO THE IDENTITY below) to the caller, who sends them however the
 * application chooses - an email of the SECURITY category, an SMS, a phone call.
 * Verification is the same verify_challenge(), under the same throttle, the same two attempt
 * caps and the same recording as a TOTP code.
 *
 * AN EMAIL LINK travels in the same message as the code: issued_link($return_url), called
 * after issue_code(), answers a signed URL to the framework's /_email_link/:token route, and
 * opening it completes the step exactly as typing the code would - it is the same answer
 * (ANSWER_ISSUED_CODE), consumes the same code, and dies with it. The link is BOUND TO THE
 * BROWSER whose challenge it was minted for: opened anywhere else it says so and asks for the
 * code instead, and that visit counts nothing. The GET route is read-only, so a mail scanner
 * that prefetches the link consumes nothing; the browser that owns the challenge is sent back
 * to $return_url with the token in the fragment, and <Two_Factor_Challenge> submits it to the
 * application's own verification endpoint as {email_link: token}. See issued_link().
 *
 * A PASSED EMAILED ANSWER VERIFIES THE ADDRESS. When an issued code (typed, or its email link)
 * answers a challenge, the identity's is_verified is set before the realm signs it in
 * (__mark_address_verified()): the answer proved the person at the keyboard reads that
 * mailbox, which is the whole of what is_verified records. This is what lets an emailed code
 * double as signup verification, and lets a portal user whose address was never verified
 * pass the portal's admission rule (can_login()) by answering one.
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
 * EVERY VALUE THIS CLASS PARKS LIVES IN THE REALM'S SESSION VALUES (SESSION_REALM): the
 * pending challenge, the TOTP seed being enrolled, the WebAuthn ceremony challenges and the
 * passkey enrollment marker. A realm's sign-out clears that realm's values, and a sign-in
 * from signed-out does not, so begin_challenge() signs the realm out FIRST and parks the
 * challenge after: it is then readable by the challenge screen and by verify_challenge(),
 * and is gone with the next sign-out. A staff challenge is never cleared by a portal
 * sign-out on the same browser, nor the reverse. The pending value carries its own expiry
 * rather than relying on the realm's.
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
 * the realm (__record_passkey_enrollment(): rows in the realm's login history, _login_history
 * or _portal_login_history). A browser that gives up on a ceremony tells the server nothing, so "begun" is
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
 * AN ISSUED CODE BELONGS TO THE IDENTITY, NOT TO THE CHALLENGE. issue_code() keeps at most one
 * live code per identity per realm, in the transient-counter store beside the failure budget
 * that governs it, for rsx.two_factor.challenge_window_minutes from its LAST issue - every
 * issue_code() restarts the clock without changing the digits. Asking again while it lives returns the SAME
 * digits, so a user holding three emails holds one code, not three where only the newest
 * works; and because the code is not keyed to the session, signing in again from a fresh
 * session (or another browser) reuses it rather than minting a parallel one. The session's
 * challenge keeps only the pointer it needs - which identity, and how many sends. Re-sending
 * the same digits means reading them back, so the code is stored ENCRYPTED with the
 * application key rather than hashed: a copy of the store alone does not reveal it, and
 * whatever it does reveal dies with the code's short life. A correct answer consumes
 * it; a spent challenge or a locked identity destroys it; abandoning one browser's challenge
 * leaves it, because another browser of the same identity may be answering it.
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
    /** A challenge answer kind: a code from a confirmed authenticator app. */
    public const ANSWER_TOTP = 'totp';

    /** A challenge answer kind: an assertion from one of the identity's passkeys. */
    public const ANSWER_PASSKEY = 'passkey';

    /** A challenge answer kind: one of the identity's unspent recovery codes. */
    public const ANSWER_RECOVERY_CODE = 'recovery_code';

    /** A challenge answer kind: the identity's live code from issue_code(). */
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

    /** _email_link_state(): the link was opened in the browser whose challenge it answers. */
    public const EMAIL_LINK_SAME_BROWSER = 'same_browser';

    /** _email_link_state(): the link is live, and this is not the browser it was minted for. */
    public const EMAIL_LINK_OTHER_BROWSER = 'other_browser';

    /** _email_link_state(): the link's code expired or was used, or the link is not one. */
    public const EMAIL_LINK_EXPIRED_OR_USED = 'expired_or_used';

    /** The Rsx_Signed_Url purpose an email link's URL is signed under. */
    public const EMAIL_LINK_PURPOSE = 'email_link';

    /** The URL-fragment key the landing route hands the token back to the challenge screen in. */
    public const EMAIL_LINK_FRAGMENT_KEY = 'rsx_email_link';

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
     * hashed. The recovery set REPLACES whatever the identity held. recovery_codes is null
     * when the deployment issues none (issues_recovery_codes()), and whatever the identity
     * held is then left as it was.
     *
     * The caller decides whether stacking a second seed is acceptable - has_confirmed_totp()
     * is the question, and this method refuses rather than silently enrolling a second one.
     *
     * @param Rsx_Model_Abstract $identity The identity to arm.
     * @param string|null $label What the credential is called in the user's settings.
     * @return array {secret, otpauth_uri, recovery_codes: array|null}
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

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'recovery_codes' => static::__mint_recovery_codes($identity_id),
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

        // A factor removed is a reason to verify again everywhere: a browser trusted on its
        // strength is not trusted on the strength of what remains.
        static::forget_trusted_devices($identity_id);

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

        static::forget_trusted_devices($identity);
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

        Session::put_value(static::TOTP_PENDING_KEY, $secret, static::challenge_expires_at(), static::SESSION_REALM);

        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => Rsx_Qr_Code::generate($uri)['svg'],
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
     * The returned plaintext codes are the ONLY time they exist. See Recovery_Codes. NULL
     * means the factor is live and there is no sheet to show: the deployment issues no
     * recovery codes (issues_recovery_codes()).
     *
     * @param string $code The code from the authenticator app.
     * @return array|null The plaintext recovery codes, or null when none are issued.
     * @throws RuntimeException When nobody is signed in, or while impersonating.
     * @throws Two_Factor_Failed_Exception When the enrollment expired or the code is wrong.
     */
    public static function confirm_totp_enrollment(string $code): ?array
    {
        $identity = static::__enrolling_identity();

        $secret = Session::get_value(static::TOTP_PENDING_KEY, null, static::SESSION_REALM);

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

        Session::forget_value(static::TOTP_PENDING_KEY, static::SESSION_REALM);

        return static::__mint_recovery_codes((int) $identity->id);
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
            Passkeys::challenge_expires_at(),
            static::SESSION_REALM
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
     * sheet only when there is one. A deployment that issues no recovery codes
     * (issues_recovery_codes()) always answers null.
     *
     * @param array $attestation The browser's attestation response.
     * @param string|null $label What the user calls this key, shown in their settings.
     * @return array|null Freshly minted recovery codes, or null if the identity had some or none are issued.
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

        return static::__mint_recovery_codes($identity_id);
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
        $session_id = Session::_current_browser_session_id();

        if ($session_id === null) {
            return null;
        }

        $row = DB::table('_session_values')
            ->where('session_id', $session_id)
            ->where('realm_id', Session::_value_realm_id(static::SESSION_REALM))
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
     * Whether this deployment issues recovery codes at all: config
     * rsx.two_factor.recovery_codes, true unless an application turned it off.
     *
     * ONE KEY, BOTH REALMS. Whether a sheet of bearer codes is an acceptable way back in is
     * a decision about the deployment's recovery policy, not about which login page somebody
     * arrived through, so staff and portal identities read the same answer.
     *
     * IT GOVERNS MINTING AND NOTHING ELSE. With it false no enrollment, no operator setup
     * and no regeneration writes a recovery code. What an identity ALREADY holds is not
     * touched and is still accepted by verify_challenge() - a code somebody wrote down while
     * the deployment issued them is theirs until it is spent or removed
     * (remove_recovery_codes()). Turned back on, the next enrollment mints a sheet as
     * usual; an identity enrolled while it was off holds none until it regenerates.
     *
     * A settings screen reads this to decide whether to offer regeneration; whether to show
     * a remaining COUNT is recovery_codes_remaining(), and whether the challenge offers the
     * recovery-code answer is challenge_pending()'s has_recovery_codes - both derived from
     * what the identity holds, never from this switch.
     *
     * @return bool
     * @throws RuntimeException When the config value is not a boolean.
     */
    public static function issues_recovery_codes(): bool
    {
        $issued = config('rsx.two_factor.recovery_codes');

        if (!is_bool($issued)) {
            throw new RuntimeException(
                'rsx.two_factor.recovery_codes must be true or false, got ' . var_export($issued, true) . '.'
            );
        }

        return $issued;
    }

    /**
     * Mint and store a fresh sheet for an identity, or answer null when the deployment
     * issues none. The one place a first enrollment and the operator setup mint from.
     *
     * @param int $identity_id
     * @return array|null The plaintext codes, or null when recovery codes are not issued.
     */
    protected static function __mint_recovery_codes(int $identity_id): ?array
    {
        if (!static::issues_recovery_codes()) {
            return null;
        }

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for(static::class, $identity_id, $codes);

        return $codes;
    }

    /**
     * Replace this identity's recovery codes and return the new plaintext set.
     *
     * The previous set stops working the moment this returns - that is the point of it,
     * and it is what a user who thinks their codes were seen is asking for.
     *
     * On a deployment that issues no recovery codes this THROWS and changes nothing: a
     * regeneration is a request for a new sheet, and there is none to give. A settings
     * screen offers the action only when issues_recovery_codes() is true.
     *
     * @return array The plaintext codes.
     * @throws RuntimeException When nobody is signed in, while impersonating, or when the
     *                          deployment issues no recovery codes.
     */
    public static function regenerate_recovery_codes(): array
    {
        $identity = static::__enrolling_identity();

        $codes = Recovery_Codes::generate();
        Recovery_Codes::store_for(static::class, (int) $identity->id, $codes);

        return $codes;
    }

    /**
     * Remove every recovery code an identity holds, leaving its factors in place.
     *
     * For the operator of a deployment that stopped issuing recovery codes
     * (issues_recovery_codes() false) and wants the sheets written before that to stop
     * working: turning the switch off mints nothing new and deletes nothing. Takes a NAMED
     * identity like remove_all(), so it is an administrative act, not a settings action.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @return int How many codes were removed.
     */
    public static function remove_recovery_codes(int|Rsx_Model_Abstract $identity): int
    {
        $identity_id = static::__resolve_id($identity);
        $removed = Recovery_Codes::remaining(static::class, $identity_id);

        $model = static::_credential_model();

        $model::where(static::_owner_column(), $identity_id)
            ->where('type_id', $model::TYPE_RECOVERY_CODE)
            ->delete();

        return $removed;
    }

    // -------------------------------------------------------------------------
    // The second-factor challenge
    // -------------------------------------------------------------------------

    /**
     * Sign the realm out, and park an identity that has passed its password.
     *
     * The order is deliberate: the SIGN-OUT comes first and the pending value is written
     * after it. The challenge lives in this realm's session values (SESSION_REALM), and a
     * realm's sign-out clears that realm's values - so a challenge written first would be
     * cleared by the sign-out it is followed by whenever somebody was still signed in. Both
     * act on the same browser row (a sign-out rotates its tokens in place), and
     * Session::put_value() establishes that row for a visitor who had none.
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

        static::__sign_out();

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
                // Sends, not mints: a resend that re-sends the live code still counts.
                'codes_issued' => 0,
            ],
            $expires_at,
            static::SESSION_REALM
        );
    }

    /**
     * The pending identity's live sign-in code, minting one when it has none, for the
     * application to deliver.
     *
     * Six random digits, held against the IDENTITY (see the class docblock): while a code
     * lives, every call - a resend, a second browser, a fresh sign-in - returns the SAME
     * digits, so however many messages the user holds they all carry the one code that works.
     * A code lives rsx.two_factor.challenge_window_minutes, and EVERY call restarts that clock:
     * a resend keeps the digits and gives them a full window again, as it does the pending
     * challenge and the email link sent beside it, so every copy of the message can say
     * "expires in N minutes" truthfully. The wrong-answer caps do not reset - a resend is a
     * delivery, never more guesses - and how many resends one sign-in may send is the
     * application's cap (codes_issued). Once a code has expired the next call mints a new one.
     *
     * Every call is counted as a SEND (challenge_pending()'s codes_issued), so the
     * application can cap resends however it likes; the attempt caps on WRONG answers are
     * the framework's and apply here exactly as they do to TOTP.
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

        $code = static::__live_or_minted_code($pending['identity_id']);
        $expires_at = static::__live_issued_code($pending['identity_id'])['expires_at'];

        // The challenge lives as long as the code it was just sent: a window that closed
        // before the code would make the message's "expires in" a lie.
        $raw = Session::get_value(static::CHALLENGE_KEY, null, static::SESSION_REALM);
        $raw['codes_issued'] = $pending['codes_issued'] + 1;
        $raw['expires_at'] = $expires_at;

        Session::put_value(static::CHALLENGE_KEY, $raw, $expires_at, static::SESSION_REALM);

        return $code;
    }

    /**
     * When the pending identity's live sign-in code expires, or null when it has none.
     *
     * For the application's own wording: asked BEFORE issue_code(), non-null means the call
     * will re-send the code the user already holds ("here is your code again"); asked after,
     * it is the moment the delivered code stops working, which every issue_code() call
     * restarts. Null too when nothing is pending or the challenge does not accept
     * an issued code. Server-side; the digits themselves are never readable here.
     *
     * @return string|null ISO 8601 UTC
     */
    public static function issued_code_expires_at(): ?string
    {
        $pending = static::__pending_challenge();

        if ($pending === null || !in_array(self::ANSWER_ISSUED_CODE, $pending['accepts'] ?? [], true)) {
            return null;
        }

        $live = static::__live_issued_code($pending['identity_id']);

        return $live === null ? null : $live['expires_at'];
    }

    /**
     * The sign-in link that travels in the same message as the issued code: an absolute,
     * signed URL to the framework route /_email_link/:token on this realm's host.
     *
     * THE CONTRACT: call it AFTER issue_code(), in the send that delivers that code, and put
     * both in the one message. The link is the code's twin - it answers the challenge as
     * ANSWER_ISSUED_CODE, a correct answer of either kind consumes both, and it stops working
     * the moment the code does (its absolute expiry, a spent challenge, a locked identity).
     * Called with no live code it THROWS rather than minting one, because a link whose code
     * nobody was sent would be a second, unannounced secret.
     *
     * ONE LINK PER CHALLENGE PER CODE. The token is derived (HMAC under a key derived from
     * app.key) from this browser's challenge id and the moment the live code was minted, so
     * a resend returns the SAME link, a fresh sign-in (a new challenge) or a re-minted code
     * gets a new one, and nothing secret is stored: what the store keeps, beside the code, is
     * the token's SHA-256 bound to {challenge, this browser's session, the identity, the
     * code's mint, $return_url}, expiring with the code - a resend restarts both clocks.
     *
     * BOUND TO THIS BROWSER. Opened in the browser whose challenge it was minted for, the
     * route redirects to $return_url with the token in the URL FRAGMENT (never sent to a
     * server, so never in a log), and <Two_Factor_Challenge> on that page submits it to the
     * application's verification endpoint as {email_link: token} - verify_challenge() needs
     * no other change. Opened in any other browser, the route renders a notice asking for the
     * code in the browser that signed in; that visit is not an attempt and the code stays
     * live. The route only READS, so a mail scanner prefetching the link consumes nothing and
     * counts nothing.
     *
     * @param string $return_url The application's challenge page (normally its verify route,
     *                           Rsx::Route() / Rsx_Portal::Route()), with no fragment.
     * @return string The absolute signed URL.
     * @throws Two_Factor_Failed_Exception When nothing is pending (the window has closed).
     * @throws \RuntimeException When the pending challenge does not accept ANSWER_ISSUED_CODE,
     *         or the identity holds no live code (issue_code() was not called first).
     * @throws \InvalidArgumentException When $return_url is empty or carries a fragment.
     */
    public static function issued_link(string $return_url): string
    {
        $pending = static::__pending_challenge();

        if ($pending === null) {
            throw new Two_Factor_Failed_Exception('Your verification window has expired. Please sign in again.');
        }

        if (!in_array(self::ANSWER_ISSUED_CODE, $pending['accepts'] ?? [], true)) {
            throw new RuntimeException(
                static::class . '::issued_link() was called for a challenge that does not accept an issued code. '
                . 'Pass ' . static::class . '::ANSWER_ISSUED_CODE in begin_challenge()\'s $accepts.'
            );
        }

        if (trim($return_url) === '' || str_contains($return_url, '#')) {
            throw new \InvalidArgumentException(
                static::class . '::issued_link() needs the challenge page\'s URL with no fragment - the link hands '
                . 'its token back in one. Got ' . var_export($return_url, true) . '.'
            );
        }

        $live = static::__live_issued_code($pending['identity_id']);

        if ($live === null) {
            throw new RuntimeException(
                static::class . '::issued_link() was called with no live issued code. Call issue_code() first and '
                . 'send the code and the link in one message - the link is the code\'s twin and dies with it.'
            );
        }

        $session_id = Session::_current_browser_session_id();

        if ($session_id === null) {
            shouldnt_happen(static::class . '::issued_link(): a challenge is pending on a browser with no session row');
        }

        $token = static::__email_link_token($pending['challenge_id'], $live['minted_at']);
        $binding = json_encode([
            'challenge_id' => $pending['challenge_id'],
            'session_id' => $session_id,
            'identity_id' => $pending['identity_id'],
            'code_minted_at' => $live['minted_at'],
            'return_url' => $return_url,
        ]);

        // A resend binds the same token again: replace it with the code's restarted expiry,
        // or store it on the first send.
        $key = static::__email_link_key($token);
        $expires_at_ms = Rsx_Time::to_ms($live['expires_at']);

        if (!Rsx_Counter::replace_value($key, $binding, $expires_at_ms)) {
            Rsx_Counter::add_value($key, $binding, $expires_at_ms);
        }

        return static::__email_link_url($token);
    }

    /**
     * What opening an email link on THIS browser means - FRAMEWORK INTERNAL, for the landing
     * route (Rsx_Email_Link_Controller). It READS ONLY: nothing is consumed, counted or
     * recorded, whatever the answer, which is what makes a prefetching mail scanner harmless.
     *
     *   EMAIL_LINK_SAME_BROWSER     the challenge it was minted for is pending here, and its
     *                               code is live: return_url is where to send the browser
     *   EMAIL_LINK_OTHER_BROWSER    the link is live, but this is not the browser that signed
     *                               in: the code from the same email still works there
     *   EMAIL_LINK_EXPIRED_OR_USED  the code expired or was used, the challenge is gone, or the
     *                               token is not a link at all
     *
     * @param string $token
     * @return array{state: string, return_url: ?string}
     */
    public static function _email_link_state(string $token): array
    {
        $expired = ['state' => self::EMAIL_LINK_EXPIRED_OR_USED, 'return_url' => null];

        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return $expired;
        }

        $stored = Rsx_Counter::value(static::__email_link_key($token));

        if ($stored === null) {
            return $expired;
        }

        $binding = json_decode($stored, true);

        if (!is_array($binding) || !isset($binding['challenge_id'], $binding['session_id'], $binding['identity_id'], $binding['code_minted_at'], $binding['return_url'])) {
            shouldnt_happen('An email link binding (' . static::class . ') is malformed: ' . $stored);
        }

        // The code the link was sent with must still be the identity's live code: a used,
        // destroyed or replaced code takes its link with it.
        $live = static::__live_issued_code((int) $binding['identity_id']);

        if ($live === null || $live['minted_at'] !== $binding['code_minted_at']) {
            return $expired;
        }

        if (Session::_current_browser_session_id() !== (int) $binding['session_id']) {
            return ['state' => self::EMAIL_LINK_OTHER_BROWSER, 'return_url' => null];
        }

        $pending = static::__pending_challenge();

        if ($pending === null || $pending['challenge_id'] !== $binding['challenge_id']) {
            return $expired;
        }

        return ['state' => self::EMAIL_LINK_SAME_BROWSER, 'return_url' => (string) $binding['return_url']];
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
     * for an application that caps resends. challenge_id is an opaque name for this one
     * challenge - the screen uses it to tell its own challenge from another tab's.
     *
     * @return array|null {challenge_id, email, email_masked, accepts, has_totp, has_passkey,
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
            'challenge_id' => $pending['challenge_id'],
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
     *     any moment - so the client address is judged before any work happens (the
     *     identity's own caps, below, cover attempts spread across addresses). The throw is
     *     let through untouched: "we did not check" is a different answer from "that was
     *     wrong", and a login function must be able to say so.
     *  2. The pending identity is loaded, or the window has closed.
     *  2b. An identity that has spent its failure budget (is_locked()) is refused before
     *     anything is tried, and its challenge is discarded - a correct answer would
     *     otherwise make the lock an oracle.
     *  3. Only the kinds the challenge ACCEPTS are tried. A passkey assertion is tried when
     *     one was offered; otherwise a typed code is tried against the identity's live
     *     issued code, then
     *     every confirmed TOTP credential, then the recovery codes. Recovery LAST, so a
     *     string that is a live TOTP code never burns a recovery code. An email link token
     *     ({email_link}) is an issued-code answer: it must be the link minted for THIS
     *     browser's challenge and the identity's live code (issued_link()). An answer of a
     *     kind the challenge does not accept is a wrong answer, and so is a wrong or dead link.
     *  4. A failure is recorded ONCE, through the realm (Login_History::record_failure()
     *     or Portal_Login_History::record_failure() with STATUS_FAILED_2FA, which already
     *     feeds Login_Throttle). Never twice: one failure counted twice halves the real
     *     limit, and the halving would only be discovered by a user locked out early. The failure also counts once against
     *     the challenge and once against the identity (the attempt caps, independent of
     *     IP); the answer that spends the challenge's budget destroys the challenge, and
     *     that answer or the one that locks the identity destroys its live issued code.
     *  5. On success the pending value is forgotten FIRST, the identity's failure count is
     *     cleared and its live issued code consumed with its email link (whichever kind of
     *     answer passed - the sign-in it was sent for is over); an issued-code answer marks the
     *     address verified (__mark_address_verified()); then the realm signs the identity in,
     *     the success is recorded, and a short-lived marker says this browser's challenge was
     *     completed (_challenge_completed_state(), read by a challenge screen left open in
     *     another tab).
     *  6. The sign-in REFUSES an identity the realm will not admit - a staff identity holding
     *     no active site membership (users.is_enabled + sites.is_enabled), a portal user the portal's account
     *     vocabulary rejects (Portal_User_Model::can_login(), or a user of another site). A
     *     correct code from such an identity is recorded STATUS_FAILED_DISABLED and then fails
     *     with the wrong-code message, so the two are indistinguishable from the outside.
     *
     * @param array $input {assertion: array}, {code: string} or {email_link: string}.
     * @return Rsx_Model_Abstract The identity now signed in (Login_User_Model for staff,
     *                            Portal_User_Model for the portal).
     * @throws \App\RSpade\Core\Auth\Auth_Throttled_Exception When the client is over the sign-in rate limit.
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
            static::__forget_issued_code($identity_id);

            throw new Two_Factor_Failed_Exception(
                'Too many incorrect codes have been entered for this account. Please try again later.',
                Two_Factor_Failed_Exception::REASON_IDENTITY_LOCKED
            );
        }

        // A BLANK ANSWER IS NOT A GUESS, so it spends none of the budget below: no failure
        // row, no throttle count, no attempt against the challenge or the identity. It is
        // refused here, after every refusal that does not depend on the answer.
        $has_assertion = isset($input['assertion']) && is_array($input['assertion']);
        $has_code = isset($input['code']) && is_string($input['code']) && trim($input['code']) !== '';
        $has_link = isset($input['email_link']) && is_string($input['email_link']) && $input['email_link'] !== '';

        if (!$has_assertion && !$has_code && !$has_link) {
            throw new Two_Factor_Failed_Exception('Enter your verification code.');
        }

        $answer = null;

        $accepts = static::__answerable($pending);

        if ($has_assertion) {
            $answer = in_array(self::ANSWER_PASSKEY, $accepts, true)
                && static::__try_assertion($input['assertion'], $identity_id) ? self::ANSWER_PASSKEY : null;
        } elseif ($has_code) {
            $answer = static::__try_code($input['code'], $pending, $accepts);
        } else {
            $answer = static::__try_email_link($input['email_link'], $pending, $accepts);
        }

        if ($answer === null) {
            static::__record_failure($email, Login_History::STATUS_FAILED_2FA, $identity_id);

            $identity_failures = Rsx_Counter::increment(
                static::__identity_failure_key($identity_id),
                static::identity_failure_window_seconds()
            );

            // The identity is now locked: no answer will be accepted until the window closes,
            // so the code it was sent is worthless to its owner and only useful to whoever
            // reads it later.
            if ($identity_failures >= static::identity_max_failures()) {
                static::__forget_issued_code($identity_id);
            }

            // The counter only has to outlive the challenge it counts, which lives exactly
            // the challenge window.
            $challenge_failures = Rsx_Counter::increment(
                static::__challenge_failure_key($pending['challenge_id']),
                (int) config('rsx.two_factor.challenge_window_minutes') * 60
            );

            if ($challenge_failures >= static::challenge_max_failures()) {
                static::abandon_challenge();

                // The code goes with the challenge it was being guessed against: the next
                // sign-in mints a new one, so the guesses already spent narrow nothing.
                static::__forget_issued_code($identity_id);

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
        static::__forget_email_link($pending);
        static::__forget_issued_code($identity_id);

        // An emailed answer proved the mailbox: the address is verified, whether or not the
        // realm goes on to admit the identity (see the class docblock).
        if ($answer === self::ANSWER_ISSUED_CODE) {
            static::__mark_address_verified($identity);
        }

        // The second factor was answered correctly, and the realm still will not admit the
        // identity - so the sign-in is refused with the same message a wrong code gets. The
        // realm's admission rule is not something to explain to whoever is typing; the audit
        // trail carries the real classification.
        if (!static::__sign_in($identity)) {
            static::__record_failure($email, Login_History::STATUS_FAILED_DISABLED, $identity_id);

            throw new Two_Factor_Failed_Exception('That code is not valid.');
        }

        static::__record_success($identity, $email);
        static::__remember_verified($identity_id, $answer);
        static::__mark_challenge_completed($pending, $has_link);

        return $identity;
    }

    /**
     * Discard the pending challenge - the user pressed cancel, or the flow is done.
     *
     * The identity's live issued code is LEFT: it belongs to the identity, not to this
     * browser's challenge, and another browser of the same identity may be answering it
     * right now - cancelling here must not break the sign-in there. A code left behind
     * still dies at the end of its absolute life, and signing in again from here re-sends
     * it rather than minting a second one.
     *
     * @return void
     */
    public static function abandon_challenge(): void
    {
        Session::forget_value(static::CHALLENGE_KEY, static::SESSION_REALM);
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
     *     endpoint is reachable by anyone, so it is throttled like a password is.
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
     * @throws \App\RSpade\Core\Auth\Auth_Throttled_Exception When the client is over the sign-in rate limit.
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
        static::__remember_verified($identity_id, self::ANSWER_PASSKEY);

        return $identity;
    }

    // -------------------------------------------------------------------------
    // Trusted browsers
    // -------------------------------------------------------------------------

    /**
     * Identities that passed a second factor in THIS request, per realm class:
     * static::class => [identity_id, ANSWER_*]. What makes trust_this_device() refuse to
     * mint trust for anybody who did not just prove a second factor.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private static array $__verified_this_request = [];

    /**
     * How many days a trusted browser is honoured in this realm -
     * config('rsx.two_factor.trusted_device_days.<realm>'); 0 is off.
     *
     * @return int
     */
    public static function trusted_device_days(): int
    {
        $days = config('rsx.two_factor.trusted_device_days.' . static::TRUST_REALM);

        if (!is_int($days) || $days < 0) {
            throw new \RuntimeException(
                'config(\'rsx.two_factor.trusted_device_days.' . static::TRUST_REALM . '\') must be a whole number of days, 0 or more.'
            );
        }

        return $days;
    }

    /**
     * Trust THIS BROWSER for $identity, for trusted_device_days(): the application's verify
     * endpoint calls it after a successful verify_challenge() (or verify_passkey_login()) when
     * its policy wants the next sign-in on this browser excused. Trusting again extends the
     * window. Records which kind of answer passed, for device_is_trusted()'s $earned_by.
     *
     * Refuses - throws - unless $identity passed a second factor in THIS request: trust is a
     * convenience on top of a verified sign-in, never a way to mint one. Throws too when the
     * realm's window is 0 (the configuration says this realm trusts no browser), and when
     * this browser has no session row.
     *
     * @param Rsx_Model_Abstract $identity
     * @return void
     */
    public static function trust_this_device(Rsx_Model_Abstract $identity): void
    {
        $verified = self::$__verified_this_request[static::class] ?? null;

        if ($verified === null || $verified[0] !== (int) $identity->id) {
            throw new \RuntimeException(
                static::class . '::trust_this_device() is only valid in the request in which this identity passed a '
                . 'second factor (verify_challenge() or verify_passkey_login()).'
            );
        }

        $days = static::trusted_device_days();

        if ($days === 0) {
            throw new \RuntimeException(
                static::class . '::trust_this_device(): trusted browsers are off in this realm - '
                . 'config(\'rsx.two_factor.trusted_device_days.' . static::TRUST_REALM . '\') is 0.'
            );
        }

        $session_id = Session::_current_browser_session_id();

        if ($session_id === null) {
            throw new \RuntimeException(static::class . '::trust_this_device(): this browser has no session to trust.');
        }

        $now = now();

        DB::statement(
            'INSERT INTO _two_factor_trusted_devices
                (identity_type, identity_id, session_id, earned_by, trusted_until, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE earned_by = VALUES(earned_by), trusted_until = VALUES(trusted_until), updated_at = VALUES(updated_at)',
            [
                static::__identity_type_id(),
                (int) $identity->id,
                $session_id,
                $verified[1],
                $now->copy()->addDays($days)->format('Y-m-d H:i:s.v'),
                $now->format('Y-m-d H:i:s.v'),
                $now->format('Y-m-d H:i:s.v'),
            ]
        );
    }

    /**
     * Has $identity passed a second factor on THIS BROWSER within the realm's window - by one
     * of $earned_by's kinds of answer, when given? The application's login function asks it
     * before starting a challenge; what a trusted browser excuses is the application's
     * policy. Always false while the realm's window is 0.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @param array|null $earned_by ANSWER_* kinds that count; null = any
     * @return bool
     */
    public static function device_is_trusted(int|Rsx_Model_Abstract $identity, ?array $earned_by = null): bool
    {
        if (static::trusted_device_days() === 0) {
            return false;
        }

        $session_id = Session::_current_browser_session_id();

        if ($session_id === null) {
            return false;
        }

        $query = DB::table('_two_factor_trusted_devices')
            ->where('identity_type', static::__identity_type_id())
            ->where('identity_id', static::__resolve_id($identity))
            ->where('session_id', $session_id)
            ->where('trusted_until', '>', now()->format('Y-m-d H:i:s.v'));

        if ($earned_by !== null) {
            foreach ($earned_by as $kind) {
                if (!in_array($kind, self::ANSWERS, true)) {
                    throw new \RuntimeException("device_is_trusted(): '{$kind}' is not one of the ANSWER_* kinds.");
                }
            }

            $query->whereIn('earned_by', $earned_by);
        }

        return $query->exists();
    }

    /**
     * Stop trusting $identity's browsers - every one, or every one but this. The framework
     * calls it when the identity's password changes, when it ends its other sessions, and
     * when a second factor is removed; an application calls it for "sign out of all devices"
     * or any policy of its own.
     *
     * @param int|Rsx_Model_Abstract $identity
     * @param bool $except_this_device Keep this browser's trust
     * @return int Rows removed
     */
    public static function forget_trusted_devices(int|Rsx_Model_Abstract $identity, bool $except_this_device = false): int
    {
        $query = DB::table('_two_factor_trusted_devices')
            ->where('identity_type', static::__identity_type_id())
            ->where('identity_id', static::__resolve_id($identity));

        $session_id = Session::_current_browser_session_id();

        if ($except_this_device && $session_id !== null) {
            $query->where('session_id', '<>', $session_id);
        }

        return $query->delete();
    }

    /**
     * Remove trust that has run out, for every identity in both realms. Called by the session
     * cleanup task. FRAMEWORK INTERNAL.
     *
     * @return int Rows removed
     */
    public static function _delete_expired_trust(): int
    {
        return DB::table('_two_factor_trusted_devices')
            ->where('trusted_until', '<=', now()->format('Y-m-d H:i:s.v'))
            ->delete();
    }

    /**
     * Forget which identities passed a second factor in this request. FRAMEWORK INTERNAL -
     * the test harness calls it between tests; a web request ends with the process state.
     *
     * @return void
     */
    public static function _reset_request_state(): void
    {
        self::$__verified_this_request = [];
    }

    /**
     * Note that $identity_id passed a second factor in this request, and by what.
     */
    protected static function __remember_verified(int $identity_id, string $answer): void
    {
        self::$__verified_this_request[static::class] = [$identity_id, $answer];
    }

    /**
     * This realm's identity model as a type ref - the identity_type column.
     */
    protected static function __identity_type_id(): int
    {
        return Type_Ref_Registry::class_to_id(class_basename(static::_identity_model()));
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
     * @param array $pending
     * @param array $accepts
     * @return string|null The ANSWER_* that matched, or null
     */
    protected static function __try_code(string $code, array $pending, array $accepts): ?string
    {
        $identity_id = $pending['identity_id'];
        $code = trim($code);

        if (in_array(self::ANSWER_ISSUED_CODE, $accepts, true)) {
            $live = static::__live_issued_code($identity_id);

            if ($live !== null && hash_equals($live['code'], $code)) {
                return self::ANSWER_ISSUED_CODE;
            }
        }

        if (in_array(self::ANSWER_TOTP, $accepts, true) && static::__try_totp($code, $identity_id)) {
            return self::ANSWER_TOTP;
        }

        if (in_array(self::ANSWER_RECOVERY_CODE, $accepts, true)
            && Recovery_Codes::consume(static::class, $identity_id, $code)) {
            return self::ANSWER_RECOVERY_CODE;
        }

        return null;
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
     *                    codes_issued}
     */
    protected static function __pending_challenge(): ?array
    {
        $pending = Session::get_value(static::CHALLENGE_KEY, null, static::SESSION_REALM);

        if (!is_array($pending) || !isset($pending['identity_id'], $pending['email'], $pending['challenge_id'], $pending['expires_at'])) {
            return null;
        }

        return [
            'identity_id' => (int) $pending['identity_id'],
            'email' => (string) $pending['email'],
            'challenge_id' => (string) $pending['challenge_id'],
            'accepts' => is_array($pending['accepts'] ?? null) ? array_values($pending['accepts']) : null,
            'expires_at' => (string) $pending['expires_at'],
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

    // -------------------------------------------------------------------------
    // Issued-code storage
    // -------------------------------------------------------------------------

    /**
     * The identity's live code, or a freshly minted one stored in its place.
     *
     * Read, then create-if-absent: the store's write is SET NX, so two requests minting for
     * the same identity at once cannot both win - the loser goes round again and reads the
     * winner's code. The loop ends because each pass either finds a live code or stores one;
     * a store that cannot write throws rather than answering false.
     *
     * @param int $identity_id
     * @return string The six digits.
     */
    protected static function __live_or_minted_code(int $identity_id): string
    {
        while (true) {
            $live = static::__live_issued_code($identity_id);

            $expires_at = static::challenge_expires_at();

            if ($live !== null) {
                // Same digits, same mint, a full window again. False only when the code
                // expired between the read and this write - then go round and mint.
                $restarted = Rsx_Counter::replace_value(
                    static::__issued_code_key($identity_id),
                    Crypt::encryptString(json_encode(['code' => $live['code'], 'minted_at' => $live['minted_at'], 'expires_at' => $expires_at])),
                    Rsx_Time::to_ms($expires_at)
                );

                if ($restarted) {
                    return $live['code'];
                }

                continue;
            }

            $code = str_pad((string) random_int(0, (10 ** self::ISSUED_CODE_DIGITS) - 1), self::ISSUED_CODE_DIGITS, '0', STR_PAD_LEFT);

            $stored = Rsx_Counter::add_value(
                static::__issued_code_key($identity_id),
                Crypt::encryptString(json_encode(['code' => $code, 'minted_at' => Rsx_Time::now_iso(), 'expires_at' => $expires_at])),
                Rsx_Time::to_ms($expires_at)
            );

            if ($stored) {
                return $code;
            }
        }
    }

    /**
     * The identity's live issued code and when it expires, or null when it has none.
     *
     * The store expires the entry at the code's own expiry (an absolute time), so presence
     * IS liveness. The entry is decrypted with the application key; one that does not decrypt
     * or decode into the expected shape is corruption, and fails loud.
     *
     * @param int $identity_id
     * @return array|null {code, minted_at, expires_at}
     */
    protected static function __live_issued_code(int $identity_id): ?array
    {
        $stored = Rsx_Counter::value(static::__issued_code_key($identity_id));

        if ($stored === null) {
            return null;
        }

        $decoded = json_decode(Crypt::decryptString($stored), true);

        if (!is_array($decoded) || !is_string($decoded['code'] ?? null) || !is_string($decoded['minted_at'] ?? null) || !is_string($decoded['expires_at'] ?? null)) {
            shouldnt_happen('The issued sign-in code stored for identity ' . $identity_id . ' (' . static::class . ') is malformed');
        }

        return ['code' => $decoded['code'], 'minted_at' => $decoded['minted_at'], 'expires_at' => $decoded['expires_at']];
    }

    /**
     * Destroy the identity's live issued code, if it has one.
     *
     * @param int $identity_id
     * @return void
     */
    protected static function __forget_issued_code(int $identity_id): void
    {
        Rsx_Counter::reset(static::__issued_code_key($identity_id));
    }

    // -------------------------------------------------------------------------
    // Email links and the completed marker
    // -------------------------------------------------------------------------

    /**
     * Try an email link token: an issued-code answer when it is the link minted for THIS
     * browser's pending challenge and the identity's live code.
     *
     * The comparison is against the token re-derived from what is pending here, so a link
     * minted for another browser's challenge, for an earlier challenge, or for a code since
     * replaced is simply a wrong answer - no lookup of the presented value is involved.
     *
     * @param string $token
     * @param array $pending
     * @param array $accepts
     * @return string|null ANSWER_ISSUED_CODE, or null
     */
    protected static function __try_email_link(string $token, array $pending, array $accepts): ?string
    {
        if (!in_array(self::ANSWER_ISSUED_CODE, $accepts, true)) {
            return null;
        }

        $live = static::__live_issued_code($pending['identity_id']);

        if ($live === null) {
            return null;
        }

        $expected = static::__email_link_token($pending['challenge_id'], $live['minted_at']);

        return hash_equals($expected, $token) ? self::ANSWER_ISSUED_CODE : null;
    }

    /**
     * Drop the binding of the email link minted for this challenge and the identity's live
     * code, if one was minted. Called on success, before the code itself is consumed - the
     * landing route would refuse the link afterwards anyway (its code is gone), but a binding
     * with nothing left to answer is not kept.
     *
     * @param array $pending
     * @return void
     */
    protected static function __forget_email_link(array $pending): void
    {
        $live = static::__live_issued_code($pending['identity_id']);

        if ($live === null) {
            return;
        }

        Rsx_Counter::reset(static::__email_link_key(static::__email_link_token($pending['challenge_id'], $live['minted_at'])));
    }

    /**
     * Leave a marker saying this browser's challenge was completed, for a challenge screen
     * still open in another tab (the user signed in from the email link, or in a second tab,
     * and comes back to the first).
     *
     * ITS LIFE IS THE CHALLENGE'S OWN WINDOW, not a number of its own: it expires when the
     * completed challenge would have. A tab answering after that would have found its
     * challenge expired anyway and says "sign in again"; until then, the truthful answer is
     * "you are signed in". It is a realm session value, so a later sign-out or a new
     * challenge (begin_challenge() signs the realm out first) clears it.
     *
     * @param array $pending
     * @param bool $by_email_link Whether the answer was the email link (the screen words it so)
     * @return void
     */
    protected static function __mark_challenge_completed(array $pending, bool $by_email_link): void
    {
        if (Rsx_Time::is_past($pending['expires_at'])) {
            return;
        }

        Session::put_value(
            static::__completed_key(),
            ['challenge_id' => $pending['challenge_id'], 'by_email_link' => $by_email_link],
            $pending['expires_at'],
            static::SESSION_REALM
        );
    }

    /**
     * {completed: true, challenge_id, by_email_link} when this browser completed a challenge of this realm
     * within that challenge's window, else null - FRAMEWORK INTERNAL, for the challenge_state
     * endpoints, which answer it when nothing is pending.
     *
     * @return array|null
     */
    public static function _challenge_completed_state(): ?array
    {
        if (Session::_current_browser_session_id() === null) {
            return null;
        }

        $marker = Session::get_value(static::__completed_key(), null, static::SESSION_REALM);

        if (!is_array($marker) || !is_string($marker['challenge_id'] ?? null)) {
            return null;
        }

        return [
            'completed' => true,
            'challenge_id' => $marker['challenge_id'],
            'by_email_link' => (bool) ($marker['by_email_link'] ?? false),
        ];
    }

    /**
     * The session value key of the completed marker, per realm.
     */
    protected static function __completed_key(): string
    {
        return static::CHALLENGE_KEY . '.completed';
    }

    /**
     * The email link token for one challenge and one code: HMAC-SHA256 under a key derived
     * from app.key, over the realm, the challenge id and the code's expiry. 256 bits nobody
     * without the application key can produce, and the same value every time it is asked for
     * the same challenge and code - which is what lets a resend repeat the link while storing
     * no secret.
     *
     * @param string $challenge_id
     * @param string $code_minted_at
     * @return string 64 lowercase hex characters
     */
    protected static function __email_link_token(string $challenge_id, string $code_minted_at): string
    {
        $app_key = (string) config('app.key');

        if ($app_key === '') {
            shouldnt_happen('Email sign-in links need app.key (APP_KEY) to be set');
        }

        $key = hash_hmac('sha256', 'rsx-two-factor-email-link', $app_key, true);

        return hash_hmac('sha256', static::class . "\n" . $challenge_id . "\n" . $code_minted_at, $key);
    }

    /**
     * Where an email link's binding is kept, per realm, keyed by the token's SHA-256 - beside
     * the identity's code, in the same store.
     *
     * @param string $token
     * @return string
     */
    protected static function __email_link_key(string $token): string
    {
        return static::CHALLENGE_KEY . ':email_link:' . hash('sha256', $token);
    }

    /**
     * The absolute URL of the landing route for a token, signed (Rsx_Signed_Url, purpose
     * EMAIL_LINK_PURPOSE) over the token and the host the URL is addressed to - the realm's
     * own host, so a staff link presented on the portal's host (or the reverse) is refused.
     *
     * @param string $token
     * @return string
     */
    protected static function __email_link_url(string $token): string
    {
        $url = static::__email_link_route($token);
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            shouldnt_happen(static::class . ': the email link route did not resolve to an absolute URL: ' . $url);
        }

        return $url . (str_contains($url, '?') ? '&' : '?')
            . http_build_query(['s' => Rsx_Signed_Url::sign(self::EMAIL_LINK_PURPOSE, $token, $host)]);
    }

    /**
     * Where one identity's live issued code is kept, per realm - beside its failure counter,
     * in the same store.
     *
     * @param int $identity_id
     * @return string
     */
    protected static function __issued_code_key(int $identity_id): string
    {
        return static::CHALLENGE_KEY . ':issued_code:identity:' . $identity_id;
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
     * Record that the identity's address is verified (is_verified), because an emailed answer
     * - an issued code or its link - just proved the mailbox. Called by verify_challenge()
     * before __sign_in(), so a realm whose admission rule requires a verified address (the
     * portal's can_login()) admits the identity on the strength of this very answer.
     *
     * @param Rsx_Model_Abstract $identity
     * @return void
     */
    abstract protected static function __mark_address_verified(Rsx_Model_Abstract $identity): void;

    /**
     * The absolute, UNSIGNED URL of the email link landing route (/_email_link/:token) on
     * this realm's host. __email_link_url() signs it.
     *
     * @param string $token
     * @return string
     */
    abstract protected static function __email_link_route(string $token): string;

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
