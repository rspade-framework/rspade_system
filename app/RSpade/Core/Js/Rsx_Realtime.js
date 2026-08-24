/**
 * Rsx_Realtime
 *
 * Client-side manager for the WebSocket realtime notification system.
 *
 * Connects lazily (only once something is actually being watched), handles
 * authentication, ref-counts subscriptions so N callers watching the same
 * topic+filter share ONE server-side subscription, resyncs every watcher on
 * (re)connect instead of trying to replay a message log, and auto-reconnects
 * on disconnect with exponential backoff.
 *
 * Usage in components (unchanged from before — auto-unsubscribes on stop):
 *     this.subscribe('Contact_Updated_Topic', {id: this.args.id}, (data, meta) => {
 *         this.reload();
 *     });
 *
 * Usage outside components:
 *     const watcher = await Rsx_Realtime.watch('Notification_Topic', (data, meta) => {
 *         show_notification(data);
 *     });
 *     // later:
 *     watcher.stop();
 *
 * Connection state:
 *     Rsx_Realtime.on_state_change((state) => {
 *         // state: 'connecting' | 'connected' | 'disconnected' | 'reconnecting'
 *     });
 *
 * Connection model, in one place:
 *   - ONE socket per tab. Connecting is single-flight (_connect), and a socket in
 *     CLOSING/CLOSED is treated as ABSENT, never as a connection to reuse.
 *   - Outgoing messages are BATCHED: every client -> relay message is queued and
 *     flushed as one array frame (_send/_flush_outbox). The relay accepts nothing
 *     else. A message queued while the link is down is DROPPED, never replayed —
 *     auth_ok resync is the one and only catch-up mechanism.
 *   - Losing the link is announced to listeners on a DELAY (OFFLINE_ANNOUNCE_GRACE_MS)
 *     so a blip never flashes an offline state at the app; internal state is updated
 *     immediately regardless.
 *   - A large wall-clock gap (laptop resume) or an unanswered liveness ping forces a
 *     disconnect/reconnect, because resync is the only correct recovery from a link
 *     that may have missed messages or died without telling the browser.
 */
class Rsx_Realtime {

    static _ws = null;

    // Diagnostic: how many WebSocket objects this tab has constructed since page load.
    // One tab holding one live connection is the invariant (see _connect); a test or a dev
    // page reads this to prove it rather than inferring it from relay-side counts.
    static _sockets_opened = 0;

    // The in-flight connect attempt, or null. See _connect() — this is what makes N
    // same-tick subscribers share ONE socket.
    static _connect_promise = null;

    // canonical_key -> { topic, filter, token, callbacks: Set<Function> }
    static _watches = new Map();

    static _connected = false;
    static _authenticated = false;
    static _reconnect_timer = null;
    static _reconnect_delay = 1000;
    static _max_reconnect_delay = 30000;

    // Diagnostic: how many BATCH frames this tab has put on the wire since page load.
    // The batching invariant (N same-tick messages -> one frame) is proven by reading
    // this, not by inferring it.
    static _frames_sent = 0;

    // System anchor: when realtime is enabled the connection is established at app boot
    // and HELD for the page lifetime (independent of app watches), so a page with zero
    // subscriptions still receives targeted refresh pushes. Counts as a connection-level
    // hold that suppresses idle-disconnect — NOT a topic subscription (a refresh is a
    // control frame the relay routes by connection stamps).
    static _anchored = false;

    // Refresh-push reload timing (owner-specified, Brian 2026-07-24 follow-up): a targeted
    // refresh must not yank the page mid-interaction on the tab that triggered the change.
    static REFRESH_RELOAD_FLOOR_MS = 500;    // never reload sooner than this after a frame
    static REFRESH_IDLE_WINDOW_MS = 5000;    // require this long with no user activity first
    static REFRESH_NAV_WATCHDOG_MS = 10000;  // clear a stuck 'navigating' flag after this long

    // Stale-reconnect reload. A tab that was offline/suspended for a long time is running
    // whatever JS bundle it loaded — a deploy during the gap leaves it on dead code, which
    // no amount of data resync repairs. Threshold comes from
    // window.rsxapp.realtime.stale_reload_after_ms (config, not env); <= 0 disables.
    static STALE_RELOAD_DEFAULT_MS = 3600000;   // 60 minutes
    // Spread a mass reconnect's reloads over this window. The existing reload gates do NOT
    // de-synchronize a herd (idle tabs all collapse to now+FLOOR, and reconnect backoff
    // re-synchronizes them at a relay restart), so without jitter every tab in the fleet
    // would reload within the same second.
    static STALE_RELOAD_JITTER_MS = 10000;

    // Wall-clock drift detector. A slept laptop cannot be measured by the socket: onclose
    // fires on RESUME (so the observed disconnect is ~0), and browser JS cannot see the
    // WebSocket pings (the browser auto-pongs below JS). A plain interval that observes a
    // jump much larger than its own cadence DID witness the suspend.
    static DRIFT_TICK_MS = 60000;
    static _last_tick_ts = 0;
    static _suspend_gap_ms = 0;              // largest unconsumed observed freeze
    static _drift_timer = null;
    static _disconnected_since = 0;          // ms timestamp of the last onclose (0 = none)

    // Refresh-push reload state. Dedupe: at most one pending reload; frames arriving while a
    // reload is pending are ignored, but the pending reload keeps re-deferring on user activity.
    static _reload_pending = false;
    static _reload_earliest_ts = 0;          // now + FLOOR at the moment the frame arrived
    static _reload_timer = null;             // the single re-evaluating check timer
    static _last_activity_ts = 0;            // last mousemove/keydown (ms)
    static _navigating = false;              // set on beforeunload, cleared on pageshow/watchdog
    static _navigating_since = 0;
    static _refresh_listeners_installed = false;

    // How long to wait, after the last watch stops, before actually closing the
    // socket (re-checked at fire time, so a new watch() in the meantime cancels it).
    //
    // 10s, not a couple of seconds: an SPA navigation DRAINS every subscription of the
    // outgoing page and establishes the next page's a moment later, so a short window
    // turns an ordinary navigate into a close + token round trip + handshake + full
    // resync for nothing. Holding an idle socket open costs the relay one map entry;
    // closing it too eagerly costs a reconnect on every navigation. (Owner, 2026-08-24.)
    static _idle_disconnect_delay = 10000;

    // The pending idle-disconnect timer, or null. One at a time — the fire-time re-check
    // is what cancels it logically, so stacking timers only wastes them.
    static _idle_timer = null;

    // Grace window between LOSING the link and TELLING the app about it. Internal state
    // (_ws / _connected / _authenticated) is updated the instant the socket closes; this
    // bounds only the listener-facing 'disconnected' announcement, so a 300ms blip — a
    // relay restart, a wifi handoff — never flashes an offline banner at the user. A
    // reconnect inside the window means the app never hears 'disconnected' at all.
    //
    // 5s because that is the shipped Realtime_Status_Badge's own GRACE_MS: shorter and no
    // consumer has reacted yet anyway, longer and a genuine outage stays hidden from the
    // user past the point they would notice the page has gone quiet.
    static OFFLINE_ANNOUNCE_GRACE_MS = 5000;
    static _offline_timer = null;
    static _offline_announced = false;

