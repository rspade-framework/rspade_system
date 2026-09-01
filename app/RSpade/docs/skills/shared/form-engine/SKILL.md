---
name: form-engine
description: "Building RSX forms on the framework's Rsx_Form engine - the $controller/$method declaration, form.submit(), server-only validation, the mandatory <Form_Errors />, set_loading()/populate(), dirty-value parking, and the Form_Utils error renderer. Use when creating or converting a form, wiring an endpoint to a form, writing the save() endpoint's validation, deciding where a form's loading state lives, or hitting \"no <Form_Errors /> in this form\", \"$controller and $method are required on the <Rsx_Form> tag\", or a form whose values arrive blank."
---

# The Rsx_Form engine

Template-side counterpart: app skill `form-components` (ships in `rsx/resource/skills/`).

`Rsx_Form` is the ONE form engine and it lives in framework core
(`system/app/RSpade/Core/Forms/`: `Rsx_Form.{js,jqhtml,scss}`, `Form_Errors.jqhtml`,
`Form_Input_Abstract.js`; the error renderer `Form_Utils.js` is in `Core/Js/`). An
application never carries a copy of any of them - they reach every bundle automatically.

Three words, used exactly:

| Word | What it is | Where it lives |
|---|---|---|
| **Form** | `Rsx_Form` - value state, dirty tracking, submission, error rendering | framework core |
| **Field** | chrome around one input: label, asterisk, help text. Purely presentational | the application (`form-components`) |
| **Input** | a `Form_Input_Abstract` subclass owning ONE named value | contract: `rspade:form-input-contract`; the roster: `form-components` |

**Always open an existing form before writing a new one.** Binding, population and error
placement are automatic rather than spelled out in markup, so copying a comparable form
beats reasoning the wiring out. The reference app's current worked example is
`system/app/RSpade/resource/reference_app/app/frontend/tasks/edit/Tasks_Edit_Action.{js,jqhtml}`.

## The declaration

```jqhtml
<Rsx_Form $controller="Frontend_Tasks_Controller" $method="save" $data=this.data.form_data>

    <% if (this.data.is_edit) { %>
        <Hidden_Input $name="id" />
    <% } %>

    <Form_Errors />

    <Form_Field $label="Title" $required=true>
        <Text_Input $name="title" $max_length=Task_Model.field_length('title') />
    </Form_Field>

    <button type="submit" class="btn btn-primary">Save Task</button>
</Rsx_Form>
```

- **`$controller` + `$method` is the ONLY place the endpoint is named.** Modals do not
  repeat it, buttons do not repeat it, JS does not repeat it. Missing either throws
  *"$controller and $method are required on the `<Rsx_Form>` tag"*.
- **`$data` present = edit form, absent = create form.** It is a plain serializable
  object - never a model instance. Values bind by matching each input's `$name`.
- **`$name` goes on the INPUT, never on the field wrapper, and you NEVER set `$value`.**
- **Exactly one `<Form_Errors />`, placed where the LAYOUT wants the feedback** - inside
  the first section, above a grid, under a heading - so the form's own containers give
  the alert their spacing. A form without one throws *"no `<Form_Errors />` in this
  form"* at the moment feedback is needed.
- A `type="submit"` button inside the form is auto-wired to `submit()`.

## Submission

`form.submit()` is the only submission path. It resolves with the server result, or
`false` on **any** failure - client `_validate()`, server validation, transport, or a
`before_submit` abort. On failure the form stays put with the user's values intact and
the messages rendered.

Events: `'submitted'` (result), `'submit_error'` (error), `'input'` (name, value).

```javascript
form.on('submitted', (form, result) => grid.reload());
```

Reaching one input: `form.input('due_date').on('val', cb)` - never a raw selector.
`form.vals()` serializes, `form.vals(object)` applies (skipping dirty names).

## Validation lives on the server, once

**Write no client-side required / format / length / range check. Anywhere.**

