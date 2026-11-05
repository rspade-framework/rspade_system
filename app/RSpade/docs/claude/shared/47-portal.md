<!-- single-source: never duplicate into another fragment. -->

## CLIENT PORTAL

A second authenticated experience for external users (clients, vendors), parallel to the staff app: `Portal_Session`, `Rsx_Portal`, `Portal_Main_Abstract`, the `Portal_User_Model` identity, application code in `/rsx/portal/`. Routing is `#[Portal_Route('/path')]` and `@portal_spa(...)` in the PORTAL auth realm. URLs come from `Rsx_Portal::Route()` / `Rsx_Portal.Route()` — never hand-join a prefix or host; `rsx_absolute_url(Rsx_Portal::Route(...))` is right everywhere — and **`Route()` answers `false` for a site with no portal**, so staff code names the record's site (`site_id:`) and refuses on false. Placement is `PORTAL_URL`, tenancy `rsx.tenancy`.

**ONE session per browser per host, shared with staff; both identities set at once is NORMAL.** Fork on `Rsx_Portal::is_portal_request()`, never on `is_logged_in()` — identity is not experience — and never mix the two facades' properties. **The portal's site is read from the URL by the framework**: portal code never hand-scopes a query and never declares a site (`Portal_Session::set_site_id()` throws in a web request).

Authorization mirrors staff: `#[Auth]` everywhere, `pre_dispatch` authorizes nothing, record rules in `portal_fetch()` + fail-closed `portal_can_read()` (`PORTAL-MODEL-FETCH-01`); uploads only through `Ajax.upload(form_data)`. **"View as Client" is READ-ONLY, DENY BY DEFAULT**: while `Portal_Session::is_impersonating()` a portal Ajax endpoint runs only if it declares `#[Portal_Impersonation_Readable]` — mark every READ endpoint, never a write. Sign-in is the framework's here too (`Portal_Auth::verify_credentials($site_id, ...)`, `Portal_Login_History`, `Rsx_Portal_Two_Factor`, `Rsx_Portal_Sso`), and portal session values are `Portal_Session::put_value()`, never `Session::`.

Skill `rspade:portal-core`; the shipped screens are the app skill `portal-app`. Details: `rsx:man portal`.