    // Outgoing batch queue. Every client -> relay message goes through _send(); the wire
    // format is an ARRAY of messages and the relay accepts nothing else.
    static _outbox = [];
    static _outbox_timer = null;

    // Coalescing window for the outbox. Fixed from the FIRST enqueue and never reset by a
    // later one, so a message waits at most this long however long the burst runs (a
    // resetting debounce — the stdlib debounce() included — can defer a frame indefinitely
    // under a steady trickle, which is why this is a plain one-shot timer instead).
    // 50ms because what is being coalesced is the replies to a burst of subscribe-token
    // Ajax calls issued in a single tick, which land within a few tens of ms of each other;
    // a lone subscribe pays at most 50ms on top of the token round trip it already waited
    // for, which is invisible against that round trip.
    static OUTBOX_FLUSH_MS = 50;

    // Hard cap on how many messages ride ONE frame. THE RELAY ENFORCES THE SAME LIMIT and
    // refuses (and closes) a longer frame naming this number, so the two ends can never
    // drift apart quietly — a skew is a loud, diagnosable disconnect by design.
    //
    // The cost being bounded is the RELAY'S EVENT-LOOP WORK PER FRAME, not frame size: every
    // element costs an HMAC signature verification, so the cap answers "how many verifications
    // may one frame demand before it is another connection's turn". (Size is a non-issue —
    // a subscribe measures ~280 bytes typical and ~800 worst case with a large filter in the
    // token, so 25 is 7-20KB, nowhere near any WebSocket constraint.) 25 also carries a
    // realistic screen of 10-40 subscribing components in one or two frames, which is the
    // efficiency batching exists for. A longer burst is SPLIT across frames, in order, never
    // truncated. (Owner, 2026-08-24.)
    static MAX_MESSAGES_PER_FRAME = 25;

    // Application-level liveness. Piggybacks the drift timer's existing cadence — no new
    // timer and no new interval constant. See _liveness_tick().
    static _last_inbound_ts = 0;
    static _liveness_ping_ts = 0;

    // Trailing-debounce window for LIVE message dispatch (per watch entry). A burst
    // of publishes within this window collapses to ONE callback invocation carrying
    // the last message's data — safe because callbacks are idempotent refetches by
    // contract. Resync dispatch is NOT debounced (fires immediately).
    static _live_dispatch_debounce_ms = 250;

    static _state = 'disconnected';
    static _state_listeners = new Set();

    /**
     * Boot: always patch Component.prototype.subscribe (safe no-op when realtime
     * is disabled). Does NOT connect — connection is lazy, established on first
     * watch()/subscribe() call.
     */
    static _on_framework_modules_init() {
        Rsx_Realtime._patch_component();
        Rsx_Realtime._install_refresh_listeners();
        Rsx_Realtime._ensure_anchor();
    }

    /**
     * Install (once) the passive activity + navigation listeners the refresh reload gate reads.
     * Done at boot so _last_activity_ts reflects real user activity by the time any refresh
     * frame arrives (installing lazily on first frame would mis-read "now" as the last action).
     * No-op when realtime is disabled or in SSR.
     */
    static _install_refresh_listeners() {
        if (Rsx_Realtime._refresh_listeners_installed) return;
        if (!window.rsxapp?.realtime_url) return;
        if (Rsx.is_ssr()) return;
        if (typeof window === 'undefined' || typeof document === 'undefined') return;
        Rsx_Realtime._refresh_listeners_installed = true;

        Rsx_Realtime._last_activity_ts = Date.now();

        // touchstart/scroll are REQUIRED, not extras: with mousemove+keydown only, a touch
        // device never marks activity at all (its timestamp stays frozen at boot), so it
        // reads as permanently idle and a reload can yank a phone user mid-scroll.
        const mark_activity = () => { Rsx_Realtime._last_activity_ts = Date.now(); };
        window.addEventListener('mousemove', mark_activity, { passive: true });
        window.addEventListener('keydown', mark_activity, { passive: true });
        window.addEventListener('touchstart', mark_activity, { passive: true });
        window.addEventListener('scroll', mark_activity, { passive: true });
        window.addEventListener('click', mark_activity, { passive: true });

        // Wall-clock drift detector (see DRIFT_TICK_MS). Installed here, at the one
        // boot-time, realtime-gated, install-once site.
        Rsx_Realtime._last_tick_ts = Date.now();
        Rsx_Realtime._drift_timer = setInterval(() => Rsx_Realtime._drift_tick(), Rsx_Realtime.DRIFT_TICK_MS);

        // beforeunload fires when the browser commits to a full navigation (link click, form
        // submit, location assignment) — the practical best signal that this tab is leaving.
        window.addEventListener('beforeunload', () => {
            Rsx_Realtime._navigating = true;
            Rsx_Realtime._navigating_since = Date.now();
        });
        // A bfcache restore / aborted navigation brings the page back — it is live again.
        window.addEventListener('pageshow', () => {
            Rsx_Realtime._navigating = false;
        });
    }

    /**
     * One tick of the wall-clock drift detector. An elapsed interval much larger than the
     * tick cadence means the machine (or the tab) was frozen for that long — the only
     * observation a browser gets of a laptop suspend. Records the LARGEST unconsumed gap
     * rather than a running sum, so a string of small scheduling hiccups can never add up
     * into a false "this tab was asleep for an hour". Consumed (and cleared) by the
     * staleness check on the next successful (re)authentication.
     */
    static _drift_tick() {
        const now = Date.now();
        const elapsed = now - Rsx_Realtime._last_tick_ts;
        Rsx_Realtime._last_tick_ts = now;

        if (elapsed > Rsx_Realtime.DRIFT_TICK_MS * 2) {
            Rsx_Realtime._suspend_gap_ms = Math.max(Rsx_Realtime._suspend_gap_ms, elapsed);

            // A gap this size means the machine (or the tab) was frozen, and a socket that
            // came back from one is not to be trusted: the browser routinely still reports
            // OPEN for a half-open TCP connection the network dropped while we slept, and
            // anything published during the gap is GONE (the relay keeps no log). Force the
            // link down and reconnect — auth_ok resync (fresh token, resubscribe, refetch
            // for every watch) is the only correct recovery. Explicitly NOT an intentional
            // close: this must reconnect.
            if (Rsx_Realtime._has_live_socket()) {
                console_debug('Realtime', 'Observed a ' + Math.round(elapsed / 1000) +
                    's wall-clock gap while connected — forcing a reconnect to resync');
                Rsx_Realtime._force_reconnect();
            }
        }

        Rsx_Realtime._liveness_tick(now);
    }

