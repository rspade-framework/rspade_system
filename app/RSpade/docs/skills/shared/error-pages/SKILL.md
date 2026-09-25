---
name: error-pages
description: "Declaring an application's full-page error screens as routes under the reserved /error/ prefix - #[Route('/error/404')] and #[Portal_Route('/error/404')], the Error_Context a page receives as $params['error'], /error/generic as the catch-all, the framework page as the last fallback, and the development-only preview. Use when building or restyling a 404 / 403 / 500 / 419 page, adding an error page for another status, wiring the portal's own error screens, responding to a ROUTE-ERROR-01 manifest-build failure, chasing a \"Page Expired\" response, or finding a log line reading \"failed while rendering\"."
---

# Error pages

**An error page is a ROUTE the application declares, not a view the framework looks up.** Nothing links to one; the framework reads the route table, finds the page for the status, and calls the method itself.

## The two controllers

Staff realm, `rsx/app/errors/`:

```php
#[Auth('public')]
class Errors_Controller extends Rsx_Controller_Abstract
{
    #[Route('/error/404')]
    public static function not_found(Request $request, array $params = [])
    {
        return rsx_view('Errors_Not_Found', ['error' => $params['error']]);
    }

    #[Route('/error/403')]      // 'Errors_Forbidden'
    #[Route('/error/419')]      // 'Errors_Expired'
    #[Route('/error/generic')]  // 'Errors_Generic' - every status with no exact page
}
```

Portal realm, `rsx/portal/errors/`, its own declarations in its own table — a portal failure must render portal links and the portal bundle, and `Rsx_Portal::Route()` resolves nothing outside the portal table:

```php
#[Auth('public')]
class Portal_Errors_Controller extends Rsx_Controller_Abstract
{
    #[Portal_Route('/error/404')]      // 'Portal_Errors_Not_Found'
    #[Portal_Route('/error/generic')]  // 'Portal_Errors_Generic'
}
```

**`ROUTE-ERROR-01` (manifest-build FATAL)**: a pattern under `/error/` must be exactly `/error/<status 400-599>` or `/error/generic`, one segment with no `:param`, **GET only**, and gated `#[Auth('public')]` on the method or its class. The failure names the rule, the surface and the remedy.

## What a page receives

One argument beyond the request: `$params['error']`, a readonly `App\RSpade\Core\Errors\Error_Context`.

| Field | Carries |
|---|---|
| `status` | the HTTP status the response will carry |
| `title` | the framework headline ("Page Not Found") |
| `message` | the framework sentence, or the reason the failing endpoint gave |
| `path` / `method` | the failing request |
| `realm` | staff or portal |
| `home_url` | the realm's home (portal prefix, or the portal root on a dedicated domain) |
| `preview` | true when a development `/error/<code>` browse fabricated this context |
| `detail` | `{class, message, file, line, frames[]}` — a **500 only**, and only for a **developer caller** in development or debug mode (`Rsx_Diagnostics::caller_sees_detail()`); null for everybody else and in strict production |
| `error_id` | a redacted 500's reference (logged as `[error_id=<id>]`); null when `detail` is shown — print it |

**The context is everything a page has.** The failing request is over, its controller never ran, the caller may be anonymous, and the page is fully inspectable with curl. So: no record lookup, no session read, no service call — and no mode check to hide `detail`, which is already redacted server-side.

## The fallback chain

1. the realm's own table, exact `/error/<status>`
2. the realm's own table, `/error/generic`
3. a **portal** failure with neither falls to the **staff** pair (a page beats no page)
4. nothing declared, or the manifest never initialised, → the framework's own standalone page (`system/resources/views/errors/rsx_error.blade.php` — inline styles, no bundle, so it renders when the manifest is what broke)

The page is called **directly**, with `($request, ['error' => $error])` — not through the ordinary action path, because a `pre_dispatch` redirect or a gate denial would take the error page away from the person who needs it. The status is then **forced** to the context's, so a page that renders a view cannot answer 200.

**A page that throws, returns null, or returns a coded response (`response_not_found()` and friends) is a page FAILURE**: the framework page renders instead and the failure is logged —

```
Error page Errors_Controller::generic failed while rendering a 500; the
framework page was rendered instead: <the page's own message>
```

The error page for the error page must not be a third error.

## Preview (development only)

Browse the URL: `/error/404`, `/error/403`, `/error/419`, `/error/500`, `/error/generic` (and the portal twins under `config('rsx.portal.prefix')`, default `/_portal`).

```bash
php artisan rsx:debug /error/generic --user=1
```

`/error/generic` previews as a **500 with the exact lookup skipped**, which is how you see what an undeclared status gets. The interception happens in **every mode**: development previews, a sealed build answers the 404 page. **An error page never answers 200 at its own URL anywhere.**

In development a real 500 still renders the interactive debug page — browse `/error/500` to see the page itself.

## What not to put on a page

- **Anything that can throw.** A 403 reaches somebody just denied; a 500 reaches a request whose boot may be what failed.
- **`Rsx::Route()` / `Rsx_Portal::Route()` to a target that might not exist** — they THROW on a miss, and that costs the reader the page. `$error->home_url` is always safe.
- **A record, a session read, session chrome.** None of it is loaded.
- **A mode check around `detail`.** Two rules to keep in step, one of them wrong eventually.
- **A link to the page itself.** These are declarations; the framework is the only caller.

## The 419

A CSRF failure on a **native form POST** renders the 419 page ("Page Expired") — the application's `/error/419` when declared. A CSRF failure on `/_ajax` or `/_upload` keeps the Ajax envelope (`{_success: false, error_code}` at HTTP 200) and renders no page.

## What never renders a page

An **anonymous denial** (302 to the realm's login through `Login_Redirect` — only an identified caller gets a 403), **Ajax and API** responses, a **non-HTML request** (an `Accept` header not preferring `text/html` gets the bare status and one line of text, which is what keeps `/_thumbnail` and friends usable), and the **pre-boot tier**.

**The pre-boot tier cannot be an application page.** The maintenance 503, the framework-version refusal and the first-run screen all run before Laravel exists — no autoloader, no config, no route table — so they render the standalone shell `system/bootstrap/rsx_preboot_page.php`, kept aligned with the framework page by hand.

The SPA's **in-app** 403/404 are a different mechanism: theme components in `rsx/theme/components/feedback/errors/`, mounted into the live layout, edited directly.

## Reference implementation

```
system/app/RSpade/resource/reference_app/app/errors/      staff module
system/app/RSpade/resource/reference_app/portal/errors/   portal pair
```

Details: `php artisan rsx:man error_pages`. Related: `rspade:auth-gates`, `rspade:blade-views`, `rspade:portal-core`.
