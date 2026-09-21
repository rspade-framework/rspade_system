# spa test catalog

The title rows live in `playwright/spa_action_title.js` (one browser session over the
template app's `/contacts` and `/contacts/view/:id`); the guard rows live in
`playwright/navigation_guard.js` (one session over the framework's own `/_sys` panel, so
it depends on no application code). The decorator half - `@title` surviving the transform
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
| SPA-GUARD-01 | A guard resolving `false` blocks the dispatch, and the block is announced on the console | playwright | `/_sys` -> `/_sys/tasks` with a false guard | URL unchanged; a warning containing `prevented by the navigation guard` | implemented | 2026-09-21 |
| SPA-GUARD-02 | A BLOCKED navigation leaves the guard registered - only an approved one clears it | playwright | same dispatch | `has_navigation_guard()` still true | implemented | 2026-09-21 |
| SPA-GUARD-03 | A guard resolving `true` allows the dispatch, and the navigation it approved clears it | playwright | `/_sys` -> `/_sys/tasks` with a true guard | landed on the target; `has_navigation_guard()` false | implemented | 2026-09-21 |
| SPA-GUARD-04 | ONE SLOT, NOT A STACK: three registrations leave only the last callback, and one clear discards three sets | playwright | three `set_navigation_guard()` + `navigation_guard_allows()`; then three sets + one clear | only the third callback invoked, once; no guard after the single clear | implemented | 2026-09-21 |
| SPA-GUARD-05 | A real page exit raises the browser's native `beforeunload` dialog while a guard is set, and nothing when none is | playwright | `page.close({runBeforeUnload:true})`, with and without a guard | a `beforeunload` dialog, then no dialog at all | implemented | 2026-09-21 |

## Notes

- The title rows are deliberately probed through `Spa_Layout.resolve_page_title()` rather
  than by scraping the header after a navigation: the contract under test is the ORDER
  (synchronous static paint, asynchronous repaint), and only the callback sees the order.
  The header/document.title assertions then prove the layout actually consumed it.
- The test reads a real contact id off the rendered datagrid instead of assuming one, so it
  survives any reseed of the template app's data.