    /**
     * Application-level liveness probe, run on the drift timer's existing cadence.
     *
     * The relay pings and reaps a client that stops answering, but that is the SERVER's
     * view of the link. The browser answers protocol pings below JS, so a tab whose path
     * has died silently (NAT drop, wifi handoff, a router that went away while the machine
     * stayed awake) keeps a socket reporting OPEN forever and simply receives nothing —
     * indistinguishable, from JS, from a quiet connection. The drift detector covers the
     * SLEPT machine; this covers the awake one.
     *
     * A tick that has seen no inbound frame for a full cadence sends one ping. If the next
     * tick still shows nothing inbound since that ping went out, the link is dead however
     * it looks, and the same forced reconnect + resync recovers it. Detection costs at most
     * two ticks, and a link carrying any traffic at all is never probed.
     */
    static _liveness_tick(now) {
        if (!Rsx_Realtime._is_ready_to_send()) {
            Rsx_Realtime._liveness_ping_ts = 0;
            return;
        }

        if (Rsx_Realtime._liveness_ping_ts) {
            if (Rsx_Realtime._last_inbound_ts < Rsx_Realtime._liveness_ping_ts) {
                console_debug('Realtime', 'Liveness ping went unanswered — forcing a reconnect');
                Rsx_Realtime._liveness_ping_ts = 0;
                Rsx_Realtime._force_reconnect();
                return;
            }
            Rsx_Realtime._liveness_ping_ts = 0;
        }

        if (now - Rsx_Realtime._last_inbound_ts < Rsx_Realtime.DRIFT_TICK_MS) return;

        Rsx_Realtime._liveness_ping_ts = now;
        Rsx_Realtime._send({ type: 'ping' });
    }

    /**
     * How long a tab may be offline/suspended before a reconnect forces a full reload.
     * From config (window.rsxapp.realtime.stale_reload_after_ms); <= 0 disables the
     * behavior entirely. Absent (realtime block not injected) uses the built-in default.
     */
    static _stale_reload_threshold_ms() {
        const configured = window.rsxapp?.realtime?.stale_reload_after_ms;
        return typeof configured === 'number' ? configured : Rsx_Realtime.STALE_RELOAD_DEFAULT_MS;
    }

    /**
     * Decide, on a successful (re)authentication, whether this tab has been out of touch
     * long enough that its CODE (not just its data) must be considered stale — a deploy
     * during the gap leaves the tab running a bundle that no resync can repair. Routes into
     * the ONE existing reload path (_handle_refresh), so dedupe, the 500ms floor, the
     * user-idle gate, navigating-away suppression, the watchdog and the _do_reload seam are
     * all reused — there is no second reload path.
     *
     * Staleness = max(socket downtime, observed suspend gap): a slept laptop reports ~0
     * downtime (onclose fires on resume) but a large drift gap; a genuine network outage on
     * a machine that stayed awake reports the reverse.
     */
    static _check_stale_reconnect() {
        const now = Date.now();
        const disconnected_ms = Rsx_Realtime._disconnected_since
            ? now - Rsx_Realtime._disconnected_since
            : 0;
        const gap_ms = Rsx_Realtime._suspend_gap_ms;

        Rsx_Realtime._disconnected_since = 0;
        Rsx_Realtime._suspend_gap_ms = 0;

        const threshold = Rsx_Realtime._stale_reload_threshold_ms();
        if (threshold <= 0) return;

        const stale_ms = Math.max(disconnected_ms, gap_ms);
        if (stale_ms <= threshold) return;

        console_debug('Realtime', 'Reconnected after ' + Math.round(stale_ms / 1000) +
            's out of touch (threshold ' + Math.round(threshold / 1000) + 's) — scheduling a stale-code reload');

        // Jitter: a relay restart reconnects the whole fleet at once and the reload gates do
        // not de-synchronize them.
        Rsx_Realtime._handle_refresh(Math.random() * Rsx_Realtime.STALE_RELOAD_JITTER_MS);
    }

    /**
     * Handle a targeted refresh push from the relay. Schedules ONE re-evaluating reload,
     * deduped: while a reload is pending, further frames are ignored (the pending reload keeps
     * re-deferring on activity). The reload fires only after max(now + FLOOR + extra_delay_ms,
     * last_activity + IDLE_WINDOW) AND while the page is not navigating away (see
     * _evaluate_reload).
     *
     * @param {number} [extra_delay_ms] Additional delay folded into the floor. Used ONLY by
     *   the stale-reconnect caller for herd jitter; a targeted session/user refresh push has
     *   a small fan-out and keeps 0 (the owner-specified 500ms floor untouched).
     */
    static _handle_refresh(extra_delay_ms = 0) {
        Rsx_Realtime._install_refresh_listeners();

        if (Rsx_Realtime._reload_pending) return;
        Rsx_Realtime._reload_pending = true;
        Rsx_Realtime._reload_earliest_ts = Date.now()
            + Rsx_Realtime.REFRESH_RELOAD_FLOOR_MS
            + Math.max(0, extra_delay_ms);

        console_debug('Realtime', 'Reload pending (>=500ms floor + ' + Math.round(Math.max(0, extra_delay_ms)) +
            'ms extra, >=5s user-idle, suppressed while navigating)');
        Rsx_Realtime._arm_reload_check(0);
    }

    /**
     * (Re)arm the single reload-check timer. One timer at a time; it recomputes the due time
     * each firing, so activity that extends the idle window is picked up on the next tick
     * without having to re-arm on every mousemove.
     */
    static _arm_reload_check(delay_ms) {
        if (Rsx_Realtime._reload_timer) {
            clearTimeout(Rsx_Realtime._reload_timer);
        }
        Rsx_Realtime._reload_timer = setTimeout(() => {
            Rsx_Realtime._reload_timer = null;
            Rsx_Realtime._evaluate_reload();
        }, Math.max(0, delay_ms));
    }

    /**
     * Decide whether the pending reload may fire now, re-deferring otherwise. Fires only when
     * BOTH the FLOOR and a full IDLE_WINDOW since the last user action have elapsed, and the
     * page is not navigating away. A due-but-navigating reload STAYS pending and re-checks (it
     * is skipped, never cancelled). The navigation watchdog clears a stuck flag: because this
     * timer firing proves the page's timers are alive, a flag set for >= NAV_WATCHDOG_MS is
     * stale and cleared here (pageshow clears an aborted-navigation flag sooner).
     */
    static _evaluate_reload() {
        if (!Rsx_Realtime._reload_pending) return;

        const now = Date.now();

        if (Rsx_Realtime._navigating
            && now - Rsx_Realtime._navigating_since >= Rsx_Realtime.REFRESH_NAV_WATCHDOG_MS) {
            Rsx_Realtime._navigating = false;
        }

        const due_ts = Math.max(
            Rsx_Realtime._reload_earliest_ts,
            Rsx_Realtime._last_activity_ts + Rsx_Realtime.REFRESH_IDLE_WINDOW_MS
        );

        if (now < due_ts) {
            Rsx_Realtime._arm_reload_check(due_ts - now);
            return;
        }

        // Due, but the browser is navigating away — skip (stay pending) and re-check; the
        // watchdog above (or a pageshow) will eventually clear a flag that never unloads.
        if (Rsx_Realtime._navigating) {
            Rsx_Realtime._arm_reload_check(Rsx_Realtime.REFRESH_RELOAD_FLOOR_MS);
            return;
        }

        console_debug('Realtime', 'Refresh reload firing');
        Rsx_Realtime._do_reload();
    }

    /**
     * The actual full-document reload. A one-line seam so behavior tests can observe "would
     * reload" without navigating the harness away.
     */
    static _do_reload() {
        window.location.reload();
    }

