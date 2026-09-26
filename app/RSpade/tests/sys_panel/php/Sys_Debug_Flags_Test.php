<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;
use App\RSpade\Core\Debug\Console_Debug_Channels;
use App\RSpade\Core\Debug\Console_Debug_Override;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\DebugFlags\_Sys_Debug_Flags_Controller;

/**
 * The Debug Flags screen: a developer's per-browser console_debug override
 * (Console_Debug_Override, stored in the session), how PHP (Debugger) and JS
 * (window.rsxapp.console_debug) pick it up, who it applies to, the screen's endpoints,
 * and the channel inventory shared with rsx:console_debug:list_channels.
 */
class Sys_Debug_Flags_Test extends Rsx_Test_Abstract
{
    /** An override no configuration on any box plausibly matches. */
    private const OVERRIDE = [
        'enabled' => '1',
        'filter_mode' => 'whitelist',
        'channels' => ['sys_probe_b', 'SYS_PROBE_A', 'sys probe a'],
        'include_benchmark' => '0',
        'include_location' => '1',
        'include_backtrace' => '1',
    ];

    /**
     * teardown() runs once per CLASS; a test that reads Debugger's resolved state first
     * resolves it for its own session (Debugger::apply_session_override()), since the
     * override a previous test resolved is process state no transaction rolls back.
     */
    public static function teardown()
    {
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        Rsx_Bundle_Abstract::$_has_rendered = null;
        static::__reset_session();

        // With no session, the next resolution is "no override" - nothing leaks into
        // the next test.
        Debugger::apply_session_override();
    }

    /**
     * RP-DEBUG-01 - normalize() is the one validator: channels are normalized, deduped
     * and sorted, 'all' carries none, the switches become booleans; a bad mode, a
     * narrowing mode with no channel, or a nameless channel is refused.
     */
    public static function test_normalize_and_validate()
    {
        $normalized = Console_Debug_Override::normalize(self::OVERRIDE);

        static::__assert_equals([
            'enabled' => true,
            'filter_mode' => 'whitelist',
            'channels' => ['SYS_PROBE_A', 'SYS_PROBE_B'],
            'include_benchmark' => false,
            'include_location' => true,
            'include_backtrace' => true,
        ], $normalized);

        $all = Console_Debug_Override::normalize(['filter_mode' => 'all', 'channels' => ['AUTH']]);
        static::__assert_equals([], $all['channels'], "'all' carries no channels");
        static::__assert_false($all['enabled'], 'an absent switch is off');

        static::__assert_array_has_key('filter_mode', Console_Debug_Override::validate(['filter_mode' => 'sideways']));
        static::__assert_array_has_key('channels', Console_Debug_Override::validate(['filter_mode' => 'specific', 'channels' => []]));
        static::__assert_array_has_key('channels', Console_Debug_Override::validate(['filter_mode' => 'blacklist', 'channels' => ['[]']]));
        static::__assert_array_has_key('channels', Console_Debug_Override::validate(['filter_mode' => 'whitelist', 'channels' => 'AUTH']));

        static::__assert_throws(InvalidArgumentException::class, fn () => Console_Debug_Override::normalize(['filter_mode' => 'sideways']));
    }

