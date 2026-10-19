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

## Form_Questions_Test.php (php) - the server half of a server-driven question

An endpoint may answer a submission with a QUESTION rather than a result:
`response_form_question($key, $question)` after validation and before any write. The framework
owns the protocol and nothing else - it reads no field of the question object, so whatever the
endpoint wrote is what the application's handler receives.

`Form_Questions_Fixture_Controller` is the endpoint under test: it asks once and then reports
what it was answered. The test trees are in the manifest while `rsx:test` runs, so the fixture
is reachable through the ordinary in-process Ajax entry point.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-question-01 | the response carries its own code | `response_form_question('proceed', [...])` | an `Error_Response` whose code is `Ajax::ERROR_QUESTION` | implemented |
| forms-question-02 | the metadata is exactly the pair | the same | keys `['key', 'question']`, the question array byte-identical | implemented |
| forms-question-03 | the reason is the code's default | a question with no `_message` | `'A question is pending'` | implemented |
| forms-question-04 | null means NOT ASKED | `[]`, `['_answers' => []]`, another key's answer | `answer()` null, `answered()` false | implemented |
| forms-question-05 | false is an ANSWER | `['_answers' => ['proceed' => false]]` | `answer()` false, `answered()` TRUE | implemented |
| forms-question-06 | any other value round-trips | true, a string, 0 | returned unchanged | implemented |
| forms-question-07 | an asking endpoint raises the exception | `Ajax::internal()` with no answers | `AjaxQuestionException` carrying the key and the question | implemented |
| forms-question-08 | an answered endpoint completes | the same call with `_answers` | the endpoint's own result, the answer included | implemented |

## form_questions_round_trip.js (playwright) - the submission loop

The browser half: `submit()` clears the spinner, runs the handler, merges the answer into
`_answers` and calls the endpoint again, all inside ONE `submit()`. The probe is a runtime-built
`Rsx_Form` on `/_sys` with a per-form `question_handler`; `Ajax.call` is replaced by a scripted
fake for the duration and restored afterwards, because what is under test is the loop and not
any endpoint.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-question-10 | cancel is not an answer | the handler returns `Rsx_Form.CANCELLED` | `submit()` resolves false, exactly one call made, nothing rendered, the form still holding its values | implemented |
| forms-question-11 | false is delivered as an answer | the handler returns `false` | the second call's body carries `_answers: {k: false}` and the original values; the first carries no `_answers` | implemented |
| forms-question-12 | answers accumulate | two questions, two different answers | the third call's body carries both | implemented |
| forms-question-13 | a runaway loop is a defect, not a wait | an endpoint that always asks | `MAX_QUESTION_ROUNDS + 1` calls, then a rendered error naming the endpoint, resolving false | implemented |
| forms-question-14 | a missing handler cannot hide | both handlers cleared | `submit()` THROWS, the message names `set_question_handler()`, and nothing is rendered in the form | implemented |

## form_message_only_error.js (playwright) - a failure that names no field

A validation error whose metadata is only `_message` used to render nothing: the renderer
drew the top alert only when it had found a field error. The probe is a runtime-built
`Rsx_Form` on `/_sys` with `Ajax.call` scripted to reject.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-message-01 | the message is all there is | `{_message}` | rendered once in `<Form_Errors />`, `submit()` false | implemented |
| forms-message-02 | the working path gains no second copy | `{_message, code}` | the message once, nothing stale | implemented |
| forms-message-03 | a hook that throws an Error | `before_submit` throws `Error` | its message rendered once | implemented |

## form_input_focus.js (playwright) - `Form_Input_Abstract.focus()`

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-focus-01 | a ready input takes focus | `focus()` | its control is the active element | implemented |
| forms-focus-02 | before ready nothing moves | `focus()` with the ready flag clear | the active element is unchanged | implemented |
| forms-focus-03 | readiness honours the request after the value | `val('buffered')`, `focus()`, `_mark_ready()` | focused, and the control held the value when it took focus | implemented |
| forms-focus-04 | readiness alone moves nothing | `_mark_ready()` with nothing pending | the active element is unchanged | implemented |

## form_dirty.js (playwright) - `Rsx_Form.dirty()` and the `'dirty'` event

The panel's site-rename form mounted at runtime on `/_sys` with a made-up site; the submit answers through a stubbed `Ajax.call`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-dirty-01 | a seeded form is clean | mount with `$data` | `dirty()` false, no `'dirty'` | implemented |
| forms-dirty-02 | a user edit dirties | type into a field | `dirty()` true, `'dirty'` (true) | implemented |
| forms-dirty-03 | changed, not touched | type the value back | `dirty()` false, `'dirty'` (false) | implemented |
| forms-dirty-04 | programmatic writes never dirty | `vals(object)`, `input.val(v)` | clean, no event, value written | implemented |
| forms-dirty-05 | keystrokes win, even typed back | `vals({name})` after typing back | the field keeps the user's value | implemented |
| forms-dirty-06 | a value buffered before ready is the baseline | `val(v)` with the ready flag clear, then `_mark_ready()` | unchanged before and after | implemented |
| forms-dirty-07 | a parked user value restored before ready is a change | `_restore_user_value(v)`, then `_mark_ready()` | changed before and after, value `v`, form dirty | implemented |
| forms-dirty-08 | a successful submit re-baselines | `submit()` | clean; `'dirty'` (false) before `'submitted'` | implemented |

## code_input.js (playwright) - `Code_Input`, one digit per box

Real keyboard input on a runtime-mounted widget on `/_sys`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-code-01 | fills from the left, digits only | type `4x0` | value `40`, cursor in box 3 | implemented |
| forms-code-02 | no gaps | click box 5, type `7` | the digit lands in box 3 | implemented |
| forms-code-03 | Backspace in an empty box | Backspace | the previous box is cleared, cursor steps back | implemented |
| forms-code-04 | Enter with boxes empty | Enter | default prevented | implemented |
| forms-code-05 | paste is spread, overflow dropped | `12-34 5678` into box 3 | `401234`, complete | implemented |
| forms-code-06 | a full last box takes no more | type `9` | unchanged | implemented |
| forms-code-07 | Enter with every box full | Enter | not prevented | implemented |
| forms-code-08 | the input contract | `val('')`, `focus()`, `val('00a42')` | cleared, cursor in box 1, `0042`; `input` carried the value; no `maxlength`, one `one-time-code` | implemented |

## field_length_script.js (playwright) - `Model.field_length()` reads the page's script

`/_sys`, the framework's `User_Model`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-fl-01 | the page carries one field-length tag, deferred, after the app bundle, naming its hash and the state key | the document's scripts | one tag, in that position | implemented |
| forms-fl-02 | the script is served immutable and `field_length()` answers what it carries | fetch the tag's URL; `User_Model.field_length('first_name')` | 200, `immutable`; the same number | implemented |
| forms-fl-03 | no length answers null | `'id'`; a name that is no column | null; null | implemented |
| forms-fl-04 | a class extending a model answers through it | `class X extends User_Model` | the model's answer | implemented |
| forms-fl-05 | missing data throws | `Rsx.__field_length_data = null`; `= {}` | a throw naming the model and the script, both times | implemented |