    /**
     * Establish the always-on system anchor connection (once) so a bundle page — staff SPA,
     * portal, or a blade page — holds a live connection able to receive targeted refresh
     * pushes even with no app subscriptions. GATED on window.rsxapp.session_hash being a
     * non-empty string (owner ruling): a page rendered with no session cookie (a first-visit
     * anonymous page, session_hash null) must NOT eagerly connect — it keeps the ordinary
     * lazy connect-on-first-watch() behavior, so no connection/session is created for a
     * visitor who has none. No-op when realtime is disabled or in SSR. Idempotent.
     */
    static _ensure_anchor() {
        if (!window.rsxapp?.realtime_url) return;
        if (typeof window.rsxapp.session_hash !== 'string' || window.rsxapp.session_hash === '') return;
        if (Rsx.is_ssr()) return;
        if (Rsx_Realtime._anchored) return;

        Rsx_Realtime._anchored = true;
        Rsx_Realtime._connect();
    }


    // =========================================================================
    // Connection state
    // =========================================================================

    /**
     * Register a callback for connection state changes. Invoked immediately with
     * the current state, then again on every transition.
     *
     * @param {Function} callback - (state) => void
     * @returns {Function} Call to stop listening.
     */
    static on_state_change(callback) {
        Rsx_Realtime._state_listeners.add(callback);
        try {
            callback(Rsx_Realtime._state);
        } catch (e) {
            console.error('[Realtime] on_state_change callback error:', e);
        }
        return () => Rsx_Realtime._state_listeners.delete(callback);
    }

    /**
     * Announce a state to LISTENERS. This is a projection for the app, never the class's
     * own source of truth: nothing here reads _state to decide behavior (_ws, _connected,
     * _authenticated and _watches are the truth, and they are updated the instant anything
     * happens). That separation is what lets the offline announcement be delayed without
     * the class ever lying to itself — see _announce_offline_after_grace().
     */
    static _set_state(state) {
        if (Rsx_Realtime._state === state) return;
        Rsx_Realtime._state = state;
        for (const listener of Rsx_Realtime._state_listeners) {
            try {
                listener(state);
            } catch (e) {
                console.error('[Realtime] on_state_change callback error:', e);
            }
        }
    }

    // =========================================================================
    // Watching (public API)
    // =========================================================================

    /**
     * Watch a topic with an optional filter. Multiple watchers of the identical
     * topic+filter share ONE underlying subscription (ref-counted) — only the
     * first caller triggers a token request and a server-side subscribe; the
     * rest just register their callback locally.
     *
     * The callback fires on every live message AND once immediately whenever the
     * subscription is (re)established — including after a reconnect — so app
     * code should treat every call as "something may have changed, resync,"
     * not as a delta to apply. This replaces server-side message replay: a
     * (re)subscribe always re-syncs rather than trying to catch you up on what
     * you missed.
     *
     * @param {string} topic - Topic class name (e.g., 'Contact_Updated_Topic')
     * @param {Object|Function} filter - Filter object, or the callback if no filter
     * @param {Function} [callback] - (data, meta) => void. data is null on a resync
     *   call (meta.resync === true), or the message payload on a live message
     *   (meta.resync === false). meta also carries {topic, filter}.
     * @returns {Promise<{stop: Function}>} Handle — call .stop() to stop watching.
     */
    static async watch(topic, filter, callback) {
        if (typeof filter === 'function') {
            callback = filter;
            filter = {};
        }
        filter = filter || {};

        // Disabled (or realtime_url not injected): safe no-op, no network touched. A
        // resolved `established` keeps disabled-mode gated loads from ever waiting.
        if (!window.rsxapp?.realtime_url) {
            return { stop() {}, established: Promise.resolve() };
        }

        const key = Rsx_Realtime._canonical_key(topic, filter);

        let entry = Rsx_Realtime._watches.get(key);
        const is_new = !entry;
        if (is_new) {
            entry = { topic, filter, token: null, callbacks: new Set() };
            // `established` resolves on this entry's first `{type:'subscribed'}` ack,
            // rejects if the subscription is finally dropped (token failure), and
            // resolves on full release. The component auto-gate races it against a
            // timeout. A no-op .catch() keeps an UNCONSUMED rejection (a non-gated
            // subscription that never reads .established) from surfacing as an unhandled
            // promise rejection — gated consumers attach their own rejection handler, so
            // this does not swallow anything they rely on.
            entry.established = new Promise((resolve, reject) => {
                entry._established_resolve = resolve;
                entry._established_reject = reject;
            });
            entry.established.catch(() => {});
            Rsx_Realtime._watches.set(key, entry);
        }
        entry.callbacks.add(callback);

        if (is_new) {
            Rsx_Realtime._connect();
            await Rsx_Realtime._sync_watch(key);
        }

        let stopped = false;
        return {
            // Nth watcher of the same entry shares the SAME established promise (already
            // resolved once the subscription is live = resolves immediately — correct,
            // the subscription IS established).
            established: entry.established,
            stop() {
                if (stopped) return;
                stopped = true;
                Rsx_Realtime._release_watch(key, callback);
            },
        };
    }

    /**
     * (Re)fetch a subscribe token for a watch entry and send subscribe. Called
     * once when a new entry is created, and again for every entry on every
     * successful (re)authentication — tokens are short-lived (60s) so a stale
     * one from before a reconnect must be replaced, not reused.
     */
    static async _sync_watch(key) {
        // The token request is async; a concurrent watch() sharing this ref-counted
        // entry can add its callback to entry.callbacks WHILE we are awaiting. A blind
        // delete-on-failure would strand such a late joiner (its callback registered in
        // a now-deleted entry, no token request of its own, no subscription ever). So on
        // failure we re-check for new joiners and retry the token request exactly once
        // for them; a second failure (or no new joiners) drops the entry loudly.
        for (let attempt = 0; attempt < 2; attempt++) {
            const entry = Rsx_Realtime._watches.get(key);
            if (!entry) return;

            const attempted_count = entry.callbacks.size;

            try {
                const response = await Realtime_Controller.get_subscribe_token({
                    topic: entry.topic,
                    filter: entry.filter,
                });

                // Entry may have been fully released while awaiting — re-check.
                const current = Rsx_Realtime._watches.get(key);
                if (!current) return;
                current.token = response.token;
                Rsx_Realtime._send_subscribe(key, current.token);
                return;
            } catch (e) {
                // Entry may have been fully released while awaiting — undefined is a
                // real, expected state here (nothing left to serve or clean up).
                const current = Rsx_Realtime._watches.get(key);
                if (!current) {
                    return;
                }

                // Retry exactly once, and only when late callbacks joined since this
                // attempt began (there is a new, un-served watcher to try for).
                if (attempt === 0 && current.callbacks.size > attempted_count) {
                    continue;
                }

                console.warn(
                    '[Realtime] Subscribe failed for ' + current.topic +
                    ' (' + e.message + ') — dropping ' + current.callbacks.size + ' watcher(s)'
                );
                // A gated component waiting on establishment must not hang: reject so its
                // gate race settles (gate_load treats rejection as log-and-proceed).
                if (current._established_reject) {
                    current._established_reject(new Error('Realtime subscribe failed for ' + current.topic));
                }
                Rsx_Realtime._watches.delete(key);
                return;
            }
        }
    }

