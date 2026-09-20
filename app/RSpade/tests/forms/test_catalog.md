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
