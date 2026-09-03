---
name: view-decomposition
description: "Breaking an over-long view page into REGION components - the ~325-line template / ~80-line tab-body trigger, the seam test (markup and the handlers it raises move together), the flat file layout in the feature's own view/ directory with _tab_<name> bodies, the eager-vs-lazy data rule (the action loads everything eager in ONE parallel batch and passes it down; a region self-loads only what may never be opened), and the sharp edges - refresh() re-running only the action's on_load, skeletons from explicit args, content handed to a child resolving against its definer, and one-shot bind flags. Use when a .jqhtml view template pushes past ~325 lines or a single tab body past ~80, when a page file is called unmaintainable or nobody can see its shape, when extracting regions or deciding whether a payload is passed down or self-loaded, when a region reaches Spa.action() from inside a Slot body, or when choosing between region decomposition and a per-variant content split."
---

# Decomposing an over-long view page

A big view template is not a code-quality failure - it lints clean, it renders, it is
well commented. It fails a different test: **opening it tells you nothing about what the
page is made of** until you have scrolled all of it. Decomposition is the fix, and it is a
convention with a number so "is this too long?" stops being a matter of taste.

The worked example ships in the reference app:
`system/app/RSpade/resource/reference_app/app/frontend/clients/view/` (that path resolves
in this monorepo and in a release). Read `Clients_View_Action.jqhtml`'s header comment
first - it states the invariants in place.

## The trigger

- **A view template over ~325 lines gets decomposed.**
- **Any single tab body over ~80 lines gets its own region**, even in a shorter page.

Neither is a lint rule; both are conventions. The number exists so the decision is made
once, by the number, rather than re-argued per page.

## The seam test

**Regions are the page's own visible seams** - tab bodies, an identity header, a detail
sidebar - never invented slices of markup.

The decisive evidence that a seam is real is **not the markup: it is that the companion JS
partitions the same way.** Before splitting, list the action's private methods and `sid()`
targets and assign each to a region. If nearly all of them land inside one region or
another, those are the seams. The few that do not - the tab bar, count badges, anything
crossing two regions - stay on the shell, deliberately.

> **Markup and the handlers it raises move TOGETHER.** A split that leaves the parent
> reaching into the child's DOM by `sid()` has moved the problem, not solved it. If a
> region's handlers cannot follow its markup, that region is not a seam.

In the shipped example `Clients_View_Sidebar` carries its own `.js` with the
enable/disable-portal, delete and restore handlers. When a region MUTATES the record it
fires a **component event** (`client_changed`) and the shell decides to `reload()` - so the
one fetch of the record stays in one place and the region never repaints the page.

The cross-region case is on the shell for the same reason: the `.Kpi_Cell--clickable`
delegate lives in `Clients_View_Action.js` because the cells are in the sidebar and the
`Tab_Bar` they drive is in the main column.

### Region decomposition is not the variant split

A different, equally legitimate move: a view whose SUCCESS state has two different layouts
by RECORD VARIANT (company vs person, say) delegates from a thin action to one content
component per variant. That solves "two layouts"; it does not solve "one layout that is too
big" - and a variant content component that is itself 400+ lines is then a candidate for
region decomposition inside itself. Ask which problem you have before choosing.

## File layout

Flat, in the feature's own `view/` directory - the existing naming convention applied per
region rather than per page:

```
rsx/app/frontend/clients/view/
├── Clients_View_Action.jqhtml       the shell - a table of contents
├── Clients_View_Action.js           the load, cross-cutting wiring, titles/breadcrumbs
├── clients_view_action.scss
├── clients_view_tab_overview.jqhtml + .scss
├── clients_view_tab_contacts.jqhtml
├── clients_view_tab_projects.jqhtml
├── clients_view_tab_activity.jqhtml
└── clients_view_sidebar.jqhtml + .js
```

