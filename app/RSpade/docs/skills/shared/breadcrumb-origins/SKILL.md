---
name: breadcrumb-origins
description: "Giving an SPA page an alternate breadcrumb parent when it is reached from another record - the reserved _via parameter, Spa.via_push(), static breadcrumb_via + breadcrumb_parent_via(via) verifying the claim, carry and strip (across full page loads too), Rsx.navigate()'s {via: 'drop'|'continue'} and a form's redirect_via, new tabs, nesting, the meta {grafted, root_url} a sidebar follows, and crumbs returning to the state a page was left in (the chain item's href, Spa.return_url(), the _scroll hash key, Spa_Journal). Use when a crumb, Back or Cancel link resets a list's sort, filters, page or scroll, when a page crumbs to the wrong parent after a detour or loses its origin after a create page's Save, when replacing a from= / return_to= / parent_* argument, on SPA-VIA-01, or when _via vanishes from the URL."
---

# Breadcrumb Origins

Breadcrumbs say where a page LIVES: every action names one parent in `breadcrumb_parent()`.
Some pages legitimately live in two places. An invoice lives under Billing, and while you are
working inside an engagement it lives under that engagement too. An **origin** lets the link
that starts that detour say so, and lets the page where the trail splits decide.

## The three moving parts

1. **The link that starts the detour pushes an origin.**
   ```js
   Rsx.navigate(Rsx.Route('Billing_Invoice_Builder_Action',
       {engagement_id: 156, _via: Spa.via_push('engagement', 156)}));
   ```
   `Spa.via_push(type, id = null)` returns a `_via` VALUE: this origin in front of the current
   page's list, capped at 4. It throws on a type outside `[a-z][a-z0-9_]*` or an id outside
   `[A-Za-z0-9_-]+`.
2. **The page where the trail splits accepts it, and VERIFIES it.**
   ```js
   static breadcrumb_via = ['engagement'];
   async breadcrumb_parent_via(via) {                  // via = {type, id} - id is a string
       await this.await_loaded();
       if (this.data.invoice.engagement_id !== int(via.id)) return null;   // decline a false claim
       return Rsx.Route('Engagements_View_Action', via.id) + '#tab=billing';
   }
   ```
   `breadcrumb_parent()` stays as the page's home parent. The pair is one declaration -
   declare both in the same class, or the manifest build fails (`SPA-VIA-01`). Both are
   inherited; a subclass that re-declares one re-declares the other.
