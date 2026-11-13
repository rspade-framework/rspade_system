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
- `system/app/RSpade/Core/Forms/Form_Questions.php` - the endpoint's reader for the answers a
  resubmission carries
- `response_form_question()` in `system/app/RSpade/helpers.php`, `Ajax::ERROR_QUESTION` and
  `AjaxQuestionException` - the question response envelope and what an in-process caller sees

## Behavior defined by

`rsx:man form_conventions` (the contract), and the `Rsx_Form.js` docblock (the implementation
summary).

## Applicability note

The question protocol has two halves and they are tested separately. The SERVER half - the
response envelope, `Form_Questions::answer()`, the in-process exception - is plain PHP and
lives in `php/`, with a fixture controller the test trees put in the manifest for the run. The
BROWSER half - the submission loop, the cancel sentinel, the round cap - is playwright.

The rest of the submission surface is a BROWSER behavior, so the coverage here is playwright. Implicit
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
| `response_form_question()` envelope: code and `{key, question}` metadata | php | implemented (`Form_Questions_Test.php`) |
| `Form_Questions::answer()` / `answered()`: null is unasked, `false` is an answer | php | implemented (`Form_Questions_Test.php`) |
| An asking endpoint raises `AjaxQuestionException` in-process | php | implemented (`Form_Questions_Test.php`) |
| `Model.field_length()` answers from the page's field-length script, null for no length, throws without data | playwright | implemented (`field_length_script.js`) |
| The page boots only after its field-length script has run, however late it arrives | playwright | implemented (`field_length_script_delayed.js`) |
| `Rsx_Form.CANCELLED` stops the submit, leaving the form open and error-free | playwright | implemented (`form_questions_round_trip.js`) |
| An answer resubmits with `_answers` plus the original values | playwright | implemented (`form_questions_round_trip.js`) |
| Answers accumulate across questions in one submit | playwright | implemented (`form_questions_round_trip.js`) |
| Any number of distinct questions completes (two of the form's own plus one per file) | playwright | implemented (`form_questions_round_trip.js`) |
| A re-asked key ends the loop with an error naming the endpoint and the key - in `submit()` and in `call_answering()` | playwright | implemented (`form_questions_round_trip.js`) |
| A question with no registered handler throws instead of rendering | playwright | implemented (`form_questions_round_trip.js`) |
| Credential fields: `$autofill_id` forwarded by `Password_Input` / `Code_Input` renders a stable id and name, keeps the token and `$sid()`; `autocomplete_off` is on | playwright | implemented (`credential_autofill.js`) |
| Blade-written fields: `Rsx_Blade_Autocomplete` gives every token-less `<input>` / `<textarea>` `autocomplete="off"` off an SPA page, keeps a declared token, and touches nothing on an SPA page | playwright | implemented (`blade_autocomplete_off.js`) |