A client check that duplicates a server rule **masks the absence of the server rule**:
blank never reaches the endpoint, the missing validator is never exercised, every manual
test looks green, and the gap surfaces only when an API caller or a script hits the
endpoint directly - silently, in production. One rule, one home; and the round trip is
fast enough that rendering the server's message IS the responsive UX.

A `$required` marker on a field wrapper is an **asterisk announcing a server rule**. It
enforces nothing. When you write it, go write the server rule - nothing will tell you it
is missing.

The one exception is an input's `_validate()`: an architectural constraint whose invalid
state cannot be expressed to the server at all. Skill `rspade:form-input-contract`.

## The endpoint

```php
#[Ajax_Endpoint]
#[Auth('can_manage_tasks')]          // #[Auth] is MANDATORY - the build fails without it
public static function save(Request $request, array $params = [])
{
    $errors = [];

    if (trim($params['title'] ?? '') === '') {
        $errors['title'] = 'Title is required';
    }

    if ($errors) {
        return response_form_error('Please correct the errors below.', $errors);
    }

    $task = !empty($params['id']) ? Task_Model::find($params['id']) : new Task_Model();
    $task->title = $params['title'];
    $task->save();

    Flash_Alert::success('Saved');
    return ['redirect' => Rsx::Route('Tasks_View_Action', $task->id)];
}
```

**Blank is a value; absent means untouched.** Every input serializes on every submit, so
a blank field arrives as `''` - something the user *did*, never an omission.

- **absent key** -> leave it alone (partial update; the external API legitimately omits).
- **present but blank** -> must validate. A required field rejects it identically on
  create and edit; an optional field **saves** it.
- **"keep the old value when blank" is forbidden** - it makes a failed clear look like a
  success.

`response_form_error($message, $fields)`: first argument is the summary, second is the
field map. `response_error(Ajax::ERROR_VALIDATION, 'a string')` is the wrong shape - that
argument is metadata, so the alert renders empty. Unmatched keys are legitimate and
render in the top alert, which **always** renders on a failed submit.

`Form_Utils` (`Core/Js/Form_Utils.js`) is the one renderer: it pins each `{field:
message}` entry under the input whose `data-name` the key matches and puts the summary in
`<Form_Errors />`. There is exactly one styling path for a failed submit - never add a
second one in a field wrapper. Skill `rspade:ajax-error-handling`.

## The page pattern (SPA add/edit)

The action loads in `on_load()`, so the form is never blank on a first visit - but a
**cached revisit** re-renders instantly while revalidation is in flight, and that is what
the overlay is for.

```javascript
on_create() {
    this.data.is_edit = !!this.args.id;
    this.data.form_data = { title: '', status: Task_Model.STATUS_PENDING };
    this._record_settled = false;          // INSTANCE property, never this.data
}

async on_load() {
    if (!this.data.is_edit) return;
    const task = await Task_Model.fetch(this.args.id);
    this.data.form_data = { id: task.id, title: task.title || '' };
}

on_render() {
    // Renders REBUILD the DOM, so the overlay is re-armed every render.
    this._set_form_loading(this.data.is_edit && !this._record_settled);
}

on_ready() {
    if (!this.data.is_edit) return;
    this._record_settled = true;
    this.$.find('.Rsx_Form').first().component().vals(this.data.form_data);
    this._set_form_loading(false);
}
```

`_record_settled` is an instance property **deliberately not `this.data`**: `this.data`
is the maybe-cached result of `on_load()`, and a cached "already settled" lies on the
next visit while the real fetch is in flight.

**The template renders the REAL form unconditionally.** A spinner placeholder branch in
place of the form is the anti-pattern this replaces - the overlay is what the user waits
behind, with the form's structure underneath. A **load failure** is different: an
error-page branch is correct, because there is no form to wait for.