    /**
     * Drop one callback from a watch entry. When the entry's last callback is
     * removed, unsubscribe on the server and, if nothing else is being watched
     * at all, schedule an idle disconnect of the socket itself.
     */
    static _release_watch(key, callback) {
        const entry = Rsx_Realtime._watches.get(key);
        if (!entry) return;

        entry.callbacks.delete(callback);
        if (entry.callbacks.size > 0) return;

        // Entry is dying — cancel any pending debounced live dispatch so no timer fires
        // for a dead entry.
        if (entry._debounce_timer) {
            clearTimeout(entry._debounce_timer);
            entry._debounce_timer = null;
            entry._live_data = null;
        }

        // Entry fully released — settle establishment (RESOLVE; a component that stopped
        // mid-wait must not hold an orphan pending promise). Already-settled = no-op.
        if (entry._established_resolve) {
            entry._established_resolve();
        }

        Rsx_Realtime._watches.delete(key);

        Rsx_Realtime._send({ type: 'unsubscribe', sub_id: key });

        if (Rsx_Realtime._watches.size === 0) {
            Rsx_Realtime._schedule_idle_disconnect();
        }
    }

    /**
     * Canonical key for a topic+filter pair — stable regardless of filter key
     * insertion order, since it's also used as the sub_id sent to Node.
     */
    static _canonical_key(topic, filter) {
        const sorted_entries = Object.keys(filter).sort().map((k) => [k, filter[k]]);
        return topic + '|' + JSON.stringify(sorted_entries);
    }

    /**
     * Shallow filter match, mirroring the server's matching logic exactly — a
     * message the server already filtered for this connection can still belong
     * to a DIFFERENT local watch entry sharing the same topic name, so the
     * client re-checks the filter before dispatching to each entry's callbacks.
     */
    static _matches_filter(filter, data) {
        if (!filter || Object.keys(filter).length === 0) return true;
        for (const key in filter) {
            // Bulk model-change frames carry {model, ids:[...]} instead of a scalar id. An
            // 'id' watcher matches when the frame's ids array contains its id (string-compared,
            // mirroring the scalar path and the server's matches_filter exactly). Only the 'id'
            // key gets this; every other key stays scalar equality so {model}-only collection
            // watchers still match via 'model'.
            if (key === 'id' && data.id === undefined && Array.isArray(data.ids)) {
                const want = String(filter.id);
                if (!data.ids.some((v) => String(v) === want)) return false;
                continue;
            }
            if (String(data[key]) !== String(filter[key])) return false;
        }
        return true;
    }

    // =========================================================================
    // Connection lifecycle
    // =========================================================================

    /**
     * The WebSocket URL to connect to: rsxapp.realtime_url with the scheme following
     * the PAGE protocol. The server emits the canonical wss:// form (production pages
     * are always https — APP_URL must be https), but a plain-http page — which only
     * exists on loopback/dev, e.g. rsx:debug browsing http://localhost — must use
     * ws:// (nothing serves TLS there; nginx's port-80 block proxies /ws to the relay).
     * Browsers forbid ws:// from https pages anyway, so scheme-follows-page is the
     * only universally valid derivation. This is what makes realtime function under
     * the rsx:debug harness instead of failing with ERR_CONNECTION_REFUSED.
     *
     * @returns {string}
     */
    static _connect_url() {
        const url = window.rsxapp.realtime_url;

        if (window.location.protocol !== 'https:' && url.startsWith('wss://')) {
            return 'ws://' + url.slice('wss://'.length);
        }

        return url;
    }

    /**
     * Is there a socket worth reusing?
     *
     * CONNECTING and OPEN are live. CLOSING and CLOSED are ABSENT even though `_ws` is
     * still assigned — close() is ASYNCHRONOUS, and a socket lingers in CLOSING for as
     * long as the round trip takes. Treating that window as "connected" is what used to
     * kill a page for good: a watch() arriving during an idle close took the fast path
     * (no connection attempted), its subscribe frame was dropped for a non-OPEN socket,
     * and the close that followed had already decided it was intentional and did not
     * reconnect — leaving a live watch entry with no socket and nothing scheduled to
     * make one.
     */
    static _has_live_socket() {
        const ws = Rsx_Realtime._ws;
        if (!ws) return false;
        return ws.readyState === WebSocket.CONNECTING || ws.readyState === WebSocket.OPEN;
    }

    /**
     * Connect to the WebSocket server. Idempotent — safe to call when already
     * connected/connecting, and SINGLE-FLIGHT: N callers in the same tick share ONE
     * attempt and therefore ONE socket.
     *
     * The claim is `_connect_promise`, and it is assigned SYNCHRONOUSLY here, before any
     * await, because the guard has to be a claim about "a connect is HAPPENING", not about
     * "a socket EXISTS". `_ws` cannot be that guard: it is only assignable after the token
     * round-trip, so every subscriber that arrived while the first token request was in
     * flight walked straight past `if (_ws) return` and opened a socket of its own. That is
     * exactly what a page does — N components each subscribing in their own on_create() run
     * in one tick — and it is how one tab with one page open came to make the relay report
     * `Connections: 10, Subscriptions: 11`: ten authenticated sockets, nine of them orphaned
     * the moment the next one overwrote `_ws`, with every subscription riding the last.
     *
     * Cleared on settle so a later reconnect starts a fresh attempt. Callers may await it.
     */
    static async _connect() {
        if (Rsx_Realtime._has_live_socket()) return;
        if (Rsx_Realtime._connect_promise) return Rsx_Realtime._connect_promise;

        let settle;
        Rsx_Realtime._connect_promise = new Promise((resolve) => { settle = resolve; });

        try {
            await Rsx_Realtime._open_socket();
        } finally {
            Rsx_Realtime._connect_promise = null;
            settle();
        }
    }

    /**
     * One connect attempt: mint a connection token, open the socket, wire its handlers.
     * Only ever called through _connect(), which serializes it.
     *
     * Every handler below begins with an own-socket check. A socket that is no longer
     * `_ws` is an ORPHAN, and an orphan must never touch shared state: its close would
     * otherwise null out a perfectly healthy live socket's `_ws`, drop `_connected` /
     * `_authenticated` under it, and schedule a reconnect nothing asked for — a late close
     * from a previous connection silently killing the current one.
     */
    static async _open_socket() {
        Rsx_Realtime._set_state('connecting');

        try {
            // Realtime_Controller is a framework-core Ajax controller. Its JS stub is
            // included in EVERY app bundle by the compiler (BundleCompiler::_get_js_stubs)
            // because this always-bundled core class depends on it — not because any app
            // bundle includes its /system source directly.
            const response = await Realtime_Controller.get_connection_token();
            const token = response.token;

            const ws = new WebSocket(Rsx_Realtime._connect_url());
            Rsx_Realtime._sockets_opened++;

            // Superseded while the token was in flight: something else already installed a
            // live socket, so this one has no owner. Close it rather than leak an open,
            // about-to-be-authenticated connection at the relay.
            if (Rsx_Realtime._has_live_socket()) {
                ws.close();
                return;
            }

            Rsx_Realtime._ws = ws;

            ws.onopen = () => {
                if (Rsx_Realtime._ws !== ws) return;
                console_debug('Realtime', 'Connected, authenticating...');
                // Sent DIRECTLY, not through the outbox: auth must be the first message on
                // the wire, and the outbox only accepts messages once authenticated, so auth
                // can never ride a batch by construction. Array-wrapped like everything else
                // — the relay accepts exactly one frame shape.
                ws.send(JSON.stringify([{ type: 'auth', token: token }]));
                Rsx_Realtime._frames_sent++;
            };

            ws.onmessage = (e) => {
                if (Rsx_Realtime._ws !== ws) return;
                let msg;
                try {
                    msg = JSON.parse(e.data);
                } catch {
                    return;
                }
                Rsx_Realtime._on_message(msg);
            };

            ws.onclose = () => {
                // An orphan closing is just garbage being collected — let it go silently.
                if (Rsx_Realtime._ws !== ws) return;

                console_debug('Realtime', 'Connection closed');
                const was_intentional = ws._rsx_intentional_close === true;
                Rsx_Realtime._handle_link_down();

                // Re-evaluated HERE, at close time — never trusted from a flag set when
                // close() was CALLED. An idle close is only still correct if the connection
                // is still unwanted by the time it completes, and up to a full round trip
                // elapses in CLOSING. A watch() (or the anchor) arriving in that window is
                // exactly the case that used to strand a live watch entry with no socket:
                // the intent was right, the condition was stale.
                const still_needed = Rsx_Realtime._watches.size > 0 || Rsx_Realtime._anchored;

                if (was_intentional && !still_needed) {
                    // Terminal by intent: nothing wants the socket, nothing will reconnect.
                    // Tell listeners immediately — there is no blip to wait out.
                    Rsx_Realtime._set_state('disconnected');
                    return;
                }

                Rsx_Realtime._announce_offline_after_grace();
                Rsx_Realtime._schedule_reconnect();
            };

            ws.onerror = () => {
                if (Rsx_Realtime._ws !== ws) return;
                // onclose will fire after this
            };
        } catch (e) {
            console_debug('Realtime', 'Connection failed: ' + e.message);
            Rsx_Realtime._schedule_reconnect();
        }
    }

