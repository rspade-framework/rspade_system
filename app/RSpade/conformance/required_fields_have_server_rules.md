---
id: required_fields_have_server_rules
title: Every required asterisk has a server rule behind it
severity: medium
when: launch, change
scope: rsx/**/*.jqhtml, rsx/**/*.blade.php, rsx/**/*_controller.php, rsx/**/*_Controller.php
related: rsx:man form_conventions, skill rspade:form-engine, skill form-components
---

## The contract

Every `$required=true` on a `<Form_Field>` has a presence rule in the endpoint its `<Rsx_Form>` names (`$controller` + `$method`), written against BLANK, identical on create and edit, rejecting with `response_form_error()` keyed by the input's `$name`. And every field the endpoint requires carries `$required` in the form. `$required` only draws an asterisk; there is deliberately no client-side check, because validation lives on the server once. An asterisk with no rule is silent everywhere: the field looks enforced, manual testing passes because a human fills it in, and the gap surfaces when an import, a script or an API caller posts the endpoint with the field blank.

Read `php artisan rsx:man form_conventions` first, then `rsx/theme/components/forms/CLAUDE.md` and skill `form-components` (the form chrome is application code; where they record a divergence they win).

No rule enforces the pairing. (`Text_Input` throwing without `$max_length` and `Form_Field_Abstract` throwing without an input child are runtime checks of other things.)

## Find every instance

    grep -rln "<Rsx_Form" rsx --include='*.jqhtml' --include='*.blade.php' | grep -v '^rsx/tests/'

For each form: read `$controller` and `$method` on the `<Rsx_Form>` tag (a modal opened with `Modal.form()` hosts a component whose template holds the tag - the modal never names an endpoint), list every `<Form_Field>` and whether it carries `$required`, then open that endpoint and read its validation block for each input `$name`. Staff, portal and Blade forms alike.

## What does not conform

- A `$required` field with no presence check in the endpoint.
- A check against ABSENCE only (`isset($params['title'])`, `!empty()` guarding an assignment). A submitted blank input arrives as `''`; the check is `trim($params['title'] ?? '') === ''` or equivalent. `if (!empty($params['x'])) { $record->x = ... }` is "keep the old value when blank" - forbidden, and on create it leaves the column at its default.
- A rule present on create and missing on edit, or the reverse.
- A rejection through `response_error(Ajax::ERROR_VALIDATION, '...')` - a form-level error with no field; use `response_form_error($message, ['<input $name>' => '...'])`.
- A required rule that runs after a write, or after a `response_form_question()` is asked - the order is validate -> ask -> write.
- A field the endpoint requires whose `<Form_Field>` has no `$required` - the user gets no warning.
- A client-side required check (JS on submit, an HTML `required` attribute) added to "help" - it masks a missing server rule.
- A length rule that restates a schema number instead of `Model::field_length('col')` (the same definition `$max_length` reads on the input).

Not findings:

- A partial-update endpoint where an ABSENT key means "leave it alone": there the check is presence first, then blank. Confirm the form always sends the field.
- An input's own `_validate()` enforcing an architectural constraint the server cannot express.
- Framework forms under `system/` (out of scope).

## Report

`PASS - <one line: N forms, M required fields, every one backed>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