A modal is chrome around a form: `Modal.form()` finds the `<Rsx_Form>` in the hosted
component and drives its `submit()`. The dialog system is application code - app skill
`modals`.

## Loading

The overlay is **state**, not a DOM operation - `set_loading()` flips it and
`on_render()` re-syncs, because renders rebuild the DOM.

`form.populate(data_or_promise)` is the procedure: overlay on -> await -> `vals()` ->
overlay off in a `finally`, so it cannot leak on success or rejection.

**Clearing is always explicit**, owned by whoever set it. `on_ready()` is the wrong hook
by definition (ready means loaded), and an auto-clear on data-set misfires on partial
`vals()` writes during load. A forgotten clear is a permanently overlaid form - loud, and
fixed in minutes; a wrong auto-clear is a briefly-blank *editable* form, the silent kind
of bug this contract exists to kill.

**While loading, `submit()` refuses.** Blank is a value, so a submit racing the fetch
would validly clear every field the data had not reached.

The veil colour is the app's: core reads `--rsx-form-veil` and falls back to a light
veil, so a dark theme that does not declare it flashes white.

```scss
:root         { --rsx-form-veil: rgba(255, 255, 255, 0.65); }
body.rsx-dark { --rsx-form-veil: rgba(16, 21, 27, 0.7); }
```

The overlay hosts the **registered spinner** - `Rsx.set_default_spinner('App_Spinner')`
once at app init, `<Spinner />` or `$(el).component(Rsx.get_default_spinner())` anywhere
else. The host owns the box and the box is a centering stage; there are no size
arguments, and spinner markup is never hand-rolled.

## Dirty protection

**Data fills fields the user has not touched; the user's keystrokes always win.**

Every user `'input'` marks that name dirty, and applying data (`$data`, `vals(object)`)
skips dirty names. Across component **re-creation** - an SPA action re-rendering on fresh
data - dirty values are parked on the live SPA action under a key frozen at `on_create()`
(`controller|method|record-id`) and re-applied to the successor, still beating `$data`.
Parking is silent, cleared on a successful submit, and dies with the action on
navigation.

To overwrite a field the user has touched, address the input: `form.input(name).val(v)`.

## Serialization, repeaters and disabled inputs

A repeater is one input holding an array (`client_ids: [1, 5, 12]`, or
`team_members: [{user_id, role_id}]`); the endpoint syncs it. Serialization is
**shallow**, so inputs a composite input renders inside itself belong to that composite,
not to the form.

`$disabled=true` renders the control disabled but the input **still serializes**.

## Polymorphic fields

```php
$eventable = Polymorphic_Field_Helper::parse($params['eventable'], [
    Contact_Model::class,
    Project_Model::class,
]);
if ($error = $eventable->validate('Please select an entity')) {
    $errors['eventable'] = $error;
}
$model->eventable_type = $eventable->model;
$model->eventable_id   = $eventable->id;
```

The client submits `{"model":"Contact_Model","id":123}`. Always whitelist with
`Model::class`. `Polymorphic_Field_Helper` is framework core (`Core/`); skill
`rspade:polymorphic`.

## Common mistakes

| Mistake | Fix |
|---|---|
| Naming the endpoint anywhere but the tag | One pipeline: `form.submit()` |
| A client-side required check | Delete it; add the server rule the asterisk promised |
| `response_error(ERROR_VALIDATION, 'msg')` | `response_form_error($message, $fields)` |
| "Keep the old value when blank" in `save()` | Validate it or save it |
| A loading placeholder instead of the form | Overlay the real form |
| A model instance in `form_data` | Extract a plain object |
| `$name` on the field wrapper | It belongs on the input |
| Storing the settled flag in `this.data` | Instance property |

Details: `php artisan rsx:man form_conventions`, `rsx:man form_input`, `rsx:man modals`.
Related: `rspade:form-input-contract`, `rspade:ajax-error-handling`, and the app skills
`form-components` (field chrome + input roster) and `modals`.
