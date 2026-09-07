# Portal (concern)

## Domain

The client portal: a separate authenticated experience for external users
(Portal_User_Model), isolated from staff (Login_User_Model/User_Model) by a
different session (Portal_Session), cookie, routes (`#[Portal_Route]`,
`@portal_spa()`), and bundle (Portal_Bundle).

This concern currently covers **portal authorization (T1)** - the contract that
decides which portal user may see which record and reach which route.

## Source under test

Framework core:
- `Core/Portal/Portal_Permission_Abstract.php` - marker base for the app facade
- `Core/Portal/Portal_Authorizable.php` - trait supplying the gated `portal_fetch()`
- `Core/Js/Portal_Permission.js` - client mirror (UI affordance hiding only)
- `Core/Models/Portal_User_Model.php` - own-record `portal_can_read()`
- `Core/Database/Orm_Controller.php` - dispatches portal requests to `portal_fetch()`
- `Core/Bundle/Rsx_Bundle_Abstract.php` - injects `window.rsxapp.portal`
- `Core/Database/Models/Rsx_Site_Model_Abstract.php` - `get_current_site_id()`, the tenant
  boundary; it forks on the EXPERIENCE of the request (B-76)
- `Core/Time/Rsx_Time.php`, `Core/Sms/Rsx_Sms.php`, `Core/Settings/Rsx_Settings.php`,
  `Core/Throttle/Rsx_Throttle.php` - the other site seams, same fork
- `Lib/Flash/Flash_Alert.php` - flash alerts are EXPERIENCE-scoped (`_flash_alerts.is_portal`);
  the tests live in the `flash` concern
- `CodeQuality/Rules/PHP/PortalModelFetchAuthCheck_CodeQualityRule.php` (PORTAL-MODEL-FETCH-01)
  - the RECORD-level contract only (`portal_can_read()` declared and called).
    PORTAL-AUTH-01 was retired with the declarative auth-gate flip: a portal
    surface's gate is an `#[Auth(...)]` attribute and the manifest build fails
    without one (see the `auth_gates` concern).

Application (template):
- `rsx/portal_permission.php` - concrete `Portal_Permission` facade
- `rsx/portal_main.php` - non-auth portal middleware (route gating is declarative:
  `#[Auth]` on each portal surface, enforced at the Portal_Dispatcher seam)
- `rsx/models/{portal_membership,shared_item,portal_project}_model.php` - per-model
  `portal_can_read()` rules

## Defining man page

`man/portal.txt` -> "AUTHORIZATION" section.

## Testable surface

- own-record visibility (portal user reads only their own row)
- shared-recipient visibility (only the linked contact reads a shared item)
- membership-scoped visibility (viewer vs collaborator; non-member denied)
- `Portal_Permission` identity / role / `can_collaborate` / `accessible_client_ids`
- `portal_fetch()` gate (declarative `#[Auth('is_logged_in')]` on the model, portal
  registry) + `portal_can_read()` deferral
- wrong-site isolation
- SITE RESOLUTION BY EXPERIENCE: a portal request scopes to the site the app DECLARED
  (`Portal_Session::set_site_id`), never to the staff session's site, and refuses loudly
  when nothing declared one
- route gating: every portal surface carries `#[Auth]`/`@auth`; anonymous access to a
  gated route redirects to the portal login with the intended URL captured
- the two code-quality rules fire on bad code and pass on the shipped code

See `test_catalog.md` for the per-test breakdown. Note the migration-provisioning
caveat in `issues_encountered.md` (ISSUE-1).
