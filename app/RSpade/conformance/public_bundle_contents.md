---
id: public_bundle_contents
title: A bundle served before sign-in includes no shared directory
severity: high
when: launch, change
scope: rsx/**/*_bundle.php, rsx/**/*.blade.php, rsx/**/*_controller.php, rsx/**/*_Controller.php
related: rsx:man bundle_api, skill rspade:bundles
---

## The contract

A bundle takes its audience from the LEAST privileged page that renders it. A bundle any signed-out visitor can load - login, signup, password reset, invite acceptance, the error pages, a portal's sign-in pages - names no shared directory (`'rsx/theme'`, `'rsx/lib'`, `'rsx/models'`, or any other directory include outside its own module). A directory include publishes the whole component library, every model's columns, enums and relationships, and every controller's route and Ajax stubs to anyone who opens the login page. Every endpoint stays gated and nothing breaks, so nothing reports it.

Read `php artisan rsx:man bundle_api` (PUBLIC-FACING BUNDLES, including How To Find The Set) first.

No code-quality rule looks at a bundle's audience (`CONV-BUNDLE-*` check placement, not audience). The sealed build's component shake does not do this either: it removes only unused components, never model or controller stubs, and does not run in development. An application test such as the template's `rsx/tests/Public_Bundle_Contents_Test.php` covers only the bundles listed in it by hand.

## Find every instance

    find rsx -name '*_bundle.php' -not -path 'rsx/resource/*'
    grep -rnE "_Bundle::render|'bundle' =>" rsx --include='*.php' | grep -v '^rsx/tests/'
    php artisan rsx:routes | grep 'file=rsx/'

For each bundle, list every page that prints it - the Blade layouts calling `::render()` and the views extending them, and the `#[SPA]` controllers naming it - and the `#[Auth]` (or `@auth` on SPA actions) each of those routes declares. One route gated `'public'` makes the bundle public. Then read the public bundle's `define()` include list.

## What does not conform

- A public bundle including a shared directory - the fix is to name the theme's global stylesheets with the type-limited form (`'rsx/theme/*.scss'`; `'<dir>/**/*.scss'` descends), the framework build they configure, each component, model and script its pages use BY FILE - both halves of every component and of every parent (the JS base class, the `<Define extends="...">` template) - plus its own `__DIR__`.
- A public page sitting in a signed-in module and so rendering that module's wide bundle (sign-in pages inside a portal module, an error page using the app layout) - give those pages a bundle of their own.
- A public bundle that names, by file, a model, controller or component its pages do not use.

Not findings: a bundle every one of whose pages requires a staff sign-in; a portal's SIGNED-IN bundle (separate from its sign-in bundle); the framework's `Core_Bundle` carried by every bundle; framework bundles under `system/`.

When a public bundle is narrowed, a missing stylesheet or parent template fails silently - say in the fix that every page it serves needs a before/after visual comparison.

## Report

`PASS - <one line: N bundles, M public, each narrowed>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
