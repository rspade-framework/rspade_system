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
| forms-enter-02 | Enter does not navigate the page | a real Enter keypress in the focused text input | the window sentinel survives, the form is still mounted, Playwright saw no new document load (a same-document history update is not a navigation) | implemented |
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

## credential_autofill.js (playwright) - credential fields keep a stable id

A password manager that remembers a field by its id needs an id that is the same on every render
and works unescaped in `'#' + id`; the jqhtml `<sid>:<cid>` id is neither. jqhtml's `$autofill_id`
gives a `$sid` field exactly the id (and name) it is handed, and the framework's credential inputs
forward a caller's `$autofill_id` to their inner field. The framework also configures jqhtml's
`autocomplete_off`, so a template-written field that declares no token renders `"off"`. Raised by
a downstream field report (2026-11-05): a password manager saved a form's generated ids and,
replaying them as unescaped selectors on the sign-in page, filled nothing.

Runtime-mounted on `/_sys`, once as the page is served and once after the in-page
`jqhtml.configure({mode: 'production', autocomplete_off: true})` a production page makes.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-autofill-01 | the integration turns `autocomplete_off` on | `jqhtml.get_config()` | `autocomplete_off === true` | implemented |
| forms-autofill-02 | a field declaring no token is "off" | `_Sys_Text_Input` | `autocomplete="off"` | implemented |
| forms-autofill-03 | id and name are the `$autofill_id` | `<Password_Input $autofill_id="password">` | `id` and `name` are `password`, `autocomplete="current-password"`, `#password` matches exactly the field | implemented |
| forms-autofill-04 | the component still reaches its field | the same | `$sid('field')` resolves it; `val()` writes and reads; typing fires `input` with the value; a re-render keeps the id and the lookup | implemented |
| forms-autofill-05 | the code's first box takes the id | `<Code_Input $autofill_id="code">` | first box `code` / `one-time-code`, the rest `off` with no id; `$sid('first_box')` resolves it; `val('123456')` complete | implemented |
| forms-autofill-06 | no `$autofill_id`, no change | `<Password_Input>` | the scoped `field:<cid>` id | implemented |
| forms-autofill-07 | nothing complained | the whole run | no jqhtml autofill warning, no page error | implemented |

## field_length_script.js (playwright) - `Model.field_length()` reads the page's script

`/_sys`, the framework's `User_Model`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-fl-01 | the page carries one field-length tag, deferred, after the app bundle, naming its hash and the state key | the document's scripts | one tag, in that position | implemented |
| forms-fl-02 | the script is served immutable and `field_length()` answers what it carries | fetch the tag's URL; `User_Model.field_length('first_name')` | 200, `immutable`; the same number | implemented |
| forms-fl-03 | no length answers null | `'id'`; a name that is no column | null; null | implemented |
| forms-fl-04 | a class extending a model answers through it | `class X extends User_Model` | the model's answer | implemented |
| forms-fl-05 | missing data throws | `Rsx.__field_length_data = null`; `= {}` | a throw naming the model and the script, both times | implemented |

## field_length_script_delayed.js (playwright) - the page boots only after its field-length script

`/_sys`, the framework's `User_Model`; the `/_field_lengths/` response is held until the app bundle has run.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-fl-06 | a late field-length script holds the boot back | the response held; the page's timers given 250 ms | bundle executed, `Rsx.__booted` false, `readyState` interactive | implemented |
| forms-fl-07 | once the script arrives the page boots with the data in place | the response released | booted, data present, `field_length()` answers, no page error | implemented |


## Form error messages are plain text (`playwright/form_errors_plain_text.js`)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-pt-01 | a field error, an unmatched field error and a general error each render their message as text | messages carrying `<b>` and `<img onerror>` | element text = the message verbatim; no `b`/`img` inside | implemented |
| forms-pt-02 | `Rsx.render_error()` renders the fatal box's file, line and message as text | a fatal error with markup in `file` and `error` | text shows them literally; no element from them | implemented |

## Hidden inputs associated by id (`playwright/form_hidden_outside.js`)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-ho-01 | `vals()` collects the native hidden inputs the form OWNS through `form.elements` - the shape `<Turnstile_Input $form>` renders outside its form | an Rsx_Form with an id; hidden inputs inside it, outside it naming its id, outside naming nothing, and inside naming another form | the first two collected, the last two not | implemented |

