<?php

namespace App\RSpade\Core\Theme;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Dark_Mode - the framework's theme-mode preference.
 *
 * THREE MODES, AND THE THIRD IS NOT AN ABSENCE. light and dark are explicit choices the
 * server can resolve on its own; AUTO means "follow the operating system", which is a
 * real stored preference whose ANSWER only the browser knows. That asymmetry shapes
 * everything here: an explicit mode is decided server-side and painted in the first byte
 * of HTML, while auto is carried to the client as a mode and resolved there.
 *
 * WHAT THIS CLASS OWNS, AND WHAT IT DOES NOT. It owns the preference (read, write,
 * resolve) and the CLASS NAME that expresses it. It owns no colours, no stylesheet and no
 * opinion about a UI toolkit: RSpade does not know whether an app is built on Bootstrap,
 * Tailwind or hand-written CSS. An app names its own vocabulary in
 * config('rsx.theme.dark_mode.attributes') and the framework renders it on the <body>
 * tag for it - that is the whole extension point.
 *
 * STAFF ONLY, deliberately, exactly like the timezone preference: the column lives on
 * login_users, and portal_users has no equivalent. A portal request therefore resolves to
 * the configured default and never to a person's choice. If portal accounts ever gain a
 * theme preference, THIS class is the one place that changes.
 *
 * See: php artisan rsx:man dark_mode
 */
class Rsx_Dark_Mode
{
    /**
     * Memoized resolved mode for this request, keyed by the identity it was resolved for.
     * Resolution touches the database, and the theme is asked for at least twice per
     * render (the body class, then the rsxapp payload).
     *
     * @var array<string, int>
     */
    private static array $_cache = [];

    /**
     * The user's stored mode, or the configured default when nobody expressed one.
     *
     * A portal request never reaches the user tier - portal_users carries no preference -
     * so it always answers with the default.
     */
    public static function get_mode(): int
    {
        $key = static::_cache_key();

        if (array_key_exists($key, static::$_cache)) {
            return static::$_cache[$key];
        }

        $mode = static::default_mode();

        if (!Rsx_Portal::is_portal_request()) {
            $login_user = Session::get_login_user();
            if ($login_user !== null) {
                $mode = static::normalize_mode($login_user->dark_mode);
            }
        }

        static::$_cache[$key] = $mode;

        return $mode;
    }

    /**
     * Store a mode for the signed-in identity. Returns whether the value actually moved,
     * which is what tells a caller the already-rendered page is now stale.
     */
    public static function set_mode(int $mode): bool
    {
        if (!static::is_valid_mode($mode)) {
            throw new \InvalidArgumentException(
                "Rsx_Dark_Mode::set_mode() received unknown mode '{$mode}'."
            );
        }

        if (Rsx_Portal::is_portal_request()) {
            shouldnt_happen('Rsx_Dark_Mode::set_mode() called on a portal request - portal accounts have no theme preference');
        }

        $login_user = Session::get_login_user();
        if (!$login_user) {
            shouldnt_happen('Rsx_Dark_Mode::set_mode() called with no signed-in login user');
        }

        $before = static::get_mode();

        // Explicit assignment - no mass assignment.
        $login_user->dark_mode = $mode;
        $login_user->save();

        static::$_cache = [];

        return static::get_mode() !== $before;
    }

    /**
     * The install-wide default for somebody who has expressed no preference.
     */
    public static function default_mode(): int
    {
        return static::normalize_mode(config('rsx.theme.dark_mode.default', Login_User_Model::DARK_MODE_AUTO));
    }

    /**
     * Is dark active for THIS request - true, false, or null when only the browser can
     * say (auto).
     *
     * Null is the honest answer for auto and callers must handle it: the server cannot
     * read prefers-color-scheme. Anything that renders a definite theme uses this, and
     * treats null as "let the client decide".
     */
    public static function is_dark(): ?bool
    {
        $mode = static::get_mode();

        if ($mode === Login_User_Model::DARK_MODE_AUTO) {
            return null;
        }

        return $mode === Login_User_Model::DARK_MODE_DARK;
    }

