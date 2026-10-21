# Test catalog: login_users

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-10-21.

## Login_User_Cli_Test (cli, default isolation) - rsx:users:password:set and rsx:users:email:set

The hidden prompt and `--password-stdin` are not driven here: Artisan::call() has no terminal and
no stdin. What is pinned is that a call with no terminal refuses rather than setting anything.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| luc-01 | the new password verifies and the old one does not; --user by email | --user=<email> --password=new | hash checks new, not old | implemented |
| luc-02 | every signed-in user session of the identity ends, nobody else's | two own sign-ins + a bystander's | sessions_ended 2; bystander still signed in | implemented |
| luc-03 | the ended sessions carry the password-change reason | one session | sessions_ended 1; sign_out_reason_id SIGN_OUT_PASSWORD_CHANGED | implemented |
| luc-04 | every refusal changes nothing | no source, two sources, empty, no --user, unknown user | each error code, exit 1; old password, session active | implemented |
| luc-05 | the address moves with its memberships on every site, trashed included; a re-addressed membership is untouched | three memberships across three sites, one trashed, one re-addressed | identity + two memberships moved, third unchanged; no session ends; repeat is action none | implemented |
| luc-06 | an address held by another identity, live or soft-deleted, is refused | the other identities' emails | email_in_use, message names the deleted identity; nothing changed | implemented |
| luc-07 | a missing or malformed --email is refused | '' and 'not-an-address' | email_required, email_invalid; nothing changed | implemented |

## Force_Password_Reset_Cli_Test (cli, default isolation) - rsx:users:force_password_reset

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fpr-01 | a staff identity is flagged by email and cleared by id; the password is untouched | --user=<email>, then --user=<id> --clear | envelope action/realm/user/was/is; stored flag 1 then 0; hash unchanged | implemented |
| fpr-02 | a portal user is flagged by id from another tenant's scope, by email only with --site | --portal-user=<id>; <email>; <email> --site --clear | flag 1; site_required; cleared | implemented |
| fpr-03 | the refusals | none; both realms; unknown id; contradicting --site | user_required, identity_conflict, portal_user_not_found, site_mismatch; nothing written | implemented |
| fpr-04 | the human output says what changed and that nothing was emailed | --user twice | "[OK] Password reset required", "No email was sent", then "already required; nothing changed" | implemented |
