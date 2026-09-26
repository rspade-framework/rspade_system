<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The panel is developer-only, surface by surface.
 *
 * Every dispatchable surface declared under app/RSpade/Sys/app/sys - route, SPA
 * bootstrap, JS action, Ajax endpoint - carries is_sysadmin, as the manifest indexed
 * it, save the one named exception (GET /_sys/stop-impersonating, is_logged_in); every panel controller inherits the rsx.sys_panel.enabled refusal from
 * _Sys_Endpoint_Controller_Abstract; and a signed-in identity that is not a developer
 * is refused at the dispatch seam, on the panel page and on one Ajax endpoint of every
 * panel controller that has one.
 *
 * Each later panel phase extends this class only by shipping controllers: the
 * enumeration picks them up.
 */
class Sys_Panel_Gate_Test extends Rsx_Test_Abstract
{
    private const PANEL_DIR = 'app/RSpade/Sys/app/sys/';

    /**
     * Surfaces under the panel that are deliberately NOT gated on is_sysadmin, each
     * with the gates it carries instead and the reason it is safe. EXACTLY ONE, and
     * RP-GATE-01 holds it there: a second entry is a design change, not a test edit.
     */
    private const UNGATED = [
        // While impersonating, the effective identity is never a developer, so an
        // is_sysadmin gate would lock the developer inside the impersonation. The body
        // checks the IMPERSONATOR's is_developer and refuses (without stopping) anyone
        // else; all it can do is end an impersonation and restore the developer.
        '_Sys_Impersonation_Controller::stop' => ['is_logged_in'],
    ];

    /** Attributes that make a PHP method a dispatchable surface. */
    private const SURFACE_ATTRIBUTES = ['Route', 'SPA', 'Ajax_Endpoint', 'Ajax_Endpoint_Model_Fetch', 'Api_Endpoint'];

    public static function teardown()
    {
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        config(['rsx.sys_panel.enabled' => true]);
        static::__reset_session();
    }

    /**
     * RP-GATE-01 - Every auth surface the manifest indexed from the panel's module
     * carries is_sysadmin, save the ONE named exception (the stop-impersonating route),
     * which carries exactly the gates UNGATED declares for it.
     */
    public static function test_every_indexed_panel_surface_is_sysadmin()
    {
        $surfaces = static::__panel_surfaces();

        static::__assert_not_empty($surfaces, 'no auth surfaces indexed under ' . self::PANEL_DIR);

        foreach ($surfaces as $target => $surface) {
            if (array_key_exists($target, self::UNGATED)) {
                continue;
            }

            static::__assert_true(
                in_array('is_sysadmin', $surface['auth'] ?? [], true),
                "{$target} is not gated on is_sysadmin (gates: " . json_encode($surface['auth'] ?? []) . ')'
            );
            static::__assert_equals(Auth_Gates::REALM_STAFF, $surface['realm'] ?? null, "{$target} is not a staff surface");
        }

        static::__assert_count(1, self::UNGATED, 'the panel has exactly one surface not gated on is_sysadmin');

        foreach (self::UNGATED as $target => $gates) {
            static::__assert_array_has_key($target, $surfaces, "named exception {$target} is not a panel surface");
            static::__assert_equals($gates, $surfaces[$target]['auth'] ?? null, "named exception {$target} carries exactly its declared gates");
            static::__assert_equals(Auth_Gates::REALM_STAFF, $surfaces[$target]['realm'] ?? null, "{$target} is not a staff surface");
        }
    }

    /**
     * RP-GATE-02 - The index is complete: every surface DECLARED in the panel's files
     * (a PHP method carrying a surface attribute, a JS class carrying @route) is one
     * of the indexed surfaces RP-GATE-01 checked.
     */
    public static function test_every_declared_panel_surface_is_indexed()
    {
        $surfaces = static::__panel_surfaces();
        $declared = [];

        foreach (Manifest::get_all() as $path => $meta) {
            if (!str_starts_with($path, self::PANEL_DIR) || empty($meta['class'])) {
                continue;
            }

            if (($meta['extension'] ?? null) === 'php') {
                foreach ($meta['public_static_methods'] ?? [] as $method => $info) {
                    if (array_intersect(self::SURFACE_ATTRIBUTES, array_keys($info['attributes'] ?? []))) {
                        $declared[] = $meta['class'] . '::' . $method;
                    }
                }
            }

            if (($meta['extension'] ?? null) === 'js') {
                foreach ($meta['decorators'] ?? [] as $decorator) {
                    if (($decorator[0] ?? null) === 'route') {
                        $declared[] = $meta['class'];
                        break;
                    }
                }
            }
        }

        static::__assert_not_empty($declared, 'no surfaces declared under ' . self::PANEL_DIR);

        foreach (array_unique($declared) as $target) {
            static::__assert_array_has_key($target, $surfaces, "{$target} is declared but has no indexed auth surface");
        }
    }

    /**
     * RP-GATE-03 - Every panel controller extends _Sys_Endpoint_Controller_Abstract,
     * so the rsx.sys_panel.enabled refusal is inherited rather than restated.
     */
    public static function test_every_panel_controller_extends_the_endpoint_base()
    {
        $controllers = static::__panel_controllers();

        static::__assert_not_empty($controllers, 'no panel controllers found');

        foreach ($controllers as $class) {
            static::__assert_true(
                Manifest::php_is_subclass_of($class, '_Sys_Endpoint_Controller_Abstract'),
                "{$class} does not extend _Sys_Endpoint_Controller_Abstract"
            );
        }
    }

