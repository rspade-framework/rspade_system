---
name: spinners
description: "Showing a loading spinner with the one registered component - <Spinner />, $(el).component(Rsx.get_default_spinner()), Rsx.set_default_spinner() from a static on_app_modules_define(), Rsx_Default_Spinner, the host as a centering stage with no size arguments, @spinner_until_ready('uncached'|'always'), show_spinner() / hide_spinner(), Modal.form spinner_until_ready. Use when a page, region or dialog needs a loading indicator, a spinner is the wrong size, or a registered spinner does not appear on the first SPA page."
---

# Spinners

There is ONE spinner in an application: the component registered in the spinner registry. Everything that needs one - a region loading, the form overlay, a page held until ready - draws that component. **Never hand-roll spinner markup** (a `<div class="spinner-border">`, a CSS animation of your own) and **never pass a size** - there are no size arguments anywhere. Contract: `rsx:man form_conventions` (THE SPINNER REGISTRY), `rsx:man spa` (SPINNER UNTIL READY).

## Drawing one

```javascript
$(element).component(Rsx.get_default_spinner());   // turn a host element into the spinner
```

```html
<Spinner />                                           <%-- template entry point, a 60x60 box by default --%>
```

**The host owns the box, and the box is a CENTERING STAGE, not a scale target.** The host element says WHERE the spinner goes and how much room it has; resize the HOST with ordinary CSS (the form overlay hosts one at 150px). The REGISTERED component decides whether it fills that box or draws at a fixed size centered inside it.

## Registering the application's spinner

```javascript
class App_Spinner extends Component {
    static on_app_modules_define() { Rsx.set_default_spinner('App_Spinner'); }
}
```

- Register from a **static `on_app_modules_define()`** - the first boot phase. The first SPA page draws its spinners before `app_init`/`app_ready`, so a registration made in a later phase silently misses that page.
- Register once. `Rsx.get_default_spinner()` answers the registered component's name.
- Until an app registers one, the framework's **`Rsx_Default_Spinner`** is used: a small rotating circle at a fixed 24px, centered in its host, in `currentColor` - so a fresh app has a working spinner before it has designed one.

## A page or dialog hidden until it is ready

Never hand-roll "hide until loaded" state (a loading overlay, a `settled` flag, re-seeding a form in `on_ready()`).

```javascript
@route('/clients/view/:id')
@layout('Frontend_Spa_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
@spinner_until_ready('uncached')
class Clients_View_Action extends Spa_Action { ... }
```

| Mode | Holds | For |
|---|---|---|
| `'uncached'` | only when this action, with these args, has never reached ready in the current cache scope (a revisit paints from the component cache at once, which a spinner would only hide) | a page that SHOWS data - a CRUD view page |
| `'always'` | every visit; the hidden tree also skips cache reads | a page never to be seen before its data is final - a CRUD edit page, where a cached form could be typed into |

The mode is required. Ready is reached bottom-up, so the action's ready means its whole tree is ready. The spinner is centered over the visible part of the action, survives the action's re-renders, and is removed at ready, by `hide_spinner()`, at the next navigation, or when an error screen replaces the action.

**An action's own wait** uses the two methods the decorator is built on:

```javascript
async save_and_continue() {
    this.show_spinner();
    try { await My_Controller.rebuild({id: this.args.id}); }
    finally { this.hide_spinner(); }
}
```

**A form dialog** passes `spinner_until_ready: 'always'` to `Modal.form()`, so the body stays hidden until its record is loaded (app skill `modals`; skill `rspade:form-engine`). A form whose values arrive after it is SHOWN uses `form.populate(promise)`, whose loading overlay draws the same registered spinner.
