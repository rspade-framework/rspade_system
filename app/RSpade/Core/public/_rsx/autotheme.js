/**
 * autotheme.js - puts the theme on <html> before the page paints.
 *
 * Loaded by every bundle as a plain, NON-deferred <script> placed directly after the
 * inline window.rsxapp block and before every stylesheet. A synchronous script in <head>
 * runs before the browser has anything to paint, so the first frame already carries the
 * right theme - for an explicit light or dark preference and for auto alike, signed in or
 * not. That placement is the whole reason this is its own file rather than part of a
 * bundle: bundle scripts are deferred, and a deferred script runs after first paint.
 *
 * It therefore depends on nothing but window.rsxapp.theme - no jQuery, no framework
 * class - and writes to document.documentElement, the only element that exists this
 * early.
 *
 * WHAT IT WRITES ON <html>
 *   <mode_class_prefix>light|dark|auto   the MODE that was chosen
 *   <dark_class>                         present exactly while dark is showing
 *   the app's declared attributes        e.g. data-bs-theme="dark"
 *
 * AUTO FOLLOWS THE OPERATING SYSTEM FOR THE LIFE OF THE PAGE: the prefers-color-scheme
 * query's change event re-resolves, and so does the page becoming visible again or being
 * restored from the back/forward cache. An explicit mode ignores the OS.
 *
 * In-bundle code talks to this through Rsx_Dark_Mode (rsx:man dark_mode), never directly.
 */
(function () {
    'use strict';

    var MODE_LIGHT = 0;
    var MODE_DARK = 1;
    var MODE_AUTO = 2;

    var root = document.documentElement;
    var media = window.matchMedia('(prefers-color-scheme: dark)');

    function config() {
        var theme = (window.rsxapp && window.rsxapp.theme) || {};
        var mode = Number(theme.mode);

        return {
            mode: mode === MODE_LIGHT || mode === MODE_DARK ? mode : MODE_AUTO,
            dark_class: theme.dark_class || 'rsx-dark',
            mode_class_prefix: theme.mode_class_prefix || 'rsx-theme-',
            dark_attributes: theme.dark_attributes || {},
            light_attributes: theme.light_attributes || {},
        };
    }

    function is_dark() {
        var mode = config().mode;

        return mode === MODE_AUTO ? media.matches : mode === MODE_DARK;
    }

    function apply() {
        var settings = config();
        var dark = is_dark();
        var prefix = settings.mode_class_prefix;
        var name;

        root.classList.toggle(prefix + 'light', settings.mode === MODE_LIGHT);
        root.classList.toggle(prefix + 'dark', settings.mode === MODE_DARK);
        root.classList.toggle(prefix + 'auto', settings.mode === MODE_AUTO);
        root.classList.toggle(settings.dark_class, dark);

        // Swap the app's own vocabulary wholesale: drop the other theme's attributes
        // before writing this one's, so a name declared in both (data-bs-theme is) ends
        // up with the right value and a name declared in one does not linger.
        var outgoing = dark ? settings.light_attributes : settings.dark_attributes;
        var incoming = dark ? settings.dark_attributes : settings.light_attributes;

        for (name in outgoing) {
            if (Object.prototype.hasOwnProperty.call(outgoing, name) && !(name in incoming)) {
                root.removeAttribute(name);
            }
        }

        for (name in incoming) {
            if (Object.prototype.hasOwnProperty.call(incoming, name)) {
                root.setAttribute(name, incoming[name]);
            }
        }
    }

    function set_mode(mode) {
        window.rsxapp.theme.mode = mode;
        apply();
    }

    window.rsx_autotheme = {
        MODE_LIGHT: MODE_LIGHT,
        MODE_DARK: MODE_DARK,
        MODE_AUTO: MODE_AUTO,
        mode: function () { return config().mode; },
        is_dark: is_dark,
        apply: apply,
        set_mode: set_mode,
    };

    apply();

    media.addEventListener('change', apply);
    window.addEventListener('pageshow', apply);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            apply();
        }
    });
})();
