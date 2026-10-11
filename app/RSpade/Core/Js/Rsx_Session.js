/**
 * Rsx_Session - the browser's side of the session lifetime.
 *
 * Two jobs:
 *
 * REPORTING PAGE VIEWS. A sign-in goes idle when nobody LOOKS AT A PAGE for
 * rsx.sessions.*.max_idle_minutes. A server-rendered page records its own view; an SPA
 * navigation never reaches a page route, so it is reported here - after every completed
 * dispatch, at most once a minute. The page load that booted this tab already counted,
 * so the first report waits a minute from boot.
 *
 * ANNOUNCING A LOST SESSION. When the server says the sign-in is gone - the
 * session_expired envelope, or auth_required on a page that was rendered signed in (the
 * session was terminated elsewhere) - the page on screen is a signed-in page nobody is
 * signed in to. That is announced exactly once, to the handler the application
 * registered with Rsx.set_session_expired_handler(); with none registered the page
 * reloads, and the server sends the browser to the sign-in screen.
 */
class Rsx_Session {

    /**
     * Page views are reported at most this often. Matches the server's own stamp
     * interval, so a report sent sooner would write nothing anyway.
     */
    static PAGE_VIEW_INTERVAL_MS = 60000;

    static _last_page_view_ms = 0;

    static _expired_announced = false;

    static _on_framework_core_init() {
        Rsx_Session._last_page_view_ms = Date.now();

        Rsx.on('spa_dispatch_ready', function () {
            Rsx_Session._report_page_view();
        });
    }

    /**
     * Tell the server somebody navigated, unless it was told within the last minute or
     * this page was rendered for nobody.
     */
    static _report_page_view() {
        if (!window.rsxapp.is_auth || Rsx_Session._expired_announced) {
            return;
        }

        const now = Date.now();

        if (now - Rsx_Session._last_page_view_ms < Rsx_Session.PAGE_VIEW_INTERVAL_MS) {
            return;
        }

        Rsx_Session._last_page_view_ms = now;

        // A refusal has already been announced by Ajax (the session_expired envelope);
        // anything else is not this navigation's problem to surface.
        Rsx_Session_Controller.page_view().catch(function () {});
    }

    /**
     * The server reported that this page's sign-in is gone. Announce it once: to the
     * application's handler, or by reloading.
     *
     * @param {string} message What the server said, for the handler to show
     */
    static _announce_expired(message) {
        if (Rsx_Session._expired_announced || !window.rsxapp.is_auth) {
            return;
        }

        Rsx_Session._expired_announced = true;

        const handler = Rsx._session_expired_handler;

        if (handler) {
            handler({ message: message });

            return;
        }

        window.location.reload();
    }
}