- snake_case file, PascalCase `<Define>` - the ordinary jqhtml rule.
- **`_tab_<name>`** marks a body of this page's tab bar; every other region takes a plain
  region name (`_sidebar`, `_identity`).
- **A `.js` or `.scss` exists only where that region has behavior or style.** Four of the
  five regions above have neither. The one `.scss` exists because that region now stands
  between `Tab_Panel` and the Sections it stacks, inheriting the container's gap duty.
- **Regions are page-private.** They live beside their page, are named after it, and never
  migrate to `rsx/theme/`. A region that later earns reuse gets PROMOTED - that is the
  existing extract/lever/promote guidance (`rspade:jqhtml` reference
  `semantic-composition.md`, "Extract, lever, or promote"; `rsx:man semantic_composition`),
  and the evidence bar is two or more live call sites. If two pages want the same region,
  it was vocabulary all along.

### The shell reads as a table of contents

Abridged from the shipped example - every visible seam is one named line:

```jqhtml
<Page_Scaffold>
    <Slot:main>
        <Card_Widget>
            <Entity_Header> ... </Entity_Header>
            <Tab_Bar $sid="tabs" $tabs=_tabs $hash="tab" $divided=true />
            <Tab_Panels $sid="panels">
                <Tab_Panel $key="overview"><Clients_View_Tab_Overview $client=_client /></Tab_Panel>
                <Tab_Panel $key="contacts"><Clients_View_Tab_Contacts $client_id=_client.id $contacts=_contacts /></Tab_Panel>
                <Tab_Panel $key="projects"><Clients_View_Tab_Projects $client_id=_client.id $projects=_projects /></Tab_Panel>
                <Tab_Panel $key="activity"><Clients_View_Tab_Activity $activity=_activity /></Tab_Panel>
                <% if (_client.portal_enabled) { %>
                <Tab_Panel $key="portal"><Clients_Portal_Panel $client_id=_client.id ... /></Tab_Panel>
                <% } %>
            </Tab_Panels>
        </Card_Widget>
    </Slot:main>
    <Slot:sidebar>
        <Clients_View_Sidebar $sid="sidebar" $client=_client $contacts_count=_contacts.length ... />
    </Slot:sidebar>
</Page_Scaffold>
```

Give the shell a header comment listing the regions and saying which data is eager and
which is lazy. That comment is the page's index; the reference app's is the model.

## The data rule

This is the part that costs the most time when it is unwritten:

> **The action loads everything EAGER, in ONE parallel batch, and passes it down. A region
> self-loads only what is LAZY - its own payload, fetched no earlier than first
> activation.**

**Consumer count is the wrong axis; eager vs lazy is the right one.** "Never pass a prop
only one child reads" sounds principled and is wrong for eager data: **a region's
`on_load()` is a full round-trip AFTER the parent's**, so pushing a default-visible tab's
payload down into that tab adds a serial hop - reintroducing, one level down, exactly the
sequential-load problem the parallel-load rule exists to prevent.

Passed down:

- the entity the page is about;
- anything two or more regions read (in the shipped example the counts render in the tab
  bar AND the sidebar KPIs before any tab is opened);
- **any eager single-consumer payload.**

Self-loaded:

- a payload that is genuinely deferrable - a tab that may never be opened at all. In the
  shipped example only `Clients_Portal_Panel` self-loads: its tab exists only for a
  portal-enabled client.

The batch itself - `Promise.all`, per-branch `.catch()` on every NON-FATAL load, the page's
subject record left uncaught, and the arguments-not-results test for when sequencing is
genuine - is the **PARALLEL LOADS IN ON_LOAD()** section of `rsx:man jqhtml` and the
`rspade:jqhtml` reference `lifecycle-and-state.md`. Do not restate it; follow it.

## Honest expectations

**Total line count GOES UP, by roughly 10-12%.** One `Define` wrapper, one file header and
one argument docblock per region cost more lines than the duplication the split removes.
Measured on the reference app's client view: ~348 lines in one template became ~390 across
six files; a downstream nine-region decomposition went 1,675 -> 1,837 (+10%) while its
largest file fell from 948 lines to 300.

