<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Debug;

use InvalidArgumentException;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Session\Session;

/**
 * A DEVELOPER's per-browser console_debug override.
 *
 * The /_sys Debug Flags screen stores one value against the developer's browser
 * session (Session::put_value(SESSION_KEY)) and it layers over config('rsx.console_debug')
 * - after the config file and the environment - for that browser only. Nothing is
 * written to .env or to any config file, so no other browser, no other developer and no
 * CLI process sees it, and it dies with the session.
 *
 * It applies ONLY while the session's identity is a developer (Session::is_developer())
 * AT REQUEST TIME, so an override left behind by a developer whose flag was since
 * removed does nothing; and only where console_debug is active at all (development and
 * debug modes - Debugger::_console_debug_enabled_for_mode()). It never widens
 * console_debug into strict production.
 *
 * Two consumers read it, once per request: Debugger::apply_session_override() (called
 * by the front controller before a page or Ajax dispatch, the first point at which the
 * browser's session is known) for PHP output, and the window.rsxapp.console_debug
 * block the bundle renders for JS output. Both call overlay(), so the two languages see
 * the same override. Only its own keys are overridden - outputs (cli/web/ajax/
 * laravel_log) and enable_get_trace stay configuration.
 *
 * Shape (normalize() is the one validator):
 *   enabled            bool
 *   filter_mode        all | whitelist | blacklist | specific
 *   channels           string[] - the whitelist, the blacklist, or the specific
 *                      channels; empty for 'all', required otherwise
 *   include_benchmark  bool
 *   include_location   bool
 *   include_backtrace  bool
 */
class Console_Debug_Override
{
    /** The _session_values key the override is stored under. */
    public const SESSION_KEY = 'sys.console_debug';

    /** console_debug's filter modes, in the order a picker offers them. */
    public const FILTER_MODES = ['all', 'whitelist', 'blacklist', 'specific'];

    /** The boolean prefix switches the override carries. */
    public const FLAGS = ['include_benchmark', 'include_location', 'include_backtrace'];

    /** The rsx.console_debug keys an override replaces (outputs and enable_get_trace are not among them). */
    public const OVERRIDDEN_KEYS = [
        'enabled', 'filter_mode', 'whitelist', 'blacklist', 'specific_channel',
        'include_benchmark', 'include_location', 'include_backtrace',
    ];

    /**
     * The override in force for THIS request, or null.
     *
     * Null unless console_debug is active in this mode, the session's identity is a
     * developer, and a value is stored. Session readers only - an anonymous request
     * creates no session here.
     *
     * @return array|null The normalized override
     */
    public static function active(): ?array
    {
        if (!Debugger::_console_debug_enabled_for_mode()) {
            return null;
        }

        if (!Session::is_developer()) {
            return null;
        }

        return static::stored();
    }

    /**
     * The value stored against this browser session, normalized, whoever is signed in.
     * A reader: null when there is no session or nothing stored.
     *
     * @return array|null
     */
    public static function stored(): ?array
    {
        $value = Session::get_value(self::SESSION_KEY);

        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            shouldnt_happen('The stored ' . self::SESSION_KEY . ' session value is not an object: ' . json_encode($value));
        }

        return static::normalize($value);
    }

    /**
     * Store an override against this browser session (a Session WRITER).
     *
     * @param array $override
     * @return array The normalized value stored
     */
    public static function store(array $override): array
    {
        $normalized = static::normalize($override);

        Session::put_value(self::SESSION_KEY, $normalized);

        return $normalized;
    }

    /**
     * Remove this browser's override. Removing an absent one is not an error.
     */
    public static function clear(): void
    {
        Session::forget_value(self::SESSION_KEY);
    }

    /**
     * What is wrong with a proposed override, keyed by field; empty when it is valid.
     *
     * @param array $input
     * @return array<string, string>
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $mode = $input['filter_mode'] ?? null;

        if (!is_string($mode) || !in_array($mode, self::FILTER_MODES, true)) {
            $errors['filter_mode'] = 'Choose one of: ' . implode(', ', self::FILTER_MODES) . '.';

            return $errors;
        }

        $channels = $input['channels'] ?? [];

        if (!is_array($channels)) {
            $errors['channels'] = 'Channels must be a list.';

            return $errors;
        }

        foreach ($channels as $channel) {
            if (!is_string($channel) || Debugger::normalize_channel($channel) === '') {
                $errors['channels'] = 'Every channel must be a name such as DISPATCH.';

                return $errors;
            }
        }

        if ($mode !== 'all' && !$channels) {
            $errors['channels'] = "Choose at least one channel for the {$mode} filter.";
        }

        return $errors;
    }

    /**
     * The canonical override. Throws on anything validate() refuses - the endpoint asks
     * validate() first and answers a form error; a caller reaching here with a bad value
     * is a defect.
     *
     * @param array $input
     * @return array
     */
    public static function normalize(array $input): array
    {
        $errors = static::validate($input);

        if ($errors) {
            throw new InvalidArgumentException('Invalid console_debug override: ' . json_encode($errors));
        }

        $mode = $input['filter_mode'];
        $channels = [];

        if ($mode !== 'all') {
            foreach ($input['channels'] as $channel) {
                $channels[] = Debugger::normalize_channel($channel);
            }
            $channels = array_values(array_unique($channels));
            sort($channels);
        }

        $out = [
            'enabled' => static::__bool($input['enabled'] ?? false),
            'filter_mode' => $mode,
            'channels' => $channels,
        ];

        foreach (self::FLAGS as $flag) {
            $out[$flag] = static::__bool($input[$flag] ?? false);
        }

        return $out;
    }

    /**
     * $config (the rsx.console_debug shape) with $override laid over it; $config
     * unchanged when $override is null.
     *
     * @param array $config
     * @param array|null $override A normalized override
     * @return array
     */
    public static function overlay(array $config, ?array $override): array
    {
        if ($override === null) {
            return $config;
        }

        $config['enabled'] = $override['enabled'];
        $config['filter_mode'] = $override['filter_mode'];
        $config['whitelist'] = $override['filter_mode'] === 'whitelist' ? $override['channels'] : [];
        $config['blacklist'] = $override['filter_mode'] === 'blacklist' ? $override['channels'] : [];
        $config['specific_channel'] = $override['filter_mode'] === 'specific' ? implode(',', $override['channels']) : null;

        foreach (self::FLAGS as $flag) {
            $config[$flag] = $override[$flag];
        }

        return $config;
    }

    /**
     * A form's boolean ('1'/'0', true/false, 1/0) as a bool.
     */
    private static function __bool($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
