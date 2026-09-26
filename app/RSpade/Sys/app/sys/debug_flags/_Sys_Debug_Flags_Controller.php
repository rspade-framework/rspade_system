<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\DebugFlags;

use Illuminate\Http\Request;
use Illuminate\Support\Env;
use App\RSpade\Core\Debug\Console_Debug_Channels;
use App\RSpade\Core\Debug\Console_Debug_Override;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Rsx;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The Debug Flags screen's endpoints: this browser's console_debug override
 * (Console_Debug_Override - stored in the developer's session, never in .env), the
 * channel inventory for its picker, and the read-only table of what is in force.
 *
 * Every value the screen shows is what THIS request - the screen's own Ajax call, from
 * the developer's browser - runs under, so an override saved here is visible in the
 * table as soon as the screen reloads.
 */
#[Auth('is_sysadmin')]
class _Sys_Debug_Flags_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * The environment key each rsx.console_debug key is read from, by the shipped
     * configuration: the framework's own reads (CONSOLE_DEBUG_CLI/WEB/AJAX, taken with
     * getenv() by Debugger) and the env() calls in the framework and template config
     * files. A key an application's config reads from somewhere else shows as 'config'.
     */
    public const CONSOLE_DEBUG_ENV = [
        'enabled' => 'CONSOLE_DEBUG_ENABLED',
        'outputs.cli' => 'CONSOLE_DEBUG_CLI',
        'outputs.web' => 'CONSOLE_DEBUG_WEB',
        'outputs.ajax' => 'CONSOLE_DEBUG_AJAX',
        'outputs.laravel_log' => 'CONSOLE_DEBUG_LOG',
        'filter_mode' => 'CONSOLE_DEBUG_FILTER_MODE',
        'specific_channel' => 'CONSOLE_DEBUG_SPECIFIC',
        'whitelist' => 'CONSOLE_DEBUG_WHITELIST',
        'blacklist' => 'CONSOLE_DEBUG_BLACKLIST',
        'include_benchmark' => 'CONSOLE_DEBUG_BENCHMARK',
        'include_location' => 'CONSOLE_DEBUG_LOCATION',
        'include_backtrace' => 'CONSOLE_DEBUG_BACKTRACE',
        'enable_get_trace' => 'ENABLE_GET_TRACE',
    ];

    /** The same, for rsx.development. */
    public const DEVELOPMENT_ENV = [
        'login_autofill' => 'RSPADE_LOGIN_AUTOFILL',
    ];

    /** The environment key Debugger reads as a channel filter over every mode. */
    public const FILTER_ENV = 'CONSOLE_DEBUG_FILTER';

    /**
     * Everything the screen draws but the channel list.
     *
     * @return array {
     *     applies: bool - console_debug is active in this mode (an override does nothing in strict production),
     *     mode: string,
     *     override: array|null - this browser's stored override, normalized,
     *     form: array - the form's values: the override, or else the configuration's own,
     *     console_debug: [{key, value, source, env}] - the effective PHP configuration,
     *     development: [{key, value, source, env}],
     *     env_filter: string|null - CONSOLE_DEBUG_FILTER, when the environment sets it
     * }
     */
    #[Ajax_Endpoint]
    public static function state(Request $request, array $params = [])
    {
        $override = Console_Debug_Override::stored();

        return [
            'applies' => Debugger::_console_debug_enabled_for_mode(),
            'mode' => Rsx::get_mode(),
            'override' => $override,
            'form' => $override ?? static::__form_from_config(config('rsx.console_debug', [])),
            'console_debug' => static::__rows(
                Debugger::effective_console_config(),
                self::CONSOLE_DEBUG_ENV,
                Debugger::session_override() === null ? [] : Console_Debug_Override::OVERRIDDEN_KEYS
            ),
            'development' => static::__rows((array) config('rsx.development', []), self::DEVELOPMENT_ENV, []),
            // Present-but-empty is meaningful here: it names no channel and silences PHP output.
            'env_filter' => ($filter = getenv(self::FILTER_ENV)) === false ? null : (string) $filter,
        ];
    }

    /**
     * The channel picker's options: every channel console_debug() is called with in the
     * source (Console_Debug_Channels::scan() - the rsx:console_debug:list_channels
     * scanner, run now), plus any channel this browser's override names that the scan
     * does not find.
     *
     * @return array [{value, label, php, js, found}]
     */
    #[Ajax_Endpoint]
    public static function channels(Request $request, array $params = [])
    {
        $out = [];

        foreach (Console_Debug_Channels::scan() as $channel => $counts) {
            $out[$channel] = [
                'value' => $channel,
                'label' => $channel,
                'php' => $counts['php'],
                'js' => $counts['js'],
                'found' => true,
            ];
        }

        $override = Console_Debug_Override::stored();

        foreach ($override['channels'] ?? [] as $channel) {
            if (!isset($out[$channel])) {
                $out[$channel] = ['value' => $channel, 'label' => $channel, 'php' => 0, 'js' => 0, 'found' => false];
            }
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * Store this browser's override.
     *
     * @param array $params enabled, filter_mode, channels[], include_benchmark,
     *                      include_location, include_backtrace
     * @return array {override}
     */
    #[Ajax_Endpoint]
    public static function save(Request $request, array $params = [])
    {
        $input = [
            'enabled' => $params['enabled'] ?? null,
            'filter_mode' => $params['filter_mode'] ?? null,
            'channels' => $params['channels'] ?? [],
        ];

        foreach (Console_Debug_Override::FLAGS as $flag) {
            $input[$flag] = $params[$flag] ?? null;
        }

        $errors = Console_Debug_Override::validate($input);

        if ($errors) {
            return response_form_error('The override was not saved.', $errors);
        }

        return ['override' => Console_Debug_Override::store($input)];
    }

    /**
     * Remove this browser's override; the configuration applies again.
     *
     * @return array {override: null}
     */
    #[Ajax_Endpoint]
    public static function reset(Request $request, array $params = [])
    {
        Console_Debug_Override::clear();

        return ['override' => null];
    }

    /**
     * The form's values when this browser has no override: the configuration's own
     * enabled, filter and switches, so saving unchanged reproduces what is in force.
     */
    private static function __form_from_config(array $config): array
    {
        $mode = $config['filter_mode'] ?? 'all';
        $channels = [];

        if ($mode === 'whitelist') {
            $channels = (array) ($config['whitelist'] ?? []);
        } elseif ($mode === 'blacklist') {
            $channels = (array) ($config['blacklist'] ?? []);
        } elseif ($mode === 'specific' && !empty($config['specific_channel'])) {
            $channels = explode(',', (string) $config['specific_channel']);
        }

        $form = [
            'enabled' => filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'filter_mode' => in_array($mode, Console_Debug_Override::FILTER_MODES, true) ? $mode : 'all',
            'channels' => array_values(array_filter(array_map([Debugger::class, 'normalize_channel'], $channels))),
        ];

        foreach (Console_Debug_Override::FLAGS as $flag) {
            $form[$flag] = filter_var($config[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $form;
    }

    /**
     * One row per leaf key of $config (outputs.* flattened), sorted by key, each with
     * where its value came from: 'browser' (this browser's override), 'env' (the
     * environment sets the key the configuration reads), or 'config'.
     *
     * @param array $config
     * @param array $env_keys key => environment key
     * @param string[] $overridden keys this browser's override replaces
     * @return array [{key, value, source, env}]
     */
    private static function __rows(array $config, array $env_keys, array $overridden): array
    {
        $rows = [];

        foreach (static::__flatten($config) as $key => $value) {
            $env = $env_keys[$key] ?? null;
            $source = 'config';

            if (in_array($key, $overridden, true)) {
                $source = 'browser';
            } elseif ($env !== null && static::__env($env) !== null) {
                $source = 'env';
            }

            $rows[] = ['key' => $key, 'value' => $value, 'source' => $source, 'env' => $env];
        }

        usort($rows, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $rows;
    }

    /**
     * An associative array's leaves as dotted keys; a LIST is one leaf (a channel list).
     */
    private static function __flatten(array $config, string $prefix = ''): array
    {
        $out = [];

        foreach ($config as $key => $value) {
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $out += static::__flatten($value, $prefix . $key . '.');
            } else {
                $out[$prefix . $key] = $value;
            }
        }

        return $out;
    }

    /**
     * An environment key's raw value as Laravel's env() sees it, or null when it is unset
     * or EMPTY (the .env convention: a setting is removed by emptying it).
     */
    private static function __env(string $name): ?string
    {
        $value = Env::getRepository()->get($name);

        return $value === null || $value === '' ? null : (string) $value;
    }
}
