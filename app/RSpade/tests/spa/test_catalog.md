# spa test catalog

The title rows live in `playwright/spa_action_title.js` (one browser session over the
template app's `/contacts` and `/contacts/view/:id`); the guard rows live in
`playwright/navigation_guard.js` (the framework's own `/_sys` panel, so it depends on no
application code; links, code navigation and real page exits) and the Back/Forward half of
the guard in `playwright/history_scroll_and_veto.js` (the HISTORY rows); the REFRESH rows live in `playwright/refresh_scroll_restoration.js`; the fragment rows live in `playwright/fragment_popstate.js`
(same `/_sys` session shape); the ResizeObserver rows live in `playwright/resize_observer_notice.js` (same shape), the rejection-origin rows in `playwright/unhandled_rejection_origin.js` (same shape). The decorator half - `@title` surviving the transform
as `_spa_title` - is JT-03f in the `js_transform` concern.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SPA-TITLE-01 | An `@title`-only action (no `page_title()` override) exposes the decorator value as its static title | playwright | `/contacts` (Contacts_Index_Action) | `get_static_title() === 'Contacts'` | implemented | 2026-08-18 |
| SPA-TITLE-02 | The static title reaches the paint callback SYNCHRONOUSLY - zero latency at dispatch - and lands in the header element and document.title | playwright | `Spa_Layout.resolve_page_title(paint)` on the same page | callback fired before the promise is awaited; header + document.title carry it | implemented | 2026-08-18 |
| SPA-TITLE-03 | An action that overrides `page_title()` contributes NO static candidate, so a generic `@title` never flashes | playwright | `/contacts/view/:id` (Contacts_View_Action) | `get_static_title() === null`, nothing painted synchronously | implemented | 2026-08-18 |
| SPA-TITLE-04 | A dynamic title still resolves from loaded data - the override awaits `await_loaded()` and the sticky `'load'` event replays for a late registration | playwright | same action, probed after dispatch | live title equals the record's name and equals what is on screen | implemented | 2026-08-18 |
| SPA-TITLE-05 | With no `@title` and no override, `page_title()` is the loud placeholder rather than an empty header | playwright | an action declaring neither | `'(title not set)'` | planned (no such action in the template app; would need a fixture action, which the SPA test harness has no home for yet) | 2026-08-18 |
| SPA-TITLE-06 | The layout `placeholder` (the template's per-URL session cache) is used ONLY when there is no static title, and is replaced by the live title | playwright | second visit to a dynamic page in one session | cached title painted first, then the live title | planned | 2026-08-18 |
| SPA-NAV-01 | Layout-chain divergence: navigating between actions sharing an outer layout recreates only the differing part | playwright | settings -> settings sibling | outer layout instance identity preserved | planned | 2026-08-18 |
| SPA-NAV-02 | `Spa.redirect()` REPLACES history where `Spa.dispatch()` pushes it | playwright | redirect from an action's `on_load()` | one history entry, back button leaves the SPA section | planned | 2026-08-18 |
| SPA-GUARD-01 | `set_navigation_guard()` is a toggle with no callback: any argument throws, stating the contract, and turns nothing on | playwright | `set_navigation_guard(fn)`, `(undefined)`, `(true)` | three Errors starting `Rsx.set_navigation_guard() takes no argument`, naming `clear_navigation_guard()` and `rsx:man spa`; guard off | implemented | 2026-10-12 |
| SPA-GUARD-02 | One slot, idempotent | playwright | three sets, one clear, a second clear | on after the sets, off after one clear and still off | implemented | 2026-10-12 |
| SPA-GUARD-03 | Guard off: a link click is intercepted and dispatched in the SPA | playwright | click a planted `<a href="/_sys/tasks">` | landed on the target; the document mark survives; no dialog | implemented | 2026-10-12 |
| SPA-GUARD-04 | Guard on: the link is NOT intercepted - a real navigation starts and the browser offers `beforeunload` | playwright | guard on, click a link, dismiss | a `beforeunload` dialog | implemented | 2026-10-12 |
| SPA-GUARD-05 | Dismissing it leaves the page, its action instance and the guard exactly as they were | playwright | same | same path, same tagged action, no dispatch, guard on | implemented | 2026-10-12 |
| SPA-GUARD-06 | Clearing the guard restores interception on the very next click | playwright | `clear_navigation_guard()`, click the link | in-SPA navigation, mark survives, no dialog | implemented | 2026-10-12 |
| SPA-GUARD-07 | Navigation from code is not guarded: `Spa.dispatch()` goes ahead in the SPA with no dialog and leaves the guard off | playwright | guard on, `Spa.dispatch(target)` | landed, mark survives, no dialog, guard off | implemented | 2026-10-12 |
| SPA-GUARD-08 | `Spa.redirect()` does the same | playwright | guard on, `Spa.redirect(start)` | landed, mark survives, no dialog, guard off | implemented | 2026-10-12 |
| SPA-GUARD-09 | A dispatch to the page already showing (a fragment) is not a departure | playwright | guard on, `Spa.dispatch(pathname + "#guard_probe=1")` | hash set, same action instance, no dispatch, no dialog, guard still on | implemented | 2026-10-12 |
| SPA-GUARD-10 | Guard on, dialog accepted: the target arrives as a full document, whose guard is off | playwright | guard on, click a link, accept | `beforeunload`; target path; the document mark is gone; guard off | implemented | 2026-10-12 |
| SPA-GUARD-11 | Closing the page raises the native `beforeunload` dialog while the guard is on | playwright | user gesture, guard on, `page.close({runBeforeUnload:true})` | a `beforeunload` dialog | implemented | 2026-10-12 |
| SPA-GUARD-12 | Once cleared, the installed listener stays silent | playwright | set then clear, close the page | the page closes; no dialog | implemented | 2026-10-12 |
| SPA-HASH-01 | A Back/Forward that keeps the path and query leaves the SAME action instance mounted and fires `spa_hash_change` once with the restored hash | playwright | `/_sys`, `pushState('#panel=open')`, `history.back()` | the tagged instance survives; one event, `hash === ''` | implemented | 2026-09-24 |
| SPA-HASH-02 | A fragment-only move is not a departure: with the navigation guard on it raises no dialog and leaves the guard on | playwright | same move with the guard on | no dialog; `has_navigation_guard()` still true | implemented | 2026-10-12 |
| SPA-HASH-03 | A Back across a PATH change still re-dispatches a fresh action and fires no `spa_hash_change` | playwright | `/_sys` -> `/_sys/tasks`, `history.back()` | a new action on `/_sys`; no event | implemented | 2026-09-24 |
| SPA-HASH-04 | An in-page `#at=` anchor link reaches its target on the MOUNTED action - the browser's native fragment scroll looks for `id="at=..."` and cannot | playwright | a `data-anchor` target appended to the `/_sys` action, `location.hash = 'at=...'` | target focused; same action instance; one `spa_hash_change` | implemented | 2026-09-24 |
| SPA-RO-01 | The browser's ResizeObserver delivery-overrun notice (both spellings, ErrorEvent with no error object) fires no `unhandled_exception` and leaves SPA navigation enabled | playwright | `/_sys`, `dispatchEvent(new ErrorEvent('error', {message}))` | no event; `Spa._spa_enabled` true | implemented | 2026-09-25 |
| SPA-RO-02 | After the notice, `Spa.dispatch()` still navigates client-side | playwright | `/_sys` -> `/_sys/tasks` | landed on the target; a window marker survives (no page load) | implemented | 2026-09-25 |
| SPA-RO-03 | The match is exact: a different `ResizeObserver loop ...` message, and the notice text carried by a real Error, are still unhandled exceptions | playwright | two control ErrorEvents | two `unhandled_exception` events; SPA disabled | implemented | 2026-09-25 |
| SPA-REJ-01 | A plain-object rejection is reported once, its message carries the value as JSON (never `[object Object]`), and it is marked `implicated: false` | playwright | `/_sys`, a dispatched `PromiseRejectionEvent` with reason `{code, detail}` | one `unhandled_exception`, message contains the value, `meta.implicated === false` | implemented | 2026-10-05 |
| SPA-REJ-02 | A rejection that implicates no page code leaves SPA navigation enabled | playwright | as SPA-REJ-01 | `Spa._spa_enabled` true | implemented | 2026-10-05 |
| SPA-REJ-03 | After it, `Spa.dispatch()` still navigates client-side | playwright | `/_sys` -> `/_sys/tasks` | landed on the target; a window marker survives | implemented | 2026-10-05 |
| SPA-REJ-04 | Extension-origin rejections are ignored outright: an Error whose stack names a `chrome-extension://` script, and Chrome's extension-messaging failure | playwright | two dispatched rejections | no `unhandled_exception`; SPA enabled | implemented | 2026-10-05 |
| SPA-REJ-05 | A rejection whose Error stack names this origin's compiled bundle is still an exception that disables the SPA | playwright | an Error with a `/_compiled/` frame | one `unhandled_exception`, `implicated === true`, SPA disabled | implemented | 2026-10-05 |
| SPA-HISTORY-01 | A new page opens at the top, one entry further along the stack | playwright | scroll A, dispatch B | B at 0, `spa_index` 1 | implemented | 2026-10-11 |
| SPA-HISTORY-02 | Back returns a page to where it was scrolled (left by a push) | playwright | A at 700 -> B -> Back | A at 700 | implemented | 2026-10-11 |
| SPA-HISTORY-03 | Forward returns a page to where it was scrolled (left by Back) | playwright | B at 300 -> Back -> Forward | B at 300 | implemented | 2026-10-11 |
| SPA-HISTORY-04 | The entry's own state follows the page while it is on screen | playwright | scroll to 450 | `history.state.scroll.y` 450 | implemented | 2026-10-11 |
| SPA-HISTORY-05 | `Rsx.url_hash_set()` keeps the entry state | playwright | set and clear a hash key | run, index and scroll intact | implemented | 2026-10-11 |
| SPA-HISTORY-06 | A layout that scrolls inside a container is measured and restored on the container | playwright | action root as the scrolling box, at 600 -> B -> Back | container at 600, window at 0 | implemented | 2026-10-11 |
| SPA-REFRESH-01 | The SPA owns restoration: the browser's is off | playwright | `playwright/refresh_scroll_restoration.js`, `/_sys` | `history.scrollRestoration === 'manual'` | implemented | 2026-10-31 |
| SPA-REFRESH-02 | A refresh returns the page to where it was, and it is there once the action is ready (was: restored, then sent to the top at ready) | playwright | at 1500, reload | 1500 at ready and two frames later | implemented | 2026-10-31 |
| SPA-REFRESH-03 | A refresh during which the user scrolls leaves the page where the user put it, at ready too | playwright | at 1500, Ajax held 1.5 s, reload, wheel -600 mid-load | the wheel's position after ready; restoration ended | implemented | 2026-10-31 |
| SPA-REFRESH-04 | A position the page cannot hold is clamped and settled | playwright | entry scroll y 999999, reload | at the maximum | implemented | 2026-10-31 |
| SPA-REFRESH-05 | Nothing is carried into the next navigation | playwright | then dispatch B | B at 0, no restoration running | implemented | 2026-10-31 |
| SPA-REFRESH-06 | A layout that scrolls inside a container is restored on the container on refresh | playwright | container at 600, reload | container 600, window 0 | implemented | 2026-10-31 |
| SPA-HISTORY-07 | Guard on: a Back/Forward between SPA pages raises the browser confirm box with the unload wording | playwright | `[A, B*, C]`, guard on, Forward, dismiss | one `confirm`, message `Leave site?\n\nChanges you made may not be saved.` | implemented | 2026-10-12 |
| SPA-HISTORY-08 | A declined Forward leaves the address, the stack length and the entry index as they were | playwright | same | on B, length and index unchanged, `Spa._history_index` unchanged | implemented | 2026-10-12 |
| SPA-HISTORY-09 | Nothing is dispatched - neither the move nor the step back - the restoring popstate is not asked about, and the page and the guard are untouched | playwright | same | asked once, no dispatch, same action instance, guard on | implemented | 2026-10-12 |
| SPA-HISTORY-10 | A declined Back does the same | playwright | guard on, Back, dismiss | as 08 + 09 | implemented | 2026-10-12 |
| SPA-HISTORY-11 | A declined two-step jump steps back across both entries | playwright | `[A*, B, C]`, guard on, `history.go(2)`, dismiss | on A, as 08 + 09 | implemented | 2026-10-12 |
| SPA-HISTORY-12 | An accepted Back/Forward turns the guard off and is dispatched in the SPA | playwright | guard on, Forward, accept | one `confirm`; on B; the document mark survives; guard off | implemented | 2026-10-12 |
| SPA-HISTORY-13 | With the guard off nothing asks, and both neighbours are still reachable | playwright | Back before arming; after: Forward, Back, Back | no dialog; C then A reached, nothing added to the stack | implemented | 2026-10-12 |
| SPA-SESSION-01 | The page's session hash names the session the request ENDED with: a sign-in during the request (dev-auth) still publishes a hash | playwright | `playwright/session_hash_after_sign_in.js` on `/_sys` | `rsxapp.session_hash` non-null | implemented (pre-fix: null - computed from the incoming cookie) | 2026-10-19 |
| SPA-SESSION-02 | The page hash and `get_state()` agree | playwright | same | equal | implemented | 2026-10-19 |
| SPA-SESSION-03 | An SPA navigation after that sign-in stays in the document, with `validate_session` NOT stubbed | playwright | `Spa.dispatch('/_sys/tasks')` | document mark survives, no `session_changed` | implemented (pre-fix: reloaded) | 2026-10-19 |
| SPA-SPINNER-01 | `'uncached'`: a cold visit inserts ONE spinner beside the action and hides it (visibility) | playwright | `playwright/spinner_until_ready.js`, the static set at run time on `/_sys/system-info` | one spinner observed, its sibling `visibility: hidden` | implemented | 2026-10-23 |
| SPA-SPINNER-02 | The decorator's spinner is removed when the action is ready | playwright | same visit, after `ready()` | no spinner, action visible | implemented | 2026-10-23 |
| SPA-SPINNER-03 | An action without the decorator is never held | playwright | `/_sys` | no spinner observed | implemented | 2026-10-23 |
| SPA-SPINNER-04 | `'uncached'`: a warm revisit (same action, same args, `was_ready_before()` true) is not held | playwright | the same route again | no spinner observed | implemented | 2026-10-23 |
| SPA-SPINNER-05 | `'always'`: held on the first visit AND the revisit, and revealed at ready | playwright | `/_sys/tasks` twice | one spinner each visit, none after ready | implemented | 2026-10-23 |
| SPA-SPINNER-11 | `'always'` boots the action with jqhtml's `skip_cache_read`, inherited by the components inside it and never left in `this.args`; `'uncached'` reads the cache | playwright | the `/_sys/tasks` revisit and the `/_sys/system-info` warm revisit | `_skip_cache_read` true on the action and a child, absent from args; false for 'uncached' | implemented | 2026-10-23 |
| SPA-SPINNER-06 | `show_spinner()` hides the action behind one spinner; a second call adds none | playwright | the mounted action | one spinner, action hidden | implemented | 2026-10-23 |
| SPA-SPINNER-07 | The spinner survives the action re-rendering, and `hide_spinner()` reveals it (a second hide is a no-op) | playwright | `show_spinner()`, `render()`, `hide_spinner()` x2 | spinner still there after render; gone after hide | implemented | 2026-10-23 |
| SPA-SPINNER-08 | Placement: the stage spans the action from its top edge to the lower of its bottom edge and the window's | playwright | `show_spinner()` on the mounted action | stage top/bottom equal the expected edges | implemented | 2026-10-23 |
| SPA-SPINNER-09 | A dispatch made during a hold removes the old spinner, which sits outside the emptied content area | playwright | `show_spinner()`, then `Spa.dispatch()` | spinner gone on the new page | implemented | 2026-10-23 |
| SPA-SPINNER-10 | An unhandled exception during a hold reveals the page (`Spinner_Overlay.hide_all()`) | playwright | `/_sys/workers` held, `Exception_Handler.display_unhandled_exception()` | spinner gone, action visible | implemented | 2026-10-23 |

## Notes

- The title rows are deliberately probed through `Spa_Layout.resolve_page_title()` rather
  than by scraping the header after a navigation: the contract under test is the ORDER
  (synchronous static paint, asynchronous repaint), and only the callback sees the order.
  The header/document.title assertions then prove the layout actually consumed it.
- The test reads a real contact id off the rendered datagrid instead of assuming one, so it
  survives any reseed of the template app's data.