## Form_Edit_Test.php (php) - writing an add/edit submission

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| FE-01 | a model without revisions is refused by name | run() on a plain model | throws naming it | implemented |
| FE-02 | a new record writes every field | `_changed` empty, no id | fields written, `updated_at` in the result | implemented |
| FE-03 | only changed fields are written | one field changed | the others untouched | implemented |
| FE-04 | saves of different fields both stand | another save of another field | no question, both values stored | implemented |
| FE-05 | a save of the same field asks and writes nothing | overlap on an enum | `field_conflict` naming that field, enum labels, nothing written | implemented |
| FE-06 | Yes writes mine, No keeps theirs and writes the rest | each answer | stored values | implemented |
| FE-07 | a further save between rounds asks again | a third save, then the old answer | a new question | implemented |
| FE-08 | a write with no revision asks a plain confirm | raw SQL | `confirm`; No writes nothing, Yes writes | implemented |
| FE-09 | a form is not in conflict with its own save | second submit from the result's `updated_at` | no question | implemented |
| FE-10 | a submission without its edit state is refused | no `_changed` | throws | implemented |
| FE-11 | a record deleted meanwhile answers not found | deleted row | `ERROR_NOT_FOUND` | implemented |
| FE-12 | the record is read under a row lock | query log | one `for update` read | implemented |
| FE-13 | a secret column says only that it changed | overlap on a never-exported column | `redacted`, no values | implemented |
| FE-14 | the question hands back editors as references | another save as signed-in user 1 | `editors` and each change's `by` are `{type, id}`; `at` ISO; `from`/`to`; `record` | implemented |
| FE-15 | two editors are both listed, nobody is null | a signed-in save, then one with nobody acting | two editors, the second null; both changes in order | implemented |
| FE-16 | a field set to the value already saved is not asked about | the same value as the other save | no question; written | implemented |
| FE-17 | a revision names the actor the record's stamp names | a signed-in save | the revision's created_by pair equals the record's updated_by pair | implemented |
| FE-18 | the view URL the endpoint passes rides the question | `run(..., $view_url)` | `question.view_url` | implemented |
| FE-19 | the composite version is the newest updated_at of the records given | `version_of($record, $coupled)` in both orders, and of one record | the coupled record's updated_at; one record's own (`Form_Edit_Coupled_Test`) | implemented |
| FE-20 | a coupled save of a mapped field the submission sends is a conflict on the FORM field, naming the record it was saved on | coupled `kind_id` saved; submission sends `activity_kind` | `field_conflict` on `activity_kind`, `fields[].record` the coupled record, nothing written; answer false keeps theirs and writes the rest | implemented |
| FE-21 | a coupled save of an unmapped column, or of a mapped field not sent, asks nothing | coupled `note` and `start_hour` saved; submission sends `name`, `activity_kind` | no question; both changes stand; result `updated_at` is the composite | implemented |
| FE-22 | a coupled save before the read is not a conflict | save, then `version_of()` as the loaded version | no question | implemented |
| FE-23 | two columns mapped to one field conflict on that field | `start_date` or `start_hour` saved; submission sends `starts` | `field_conflict` on `starts`, kind structured, current null | implemented |
| FE-24 | a coupled record that moved with no revision asks the plain confirm | raw update of the coupled row's updated_at | `confirm` | implemented |
| FE-25 | every record is row-locked, record first; a coupled model without revision history is refused | query log; a plain fixture as the coupled record | two FOR UPDATE reads in order; RuntimeException naming the model | implemented |

## Form_Edit_Fact_Groups_Test.php (php) - one fact in several columns, and the fields: map

`Form_Edit_Grouped_Fixture_Model` declares the fact group `when` (all_day_date, starts_at, ends_at,
label "Date and time"); its runtime table is created in setup() and the schema told
(`Rsx_Schema::forget()`), since the declaration is checked against live columns.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| FE-26 | a save of one column of a group conflicts with a field stored in another | all_day_date saved; submission sends starts_at | `field_conflict` on starts_at, kind structured, `groups` ["Date and time"], each change carrying record, column, group and that column's from/to; nothing written | implemented |
| FE-27 | the comparison is not by the field's own column | only ends_at saved; submission sets starts_at to its stored value | asked anyway, the change naming ends_at | implemented |
| FE-28 | a field outside the group is not a conflict | all_day_date saved; submission sends title | no question; both stand | implemented |
| FE-29 | a grouped field changed only in its own column, to the same value, asks nothing | starts_at saved; submission sends the same starts_at | no question | implemented |
| FE-30 | fields: maps an own column to a differently named field; set() writes the mapped column | billing_text saved; submission sends description with `fields: ['billing_text' => 'description']` | conflict on description, kind value, loaded/current/yours; Overwrite writes billing_text | implemented |
| FE-31 | without the map a renamed field matches nothing | the same, no fields: | no question | implemented |
| FE-32 | a column mapped to two fields conflicts with a submission sending either | stamp saved; `'stamp' => ['stamp_day', 'stamp_hour']` | conflict on the field sent | implemented |
| FE-33 | set() refuses a field stored in a shared column | set('stamp_day') with the two-field map | RuntimeException | implemented |
| FE-34 | a coupled record's group applies through its map | coupled all_day_date saved; submission sends activity_time mapped to starts_at | conflict on activity_time naming the coupled record and the group | implemented |
| FE-35 | the declaration is read normalized; undeclared is none | fact_groups() on both fixtures | the normalized group; [] | implemented |
| FE-36 | a malformed declaration fails loud | unknown column, a column in two groups, one (or a repeated) column, no label, an extra key, an unnamed group, no table | LogicException naming the problem | implemented |