    /**
     * The classes to put on <body> for this request.
     *
     * Always includes the marker class naming the MODE, so CSS and JS can both see what
     * was asked for; includes the dark class only when dark is definitely active. Under
     * auto the dark class is absent server-side and Rsx_Dark_Mode.js adds it at boot if
     * the OS says so.
     *
     * @return string[]
     */
    public static function body_classes(): array
    {
        $classes = [static::mode_class(static::get_mode())];

        if (static::is_dark() === true) {
            $classes[] = static::dark_class();
        }

        return $classes;
    }

    /**
     * The app's OWN theme vocabulary for this request, as attribute => value.
     *
     * THE UI-AGNOSTIC SEAM. RSpade renders these on <body> and has no idea what they
     * mean; an app declares them in config('rsx.theme.dark_mode.attributes') keyed by
     * 'dark' and 'light'. A Bootstrap app declares data-bs-theme; something else declares
     * whatever it uses. Empty by default, because the framework ships no UI toolkit.
     *
     * Under AUTO this returns nothing - the answer is not known server-side, and
     * Rsx_Dark_Mode.js applies the right set at boot instead.
     *
     * @return array<string, string>
     */
    public static function body_attributes(): array
    {
        $is_dark = static::is_dark();

        if ($is_dark === null) {
            return [];
        }

        return static::attributes_for($is_dark);
    }

    /**
     * The declared attribute set for one resolved theme.
     *
     * @return array<string, string>
     */
    public static function attributes_for(bool $is_dark): array
    {
        $declared = config('rsx.theme.dark_mode.attributes', []);
        $set = $declared[$is_dark ? 'dark' : 'light'] ?? [];

        return is_array($set) ? $set : [];
    }

    /**
     * The class that means "dark is active right now".
     */
    public static function dark_class(): string
    {
        return (string) config('rsx.theme.dark_mode.dark_class', 'rsx-dark');
    }

    /**
     * The class that names which MODE was chosen (not which theme is showing).
     */
    public static function mode_class(int $mode): string
    {
        $prefix = (string) config('rsx.theme.dark_mode.mode_class_prefix', 'rsx-theme-');

        if ($mode === Login_User_Model::DARK_MODE_DARK) {
            return $prefix . 'dark';
        }

        if ($mode === Login_User_Model::DARK_MODE_LIGHT) {
            return $prefix . 'light';
        }

        return $prefix . 'auto';
    }

    /**
     * Every mode, for a settings widget. Ordered by the enum's own 'order'.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function mode_options(): array
    {
        return Login_User_Model::dark_mode__enum_select();
    }

    public static function is_valid_mode(int $mode): bool
    {
        return in_array($mode, Login_User_Model::dark_mode__enum_ids(), true);
    }

    /**
     * Coerce anything stored or configured into a mode this class will answer with.
     * An unrecognised value reads as AUTO rather than throwing: a preference column is
     * not a place to take the application down.
     */
    public static function normalize_mode($mode): int
    {
        // Guard the cast: (int) null and (int) 'nonsense' are both 0, which is a VALID
        // mode (light) - so casting first would silently turn "no value" and "broken
        // value" into an explicit light preference the user never expressed.
        if ($mode === null || $mode === '' || !is_numeric($mode)) {
            return Login_User_Model::DARK_MODE_AUTO;
        }

        $mode = (int) $mode;

        return static::is_valid_mode($mode) ? $mode : Login_User_Model::DARK_MODE_AUTO;
    }

    /**
     * Discard the memoized value. Called on write; exposed for tests that change
     * identity mid-request.
     */
    public static function _clear_cache(): void
    {
        static::$_cache = [];
    }

    /**
     * The identity this request's answer belongs to.
     */
    private static function _cache_key(): string
    {
        if (Rsx_Portal::is_portal_request()) {
            return 'portal';
        }

        return 'staff:' . (string) Session::get_login_user_id();
    }
}