    /**
     * RP-DEBUG-02 - A developer's stored override is what PHP and JS both run under:
     * Debugger's effective configuration and the rendered rsxapp.console_debug carry it,
     * the specific mode joins its channels into specific_channel, and the outputs stay
     * the configuration's.
     */
    public static function test_developer_override_reaches_php_and_rsxapp()
    {
        static::__acting_as_user(1);
        static::__assert_true(Session::is_developer(), 'the baseline user 1 is a developer');
        Debugger::apply_session_override();

        $baseline = static::__rendered_console_debug();

        Console_Debug_Override::store(self::OVERRIDE);
        Debugger::apply_session_override();

        static::__assert_equals(Console_Debug_Override::normalize(self::OVERRIDE), Debugger::session_override());

        $php = Debugger::effective_console_config();
        static::__assert_true($php['enabled'], 'PHP: enabled');
        static::__assert_equals('whitelist', $php['filter_mode'], 'PHP: filter_mode');
        static::__assert_equals(['SYS_PROBE_A', 'SYS_PROBE_B'], $php['whitelist'], 'PHP: whitelist');
        static::__assert_true($php['include_location'], 'PHP: include_location');

        $js = static::__rendered_console_debug();
        static::__assert_true($js['enabled'], 'rsxapp: enabled');
        static::__assert_equals('whitelist', $js['filter_mode'], 'rsxapp: filter_mode');
        static::__assert_equals(['SYS_PROBE_A', 'SYS_PROBE_B'], $js['filter_channels'], 'rsxapp: filter_channels');
        static::__assert_false($js['include_benchmark'], 'rsxapp: include_benchmark');
        static::__assert_true($js['include_location'], 'rsxapp: include_location');
        static::__assert_true($js['include_backtrace'], 'rsxapp: include_backtrace');
        static::__assert_equals($baseline['outputs'], $js['outputs'], 'the outputs stay the configuration\'s');

        Console_Debug_Override::store(array_merge(self::OVERRIDE, ['filter_mode' => 'specific']));
        Debugger::apply_session_override();

        static::__assert_equals('SYS_PROBE_A,SYS_PROBE_B', Debugger::effective_console_config()['specific_channel'], 'specific joins its channels');
        static::__assert_equals('SYS_PROBE_A,SYS_PROBE_B', static::__rendered_console_debug()['specific_channel'], 'rsxapp: specific_channel');
    }

