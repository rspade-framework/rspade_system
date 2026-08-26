<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Auth\Auth_Gates;
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
     * The identifying prefix of the adopted key ('rsk_live_ab12...'), or null.
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
     * Three things make that safe:
     *   - the identity is torn down in a finally, so it never leaks into the rest of the
     *     render no matter how the loop exits;
     *   - Auth_Gates memoizes per realm|check for the whole request, so the memo is reset on
     *     the way IN (or the viewer's already-evaluated answers would be reused for the key's
     *     user) and again on the way OUT (or the key user's answers would leak back to the
     *     viewer). Missing either reset produces a wrong answer that still looks plausible,
     *     which is why both are here rather than at the call site;
     *   - it answers a VISIBILITY question only. Nothing downstream trusts this list.
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

        $targets = [];

        Session::_set_api_identity((int) $user->login_user_id, (int) $user->site_id, (int) $user->id);
        Auth_Gates::reset_memo();

        try {
            foreach (Api_Catalog::get_endpoint_list(false) as $endpoint) {
                $target = $endpoint['class'] . '::' . $endpoint['method'];

                $targets[$target] = Auth_Gates::can_access($target, Auth_Gates::REALM_STAFF);
            }
        } finally {
            Session::_reset_api_identity();
            Auth_Gates::reset_memo();
        }

        return $targets;
    }
}
