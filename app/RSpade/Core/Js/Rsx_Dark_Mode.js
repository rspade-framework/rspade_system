/**
 * Rsx_Dark_Mode - the in-bundle face of the theme.
 *
 * The theme itself is applied by autotheme.js: a small synchronous script every bundle
 * loads in <head> ahead of its stylesheets, so the class and attributes are on <html>
 * before the first paint and stay in step with the operating system under auto. That
 * script cannot be part of a bundle (bundle scripts are deferred, and run after paint),
 * so it publishes window.rsx_autotheme and this class is how framework and application
 * code reach it.
 *
 * mode()      the preference this page is running under
 * is_dark()   whether dark is showing right now - a real answer under auto too
 * set_mode()  re-theme the page in place, after the preference was saved
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
     * The mode this page is running in.
     */
    static mode() {
        return window.rsx_autotheme.mode();
    }

    /**
     * Is dark showing right now? Under auto this asks the OS.
     */
    static is_dark() {
        return window.rsx_autotheme.is_dark();
    }

    /**
     * Switch the page on screen to another mode. Call it with the mode the server
     * confirmed after Rsx_Dark_Mode_Controller.set_dark_mode(): the theme changes at
     * once, with no reload, and auto resumes following the OS.
     */
    static set_mode(mode) {
        mode = int(mode);

        if (![Rsx_Dark_Mode.MODE_LIGHT, Rsx_Dark_Mode.MODE_DARK, Rsx_Dark_Mode.MODE_AUTO].includes(mode)) {
            shouldnt_happen(`Rsx_Dark_Mode.set_mode() received unknown mode '${mode}'`);
        }

        window.rsx_autotheme.set_mode(mode);
    }
}
