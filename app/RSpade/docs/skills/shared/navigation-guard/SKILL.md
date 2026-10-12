---
name: navigation-guard
description: Having the browser ask before the user leaves a page with unsaved changes, using the Rsx.set_navigation_guard() / Rsx.clear_navigation_guard() / Rsx.has_navigation_guard() toggle - on when the page becomes dirty, off when the change is saved or discarded. Use when asked to warn on "navigate away", add a "leave page warning" or an "unsaved changes prompt", wire "beforeunload"/"onbeforeunload", "confirm before leaving" or "are you sure you want to leave", when a page asks "Leave site? Changes you made may not be saved." on every exit, when a guarded link click reloads the whole page, or when the console says "Rsx.set_navigation_guard() takes no argument: the navigation guard is a toggle, not a callback".
---

# The navigation guard

A page with changes that would be lost says so, and **the browser asks** before the user
leaves it. The guard is a toggle - three statics on `Rsx`, available on every page, SPA or
not:

```js
Rsx.set_navigation_guard()     // on  - takes NO argument
Rsx.clear_navigation_guard()   // off
Rsx.has_navigation_guard()     // boolean
```

There is no callback and no dialog to write. One slot, idempotent. Passing ANY argument
to `set_navigation_guard()` throws:

```
Rsx.set_navigation_guard() takes no argument: the navigation guard is a toggle, not a callback. Turn it on when the page has unsaved changes and off with Rsx.clear_navigation_guard() when they are saved or discarded. The browser asks the user; there is no callback. See rsx:man spa, THE NAVIGATION GUARD.
```

## What asks, while the guard is on

| The user... | What happens |
|---|---|
| clicks a link (`<a href>`) | The SPA does NOT intercept it. The browser navigates for real and raises its native unload dialog. Stay: nothing changed. Leave: **a full page load**. |
| refreshes, closes the tab, types a URL, goes Back/Forward out of the document | The same native unload dialog. |
| goes Back/Forward to another page of the SPA | Nothing unloads, so the SPA raises the browser's native **confirm** box. OK: the guard goes off and the move is an ordinary in-SPA navigation. Cancel: nothing is dispatched, the address is put back, the page is untouched and the guard stays on. |
| goes Back/Forward over a `#fragment` only | Not a departure: `spa_hash_change` fires, nobody is asked. |
| (your code) calls `Spa.dispatch()` / `Spa.redirect()` | **Not guarded.** The dispatch goes ahead in the SPA with no prompt and turns the guard off. |

With the guard off, links are intercepted again from the very next click and nothing asks.

## What the dialogs say, and what cannot be changed

- The unload dialog is entirely the browser's: its wording cannot be set by a page.
- The confirm box carries the same wording - `Leave site?` / `Changes you made may not be
  saved.` - under a heading and OK / Cancel buttons that are the browser's too.
- **A browser raises the unload dialog only once the user has interacted with the page**
  (a click, a key press). A page nobody touched unloads without asking. In a test, click
  or type first.
- Confirming a LINK is a full page load; confirming BACK stays in the SPA. Both are
  correct - a link was handed to the browser, a history move never left the document.

## The recipe: the guard follows the page's state

On at the first change, off when the change is saved, off when it is discarded, off when
the page is torn down.

```js
on_ready() {
    const form = this.$.find('.Rsx_Form').first().component();
    form.on('input', () => Rsx.set_navigation_guard());        // the user changed something
    form.on('submitted', () => Rsx.clear_navigation_guard());  // saved
}

discard_changes() {                 // <a href=... @click=this.discard_changes>Cancel</a>
    Rsx.clear_navigation_guard();   // the link then navigates in the SPA as usual
}

on_stop() {
    Rsx.clear_navigation_guard();   // a page that is gone has nothing to lose
}
```

- **`'input'` on `<Rsx_Form>` is the "something changed" signal.** It fires for an edit
  the user makes and never for values applied from loaded data (`$data`, `vals(object)`,
  `populate()`), so an edit form filling itself does not turn the guard on. It also fires
  again for a value typed and then typed back - the guard stays on until save or discard.
- The form engine never sets or clears the guard itself. A page opts in.
- A re-render replaces the form component: wire the instance you are given, once per
  instance.
- A Cancel LINK needs the `@click` above; without it the link is a guarded link and the
  browser asks about changes the user just chose to throw away.

## Navigation from code is not guarded

A Save handler that dispatches to the record, a row click, a button that calls
`Spa.dispatch()`: none is asked about, and each turns the guard off. If a button should
not lose the user's changes silently, do not navigate from it while
`Rsx.has_navigation_guard()` is true - ask with your own `Modal.confirm()` first. A
dispatch to the page already showing (same path and query) is not a departure and leaves
the guard as it is.

## Reference examples

Three add/edit pages in the reference app use the recipe as written; the first carries
the full commentary:

- `system/app/RSpade/resource/reference_app/app/frontend/contacts/edit/Contacts_Edit_Action.js`
- `system/app/RSpade/resource/reference_app/app/frontend/tasks/edit/Tasks_Edit_Action.js`
- `system/app/RSpade/resource/reference_app/app/frontend/projects/edit/Projects_Edit_Action.js`

## Common mistakes

- **Turning the guard on at page load.** It then asks on every exit, changed or not. On
  at the first change.
- **Forgetting to turn it off on save.** The form's `'submitted'` event is the clear.
  Without it the user saves, clicks a link, and is asked about changes already stored.
- **No `on_stop()` clear.** A guard left on belongs to no page and asks on every exit
  from whatever page came next.
- **Passing a function.** It throws; there is no callback. Delete the leave-dialog.
- **Expecting custom wording, or a styled dialog.** Both dialogs are the browser's.
- **Writing your own `beforeunload` listener beside the guard.** Two listeners.
  `set_navigation_guard()` installs the one there is.
- **Expecting a prompt in a page nobody touched.** The browser will not raise one.

Contract: `rsx:man spa`, THE NAVIGATION GUARD.
