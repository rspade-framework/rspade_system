# Concern: errors (Error_Screens)

## Domain

What a terminal request outcome RENDERS. Every dispatch that ends without an
application response - a gate denial, an unmatched URL, an uncaught exception -
comes out of one renderer per outcome instead of a mix of `abort()` calls and
Laravel's unthemed default blades.

Server side (`App\RSpade\Core\Errors\Error_Screens`):

| Outcome | Entry point | Result |
|---------|-------------|--------|
| Denial | `unauthorized(Request, ?realm)` | 302 to the realm's login route (no session) or a themed 403 |
| Unmatched URL | `not_found(Request)` | themed 404 |
| Crash | `fatal(Request, ?Throwable)` | themed 500, detail redacted in production |

The SPA twin (`Core/SPA/Error_Screens.js`) renders the same three outcomes into
the live layout's content area using the app-owned theme components in
`rsx/theme/components/feedback/errors/`.

## Applicability

- The unauthorized SPLIT (login redirect vs 403) lives here and nowhere else -
  both dispatchers and the exception chain call into it.
- Production redaction is a security property, not a formatting choice: an error
  page is fully inspectable with curl.
- The exception-chain seam must stay BEHIND the dispatch bootstrapper. RSX
  routing is a Laravel 404 the bootstrapper catches; a handler claiming 404s
  ahead of it would take every RSX route offline.

## Source files under test

- `app/RSpade/Core/Errors/Error_Screens.php`
- `resources/views/errors/rsx_error.blade.php`
- `app/RSpade/Core/Exceptions/Web_Exception_Handler.php`
- `app/RSpade/Core/Dispatch/Dispatcher.php` (terminal paths)
- `app/RSpade/Core/Portal/Portal_Dispatcher.php` (terminal paths)
- `app/RSpade/Core/SPA/Error_Screens.js`, `app/RSpade/Core/SPA/Spa.js`

## Behavior of record

- `php artisan rsx:man auth_gates` - ERROR SCREENS
- `php artisan rsx:man class_override` - the PHP customization story
- `php artisan rsx:man login_redirect` - what the redirect thread validates

## Testable surface

- **php**: the split matrix (anonymous / authenticated / explicit portal realm),
  statuses and bodies for all three screens, the redaction rule across modes, the
  dispatcher's unmatched-URL path, and the exception-handler mapping + ordering.
- **playwright**: the SPA screens (gate denial renders at the denied URL with
  history moved; an unmatched SPA URL renders the not-found body inside the live
  layout). Covered today by `rsx:debug --eval` probes rather than a committed
  spec - see the catalog.
- **http**: the curl status matrix (302 / 200 / 404, staff and portal). Covered
  today by manual curl during the epic - see the catalog.