    /**
     * The link is down. Updates the INTERNAL truth immediately — socket, connection flags,
     * downtime clock, outgoing queue — and says nothing to listeners: what the app is told,
     * and when, is the caller's decision (see _announce_offline_after_grace).
     *
     * The outbox is discarded here rather than held: its tokens belong to the connection
     * that just died, and every watch is re-established from scratch on the next auth_ok.
     */
    static _handle_link_down() {
        Rsx_Realtime._ws = null;
        Rsx_Realtime._connected = false;
        Rsx_Realtime._authenticated = false;
        Rsx_Realtime._liveness_ping_ts = 0;

        Rsx_Realtime._outbox = [];
        if (Rsx_Realtime._outbox_timer) {
            clearTimeout(Rsx_Realtime._outbox_timer);
            Rsx_Realtime._outbox_timer = null;
        }

        // Start the downtime clock (consumed by _check_stale_reconnect on the next auth_ok).
        // Only the FIRST close of a disconnected stretch counts, so a failed reconnect
        // attempt does not restart the measurement.
        if (!Rsx_Realtime._disconnected_since) {
            Rsx_Realtime._disconnected_since = Date.now();
        }
    }

    /**
     * Tell listeners we are offline — but only if we are STILL offline once the grace
     * window has passed. A reconnect inside the window means the app never hears
     * 'disconnected' at all, which is the point: a 300ms blip is not an outage.
     *
     * Once an outage HAS been announced, further closes in the same outage skip the grace
     * and announce immediately — the delay exists to avoid crying wolf, and we are already
     * known to be offline.
     */
    static _announce_offline_after_grace() {
        if (Rsx_Realtime._offline_announced) {
            Rsx_Realtime._set_state('disconnected');
            return;
        }
        if (Rsx_Realtime._offline_timer) return;

        Rsx_Realtime._offline_timer = setTimeout(() => {
            Rsx_Realtime._offline_timer = null;
            if (Rsx_Realtime._connected) return;
            Rsx_Realtime._offline_announced = true;
            Rsx_Realtime._set_state('disconnected');
        }, Rsx_Realtime.OFFLINE_ANNOUNCE_GRACE_MS);
    }

    /**
     * Cancel a pending offline announcement and end the outage. Called on auth_ok.
     */
    static _cancel_offline_announcement() {
        if (Rsx_Realtime._offline_timer) {
            clearTimeout(Rsx_Realtime._offline_timer);
            Rsx_Realtime._offline_timer = null;
        }
        Rsx_Realtime._offline_announced = false;
    }

    /**
     * Tear the current socket down and immediately reconnect, because the link cannot be
     * trusted (a wall-clock gap, or a liveness ping that went unanswered). The socket is
     * ORPHANED first, so its own close handler cannot run the ordinary close path
     * underneath the replacement we are about to open — and this is deliberately NOT an
     * intentional close: reconnecting, and the auth_ok resync it produces, IS the recovery.
     */
    static _force_reconnect() {
        const ws = Rsx_Realtime._ws;
        Rsx_Realtime._handle_link_down();
        if (ws) {
            ws.close();
        }
        Rsx_Realtime._announce_offline_after_grace();
        Rsx_Realtime._connect();
    }

    /**
     * Close the socket because nothing is watching anymore. Marked on the SOCKET, not on
     * the class: with one flag shared by every socket, a close that completes after its
     * successor has been installed leaves the flag set and the next real drop reads as
     * intentional.
     */
    static _close_idle() {
        const ws = Rsx_Realtime._ws;
        if (!ws) return;
        ws._rsx_intentional_close = true;
        ws.close();
    }

    /**
     * Wait a grace period, then close the socket if STILL nothing is being
     * watched (re-checked at fire time, so a new watch() in the meantime is a
     * natural no-op cancellation — no explicit timer bookkeeping needed).
     */
    static _schedule_idle_disconnect() {
        // The system anchor holds the connection open for the page lifetime (refresh pushes
        // arrive with no app subscription), so never idle-close while anchored.
        if (Rsx_Realtime._anchored) return;
        if (Rsx_Realtime._idle_timer) return;

        Rsx_Realtime._idle_timer = setTimeout(() => {
            Rsx_Realtime._idle_timer = null;
            if (Rsx_Realtime._anchored) return;
            if (Rsx_Realtime._watches.size > 0) return;
            Rsx_Realtime._close_idle();
        }, Rsx_Realtime._idle_disconnect_delay);
    }