The win is **per-file size and a navigable shell**, never total volume. Anyone promising a
shrink will be wrong, and the first reviewer to run `wc -l` will say so.

## Sharp edges

**`refresh()` / `reload()` re-run only the ACTION's `on_load()`.** A self-loading region is
outside that path, so it needs **its own realtime posture, decided deliberately when it is
created** - its own `this.subscribe(...)` in `on_create()`, or an explicit decision that
stale-until-reopened is acceptable. Inheriting the parent's posture by accident is the
failure mode.

**A region renders its skeleton from an explicit arg, never an inherited helper.** A
placeholder helper defined once at the top of a monolithic template does not survive the
split; pass `$loading` (or the payload plus a flag) and let the region render its own
skeleton. Note that a page using the three-state loading pattern (`<Loading_Spinner>` ->
`<Universal_Error_Page_Component>` -> content) only ever renders its regions with settled
data - the reference app works that way and its regions carry no loading branch at all.
That is a property of the page, not a licence to infer loading state.

**Content you hand to a child still resolves against the region that wrote it.** This
includes every `<Slot:>` body: `<%= %>` expressions, locals, `$sid` ids and handler
expressions (`@click=this.handler` runs against the definer) all follow the template the
markup is written in, never the component it is rendered inside. Full contract:
**DELEGATED HANDLERS** in `rsx:man jqhtml`, and pitfall P1 in the `rspade:jqhtml`
reference `semantic-composition.md`.

**Reaching `Spa.action()` from inside a slot to find your own component is the smell** -
a component going through the page to find itself. A region's slot bodies see the
region's own `this`, locals and `$sid` targets directly; write `this.method` and let the
markup stay where it reads.

**A parent repaint destroys region instances, so one-shot bind flags lie.** `if
(!this._wired) …` is wrong in both directions: the flag dies with the instance while the
handler lives on the surviving root element, so it double-binds on a repaint and suppresses
a rebind that was needed. The pattern is always one `this.$.off('.ns')` at the top of
`on_ready()`, then `this.$.on('click.ns', selector, …)`. What survives a repaint and what
does not is the **WHAT SURVIVES A RE-RENDER** table in `rsx:man jqhtml`.

**Component events between a region and its shell need no namespace and no `.off()`** - a
parent render destroys the child, so every `on_ready()` registers against a brand-new
instance that has fired nothing. DOM binds are the opposite; do not carry one habit into
the other.

## The checklist

1. **Enumerate the regions** from the rendered page - tab bodies, identity header, sidebar.
   Name each one before touching a file.
2. **Partition the companion JS** - assign every private method and `sid()` target to a
   region or to "cross-cutting". A region with no candidate handlers is fine; a handler
   with no obvious region means the seam is wrong.
3. **Move markup and its handlers together**, one region at a time. A region that mutates
   the record fires a component event upward; it does not repaint the page.
4. **Hoist the eager data, sink the lazy.** The action's `on_load()` becomes one
   `Promise.all`; a region gets an `on_load()` only when its payload may never be needed,
   and then it gets its own realtime posture too.
5. **Write the argument docblock** at the top of each region: every `$arg`, whether it is
   required, and who supplies it. Write the shell's table-of-contents header comment.
6. **Verify each region live** with `rsx:debug` before and after - render the page, open
   every tab, assert lazy-load transitions and cross-region navigation with `--eval`, and
   take before/after screenshots. Run `rsx:check` ONCE at the end.

## Pointers

`rspade:jqhtml` (references `lifecycle-and-state.md`, `semantic-composition.md`) ·
`rspade:realtime` · `rspade:rsx-debug` · `rsx:man jqhtml` ·
`rsx:man view_action_patterns` · `rsx:man semantic_composition` ·
reference app `app/frontend/clients/view/` and its feature `CLAUDE.md`
