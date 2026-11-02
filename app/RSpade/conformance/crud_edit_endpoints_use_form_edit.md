---
id: crud_edit_endpoints_use_form_edit
title: A form that edits a record saves through Form_Edit
severity: high
when: launch, change
scope: rsx/app/**/*controller*.php, rsx/app/**/*_action.js, rsx/app/**/*_Action.js, rsx/app/**/*modal*.js
related: rsx:man form_conventions, skill rspade:form-engine
---

## The contract

An `<Rsx_Form>` that edits an existing record saves through `Form_Edit::run()`: only changed fields are written, under a row lock, and two editors of one field are asked. An endpoint that assigns fields itself loses the other person's work with no error, so nothing else reports it.

Read `php artisan rsx:man form_conventions` (EDITING A RECORD) first.

Already enforced, not re-checked: `Form_Edit` throws for a model without `$revisions`, and for a submission whose seed carried no `updated_at`.

## Find every instance

    grep -rln '<Rsx_Form' rsx

For each form with a `$data` seed, or one `populate()` fills from a record, open the endpoint its `$method` names.

## What does not conform

- An endpoint that writes an existing record with `$record->field = ...` and `save()` for a field the form submits.
- `Form_Edit::run()` inside a `DB::transaction()` of the endpoint's own.
- A `$record->field = ...` inside the callback for a submitted column, which writes it whether or not it changed. A value derived by the server is the exception.
- A pivot or detail-table field written without `$edit->changed()`.
- An edit seed built by hand without `updated_at`, which fails only when the form is first saved.

A form over settings that one person owns (their own profile, a site's settings) may be exempt: say so beside the endpoint.
