---
name: navigation-guard
description: Warning the user before they leave a page with unsaved work, using Rsx.set_navigation_guard / Rsx.clear_navigation_guard / Rsx.has_navigation_guard - one callback slot consulted by SPA dispatch, plus the browser's native dialog for real page exits. Use when asked to warn on "navigate away", add a "leave page warning" or an "unsaved changes prompt", wire "beforeunload"/"onbeforeunload", "confirm before leaving", "block SPA navigation" or "are you sure you want to leave", or when a dispatch silently does nothing and the console says "prevented by the navigation guard".
---

# The navigation guard

An editor with unsaved work asks before the user leaves it. Three statics on `Rsx`,
available on every page, SPA or not:

```js
Rsx.set_navigation_guard(async (url) => boolean)   // register
Rsx.clear_navigation_guard()                       // discard
Rsx.has_navigation_guard()                         // is one registered?
```

## The three rules

1. **ONE SLOT, NOT A STACK.** The last `set_navigation_guard()` wins; a single
   `clear_navigation_guard()` discards the slot however many times it was set. So
   registering on every keystroke is harmless - it overwrites, it does not accumulate.
2. **An approved navigation CLEARS the guard.** A departure the user allowed cannot
   leave a stale prompt armed for the next page. A BLOCKED navigation leaves the guard
   registered, because the user is still on the page that had unsaved work.
3. **A real page exit gets the browser's native dialog.** Refresh, tab close, typed URL,
   external link: the browser asks, in its own wording, and your callback is NOT
   consulted. `set_navigation_guard()` installs that listener itself - you never write
   `addEventListener('beforeunload', ...)`. On a server-rendered Blade page this native
   dialog is the entire feature.

## You write the dialog

The framework shows nothing of its own for SPA navigation and never references `Modal`.
The callback IS the prompt, and it must resolve **exactly `true`** to allow:

```js
Rsx.set_navigation_guard(async () => Modal.confirm(
    'Unsaved changes',
    'You have unsaved changes. Leave without saving?',
    'Leave',
    'Stay'
));
```

`Modal.confirm()` resolves true/false, which is exactly the contract.

## What it covers

Link clicks, Back/Forward, `Spa.dispatch()` and `Spa.redirect()` all reach
`Spa.dispatch()`, the single navigation choke point, so ONE registration covers all four.
The callback receives the target URL. A programmatic navigation that must not be asked
about passes `{ skip_navigation_guard: true }`; popstate never does, because Back/Forward
is always subject to the guard.

A blocked navigation logs:

```
[Rsx] Navigation to /contacts was prevented by the navigation guard.
```

That is the line to grep for when a dispatch appears to do nothing.

## Reference example

`system/app/RSpade/resource/reference_app/app/frontend/contacts/edit/Contacts_Edit_Action.js`
arms the guard when its `<Rsx_Form>` fires `'input'` and clears it on `'submitted'`.

## Common mistakes

- **Forgetting to clear on save.** The form's `'submitted'` event is the clear. Without
  it the user saves, navigates, and is asked about changes that are already stored.
- **Resolving a truthy non-boolean.** `'yes'`, `1` and a resolved object all BLOCK. Only
  `true` allows.
- **Expecting custom wording in the native dialog.** Browsers render their own text for
  beforeunload and ignore any string. Custom copy exists only on the SPA path.
- **Letting the callback reject.** A rejection is not caught - it propagates as an
  unhandled error. Answer the question; do not throw it.
- **Writing your own `beforeunload` listener beside the guard.** Two listeners, two
  dialogs. `set_navigation_guard()` already installed one.

Contract: `rsx:man spa`, THE NAVIGATION GUARD.
