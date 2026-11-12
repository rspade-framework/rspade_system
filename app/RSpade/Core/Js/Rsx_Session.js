/**
 * Rsx_Session - the browser's side of the session lifetime.
 *
 * Two jobs:
 *
 * REPORTING PAGE VIEWS. A sign-in goes idle when nobody USES A PAGE for
 * rsx.sessions.*.max_idle_minutes. A server-rendered page records its own view. Inside a
 * page the browser reports activity itself through report_page_view(), at most once a
 * minute, from three places:
 *   - every completed SPA dispatch;
 *   - a URL-hash write through Rsx.url_hash_set() / url_hash_set_single() that CHANGES the
 *     hash while the browser holds a fresh user activation (a click or a key press just
 *     now) - a sub-tab, a grid's page, sort or filter; a hash rewritten by code on its own
 *     (a grid settling its state after a load or a realtime refresh) reports nothing;
 *   - the application, wherever its UI acts on a person's input.
 * The page load that booted this tab already counted, so the first report waits a minute
 * from boot. Ajax and API calls never count: a tab left open on a polling screen must not
 * hold a sign-in open.
 *
 * ANNOUNCING A LOST SESSION. When the server says the sign-in is gone - the
 * session_expired envelope, or auth_required on a page that was rendered signed in (the
 * session was terminated elsewhere) - the page on screen is a signed-in page nobody is
 * signed in to. That is announced exactly once, to the handler the application
 * registered with Rsx.set_session_expired_handler(); with none registered the page
 * reloads, and the server sends the browser to the sign-in screen.
 *
 * See rsx:man session, HOW SESSIONS TIME OUT.
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
            Rsx_Session.report_page_view();
        });
    }

    /**
     * Tell the server a person did something on this page: the sign-in's idle clock
     * (rsx.sessions.*.max_idle_minutes) restarts, exactly as for a page view. Staff and
     * portal alike - the request's own experience decides which sign-in is stamped.
     *
     * Call it where your UI acts on a person's input - a dialog opened or closed, an
     * approval, an inline save. NEVER from a timer, a poll or a realtime handler: those
     * run with nobody at the screen, and would hold an abandoned sign-in open forever.
     *
     * Cheap to call often: at most one report a minute per tab, and none within a minute of
     * the page load that booted it. Nothing is sent while nobody is signed in on this page
     * or after its sign-in was announced as gone. The server judges the report like a page
     * navigation - a sign-in already past its limits is ended there, with no Ajax grace,
     * and the session_expired envelope is announced as for any call.
     */
    static report_page_view() {
        if (!window.rsxapp.is_auth || Rsx_Session._expired_announced) {
            return;
        }

        const now = Date.now();

        if (now - Rsx_Session._last_page_view_ms < Rsx_Session.PAGE_VIEW_INTERVAL_MS) {
            return;
        }

        Rsx_Session._last_page_view_ms = now;

        // A refusal has already been announced by Ajax (the session_expired envelope);
        // anything else is not this report's problem to surface.
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