    /**
     * RP-GATE-04 - A signed-in identity that is not a developer is refused the panel
     * page with a 403 (not a login redirect: it IS signed in).
     */
    public static function test_a_non_developer_is_refused_the_panel_page()
    {
        static::__acting_as_non_developer();

        Rsx_Bundle_Abstract::$_has_rendered = null;
        $response = Dispatcher::dispatch('/_sys', 'GET', [], Request::create('/_sys', 'GET'));

        static::__assert_equals(403, $response->getStatusCode());
    }

    /**
     * RP-GATE-05 - The same identity is refused one Ajax endpoint of every panel
     * controller that declares any, through the browser transport.
     */
    public static function test_a_non_developer_is_refused_every_panel_controllers_ajax()
    {
        static::__acting_as_non_developer();

        foreach (static::__first_ajax_endpoint_per_controller() as $controller => $action) {
            $envelope = static::__ajax($controller, $action);

            static::__assert_equals(
                Ajax::ERROR_UNAUTHORIZED,
                $envelope['error_code'] ?? null,
                "{$controller}::{$action} answered a non-developer: " . json_encode($envelope)
            );
        }
    }

    /**
     * RP-GATE-06 - The inherited refusal on the Ajax channel: a panel-shaped
     * controller answers a developer while the panel is enabled and answers
     * not_found when it is switched off; a non-developer is refused by the gate.
     */
    public static function test_the_inherited_switch_refuses_on_the_ajax_channel()
    {
        $fixture = 'Sys_Panel_Endpoint_Fixture_Controller';

        static::__acting_as_user(1);

        $envelope = static::__ajax($fixture, 'ping');
        static::__assert_true($envelope['_success'] ?? false, 'a developer is answered: ' . json_encode($envelope));

        config(['rsx.sys_panel.enabled' => false]);
        $envelope = static::__ajax($fixture, 'ping');
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $envelope['error_code'] ?? null, 'a disabled panel is not found: ' . json_encode($envelope));
        config(['rsx.sys_panel.enabled' => true]);

        static::__acting_as_non_developer();
        $envelope = static::__ajax($fixture, 'ping');
        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $envelope['error_code'] ?? null, 'a non-developer is refused: ' . json_encode($envelope));
    }

    /**
     * Auth surfaces whose declaring file is under the panel module, keyed by target.
     */
    private static function __panel_surfaces(): array
    {
        $out = [];

        foreach (Auth_Gates::get_surfaces() as $target => $surface) {
            if (str_starts_with($surface['file'] ?? '', self::PANEL_DIR)) {
                $out[$target] = $surface;
            }
        }

        return $out;
    }

    /**
     * Simple names of every concrete controller declared under the panel module.
     */
    private static function __panel_controllers(): array
    {
        $out = [];

        foreach (Manifest::get_all() as $path => $meta) {
            if (!str_starts_with($path, self::PANEL_DIR) || ($meta['extension'] ?? null) !== 'php' || empty($meta['class'])) {
                continue;
            }

            if (Manifest::php_is_subclass_of($meta['class'], 'Rsx_Controller_Abstract') && empty($meta['abstract'])) {
                $out[] = $meta['class'];
            }
        }

        return $out;
    }

    /**
     * Controller => its first Ajax endpoint, for every panel controller declaring one.
     */
    private static function __first_ajax_endpoint_per_controller(): array
    {
        $out = [];

        foreach (Manifest::get_all() as $path => $meta) {
            if (!str_starts_with($path, self::PANEL_DIR) || ($meta['extension'] ?? null) !== 'php' || empty($meta['class'])) {
                continue;
            }

            foreach ($meta['public_static_methods'] ?? [] as $method => $info) {
                if (array_key_exists('Ajax_Endpoint', $info['attributes'] ?? [])) {
                    $out[$meta['class']] = $method;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Sign in an identity that is not a developer, holding an enabled membership on
     * user 1's site - an ordinary signed-in staff user in every respect but the
     * login identity's is_developer, which is what the gate reads.
     */
    private static function __acting_as_non_developer(): void
    {
        $anchor = User_Model::without_site_scope(function () {
            return User_Model::find(1);
        });
        static::__assert_not_null($anchor, 'the test baseline carries user 1');

        $site_id = (int) $anchor->site_id;

        $login_user = new Login_User_Model();
        $login_user->email = 'sys-gate-probe-' . random_hash(8) . '@rspade.test';
        $login_user->password = Hash::make(random_hash(16));
        $login_user->is_activated = 1;
        $login_user->is_verified = 1;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        // The site-scope trait takes site_id from the session on create, so the tenant
        // is declared before the membership row is written.
        Session::set_site_id($site_id);

        $membership = new User_Model();
        $membership->login_user_id = $login_user->id;
        $membership->email = $login_user->email;
        $membership->first_name = 'Gate';
        $membership->last_name = 'Probe';
        $membership->is_enabled = 1;
        $membership->save();

        Session::impersonate($site_id, (int) $login_user->id, (int) $membership->id);

        static::__assert_true(Session::is_logged_in(), 'the probe identity is signed in');
        static::__assert_false(Session::is_developer(), 'the probe identity is not a developer');
    }

    /**
     * One call through the browser transport, as the front controller receives it.
     */
    private static function __ajax(string $controller, string $action): array
    {
        // A session is minted so the call carries a real CSRF token: a CSRF refusal is
        // also 'unauthorized', and must never pass for the gate's.
        Session::get_session_id();

        $request = Request::create('/_ajax/' . $controller . '/' . $action, 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_CSRF_TOKEN' => (string) Session::get_csrf_token(),
        ]);
        app()->instance('request', $request);

        $response = Rsx_Front_Controller::handle($request);

        $envelope = json_decode($response->getContent(), true) ?? ['raw' => $response->getContent()];

        static::__assert_true(
            ($envelope['reason'] ?? null) !== 'CSRF token mismatch',
            "{$controller}::{$action} was refused by CSRF, not by its gate"
        );

        return $envelope;
    }
}