    /**
     * Handle incoming WebSocket messages.
     */
    static _on_message(msg) {
        // Any inbound frame proves the link is carrying traffic (see _liveness_tick).
        Rsx_Realtime._last_inbound_ts = Date.now();

        if (msg.type === 'auth_ok') {
            console_debug('Realtime', 'Authenticated');
            Rsx_Realtime._connected = true;
            Rsx_Realtime._authenticated = true;
            Rsx_Realtime._reconnect_delay = 1000;
            Rsx_Realtime._cancel_offline_announcement();
            Rsx_Realtime._set_state('connected');

            // Nothing wanted this connection by the time it finished opening — the idle
            // timer may have fired while the token request was in flight, when there was
            // no socket for it to close. Without this the socket would stay open forever
            // with nothing watching, since idle disconnect is otherwise only scheduled
            // from the release path.
            if (Rsx_Realtime._watches.size === 0 && !Rsx_Realtime._anchored) {
                Rsx_Realtime._schedule_idle_disconnect();
            }

            // Long gap => the tab's CODE may be stale; schedule an idle-gated reload. Never
            // returns early — the resync below must still run (the reload is minutes out at
            // worst, and the page stays live and correct until it fires).
            Rsx_Realtime._check_stale_reconnect();

            // Resync every active watch: fresh token (the old one may have
            // expired) + resubscribe. This is also what makes a reconnect after
            // a missed-message gap correct — see watch() docblock.
            for (const key of Rsx_Realtime._watches.keys()) {
                Rsx_Realtime._sync_watch(key);
            }
            return;
        }

        if (msg.type === 'subscribed') {
            // The subscribe ack IS the resync signal: fire every callback for this
            // watch once, with no data — the callback's job is to go fetch fresh
            // state itself, exactly as it would for a live "something changed".
            const entry = Rsx_Realtime._watches.get(msg.sub_id);
            if (entry) {
                // First ack establishes the subscription: resolve `established` so any
                // gated component's first load can proceed. Reconnect re-acks no-op it.
                if (entry._established_resolve) {
                    entry._established_resolve();
                }
                Rsx_Realtime._dispatch(entry, null, true);
            }
            return;
        }

        if (msg.type === 'message') {
            for (const entry of Rsx_Realtime._watches.values()) {
                if (entry.topic !== msg.topic) continue;
                if (!Rsx_Realtime._matches_filter(entry.filter, msg.data)) continue;
                Rsx_Realtime._dispatch(entry, msg.data, false);
            }
            return;
        }

        if (msg.type === 'refresh') {
            // Targeted control frame (session/user refresh) — routed by connection stamps,
            // not tied to any subscription.
            Rsx_Realtime._handle_refresh();
            return;
        }

        if (msg.type === 'pong') {
            // Liveness answer. The timestamp recorded at the top of this method IS the
            // signal; nothing else to do.
            return;
        }

        if (msg.type === 'error') {
            console.warn('[Realtime] Server error:', msg.message);
        }
    }

    static _dispatch(entry, data, resync) {
        if (resync) {
            // Resync already instructs callbacks to refetch fresh state — cancel any
            // pending debounced live dispatch (it would only trigger a redundant refetch)
            // and fire immediately.
            if (entry._debounce_timer) {
                clearTimeout(entry._debounce_timer);
                entry._debounce_timer = null;
                entry._live_data = null;
            }
            Rsx_Realtime._fire_callbacks(entry, data, true);
            return;
        }

        // Live message: trailing-debounce per entry. Each new message resets the timer
        // and overwrites the pending data, so a publish burst collapses to a single
        // callback invocation carrying the last message's data.
        entry._live_data = data;
        if (entry._debounce_timer) {
            clearTimeout(entry._debounce_timer);
        }
        entry._debounce_timer = setTimeout(() => {
            entry._debounce_timer = null;
            const live_data = entry._live_data;
            entry._live_data = null;
            Rsx_Realtime._fire_callbacks(entry, live_data, false);
        }, Rsx_Realtime._live_dispatch_debounce_ms);
    }

    static _fire_callbacks(entry, data, resync) {
        const meta = { resync, topic: entry.topic, filter: entry.filter };
        for (const callback of entry.callbacks) {
            try {
                callback(data, meta);
            } catch (e) {
                console.error('[Realtime] Watch callback error:', e);
            }
        }
    }

    /**
     * Send a subscribe message to Node for one watch entry (no-op if not yet
     * connected/authenticated — the auth_ok handler resyncs everything once we
     * are).
     */
    static _send_subscribe(key, token) {
        Rsx_Realtime._send({
            type: 'subscribe',
            token: token,
            sub_id: key,
        });
    }

    /**
     * Is the link ready to carry a message right now?
     */
    static _is_ready_to_send() {
        return Rsx_Realtime._ws?.readyState === WebSocket.OPEN && Rsx_Realtime._authenticated;
    }

    /**
     * Queue ONE outgoing message for the next batch flush. This is the only way anything
     * reaches the relay after auth.
     *
     * A message queued while the link is down is DROPPED, not replayed, and that is the
     * delivery contract rather than an omission: every entry in _watches is re-established
     * from scratch on every auth_ok, so a subscribe lost to a closed socket is reissued
     * moments later by the resync. Turning this into a replay queue would put a SECOND
     * mechanism on the same job, and the two would have to agree about ordering, tokens
     * and expiry forever. Do not "fix" it into one.
     */
    static _send(msg) {
        if (!Rsx_Realtime._is_ready_to_send()) return;

        Rsx_Realtime._outbox.push(msg);

        // One-shot: the window is measured from the FIRST enqueue and never extended by a
        // later one (see OUTBOX_FLUSH_MS).
        if (Rsx_Realtime._outbox_timer) return;
        Rsx_Realtime._outbox_timer = setTimeout(() => {
            Rsx_Realtime._outbox_timer = null;
            Rsx_Realtime._flush_outbox();
        }, Rsx_Realtime.OUTBOX_FLUSH_MS);
    }

    /**
     * Put everything queued on the wire as array frames, in order, chunked at
     * MAX_MESSAGES_PER_FRAME. Anything queued for a link that died in the meantime is dropped —
     * the auth_ok resync reissues it.
     */
    static _flush_outbox() {
        const queued = Rsx_Realtime._outbox;
        Rsx_Realtime._outbox = [];

        if (queued.length === 0) return;
        if (!Rsx_Realtime._is_ready_to_send()) return;

        for (let i = 0; i < queued.length; i += Rsx_Realtime.MAX_MESSAGES_PER_FRAME) {
            Rsx_Realtime._ws.send(JSON.stringify(queued.slice(i, i + Rsx_Realtime.MAX_MESSAGES_PER_FRAME)));
            Rsx_Realtime._frames_sent++;
        }
    }

    /**
     * Schedule reconnection with exponential backoff: 1s, 2s, 4s, 8s, 16s, 30s max.
     */
    static _schedule_reconnect() {
        Rsx_Realtime._set_state('reconnecting');

        if (Rsx_Realtime._reconnect_timer) return;
        if (!window.rsxapp?.realtime_url) return;

        console_debug('Realtime', 'Reconnecting in ' + Rsx_Realtime._reconnect_delay + 'ms');

        Rsx_Realtime._reconnect_timer = setTimeout(() => {
            Rsx_Realtime._reconnect_timer = null;
            Rsx_Realtime._connect();
        }, Rsx_Realtime._reconnect_delay);

        Rsx_Realtime._reconnect_delay = Math.min(
            Rsx_Realtime._reconnect_delay * 2,
            Rsx_Realtime._max_reconnect_delay
        );
    }

    // =========================================================================
    // Component integration
    // =========================================================================

    /**
     * Resolve this.subscribe()'s overloaded first argument into the canonical
     * (topic, filter, callback) triple that watch() understands:
     *
     *   subscribe('Topic', filter?, cb)   -> unchanged (string topic path)
     *   subscribe(Model_Class, id, cb)    -> ('Model_Changed_Topic', {model, id}, cb)
     *   subscribe(record_instance, cb)    -> ('Model_Changed_Topic', {model, id}, cb)
     *
     * The two model overloads FAIL LOUD (throw) when the model has not opted into
     * realtime emission (no truthy __REALTIME static baked into its stub) — a
     * subscribe that can never receive a message is a programming error, surfaced in
     * EVERY environment because this validation runs here, before watch()'s
     * realtime-disabled no-op. watch() itself stays purely string-topic based.
     *
     * @returns {[string, Object, Function]} [topic, filter, callback]
     */
    static _resolve_subscribe_args(a, b, c) {
        // String topic — the original signature, untouched.
        if (typeof a === 'string') {
            return [a, b, c];
        }

        // Model class: a class/function carrying the __MODEL static. (b = id, c = cb)
        if (typeof a === 'function' && a.__MODEL) {
            Rsx_Realtime._assert_model_realtime(a);
            return ['Model_Changed_Topic', { model: a.getModelName(), id: int(b) }, c];
        }

        // Model record instance: an object whose constructor carries __MODEL. (b = cb)
        if (a && typeof a === 'object' && a.constructor && a.constructor.__MODEL) {
            const model_class = a.constructor;
            Rsx_Realtime._assert_model_realtime(model_class);
            return ['Model_Changed_Topic', { model: model_class.getModelName(), id: int(a.id) }, b];
        }

        throw new Error(
            'subscribe(): first argument must be a topic name (string), an ORM model class, ' +
            'or a model record instance — received ' + (a === null ? 'null' : typeof a)
        );
    }

