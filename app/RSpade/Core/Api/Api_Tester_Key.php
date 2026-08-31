<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;

/**
 * Api_Tester_Key - the API key a browser session has adopted for the /apidocs page.
 *
 * WHY THE SERVER NEEDS TO KNOW. The plaintext key lives in the browser (sessionStorage) for
 * the tester's Authorization header, and cannot come back from the database - only its
 * SHA-256 is stored. But when the console restricts its listing, the SERVER has to
 * answer "which endpoints may this caller use" while BUILDING the page, so it needs the key
 * too. What is stored here is the key's ID, in session-scoped storage, which the browser
 * never names and therefore cannot forge.
 *
 * Adoption is a browser-session convenience, not an identity: it does not log anybody in and
 * it grants nothing. It selects which key's permissions the LISTING is drawn for, and
 * Api_Dispatcher still gates every actual call regardless of what this page chose to show.
 */
class Api_Tester_Key
{
    /**
     * The session-value key. Namespaced because one browser session is shared by the staff
     * app and the portal.
     */
    private const SESSION_KEY = 'apidocs.tester_key_id';

    /**
     * Remember which key this browser session is using.
     */
    public static function adopt(Api_Key_Model $key): void
    {
        Session::put_value(self::SESSION_KEY, (int) $key->id);
    }

    /**
     * Stop using a key. Removing an absent one is not an error.
     */
    public static function forget(): void
    {
        Session::forget_value(self::SESSION_KEY);
    }

    /**
     * The adopted key, or null when there is none or it is no longer usable.
     *
     * Re-validated on every read rather than trusted: a key can be revoked or expire between
     * being adopted and being read, and a page listing endpoints for a dead key would be
     * lying in the most confusing possible way.
     */
    public static function current(): ?Api_Key_Model
    {
        $id = Session::get_value(self::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        $key = Api_Key_Model::find((int) $id);

        if (!$key || !$key->is_valid()) {
            // Self-healing: drop the reference so the page stops offering a dead key back.
            self::forget();

            return null;
        }

        return $key;
    }

    /**
     * The identifying prefix of the adopted key ('rsx_live_ab12...'), or null.
     *
     * The prefix is all the page may show. The secret is unrecoverable by design, and
     * echoing a full key back into a rendered document would be the one place it could leak.
     */
    public static function current_prefix(): ?string
    {
        $key = self::current();

        return $key ? $key->key_prefix : null;
    }

    /**
     * The set of 'Class::method' targets the adopted key's user may call, or NULL when no
     * key is adopted (which is NOT the same as "none of them" - the caller decides what an
     * absent key means).
     *
     * HOW IT WORKS, and why it is fenced. Auth checks are parameterless and evaluate against
     * the CURRENT session, so the only way to ask "would this gate pass for the key's user"
     * is to become that user for the duration of the question. Session::_set_api_identity()
     * is the same headless, cookie-less identity Api_Dispatcher establishes for a Bearer
     * call, so the answer is the real one rather than an approximation of it.
     *
     * Two things make that safe:
     *   - the identity is torn down in a finally, so it never leaks into the rest of the
     *     render no matter how the loop exits;
     *   - it answers a VISIBILITY question only. Nothing downstream trusts this list.
     *
     * The identity fence is the WHOLE fence: Auth_Gates evaluates every check live, so the
     * answers follow whichever identity is installed at the moment of the ask and there is
     * nothing to invalidate on either side of the swap.
     *
     * The ADOPTED-KEY answer is gates INTERSECTED with the key's own scope rules - see
     * accessible_targets_for_key(), which this delegates to.
     *
     * @return array<string, bool>|null
     */
    public static function accessible_targets(): ?array
    {
        $key = self::current();

        if (!$key) {
            return null;
        }

        $user = $key->get_user();

        if (!$user) {
            return null;
        }

        return self::accessible_targets_for_key($key);
    }

    /**
     * Which targets can ONE KEY reach: its user's gates INTERSECTED with its own scope rules.
     *
     * The two questions are genuinely different and both have to be asked. #[Auth] answers
     * "may this user use this surface at all"; the key's scopes answer "may this credential,
     * which its holder deliberately narrowed". A console that showed either one alone would
     * lie in one of two ways - listing endpoints a scoped key gets a 403 from, or listing
     * endpoints the rules permit but the user's permissions do not.
     *
     * SCOPES SUBTRACT, so this is an intersection and never a union: a rule can only remove
     * a target the gates already admitted. An unrestricted key is exactly its user's answer.
     *
     * @return array<string, bool> 'Class::method' => reachable
     */
    public static function accessible_targets_for_key(Api_Key_Model $key): array
    {
        $user = $key->get_user();

        if (!$user) {
            return [];
        }

        $targets = self::accessible_targets_for_user($user);

        if ($key->is_unrestricted()) {
            return $targets;
        }

        // Which endpoints the RULES reach, keyed the same way the gate answers are. Every
        // catalogue row carries both halves Api_Scopes needs (pattern + methods), and one
        // granted verb is enough for the endpoint to be listed - the console lists
        // endpoints, not verbs.
        $granted = [];

        foreach (Api_Catalog::get_endpoint_list(false) as $endpoint) {
            if (empty(Api_Scopes::targets_matching($key->scopes, [$endpoint]))) {
                continue;
            }

            $granted[$endpoint['class'] . '::' . $endpoint['method']] = true;
        }

        foreach ($targets as $target => $allowed) {
            $targets[$target] = $allowed && !empty($granted[$target]);
        }

        return $targets;
    }

    /**
     * Is the adopted key SCOPED (carrying rules that narrow it below its holder's
     * authority), UNRESTRICTED (false), or is there no adopted key at all (null)?
     *
     * Three-valued because the console distinguishes all three: "no key" is a different
     * thing to say than "a key that can reach everything its owner can".
     */
    public static function current_is_scoped(): ?bool
    {
        $key = self::current();

        if (!$key) {
            return null;
        }

        return !$key->is_unrestricted();
    }

    /**
     * The same question asked about a user directly, with no adopted key and no browser
     * session involved: which 'Class::method' targets would this user's #[Auth] gates admit.
     *
     * Split out because the CLI asks it too - rsx:api:openapi scopes its document to a user
     * named by --user, and there is no session there to adopt a key into. The fencing
     * described above is the whole reason this is ONE implementation rather than two: an
     * identity swap that is not torn down is easy to get subtly wrong, and the wrong answer
     * still looks plausible.
     *
     * @return array<string, bool>
     */
    public static function accessible_targets_for_user(User_Model $user): array
    {
        $targets = [];

        Session::_set_api_identity((int) $user->login_user_id, (int) $user->site_id, (int) $user->id);

        try {
            foreach (Api_Catalog::get_endpoint_list(false) as $endpoint) {
                $target = $endpoint['class'] . '::' . $endpoint['method'];

                $targets[$target] = Auth_Gates::can_access($target, Auth_Gates::REALM_STAFF);
            }
        } finally {
            Session::_reset_api_identity();
        }

        return $targets;
    }
}
