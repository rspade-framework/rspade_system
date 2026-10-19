---
id: reload_vs_refresh
title: refresh() where the server hints, reload() where the user acts
severity: low
when: change, periodic
scope: rsx/**/*.js, rsx/**/*.jqhtml
related: rsx:man jqhtml, rsx:man realtime, skill rspade:jqhtml, skill rspade:realtime
---

## The contract

A component refetches with `refresh()` when the trigger is the SERVER saying something may have changed (a realtime callback, a poll, an ambient event), and with `reload()` only when its arguments changed or the user's own action must land unconditionally. `refresh()` is `reload(false)`: the same fetch, but it repaints only when `this.data` actually changed. `reload()` always repaints after the fetch, which destroys child DOM, child state and bound handlers, closes open menus and resets scroll and selection. A `reload()` on a server hint makes a busy page strobe and throws away what the user is in the middle of.

Read `php artisan rsx:man jqhtml` (reload()/refresh()) and skill `rspade:realtime` (the refresh() rule of thumb) first.

## Find every instance

    grep -rnE "\.reload\(" rsx --include='*.js' --include='*.jqhtml' | grep -v "location\.reload"

For each hit, read the enclosing method and find what calls it: a `this.subscribe(...)` / `Rsx_Realtime.watch(...)` callback, a timer, an event from another component (`.on('...')`), a button or form handler, a changed argument.

## What does not conform

- `reload()` inside a realtime subscription callback, a poll or interval, or a handler for an ambient event the user did not cause - should be `refresh()`.
- `reload()` on, or on a host around, a live-stream (Standalone tier) component - a terminal, log tail or transcript built without `on_load()`. Either call rebuilds it from nothing; such a component appends instead.
- A `reload()` paired with caching or fetch suppression to stop a strobe - `refresh()`'s data comparison is the fix.
- A method named `reload` or `refresh` defined on a component as a wrapper - both are reserved names.

Not findings:

- `reload()` after the user's own save or action (the post-save editor pattern), or after `this.args` changed (filter switched, record id swapped).
- `reload()` or `refresh()` on a component with no custom `on_load()`: both are a plain re-render there and the choice changes nothing.
- A surviving `reload()` that carries a comment stating why the DOM must be rebuilt.
- `window.location.reload()` - a page reload, not this API.

Keep in mind when judging: a quiet `refresh()` (data unchanged) does not run `on_ready()` again, so code that relies on `on_ready()` re-running after a refetch is a reason a `reload()` may be deliberate - read it before reporting.

No rule enforces this; every hit is judged by hand.

## Report

`PASS - <one line: N reload() calls read, all justified>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
