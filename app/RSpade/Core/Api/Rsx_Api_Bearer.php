<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Rsx_Portal;
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
 *      /_preview/{pdf,sheet}). Those are ordinary #[Route]s dispatched by the page
 *      Dispatcher - yet a file's bytes are exactly what an API client that just uploaded it
 *      wants next, and inventing /api/vN/ mirrors of the byte-serving routes would have
 *      meant two URLs per file forever. So the EXISTING URLs learn one extra credential
 *      instead: the Dispatcher calls authenticate_file_route() below as soon as one of them
 *      matches, ahead of the gates and the application's Main::pre_dispatch.
 *
 * The rules are identical wherever the header is honored, because they are written once: the
 * key must resolve, its login identity must be live (not soft-deleted), and its user must be
 * BOTH active and permitted to use the API (users.is_api_access_enabled). A caller learns
 * that the key does not work, never WHY. The application's account policy
 * (Main::pre_dispatch) is asked on both channels too, and refuses with account_refused().
 */
class Rsx_Api_Bearer
{
    /**
     * The representative path the file-serving web routes are scope-checked against - one
     * concrete member of the /api/v1/files subtree. See authenticate_file_route().
     */
    private const FILES_SUBTREE_PROBE = '/api/v1/files/anything';

    /**
     * The raw key from an Authorization: Bearer header, or null when there is no such header.
     */
    public static function token_from(Request $request): ?string
    {
        $auth_header = $request->header('Authorization');

        // The scheme name is case-insensitive (RFC 7235 section 2.1, RFC 6750).
        if (!$auth_header || strncasecmp($auth_header, 'Bearer ', 7) !== 0) {
            return null;
        }

        return substr($auth_header, 7);
    }

    /**
     * Authenticate the Bearer key and establish the headless Session identity.
     *
     * Resolves the key's user WITHOUT site scope (no site identity exists yet), and refuses
     * unless that user is BOTH active (User_Model::is_active(): the membership and its site both
     * enabled) and permitted to use the API (users.is_api_access_enabled - the same column
     * Session::has_api_access() reads), and unless its login identity is live: a soft-deleted
     * login_users row is an account RsxAuth::verify_credentials() already treats as not found, so its keys
     * die with it. The refusal happens BEFORE
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
        if (!$user || !$user->is_active() || !$user->is_api_access_enabled
            || Login_User_Model::find((int) $user->login_user_id) === null) {
            return ['error' => ['unauthorized', 'Invalid or expired API key'], 'key' => null, 'user' => null];
        }

        Session::_set_api_identity((int) $user->login_user_id, (int) $user->site_id, (int) $user->id);
        static::touch_last_used($key);

        return ['error' => null, 'key' => $key, 'user' => $user];
    }

    /**
     * The file-serving web routes that accept an API key in place of a cookie session, by
     * manifest surface key (simple class name + method - the same key in both realms, and
     * the same key an application's class override of either controller keeps).
     *
     * EVERY ONE IS GET-ONLY, which is why a READ-ONLY key needs no clamp here: there is no
     * verb to refuse. The day a non-GET route joins this list it needs the API dispatcher's
     * read_only gate as well as the scope clamp below.
     */
    private const FILE_ROUTE_SURFACES = [
        'File_Attachment_Controller::download_file',
        'File_Attachment_Controller::inline',
        'File_Attachment_Controller::download_multiple_zip',
        'File_Attachment_Controller::thumbnail_preset',
        'File_Attachment_Controller::thumbnail',
        'File_Preview_Controller::pdf_rendition',
        'File_Preview_Controller::sheet_rendition',
    ];

    /**
     * Whether a matched page surface accepts an API key (see FILE_ROUTE_SURFACES).
     */
    public static function is_file_route(string $surface): bool
    {
        return in_array($surface, self::FILE_ROUTE_SURFACES, true);
    }

    /**
     * Let a file-serving web route accept an API key in place of a cookie session.
     *
     * Called by the page Dispatcher for a matched FILE_ROUTE_SURFACES row, BEFORE the
     * #[Auth] gates and BEFORE the realm's Main::pre_dispatch - so the application's account
     * policy sees the key's holder exactly as the API dispatcher shows it to that hook, and
     * the file.*.authorize gates see the key's user in Session::get_user() exactly as they
     * see a browser's user. A non-null Main::pre_dispatch answer for the identity set here is
     * the API's own 403 account_refused (account_refused() below): the Dispatcher asks
     * Session::is_api_request() and answers it that way, never with the hook's page response,
     * because a key holder cannot follow an interstitial.
     *
     * Any other surface returns null untouched: the key is honoured on these routes and
     * nowhere else on the page channel.
     *
     * The outcomes, in this order:
     *   - a Bearer header on a PORTAL-realm request (the portal twin of these routes),
     *     identity or not -> the API's own 404 not_found: the API does not exist on the portal.
     *   - an identity already exists (a staff or portal cookie session, or an API identity set
     *     earlier in this request) -> null, and NOTHING is touched. A browser request behaves
     *     exactly as it would without this method, header or no header. This also enforces
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
     */
    public static function authenticate_file_route(string $surface, Request $request): ?Response
    {
        if (!static::is_file_route($surface)) {
            return null;
        }

        // The portal realm takes no key. These routes also answer in the portal's route
        // table, for portal pages; an API key is a STAFF identity and the API does not exist
        // on the portal, so a key presented there is refused exactly as an unknown API path
        // on the portal host is (Api_Dispatcher) - never signed into a portal request. A
        // browser never sends one of its own accord, so no page is affected.
        if (Rsx_Portal::is_portal_request() && static::token_from($request) !== null) {
            return Rsx_Api::error('not_found', 'Unknown API endpoint', 404);
        }

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
     * The ONE answer to a non-null Main::pre_dispatch for a bearer identity, on the API and on
     * the file-serving web routes alike: an API client cannot follow a redirect or an
     * interstitial, so whatever the hook returned becomes this uniform 403.
     */
    public static function account_refused(): JsonResponse
    {
        return Rsx_Api::error('account_refused', 'This account may not use the API at this time.', 403);
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
