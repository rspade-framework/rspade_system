# spa test catalog

All implemented rows live in `playwright/spa_action_title.js` (one browser session over the
template app's `/contacts` and `/contacts/view/:id`). The decorator half - `@title` surviving
the transform as `_spa_title` - is JT-03f in the `js_transform` concern.

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

## Notes

- The title rows are deliberately probed through `Spa_Layout.resolve_page_title()` rather
  than by scraping the header after a navigation: the contract under test is the ORDER
  (synchronous static paint, asynchronous repaint), and only the callback sees the order.
  The header/document.title assertions then prove the layout actually consumed it.
- The test reads a real contact id off the rendered datagrid instead of assuming one, so it
  survives any reseed of the template app's data.
