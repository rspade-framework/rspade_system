# Concern: flash

Server-to-client flash alerts: queued in the database against a session AND an experience,
handed to the browser once, then deleted. One browser has ONE session shared by the staff
app and the portal, so delivery is scoped by the pair (session_id, is_portal): a staff page
never consumes a portal page's message and vice versa. Silent-to-the-database in console
context, and swept hourly.

## Source under test

- `system/app/RSpade/Lib/Flash/Flash_Alert.php` - the writers, session resolution, the
  per-session cap, the CLI contract, and `get_pending_messages()` (the consuming read)
- `system/app/RSpade/Lib/Flash/Flash_Alert_Model.php`
- `system/app/RSpade/Lib/Flash/Flash_Alert_Cleanup_Service.php` - hourly retention sweep

## Behavior defined by

`system/app/RSpade/Lib/Flash/CLAUDE.md`

## Applicability note

A PHP test IS a console process, so the CLI contract is exercised end to end through the
public writers, while the WEB paths (session resolution, the read, the cap) are reached
through their protected seams - `_resolve_session_id()`, `_pending_for_session()`,
`_enforce_session_cap()`. That is not a shortcut around the public API: in console
context the public writers deliberately print instead of persisting, so there is no
in-process way to drive the web path. Full-stack coverage of the cookie contract is the http
row below.

## Testable surface

| Area | Type | Status |
|---|---|---|
| Per-session cap at the writer | php | implemented (`Flash_Alert_Cap_Test`) |
| Realm resolution: a portal write mints exactly one session (the browser's) | php | implemented (`Flash_Alert_Realm_Test`) |
| Experience scoping: a portal-queued alert is invisible to a staff read on the SAME session, and vice versa | php | implemented (`Flash_Alert_Realm_Test`) |
| The cap is keyed per (session, experience) | php | implemented (`Flash_Alert_Cap_Test`) |
| Cross-session invisibility (two browsers) | php | implemented (`Flash_Alert_Realm_Test`) |
| Read: expiry sweep, one-time delivery, delete-on-read | php | implemented (`Flash_Alert_Realm_Test`) |
| CLI: no session, no DB, stderr + log levels | php | implemented (`Flash_Alert_Cli_Test`) |
| Hourly retention sweep (any session, chunked) | php | implemented (`Flash_Alert_Sweep_Test`) |
| POST /_portal/login sets the ONE `rsx` cookie (no second cookie) | http | implemented (`http/flash_portal_login_cookie.sh`) |
| Client queue persistence across navigation | playwright | deferred |