## form_edit_state.js (playwright) - what a form tells an endpoint about an edit

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| FES-01 | an untouched form reports nothing and its version | submit | `_changed` empty, `_loaded_updated_at` the seed's | implemented |
| FES-02 | a typed value is reported | type, submit | `_changed` names it | implemented |
| FES-03 | a programmatic val() is reported though the form stays clean | `val()` | reported, `dirty()` false | implemented |
| FES-04 | a before_submit value is reported | hook adds a key | `_changed` names it | implemented |
| FES-05 | a successful submit moves the snapshot and version | answer with `updated_at` | next submit reports nothing, new version | implemented |

## blade_autocomplete_off.js (playwright) - Blade fields default to autocomplete="off"

`Rsx_Blade_Autocomplete._on_framework_core_init()` applies jqhtml's `autocomplete_off` policy to
the fields a Blade page wrote: on a page that is not an SPA page, every `<input>` and `<textarea>`
with no `autocomplete` attribute gets `"off"`. Probe fields are written into `/_sys` at run time
and the class is called with `window.rsxapp.is_spa` set to each value.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-blade-ac-01 | a token-less field becomes "off" | `<input>`, hidden `<input>`, `<textarea>`, `is_spa` false | `autocomplete="off"` on each | implemented |
| forms-blade-ac-02 | only the attribute exempts a field | `<input id name>` with no token | `autocomplete="off"` | implemented |
| forms-blade-ac-03 | a declared token is kept | `autocomplete="username"`, `"current-password"`, `"off"` | unchanged | implemented |
| forms-blade-ac-04 | a `<select>` is not touched | `<select>` | no attribute | implemented |
| forms-blade-ac-05 | an SPA page is left alone | the same fields, `is_spa` true | token-less fields carry no attribute; tokens kept | implemented |
| forms-blade-ac-06 | the page reported no error | - | no `pageerror` / `console.error` | implemented |

## form_request_ref.js (playwright) - the request reference and resubmission

Every call `Rsx_Form.submit()` makes carries a `_request_ref` bound to the form's identity on the
browser history entry (`rsx:man form_conventions`, RESUBMITTING). Probe forms are built at run
time inside the `/_sys` SPA action; the endpoint is answered by the script at the network level
with a request history that follows the server contract (replay on the same fingerprint,
`request_ref_reused` with `{completed_at, original}` on a changed one, `request_response_expired`
on demand), counting the rows it "wrote". The server half is `Request_History_Test`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| forms-ref-01 | a lost answer is resent under the same reference | first answer replaced by a 502 after the server recorded it; submit again | same ref twice, stored answer resolved, `'replayed'` `{result, completed_at}` once, replay notice once, one row | implemented |
| forms-ref-02 | a form that stays on screen gets a new reference | submit again after a success with no redirect | a new ref, a second row | implemented |
| forms-ref-03 | a validation error and a question keep the reference | blank title, then a question, then the corrected submit | one ref across all three calls; the last runs | implemented |
| forms-ref-04 | Back finds the used reference | success redirects; Back; a new instance on the entry | `already_submitted()` true, the same ref | implemented |
| forms-ref-05 | the same values from Back replay | submit the new instance unchanged | the original answer, its redirect followed, no new row | implemented |
| forms-ref-06 | changed values reach the resubmit handler | Back, change, handler CANCELLED then `'new'` | context `{form, completed_at, original, expired: false}`; CANCELLED renders nothing and keeps values; `'new'` writes under a fresh ref | implemented |
| forms-ref-07 | an expired answer reaches the handler | `request_response_expired` | `expired: true`, `original` null | implemented |
| forms-ref-08 | with no resubmit handler the refusal renders and the next submit is new | handlers cleared | `<Form_Errors />` says already submitted; ref replaced; next submit writes | implemented |
| forms-ref-09 | dialog and duplicate identities are instance-bound | two live forms of one identity; one inside `role="dialog"` | only the first page form's ref is on the entry | implemented |
| forms-ref-10 | `Ajax.action()` | lost answer then retry; success; another endpoint | retry resends the ref and is `replayed`; success replaces it; each endpoint its own | implemented |
