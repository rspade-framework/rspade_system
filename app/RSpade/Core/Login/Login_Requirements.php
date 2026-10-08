<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Login;

use RuntimeException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Login\Login_Requirement_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Login_Requirements - the state between "signed in" and "admitted".
 *
 * A signed-in identity with an unmet Login_Requirement_Abstract reads as NOT LOGGED IN to
 * every identity reader - Session::is_logged_in(), get_user(), get_login_user(),
 * get_login_user_id() and the Portal_Session equivalents - except while the request is
 * dispatched to a surface one of its outstanding requirements lists. So every page, Ajax
 * endpoint, model fetch, realtime subscription and file read treats it as anonymous, and the
 * requirement's own screen and endpoints see the user they act on.
 *
 * WHY THE READERS AND NOT THE SURFACES. The second-factor challenge signs the session out
 * because "signed in plus a flag" leaves every surface as a place the flag can be forgotten.
 * A requirement cannot sign the session out - its steps reuse features that need the
 * identity (an upload, an OAuth callback that writes to the user's row) - so the check sits
 * where forgetting it is impossible: in the readers, failing CLOSED. A surface nobody thought
 * about denies, because to it the user is not there.
 *
 * WHERE THE LIST LIVES. The outstanding requirements per realm are carried on the session
 * row (_sessions.login_requirements, {"staff": [...], "portal": [...]}), computed when an
 * identity signs in - Session::set_login_user_id(), Portal_Session::set_portal_user_id() -
 * and when the staff site changes, and cleared on sign-out. A request reads it with the row
 * it already loaded; an application with no requirement classes pays nothing.
 *
 * THE SURFACE. The dispatch seams bind the target being served (_bind_surface()): the
 * page dispatcher the matched route, Ajax::execute() each endpoint call. Before a surface is
 * bound - early boot, CSRF, the transport's own checks - a pending identity is concealed.
 *
 * RE-EVALUATION IS AUTOMATIC. When a pending identity asks for a surface its requirements do
 * not list, the requirements are evaluated again before it is turned away - once per request.
 * So the moment a user has done what was asked, their next request lets them in, and a
 * requirement's endpoint never has to announce completion. recheck() is there for an
 * endpoint that wants to know where to send the user next.
 *
 * STEERING. A pending identity refused by a page's gates is redirected to the first
 * outstanding requirement's screen instead of the login page; refused by an Ajax endpoint it
 * gets Ajax::ERROR_REQUIREMENT_PENDING carrying that destination, and the JS Ajax layer
 * navigates there. A public surface is served to it as to any anonymous visitor.
 *
 * NOT SUBJECT: a bearer-key API call (its identity is the key's, never the session's), and
 * the CLI. While impersonating, only requirements that say applies_while_impersonating().
 *
 * See: php artisan rsx:man login_requirements
 */
class Login_Requirements
{
    /**
     * TEST SEAM. The CLI is never subject to login requirements - a script acting as a user
     * is not a browser signing in - so _compute() does nothing there and the CLI identity is
     * never concealed. A test that exercises the requirements in process sets this, and the
     * CLI identity is then treated exactly as a browser session's. Reset by
     * _reset_for_testing().
     */
    public static bool $_enforce_in_cli_for_testing = false;

    /**
     * The surface the current request is being dispatched to, once a seam has bound one.
     */
    private static ?string $__surface = null;

    /**
     * Whether any seam has bound a surface in this request.
     */
    private static bool $__surface_bound = false;

    /**
     * True while is_satisfied() runs: the readers reveal the identity to the evaluation that
     * decides whether to conceal it, so a requirement that reaches for Session cannot recurse.
     */
    private static bool $__evaluating = false;

    /**
     * Realms re-evaluated in this request (re-evaluation runs once per request per realm).
     *
     * @var array<string, bool>
     */
    private static array $__rechecked = [];

    /**
     * Requirement classes per realm, sorted, memoised per process (the manifest is fixed for
     * a request).
     *
     * @var array<string, array>|null
     */
    private static ?array $__classes = null;

    /**
     * Simple class name => fully qualified name, for every requirement class.
     *
     * @var array<string, string>|null
     */
    private static ?array $__fqcns = null;

    // -------------------------------------------------------------------------
    // Application API
    // -------------------------------------------------------------------------

    /**
     * The requirements the current identity has not met in a realm, as class names in the
     * order the user is taken through them.
     *
     * @param string|null $realm 'staff' or 'portal'; null = the realm of this request.
     * @return array
     */
    public static function outstanding(?string $realm = null): array
    {
        $realm = static::__realm($realm);
        $declared = static::__fqcn_map();

        // A name whose class no longer exists (a requirement deleted or renamed since this
        // session's list was computed) is not a requirement any more, and is passed over.
        return array_values(array_filter(
            Session::_get_login_requirements()[$realm] ?? [],
            fn ($name) => is_string($name) && isset($declared[$name])
        ));
    }

    /**
     * Does the current identity have requirements outstanding in a realm?
     *
     * @param string|null $realm
     * @return bool
     */
    public static function is_pending(?string $realm = null): bool
    {
        return static::outstanding($realm) !== [];
    }

    /**
     * The URL of the first outstanding requirement's screen, or null when nothing is
     * outstanding.
     *
     * @param string|null $realm
     * @return string|null
     */
    public static function destination(?string $realm = null): ?string
    {
        $realm = static::__realm($realm);
        $outstanding = static::outstanding($realm);

        if ($outstanding === []) {
            return null;
        }

        $class = static::__fqcn($outstanding[0]);
        $target = $class::screen();

        return $realm === Auth_Gates::REALM_PORTAL ? Rsx_Portal::Route($target) : Rsx::Route($target);
    }

    /**
     * Evaluate the current identity's requirements now and answer where it goes next: the
     * next outstanding requirement's screen, or null when everything is met and the identity
     * is admitted.
     *
     * A requirement's endpoint calls this after doing its work, to send the user onward;
     * nothing else needs to - a pending identity is re-evaluated automatically whenever it
     * asks for something its requirements do not list.
     *
     * @param string|null $realm
     * @return string|null
     */
    public static function recheck(?string $realm = null): ?string
    {
        $realm = static::__realm($realm);

        static::_compute($realm);

        return static::destination($realm);
    }

    /**
     * Re-evaluate a user's requirements on every live session they hold - for when the
     * application changes policy mid-session ("every administrator must now enroll a second
     * factor"), so the change reaches people already signed in on their next request.
     *
     * @param Rsx_Model_Abstract $user A User_Model (staff, that site's sessions) or a
     *                                 Portal_User_Model.
     * @return void
     */
    public static function recheck_user(Rsx_Model_Abstract $user): void
    {
        if ($user instanceof Portal_User_Model) {
            $sessions = Session::where('portal_user_id', $user->id)->where('active', true)->result_set();
            $realm = Auth_Gates::REALM_PORTAL;
        } elseif ($user instanceof User_Model) {
            $sessions = Session::where('login_user_id', $user->login_user_id)
                ->where('site_id', $user->site_id)
                ->where('active', true)
                ->result_set();
            $realm = Auth_Gates::REALM_STAFF;
        } else {
            throw new RuntimeException('Login_Requirements::recheck_user() takes a User_Model or a Portal_User_Model, got ' . get_class($user));
        }

        foreach ($sessions as $session) {
            $impersonating = $realm === Auth_Gates::REALM_PORTAL
                ? !empty($session->impersonator_user_id)
                : !empty($session->impersonator_login_user_id);

            $map = is_array($session->login_requirements) ? $session->login_requirements : [];
            $map[$realm] = static::__evaluate($realm, $user, $impersonating);

            $session->login_requirements = static::__normalize_map($map);
            $session->save();
        }
    }

    /**
     * Every requirement class declared for a realm, in the order a user is taken through
     * them (ORDER, then class name).
     *
     * @param string $realm
     * @return array Simple class names.
     */
    public static function all(string $realm): array
    {
        return static::__classes()[$realm] ?? [];
    }

    // -------------------------------------------------------------------------
    // Framework seams
    // -------------------------------------------------------------------------

    /**
     * Bind the surface the request is being dispatched to. Returns the previous binding so a
     * nested call (one endpoint of a batch) can restore it.
     *
     * FRAMEWORK INTERNAL - the dispatch seams only.
     *
     * @param string|null $target 'Controller::method'
     * @return string|null
     */
    public static function _bind_surface(?string $target): ?string
    {
        $previous = static::$__surface;

        static::$__surface = $target;
        static::$__surface_bound = true;

        return $previous;
    }

    /**
     * Should the identity readers hide the realm's signed-in identity right now?
     *
     * FRAMEWORK INTERNAL - Session::get_login_user_id() and Portal_Session::
     * get_portal_user_id() only.
     *
     * @param string $realm
     * @return bool
     */
    public static function _conceals(string $realm): bool
    {
        if (static::$__evaluating) {
            return false;
        }

        if (static::outstanding($realm) === []) {
            return false;
        }

        if (!static::$__surface_bound) {
            return true;
        }

        if (static::__surface_allowed($realm)) {
            return false;
        }

        // Asked for something the requirements do not list: evaluate again before turning
        // the user away, once per request - the requirement may have been met a moment ago.
        if (!isset(static::$__rechecked[$realm])) {
            static::$__rechecked[$realm] = true;
            static::_compute($realm);

            return static::outstanding($realm) !== [];
        }

        return true;
    }

    /**
     * Where a request refused by its gates should be sent instead of the login page: the
     * first outstanding requirement's screen, when the realm has requirements outstanding and
     * the refused surface is not one of theirs. Null otherwise.
     *
     * FRAMEWORK INTERNAL - the dispatcher's and Ajax's refusal paths.
     *
     * @param string $realm
     * @return string|null
     */
    public static function _steer_destination(string $realm): ?string
    {
        if (!static::_conceals($realm)) {
            return null;
        }

        return static::destination($realm);
    }

    /**
     * Evaluate the realm's requirements for the identity on this session and store the
     * outstanding list on the session row. An identity that is not (fully) signed in clears
     * the list.
     *
     * FRAMEWORK INTERNAL - the sign-in, site-switch and impersonation paths, and recheck().
     *
     * @param string $realm
     * @return void
     */
    public static function _compute(string $realm): void
    {
        if (Session::is_api_request()) {
            return;
        }

        if (php_sapi_name() === 'cli' ? !static::$_enforce_in_cli_for_testing : !Session::has_session()) {
            return;
        }

        $map = Session::_get_login_requirements();

        if (static::all($realm) === []) {
            if (isset($map[$realm])) {
                unset($map[$realm]);
                Session::_set_login_requirements(static::__normalize_map($map));
            }

            return;
        }

        $user = static::__realm_user($realm);

        $map[$realm] = $user === null
            ? []
            : static::__evaluate($realm, $user, static::__is_impersonating($realm));

        Session::_set_login_requirements(static::__normalize_map($map));
    }

    /**
     * Forget a realm's outstanding requirements - the identity signed out.
     *
     * FRAMEWORK INTERNAL - the sign-out paths.
     *
     * @param string $realm
     * @return void
     */
    public static function _clear(string $realm): void
    {
        $map = Session::_get_login_requirements();

        if (!isset($map[$realm])) {
            return;
        }

        unset($map[$realm]);
        Session::_set_login_requirements(static::__normalize_map($map));
    }

    /**
     * Reset the per-request state. FRAMEWORK INTERNAL - the test harness, between requests
     * it simulates in one process.
     *
     * @return void
     */
    public static function _reset_request_state(): void
    {
        static::$__surface = null;
        static::$__surface_bound = false;
        static::$__evaluating = false;
        static::$__rechecked = [];
    }

    /**
     * Undo everything a test did here: the request state, the CLI enforcement seam, the
     * CLI process's stored list and the memoised class map.
     *
     * @return void
     */
    public static function _reset_for_testing(): void
    {
        static::_reset_request_state();
        static::$_enforce_in_cli_for_testing = false;
        static::$__classes = null;
        static::$__fqcns = null;

        if (php_sapi_name() === 'cli') {
            Session::_set_login_requirements(null);
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Which requirements this user has not met.
     *
     * @param string $realm
     * @param Rsx_Model_Abstract $user
     * @param bool $impersonating
     * @return array Simple class names, in order.
     */
    private static function __evaluate(string $realm, Rsx_Model_Abstract $user, bool $impersonating): array
    {
        $outstanding = [];
        $was_evaluating = static::$__evaluating;
        static::$__evaluating = true;

        try {
            foreach (static::all($realm) as $name) {
                $class = static::__fqcn($name);

                if ($impersonating && !$class::applies_while_impersonating()) {
                    continue;
                }

                if (!$class::is_satisfied($user)) {
                    $outstanding[] = $name;
                }
            }
        } finally {
            static::$__evaluating = $was_evaluating;
        }

        return $outstanding;
    }

    /**
     * Is the bound surface the screen or a listed surface of an outstanding requirement?
     *
     * @param string $realm
     * @return bool
     */
    private static function __surface_allowed(string $realm): bool
    {
        if (static::$__surface === null) {
            return false;
        }

        foreach (static::outstanding($realm) as $name) {
            $class = static::__fqcn($name);

            if ($class::screen() === static::$__surface || in_array(static::$__surface, $class::surfaces(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The realm's user for this session, read past the concealment: the site User_Model for
     * staff (null until a site is set), the Portal_User_Model for the portal.
     *
     * @param string $realm
     * @return Rsx_Model_Abstract|null
     */
    private static function __realm_user(string $realm): ?Rsx_Model_Abstract
    {
        if ($realm === Auth_Gates::REALM_PORTAL) {
            $portal_user_id = Portal_Session::_get_portal_user_id_unconcealed();

            return $portal_user_id ? Portal_User_Model::find($portal_user_id) : null;
        }

        $login_user_id = Session::_get_login_user_id_unconcealed();
        $site_id = Session::get_site_id();

        if (empty($login_user_id) || empty($site_id)) {
            return null;
        }

        return User_Model::where('login_user_id', $login_user_id)->where('site_id', $site_id)->first();
    }

    /**
     * @param string $realm
     * @return bool
     */
    private static function __is_impersonating(string $realm): bool
    {
        return $realm === Auth_Gates::REALM_PORTAL ? Portal_Session::is_impersonating() : Session::is_impersonating();
    }

    /**
     * Drop empty realms; an empty map is stored as NULL.
     *
     * @param array $map
     * @return array|null
     */
    private static function __normalize_map(array $map): ?array
    {
        $map = array_filter($map, fn ($list) => is_array($list) && $list !== []);

        return $map === [] ? null : $map;
    }

    /**
     * 'staff' or 'portal', defaulting to the realm of this request.
     *
     * @param string|null $realm
     * @return string
     */
    private static function __realm(?string $realm): string
    {
        $realm = $realm ?? (Rsx_Portal::is_portal_request() ? Auth_Gates::REALM_PORTAL : Auth_Gates::REALM_STAFF);

        if ($realm !== Auth_Gates::REALM_STAFF && $realm !== Auth_Gates::REALM_PORTAL) {
            throw new RuntimeException("Login requirements have two realms, 'staff' and 'portal'; got '{$realm}'.");
        }

        return $realm;
    }

    /**
     * The requirement classes, grouped by realm and sorted. A concrete class without a valid
     * REALM throws, naming the class.
     *
     * @return array<string, array>
     */
    private static function __classes(): array
    {
        if (static::$__classes !== null) {
            return static::$__classes;
        }

        $by_realm = [Auth_Gates::REALM_STAFF => [], Auth_Gates::REALM_PORTAL => []];

        foreach (static::__fqcn_map() as $name => $class) {
            if (!defined($class . '::REALM') || !isset($by_realm[$class::REALM])) {
                throw new RuntimeException(
                    "Login requirement {$name} must declare const REALM = 'staff' or 'portal'. See: php artisan rsx:man login_requirements"
                );
            }

            $by_realm[$class::REALM][] = ['name' => $name, 'order' => (int) $class::ORDER];
        }

        foreach ($by_realm as $realm => $entries) {
            usort($entries, fn ($a, $b) => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);
            $by_realm[$realm] = array_column($entries, 'name');
        }

        return static::$__classes = $by_realm;
    }

    /**
     * A requirement's fully qualified class name from its simple name.
     *
     * @param string $name
     * @return string
     */
    private static function __fqcn(string $name): string
    {
        $class = static::__fqcn_map()[$name] ?? null;

        if ($class === null) {
            throw new RuntimeException("'{$name}' is not a login requirement class (it extends no Login_Requirement_Abstract the manifest knows).");
        }

        return $class;
    }

    /**
     * Every concrete requirement class: simple name => fully qualified name.
     *
     * @return array<string, string>
     */
    private static function __fqcn_map(): array
    {
        if (static::$__fqcns === null) {
            static::$__fqcns = array_map(
                fn (array $record) => $record['fqcn'],
                Manifest::php_class_records_extending('Login_Requirement_Abstract')
            );
        }

        return static::$__fqcns;
    }
}
