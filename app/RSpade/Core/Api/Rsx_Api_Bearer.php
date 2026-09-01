<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Api_Bearer - the ONE implementation of "turn an Authorization: Bearer rsx_... header into
 * a headless Session identity".
 *
 * Two callers, one implementation:
 *
 *   1. Api_Dispatcher, for every /api/vN/ request. Auth runs FIRST there, uniformly across the
 *      whole namespace, so a caller cannot probe which endpoints exist without a good key.
 *   2. The file-SERVING web routes (/_download, /_inline, /_thumbnail/*, /_download_zip and
 *      /_preview/pdf). Those are ordinary #[Route]s dispatched by the main Dispatcher, which
 *      never sees API identity - yet a file's bytes are exactly what an API client that just
 *      uploaded it wants next, and inventing /api/vN/ mirrors of four byte-serving routes
 *      would have meant two URLs per file forever. So the EXISTING URLs learn one extra
 *      credential instead: authenticate_web_request() below.
 *
 * The rules are identical wherever the header is honored, because they are written once: the
 * key must resolve, and its user must be BOTH active and permitted to use the API
 * (users.is_api_access_enabled). A caller learns that the key does not work, never WHY.
 */
class Rsx_Api_Bearer
{
    /**
     * The representative path the file-serving web routes are scope-checked against - one
     * concrete member of the /api/v1/files subtree. See authenticate_web_request().
     */
    private const FILES_SUBTREE_PROBE = '/api/v1/files/anything';

    /**
     * The raw key from an Authorization: Bearer header, or null when there is no such header.
     */
    public static function token_from(Request $request): ?string
    {
        $auth_header = $request->header('Authorization');

        if (!$auth_header || !str_starts_with($auth_header, 'Bearer ')) {
            return null;
        }

        return substr($auth_header, 7);
    }

    /**
     * Authenticate the Bearer key and establish the headless Session identity.
     *
     * Resolves the key's user WITHOUT site scope (no site identity exists yet), and refuses
     * unless that user is BOTH active and permitted to use the API (users.is_api_access_enabled
     * - the same column Session::has_api_access() reads). The refusal happens BEFORE
     * _set_api_identity(), so a refused user never gets an identity established, and it reuses
     * the one uniform message every other key failure returns. On success, sets the API identity
     * and throttles the last_used_at touch.
     *
     * @return array{error: ?array, key: ?Api_Key_Model, user: ?User_Model}
     */
    public static function authenticate(Request $request): array
    {
        $token = static::token_from($request);
        if ($token === null) {
            return [
                'error' => ['auth_required', 'API key is required. Provide via Authorization: Bearer <key> header.'],
                'key' => null,
                'user' => null,
            ];
        }

        $key = Api_Key_Model::find_by_key($token);
        if (!$key) {
            return ['error' => ['unauthorized', 'Invalid or expired API key'], 'key' => null, 'user' => null];
        }

        // No site identity is established yet, so the site-scoped find must run unscoped.
        $user = User_Model::without_site_scope(fn () => User_Model::find((int) $key->user_id));
        if (!$user || !$user->is_active() || !$user->is_api_access_enabled) {
            return ['error' => ['unauthorized', 'Invalid or expired API key'], 'key' => null, 'user' => null];
        }

        Session::_set_api_identity((int) $user->login_user_id, (int) $user->site_id, (int) $user->id);
        static::touch_last_used($key);

        return ['error' => null, 'key' => $key, 'user' => $user];
    }

    /**
     * Let an ordinary web route accept an API key in place of a cookie session.
     *
     * Called as the FIRST statement of a byte-serving route, before its file.*.authorize gates
     * run, so those gates see the key's user in Session::get_user() exactly as they see a
     * browser's user.
     *
     * Three outcomes, in this order:
     *   - an identity already exists (a staff or portal cookie session, or an API identity set
     *     earlier in this request) -> null, and NOTHING is touched. A browser request behaves
     *     exactly as it did before this method existed, header or no header. This also enforces
     *     Session::_set_api_identity()'s once-per-request contract.
     *   - no identity and no Bearer header -> null. The route proceeds anonymously and its
     *     gates decide, as they always have.
     *   - no identity and a Bearer header that does not authenticate -> a 401 JSON response.
     *     A bad key DENIES; it never degrades into an anonymous request, because a caller who
     *     presented a credential is entitled to be told it was refused rather than silently
     *     handed whatever an anonymous visitor may see.
     *
     * A SCOPED key is clamped here too, and it must be: these routes serve the very bytes
     * GET /api/v1/files/:key describes, on a URL that carries no /api/vN/ path for a scope to
     * match. Without this check a key scoped away from files would be refused by the API and
     * then handed the same file on /_download - the scope would be advisory. So a scoped key
     * is evaluated against a SYNTHETIC path inside the files subtree: the question asked is
     * "does any scope reach the files subtree", and the representative single-segment path
     * below is how decide() is asked it. A key with no such scope gets the same
     * insufficient_scope 403 the dispatcher returns.
     *
     * The 403 body names no 'required' target, unlike the dispatcher's: the synthetic path is
     * not an endpoint the caller could ever call, and printing it would advertise a URL that
     * does not exist.
     *
     * A READ-ONLY KEY NEEDS NO CLAMP HERE. Every route that calls this method is declared
     * GET-only (/_download, /_inline, /_thumbnail/*, /_download_zip, /_preview/pdf), so a
     * read-only key may use all of them by definition - there is no verb to refuse. The day
     * a non-GET route adopts this credential, it needs the dispatcher's read_only gate as
     * well as this scope clamp.
     */
    public static function authenticate_web_request(Request $request): ?Response
    {
        if (Session::is_api_request() || Session::has_session()) {
            return null;
        }

        if (static::token_from($request) === null) {
            return null;
        }

        $auth = static::authenticate($request);
        if ($auth['error'] !== null) {
            return Rsx_Api::error($auth['error'][0], $auth['error'][1], 401);
        }

        if (!Api_Scopes::decide($auth['key']->scopes, self::FILES_SUBTREE_PROBE, (int) $auth['key']->id)) {
            return Rsx_Api::error(
                'insufficient_scope',
                'This API key is not scoped for this endpoint',
                403
            );
        }

        return null;
    }

    /**
     * Bump last_used_at, but only when it is null or older than 60 seconds. Keeps a
     * high-frequency key from writing the row on every single request.
     */
    public static function touch_last_used(Api_Key_Model $key): void
    {
        $last = $key->last_used_at;

        if ($last === null) {
            $key->touch_last_used();

            return;
        }

        // last_used_at carries the model's datetime cast (Carbon) - touch only when stale.
        if ($last->lt(now()->subSeconds(60))) {
            $key->touch_last_used();
        }
    }
}