3. **Everything else is automatic.**
   - Pages BELOW the deciding page (the builder, the invoice page's subpages) need no code.
     Their normal parent leads to the decider, and the walk passes the origin up to it.
   - Every navigation from a page with `_via` carries it onto a route of this site
     (`Rsx.navigate()`, `Rsx.redirect()`, link clicks - one door, `rsx:man navigation`) - a
     full page load included, by a one-use session-storage hand-off the next document reads at
     boot - and a link's href is rewritten on mousedown / middle-click / context menu, so new
     tabs and "Copy link" keep it.
   - After the new page's trail is walked, `_via` is KEPT when the same page decided it as
     before (or the URL named it explicitly - a link, a reload, a pasted URL, a new tab), and
     otherwise removed from the address bar with `replaceState` (no history entry); the page
     then shows its plain trail. Nothing is painted before that decision.

## Rules

- **Push an origin only where a detour STARTS.** Never re-add `_via` to ordinary links;
  carrying is the framework's job, and a hand-copied `_via` is an explicit claim that defeats
  the strip.
- **A decider always verifies.** The claim arrives in a URL, so anyone can type it. Check it
  against the page's own data and return `null` when it does not hold. A bad `_via` is then
  ignored and stripped, never believed.
- **Never read `this.args._via`** - it is never there (FATAL `SPA-VIA-01`). The hook receives
  its token; a layout reads `Spa.via()` (`[{type, id}, ...]`).
- **Never put a URL in an origin**, and never invent `from=` / `return_to=` / `parent_*` to
  steer a crumb. A URL is an injectable parent, and an invented parameter does not follow the
  user to subpages. A route parameter named `_via` is FATAL.
- **Pre-filling a form is not an origin.** `engagement_id` on New Invoice is data and stays its
  own argument. `_via` only steers the trail; a link that starts a detour often carries both.
- **A parent must not point back into the subtree.** The walk stops at the repeat with a
  warning (and at 20 pages deep).
- **A create page that hands over to the record it created navigates with
  `Rsx.navigate(url, {via: 'continue'})`; nothing else does.** On an `Rsx_Form`, the create
  endpoint answers `'redirect_via' => 'continue'` beside its `redirect` and the form passes it
  as the `{via}` option. The record page must also declare the origin type
  (`static breadcrumb_via`) and verify it in `breadcrumb_parent_via()` against the record, or the
  origin is stripped. The `{via}` values are `'carry'` (default), `'drop'`, `'continue'`;
  anything else throws.
- **Leaving the subtree on purpose from code** is `Rsx.navigate(url, {via: 'drop'})` (or
  `Rsx.redirect`). Without it, a navigation to the page already showing carries the origin
  back on, which is exactly the URL on screen - a reload.

## Nesting

`_via` is an ordered list, nearest origin first (`engagement.156,contact.42`), at most 4
tokens (the oldest are dropped). `Spa.via_push()` puts the new origin in front of the current
page's list, so origins the user actually traversed nest by themselves:

- walking up, each decider takes the first token and passes the rest to its parent;
- a decider that can DERIVE a further origin from its own data returns a parent URL with its
  own `_via`, which goes in front of the remainder.

Keep/strip is judged on the FIRST token's decider only.

## Sidebar

The resolver's `on_chain(chain, meta)` / `on_complete(chain, meta)` carry
`meta = {grafted, root_url, decider}`, and every layout in the chain receives
`on_breadcrumb_complete(chain, meta)` (a `Spa_Layout` hook, empty by default) when the trail
completes. A grafted page lights the section its TRAIL starts in: the template's
`Sidebar_Nav.follow_trail(meta)` matches `meta.root_url` first and falls back to the current
URL. An app with its own sidebar does the same from `on_breadcrumb_complete()`.

## Returning to the state you left

A crumb's `href` (not its `url`) is the link to render. For a crumb above the current page it
is that page in the state the user LEFT it in - its fragment (a grid's sort, filters, page, a
tab) plus the one-time scroll key `_scroll=<y>` - when they came through it on this trail:
the nearest earlier entry of this tab's journey that is that page, reached without passing a
page whose trail did not show it. Otherwise it is the bare route. The SPA keeps the per-tab
journal (`Spa_Journal`, session storage) by itself; nothing to declare.

- **A layout renders `crumb.href`**, and compares / caches on `crumb.url`.
- **A Back / Cancel button or a deleted record's exit** to a page on the trail uses the same
  answer: `Spa.return_url(Rsx.Route('Clients_Index_Action'))` (synchronous; the URL unchanged
  when the rule does not hold).
- **A list's fragment keys must be STABLE** - a name, never a component id - or the grid of
  the next visit does not read them back (the template grid uses its component name,
  `$hash_ns` to override).
- **`_scroll` is reserved**: never write it while scrolling, never use it for page state. The
  SPA removes it from the address before the entry is written and applies it through the Back
  restoration (which yields to the user's own scroll).
- **A component's first load must not move the page** (no scroll-to-self on load), or it
  undoes the restoration - and Back's too.

## Checking it

- `rsx:debug '/billing/invoices/123?_via=engagement.156' --user=1`: the trail ends at the
  engagement's Billing tab, and `--eval="return location.search"` still shows the `_via`.
- With an invoice that does not belong to engagement 156: the plain trail, and `_via` gone
  from the URL.
- Returning state: sort/filter/scroll a list, open a record, then
  `--eval="return $('.Breadcrumb_Nav a').first().attr('href')"` shows the list's fragment and
  `_scroll`; `Rsx_Storage.session_get('spa_journal')` shows the journal.
- `console_debug` channel `Spa` says why a `_via` was stripped (malformed, nobody accepted
  the first origin, a different decider).

Details: `rsx:man breadcrumb_origins`.
