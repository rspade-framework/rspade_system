# Test catalog: forms

Status legend: `implemented` | `deferred` (reason) | `blocked` | `planned`.

## form_enter_submits.js (playwright) - implicit submission reaches the pipeline

`<Define:Rsx_Form tag="form">` makes the component a real `<form>`, so the browser's IMPLICIT
SUBMISSION applies: a form whose only field is a single-line text input submits on Enter with
no button involved. `Rsx_Form` wired `button[type="submit"]` clicks and nothing else, so that
submission ran its DEFAULT action and the browser navigated - in a modal the dialog vanished
with everything typed into it, on a full page the action was re-dispatched and the form came
back blank. Reported from a downstream field report on 2026-09-08.

The probe form carries NO submit button, deliberately: with one present the browser activates
the BUTTON instead, which the pre-existing click wiring already intercepted - which is exactly
why the defect went unseen. One text input and nothing else is the shape that reaches implicit
submission, and it is the shape of the one-field dialog where the data loss was worst.

`submit()` is replaced on the instance with a recorder - the probe names no real endpoint, and
what is under test is whether the pipeline is REACHED.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-enter-01 | the probe mount is a real form element | `Rsx_Form` mounted on a `<form>` | `tagName === 'FORM'` | implemented |
| forms-enter-02 | Enter does not navigate the page | a real Enter keypress in the focused text input | the window sentinel survives, the form is still mounted, Playwright saw no frame navigation | implemented |
| forms-enter-03 | Enter reaches the submission pipeline | the same keypress | `submit()` called exactly once - a bare `preventDefault()` would stop the navigation and leave Enter doing nothing, which is its own bug | implemented |