    /**
     * Throw unless the model class has opted into realtime emission (baked-in
     * __REALTIME static, mirroring PHP `public static $realtime = true`).
     */
    static _assert_model_realtime(model_class) {
        if (!model_class.__REALTIME) {
            const name = typeof model_class.getModelName === 'function'
                ? model_class.getModelName()
                : (model_class.__MODEL || model_class.name);
            throw new Error(
                name + ' does not declare realtime emission — set ' +
                '`public static $realtime = true;` on the PHP model to subscribe to its changes.'
            );
        }
    }

    /**
     * Patch Component prototype to add this.subscribe() convenience — a thin
     * wrapper over watch() that tracks the returned handle for auto-cleanup —
     * and to stop every watch a component started when it is destroyed.
     *
     * this.subscribe() is IDEMPOTENT per canonical (topic + sorted-filter) within a
     * single component instance: ONE live subscription per (topic, filter) per
     * component. A second call with the identical topic+filter returns the EXISTING
     * handle — it does NOT add another callback or another handle entry. This is what
     * makes the canonical usage safe:
     *
     *     on_ready() { this.subscribe(Model, id, () => this.reload()); }
     *
     * on_ready re-fires after every reload(); without idempotence each reload would
     * register a fresh callback closure on the shared ref-counted entry, so message N
     * would trigger N reloads (exponential snowball). The canonicalization here is the
     * exact one watch() uses (Rsx_Realtime._canonical_key), so it collapses the same
     * pairs the ref-counter collapses. A component that genuinely wants a SECOND
     * callback on the same topic+filter must use Rsx_Realtime.watch() directly.
     */
    static _patch_component() {
        // NOTE: intentionally a NON-async function. Load-gate registration (this.gate_load)
        // must happen SYNCHRONOUSLY within the subscribe() call so an on_create()
        // subscription always registers its gate before jqhtml flips the first-load latch
        // (__first_load_started). An async function would yield at its first await and miss
        // the latch. We still return a promise resolving to the handle (callers may await).
        Component.prototype.subscribe = function (topic, filter, callback) {
            const [r_topic, r_filter, r_callback] = Rsx_Realtime._resolve_subscribe_args(topic, filter, callback);

            if (!this._realtime_subs) this._realtime_subs = [];
            if (!this._realtime_sub_keys) this._realtime_sub_keys = new Map();

            // Idempotent per (topic, filter) for this component instance. A repeat call
            // returns the cached handle/promise WITHOUT registering another gate or
            // callback. Registered synchronously (in-flight marker) so even concurrent
            // identical calls collapse to one subscription.
            const key = Rsx_Realtime._canonical_key(r_topic, r_filter);
            if (this._realtime_sub_keys.has(key)) {
                return this._realtime_sub_keys.get(key);
            }

            // A subscription registered BEFORE this component's first load (i.e. in
            // on_create(), latch still false) GATES that load on subscription
            // establishment, so the ONE fetch happens strictly after the subscription is
            // live — no fetch-then-resync duplicate. Post-load subscriptions (on_ready)
            // skip the gate and behave as fetch-then-revalidate, exactly as before.
            const realtime_active = !!window.rsxapp?.realtime_url;
            const pre_load = realtime_active && this.__first_load_started !== true;

            let final_callback = r_callback;
            let resolve_established_gate = null;

            if (pre_load) {
                // Deferred gate: resolved when establishment (ack) OR the timeout wins.
                const established_gate = new Promise((resolve) => { resolve_established_gate = resolve; });

                // Timeout RESOLVES (never rejects) so a relay-down page still loads after
                // load_gate_timeout_ms. `timed_out` (set synchronously in the timer) records
                // the race winner for the swallow decision: the establishment ack and its
                // resync dispatch land in the SAME synchronous message-handler frame, so
                // reading a boolean here is race-free — keying the swallow off the gate
                // promise's resolution would lose to that synchronous resync by microtasks.
                const timeout_ms = int(window.rsxapp?.realtime?.load_gate_timeout_ms) || 1500;
                let timed_out = false;
                const timeout = new Promise((resolve) => setTimeout(() => { timed_out = true; resolve(); }, timeout_ms));

                // Register the gate SYNCHRONOUSLY (before any await). gate_load throws only
                // if the latch already flipped — the pre_load check IS the guard, so if it
                // somehow throws, let it (fail loud).
                this.gate_load(Promise.race([established_gate, timeout]));

                // Swallow the FIRST resync IFF the ack won the race (arrived before the
                // timeout): that resync IS the gated load's revalidation, so re-running it
                // would rebuild the double fetch. A timeout-won late ack (timed_out) passes
                // through as the race healer for whatever the timeout window missed; live
                // messages (resync !== true) and reconnect resyncs always pass through.
                let swallowed = false;
                final_callback = (data, meta) => {
                    // meta is always constructed by _fire_callbacks - fail loud if not.
                    if (!swallowed && !timed_out && meta.resync === true) {
                        swallowed = true;
                        return;
                    }
                    r_callback(data, meta);
                };
            }

            const handle_promise = Rsx_Realtime.watch(r_topic, r_filter, final_callback);

            const tracked = handle_promise.then((handle) => {
                // Wire establishment -> resolve the gate (settle on BOTH fulfil and reject:
                // a rejected established must still resolve the gate so it never dangles —
                // gate_load treats the gate resolving as "proceed").
                if (pre_load && resolve_established_gate) {
                    handle.established.then(resolve_established_gate, resolve_established_gate);
                }

                // The component may have been destroyed WHILE this subscribe was in flight
                // (a child recreated on the parent's double-render, stopped before its token
                // round-trip resolved). on_stop only stops already-resolved handles, so
                // without this check the just-resolved handle would leak a dead callback
                // firing on a destroyed component on every live message. Release it.
                if (this._realtime_stopped) {
                    handle.stop();
                    return handle;
                }

                this._realtime_subs.push(handle);
                this._realtime_sub_keys.set(key, handle);
                return handle;
            });

            this._realtime_sub_keys.set(key, tracked);

            return tracked;
        };

        const _original_on_stop = Component.prototype.on_stop;
        Component.prototype.on_stop = function () {
            // Flags any subscribe() still awaiting its token to self-release on resolve.
            this._realtime_stopped = true;

            if (this._realtime_subs) {
                for (const handle of this._realtime_subs) {
                    handle.stop();
                }
                this._realtime_subs = null;
            }
            this._realtime_sub_keys = null;

            if (_original_on_stop) {
                _original_on_stop.call(this);
            }
        };
    }
}
