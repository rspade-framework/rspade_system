# Test catalog: login_requirements

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-10-07.

## Login_Requirements_Test (php, default isolation)

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| LR-01 | nothing outstanding is an ordinary sign-in | php | a user meeting every requirement | outstanding [], is_logged_in, get_user | implemented | 2026-10-07 |
| LR-02 | an unmet requirement conceals the identity from every reader | php | a user the fixture names, no surface bound | outstanding [fixture]; is_logged_in false; get_login_user_id / get_login_user / get_user null; the unconcealed id is held | implemented | 2026-10-07 |
| LR-03 | the screen and listed surfaces see the identity; others do not | php | bind screen, listed, unlisted | true, true, false | implemented | 2026-10-07 |
| LR-04 | meeting the requirement admits on the next refused request | php | bind unlisted, satisfy, new request, bind unlisted | admitted, list cleared | implemented | 2026-10-07 |
| LR-05 | ORDER, destination, recheck() | php | two unmet fixtures | ordered list; destination the first screen; recheck() the second, then null | implemented | 2026-10-07 |
| LR-06 | Ajax steers to the requirement | php | Ajax::execute unlisted, then listed | ERROR_REQUIREMENT_PENDING with the screen as destination; the listed endpoint runs | implemented | 2026-10-07 |
| LR-07 | a refused page redirects to the screen, and the screen is served | php | Dispatcher::dispatch unlisted page, then the screen | 302 to the screen; 200 with the marker | implemented | 2026-10-07 |
| LR-08 | signing out clears the list | php | pending user, logout | is_pending false | implemented | 2026-10-07 |
| LR-09 | impersonation is exempt unless the requirement says otherwise | php | impersonating sign-in; then applies_while_impersonating | not pending; then pending after recheck | implemented | 2026-10-07 |
| LR-10 | recheck_user() writes every live session's list | php | an inserted _sessions row; unmet then met | {"staff":[fixture]}, then NULL | implemented | 2026-10-07 |
| LR-11 | the portal realm is its own list | php | staff sign-in + a pending portal user on one session | portal concealed, staff not; the portal screen sees the portal user; portal destination | implemented | 2026-10-07 |
| LR-12 | the health row accepts well-formed requirements | php | the fixtures | OK, counts per realm | implemented | 2026-10-07 |
| LR-13 | a real browser on an SPA page is sent to the screen by requirement_pending | playwright | a pending user, an SPA route | lands on the screen | planned | 2026-10-07 |