    /**
     * RP-DEBUG-03 - The seam is wired: a page dispatched through the front controller
     * for the developer's session renders the override into its rsxapp.
     */
    public static function test_front_controller_applies_the_override()
    {
        static::__acting_as_user(1);
        Console_Debug_Override::store(self::OVERRIDE);

        $request = Request::create('/_sys', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        app()->instance('request', $request);
        Rsx_Bundle_Abstract::$_has_rendered = null;

        $response = Rsx_Front_Controller::handle($request);

        static::__assert_equals(200, $response->getStatusCode(), 'the developer is served the panel');
        $js = static::__console_debug_from_html($response->getContent());
        static::__assert_equals('whitelist', $js['filter_mode'], 'the page carries the override');
        static::__assert_equals(['SYS_PROBE_A', 'SYS_PROBE_B'], $js['filter_channels']);
        static::__assert_not_null(Debugger::session_override(), 'and PHP runs under it');
    }

    /**
     * RP-DEBUG-04 - The same stored value under a session whose identity is NOT a
     * developer does nothing: no override resolves, and PHP and rsxapp are exactly what
     * they are with nothing stored.
     */
    public static function test_non_developer_session_gets_nothing()
    {
        static::__acting_as_non_developer();
        Debugger::apply_session_override();

        $baseline_js = static::__rendered_console_debug();
        $baseline_php = Debugger::effective_console_config();

        Console_Debug_Override::store(self::OVERRIDE);
        static::__assert_not_null(Console_Debug_Override::stored(), 'the value is stored');
        static::__assert_null(Console_Debug_Override::active(), 'but it is not active for a non-developer');

        Debugger::apply_session_override();

        static::__assert_null(Debugger::session_override());
        static::__assert_equals($baseline_php, Debugger::effective_console_config(), 'PHP configuration unchanged');
        static::__assert_equals($baseline_js, static::__rendered_console_debug(), 'rsxapp.console_debug unchanged');
    }

    /**
     * RP-DEBUG-05 - An anonymous request resolves no override and creates no session
     * doing so.
     */
    public static function test_anonymous_is_unaffected_and_creates_no_session()
    {
        // The runner declares a boot tenant for every test; an anonymous request has none.
        static::__reset_session();

        static::__assert_false(Session::has_session(), 'no session to begin with');
        $rows = DB::table('_sessions')->count();

        Debugger::apply_session_override();

        static::__assert_null(Debugger::session_override());
        static::__assert_false(Session::has_session(), 'resolving the override created no session');
        static::__assert_equals($rows, DB::table('_sessions')->count(), 'no _sessions row was written');
    }

    /**
     * RP-DEBUG-06 - The screen's endpoints: save refuses an invalid override with a
     * field error and stores a valid one; state() reports it, with 'browser' as the
     * source of every key it replaces and 'config'/'env' for the outputs; reset removes
     * it and the next request runs under the configuration again.
     */
    public static function test_endpoints_save_state_reset()
    {
        static::__acting_as_user(1);

        $refused = _Sys_Debug_Flags_Controller::save(Request::create('/'), ['filter_mode' => 'whitelist', 'channels' => []]);
        static::__assert_true($refused instanceof Error_Response, 'a whitelist with no channel is refused');
        static::__assert_array_has_key('channels', $refused->get_metadata());
        static::__assert_null(Console_Debug_Override::stored(), 'nothing was stored');

        $saved = _Sys_Debug_Flags_Controller::save(Request::create('/'), self::OVERRIDE);
        static::__assert_equals(Console_Debug_Override::normalize(self::OVERRIDE), $saved['override']);

        Debugger::apply_session_override();
        $state = _Sys_Debug_Flags_Controller::state(Request::create('/'));

        static::__assert_equals($saved['override'], $state['override']);
        static::__assert_equals($saved['override'], $state['form'], 'the form shows the override');

        $sources = array_column($state['console_debug'], 'source', 'key');
        foreach (Console_Debug_Override::OVERRIDDEN_KEYS as $key) {
            static::__assert_equals('browser', $sources[$key] ?? null, "{$key} comes from this browser");
        }
        static::__assert_true(in_array($sources['outputs.web'] ?? null, ['config', 'env'], true), 'outputs.web is never the browser\'s');
        static::__assert_equals('login_autofill', $state['development'][0]['key'] ?? null, 'rsx.development is listed');

        $channels = _Sys_Debug_Flags_Controller::channels(Request::create('/'));
        $by_value = array_column($channels, null, 'value');
        static::__assert_false($by_value['SYS_PROBE_A']['found'], 'a stored channel the source never names is still offered');

        _Sys_Debug_Flags_Controller::reset(Request::create('/'));
        static::__assert_null(Console_Debug_Override::stored(), 'reset removes it');

        Debugger::apply_session_override();
        static::__assert_null(Debugger::session_override(), 'the next request runs under the configuration');
        static::__assert_null(_Sys_Debug_Flags_Controller::state(Request::create('/'))['override']);
    }

    /**
     * RP-DEBUG-07 - The channel inventory is ONE scan: the service returns exactly the
     * channels rsx:console_debug:list_channels prints.
     */
    public static function test_channel_service_matches_the_command()
    {
        $scanned = array_keys(Console_Debug_Channels::scan());

        Artisan::call('rsx:console_debug:list_channels');
        preg_match_all('/^\|\s*([A-Z0-9_]+)\s*\|/m', Artisan::output(), $matches);

        static::__assert_not_empty($scanned, 'the source names at least one channel');
        static::__assert_equals($scanned, $matches[1]);
        static::__assert_true(in_array('DISPATCH', $scanned, true), 'the dispatcher\'s own channel is found');
    }

    /**
     * rsxapp.console_debug as a page rendered now would carry it.
     */
    private static function __rendered_console_debug(): array
    {
        return Rsx_Bundle_Abstract::console_debug_payload();
    }

    private static function __console_debug_from_html(string $html): array
    {
        static::__assert_true(
            (bool) preg_match('/window\.rsxapp = (\{.*?\});<\/script>/s', $html, $match),
            'the page carries window.rsxapp'
        );

        $rsxapp = json_decode($match[1], true);
        static::__assert_array_has_key('console_debug', $rsxapp, 'rsxapp carries console_debug in this mode');

        return $rsxapp['console_debug'];
    }

    /**
     * Sign in an identity that is not a developer, holding an enabled membership on
     * user 1's site.
     */
    private static function __acting_as_non_developer(): void
    {
        $anchor = User_Model::without_site_scope(function () {
            return User_Model::find(1);
        });

        $site_id = (int) $anchor->site_id;

        $login_user = new Login_User_Model();
        $login_user->email = 'sys-debug-probe-' . random_hash(8) . '@rspade.test';
        $login_user->password = Hash::make(random_hash(16));
        $login_user->is_activated = 1;
        $login_user->is_verified = 1;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        Session::set_site_id($site_id);

        $membership = new User_Model();
        $membership->login_user_id = $login_user->id;
        $membership->email = $login_user->email;
        $membership->first_name = 'Debug';
        $membership->last_name = 'Probe';
        $membership->is_enabled = 1;
        $membership->save();

        Session::impersonate($site_id, (int) $login_user->id, (int) $membership->id);

        static::__assert_false(Session::is_developer(), 'the probe identity is not a developer');
    }
}
