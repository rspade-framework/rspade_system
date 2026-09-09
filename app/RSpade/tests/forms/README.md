# Concern: forms

The form engine: `Rsx_Form` owns value state, dirty tracking, submission and error rendering;
`Form_Field` is presentation around one input; `Form_Input_Abstract` subclasses own one named
value. `form.submit()` is the ONE submission path, and every way a user can reach it - a
`type="submit"` button inside the form, the form element's own submit event, a modal's primary
button, a programmatic caller - funnels into it.

## Source under test

- `system/app/RSpade/Core/Forms/Rsx_Form.js` - the pipeline, the submission wiring
- `system/app/RSpade/Core/Forms/Rsx_Form.jqhtml` - `<Define:Rsx_Form tag="form">`, which is
  what makes the component a real `<form>` element and brings the browser's own submission
  machinery into scope

## Behavior defined by

`rsx:man form_conventions` (the contract), and the `Rsx_Form.js` docblock (the implementation
summary).

## Applicability note

The submission surface is a BROWSER behavior, so the coverage here is playwright. Implicit
submission - Enter in a text input, with no button involved - exists only in a real browser
running a real form element; a synthetic `.trigger('submit')` reproduces none of it and would
pass against the very defect the test exists to catch.

The probe builds its form at runtime on the framework's own `/_sys` page, because a browser
test cannot see the test tree at all: the trees enter the manifest only while `rsx:test` runs,
and a playwright script drives the ordinary web server.

## Testable surface

| Area | Type | Status |
|---|---|---|
| Enter in a text input submits rather than navigating | playwright | implemented (`form_enter_submits.js`) |
| Enter reaches `submit()` exactly once | playwright | implemented (`form_enter_submits.js`) |
| `type="submit"` button wiring | playwright | planned |
| Loading guard: `submit()` refuses while the overlay is up | playwright | planned |
| Re-entrancy guard: a second submit in flight returns false | playwright | planned |
| Server error rendering into `<Form_Errors />` | playwright | planned |
