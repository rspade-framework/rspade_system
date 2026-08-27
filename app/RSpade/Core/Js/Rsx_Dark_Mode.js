/**
 * Rsx_Dark_Mode - the client half of the theme preference.
 *
 * THE SERVER DOES THE WORK WHENEVER IT CAN. An explicit light or dark preference is
 * resolved in PHP and painted onto <body> in the first bytes of HTML, so this class has
 * nothing to do for it - there is no flash to fix because the page was never the wrong
 * colour. That is the whole reason the theme lives on the body tag rather than being
 * applied by script.
 *
 * WHAT IS LEFT IS AUTO, and only auto. "Follow the operating system" is a question no
 * server can answer: prefers-color-scheme exists only in the browser. So under auto the
 * server emits the mode class and no theme, and this applies the resolved answer at boot
 * and keeps following it - a user who flips their OS appearance mid-session sees the app
 * change with it, without a reload.
 *
 * It applies exactly what the server WOULD have applied: the class and the app-declared
 * attributes both arrive in window.rsxapp.theme, so there is one vocabulary and this
 * never has to guess at the app's UI toolkit.
 *
 * A page whose mode is light or dark is left strictly alone - no listener, nothing to
 * fight the explicit choice the user made.
 *
 * See `rsx:man dark_mode`.
 */
class Rsx_Dark_Mode {

    /**
     * Mirror of Login_User_Model::DARK_MODE_* - keep in sync.
     */
    static MODE_LIGHT = 0;
    static MODE_DARK = 1;
    static MODE_AUTO = 2;

    /**
     * The live OS query, kept so the listener can be removed in a test.
     */
    static _media = null;

    /**
     * The theme block the server shipped, with safe defaults for a page that predates it.
     */
    static config() {
        const theme = (window.rsxapp && window.rsxapp.theme) || {};

        return {
            mode: int(theme.mode ?? Rsx_Dark_Mode.MODE_AUTO),
            is_dark: theme.is_dark ?? null,
            dark_class: str(theme.dark_class || 'rsx-dark'),
            dark_attributes: theme.dark_attributes || {},
            light_attributes: theme.light_attributes || {},
        };
    }

    /**
     * The mode this page is running in.
     */
    static mode() {
        return Rsx_Dark_Mode.config().mode;
    }

    /**
     * Is dark showing right now? Under auto this asks the OS, so it is always a real
     * answer - unlike the server's is_dark, which is null there.
     */
    static is_dark() {
        const config = Rsx_Dark_Mode.config();

        if (config.mode === Rsx_Dark_Mode.MODE_AUTO) {
            return Rsx_Dark_Mode._os_prefers_dark();
        }

        return config.mode === Rsx_Dark_Mode.MODE_DARK;
    }

    /**
     * Boot: resolve auto, then keep following the OS for the life of the page.
     *
     * Explicit modes return immediately - the server already painted them, and attaching
     * a listener would let the OS override a choice the user deliberately made.
     */
    static _on_framework_core_init() {
        if (Rsx.is_ssr()) {
            return;
        }

        if (Rsx_Dark_Mode.mode() !== Rsx_Dark_Mode.MODE_AUTO) {
            return;
        }

        Rsx_Dark_Mode._media = window.matchMedia('(prefers-color-scheme: dark)');
        Rsx_Dark_Mode.apply(Rsx_Dark_Mode._media.matches);

        Rsx_Dark_Mode._media.addEventListener('change', function (event) {
            Rsx_Dark_Mode.apply(event.matches);
        });
    }

    /**
     * Put the given theme on <body>: the framework's class, plus whatever attributes the
     * app declared for it. Idempotent, so it is safe to call on every OS change.
     */
    static apply(is_dark) {
        const config = Rsx_Dark_Mode.config();
        const $body = $('body');

        $body.toggleClass(config.dark_class, !!is_dark);

        // Swap the app's own vocabulary wholesale: remove the other theme's attributes
        // before adding this one's, so a name declared in both (data-bs-theme is) ends up
        // with the right value rather than whichever was written last.
        const outgoing = is_dark ? config.light_attributes : config.dark_attributes;
        const incoming = is_dark ? config.dark_attributes : config.light_attributes;

        foreach(outgoing, function (value, name) {
            if (!(name in incoming)) {
                $body.removeAttr(name);
            }
        });

        foreach(incoming, function (value, name) {
            $body.attr(name, value);
        });
    }

    /**
     * Does the operating system currently ask for dark?
     */
    static _os_prefers_dark() {
        return window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
}
