---
id: async_continuations_check_stopped
title: Every wait outside on_load() goes through alive(), so nothing resumes on a stopped component
severity: medium
when: launch, change
scope: rsx/**/*.js
related: rsx:man jqhtml, skill rspade:jqhtml, conformance jqhtml_component_design
---

## The contract

Code that resumes after an `await` - or in a `.then()` callback or a timer - runs outside every lifecycle hook, and by the time it resumes the component may have been STOPPED: the user navigated away, a parent re-rendered, a dialog closed. jqhtml cannot cancel that continuation, but it can decline to resume it. So every wait outside `on_load()` whose continuation acts on the component goes through `this.alive()`, which never settles once the component has stopped:

    const res = await this.alive(Tasks_Controller.resolve_parent_project({...}));
    this.sid('project_select').val(res.project_id);

The one alternative is the explicit `is_stopped()` test, for code that must KEEP GOING after a stop - a download the user asked for, a value a caller is awaiting, a cleanup that must run. Such code awaits the plain promise and tests `if (this.is_stopped()) return;` at the point where the component-specific part begins. Either form conforms; the explicit form needs that reason to be visible in the code.

Unguarded, a child handle on the stopped component is `null` (`this.sid()`, `this.$sid(...).component()`) and the next method call throws a TypeError; `reload()`, `refresh()` and `load()` reject; and a page-level effect lands on whatever page the user is now on. An unhandled exception also switches the SPA to full page loads (`Rsx._handle_unhandled_exception()`), so one late response degrades navigation for the rest of the visit. Nothing in a test or a manual click-through catches it - it needs a navigation timed inside a round trip - which is why it is audited.

`on_load()` is NOT in scope, and neither `alive()` nor `is_stopped()` may be called there - both THROW. `on_load()` may be deduplicated (identical components loading at the same moment share one run of it), so inside it `this` is not one component and "stopped" has no answer; nor is a guard needed, since jqhtml abandons the result of a load whose component was stopped, and DOM and `this.state`/`this.args` writes already throw there. `await child.ready()` and `await child.rendered()` ARE in scope: both also resolve when the child, or this component, is stopped.

Read `php artisan rsx:man jqhtml` (ASYNC CONTINUATIONS) and skill `rspade:jqhtml` (rule 28, and `reference/lifecycle-and-state.md`, "Code that resumes after an await") first.

## Find every instance

List the component classes that wait on anything:

    grep -rlE "\bawait\b|\.then\(|setTimeout\(|setInterval\(" rsx --include='*.js' | grep -v '^rsx/tests/' | xargs grep -lE "extends (Component|Spa_Action|Spa_Layout|[A-Za-z_]+_Abstract)\b"

In each, read every method EXCEPT `on_load()` that contains an `await`, a `.then(` callback, a `setTimeout`/`setInterval` callback or a `debounce()`'d function: lifecycle hooks (`on_ready()`, `on_loaded()`, `on_render()`), event handlers, and helpers - including a helper started without being awaited (`this._refresh(x);` from `on_ready()` or a handler is the classic case). For each continuation, find the first statement after the wait that acts on the component (the list below) and check that the wait went through `this.alive()`, or that an `is_stopped()` test precedes the action after the LAST wait before it - every `await` in a sequence is its own resume point. A `.then()` callback, a timer or a debounced function that acts on the component tests `is_stopped()` at its top (or awaits through `alive()` inside).

## What does not conform

A continuation that, after a wait NOT made through `this.alive()` and with no `is_stopped()` test since that wait:

- **uses a child component handle** - `this.sid(...)`, `this.$sid(...).component()`, `$(...).component()` inside `this.$` - and calls a method or reads a property on it;
- **re-renders or refetches** - `this.reload()`, `this.refresh()`, `this.render()`, `this.load()`, or the same on a child;
- **starts a page-level effect** - `Spa.dispatch()` / `Spa.redirect()` (it would move a user who has already gone elsewhere), `Modal.*`, `Flash_Alert.*`, `Rsx.set_navigation_guard()`, `window.location` changes;
- **subscribes, binds or triggers** - `this.subscribe()`, `.on(...)` on a child, `this.trigger(...)`.

Fix: wrap the wait - `await this.alive(<the promise>)`. Where the code must keep going after a stop, keep the plain await and insert `if (this.is_stopped()) return;` before the component-specific part, with a short comment saying what must still happen.

Also findings:

- **`alive()` or `is_stopped()` inside `on_load()`** - it throws at run time; delete it.
- **An `is_stopped()` test where `alive()` would do** - a plain `await x; if (this.is_stopped()) return;` with nothing in between that must survive a stop. Fix: `await this.alive(x);`.
- **A hand-rolled stopped flag** (`this._stopped = true` in `on_stop()`, or any read or write of jqhtml's internal `_stopped`). Fix: `alive()` / `is_stopped()`.

Not findings:

- `on_load()` bodies without either call.
- `page_title()`, `breadcrumb_label()` and similar overrides that `await this.await_loaded()` and then only READ `this.data` / `this.args` and return a value - reading a stopped component is harmless and the caller discards the answer.
- A continuation that touches nothing on the component (it works on locals, statics or a model it then returns).
- A continuation whose only touches are jQuery reads or writes on `this.$` / `this.$sid(...)` with no `.component()` - inert on a stopped component's element. `alive()` is still welcome there.
- A continuation in a component that is never stopped while the page lives (the persistent top-level layout), when a comment says so.
- An `is_stopped()` test after work that must survive a stop (a download, a value returned to a caller, recovery codes shown once), when that work sits between the wait and the test.

## Report

`PASS - <one line: N continuations read in M classes, all guarded or exempt>`, or one finding per line:

    file:line - what the continuation touches unguarded (or the misuse) - the conforming fix

No other prose.
