---
id: runtime_assembled_component_names
title: Components reached by an assembled name are kept in their bundle
severity: medium
when: launch, change
scope: rsx/**/*.js, rsx/**/*.jqhtml, rsx/**/*_bundle.php
related: rsx:man bundle_api, skill rspade:bundles
---

## The contract

Every jqhtml component the application renders is named, written out whole, somewhere the sealed build's component shake can see it - or is listed in its bundle's `'keep'`. In debug and production the build drops from each bundle every component nothing it serves names; a name assembled at run time (`'Select_' + kind + '_Input'`) is named nowhere, so the component is dropped. Development drops nothing, so the page works on every developer's box; in production the component renders as an empty element with one console warning and nothing else reports it.

Read `php artisan rsx:man bundle_api` (THE COMPONENT SHAKE) first: what counts as a mention (a whole word in the bundle's JS, its Blade, a quoted PHP/config string, another kept component, a comment), what does not (a `.Name` CSS selector), and the automatic keeps (a static method, an `@route`).

Nothing detects an assembled name automatically. A `'keep'` entry naming no component is a build error, so typos in the list are caught; omissions are this audit.

## Find every instance

    grep -rnE "\.component\(\s*[^'\")]" rsx --include='*.js'
    grep -rnE "\bcomponent:\s*[^'\"\` {]" rsx --include='*.js' | grep -v "component: null"
    php artisan rsx:bundle:shake:report

For each `.component(<expression>)` or `component: <expression>` hit, trace where the expression's value comes from: a literal written somewhere the bundle carries (kept - not a finding), an argument passed in by callers (follow the callers), or a string built by concatenation, template literal or lookup from data (the finding shape). A broad sweep for name fragments is also worth one pass, though it is noisy:

    grep -rnE "['\"\`][A-Z][A-Za-z0-9]*_['\"\`] *\+|\+ *['\"\`]_[A-Z][A-Za-z0-9]*['\"\`]" rsx --include='*.js'

Then read the report: for every component listed as DROPPED from a bundle, be able to say why no page of that bundle uses it. `php artisan rsx:bundle:shake:report <Bundle> --why=<Name>` traces one decision; `--kept` lists every keep with its reason.

## What does not conform

- A component reached only through an assembled or data-driven name and absent from its bundle's `'keep'` - add it to `'keep'` in that bundle's `define()`.
- A component the report drops from a bundle whose pages do render it.

Not findings: a name passed through as an argument whose every origin is a literal the bundle carries; a dropped component no page of that bundle uses.

## Report

`PASS - <one line: N dynamic call sites traced, report reviewed for M bundles>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
