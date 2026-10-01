# Test catalog: login_users

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-10-01.

## Login_User_Cli_Test (cli, default isolation) - rsx:users:password:set and rsx:users:email:set

The hidden prompt and `--password-stdin` are not driven here: Artisan::call() has no terminal and
no stdin. What is pinned is that a call with no terminal refuses rather than setting anything.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| luc-01 | the new password verifies and the old one does not; --user by email | --user=<email> --password=new | hash checks new, not old | implemented |
| luc-02 | every session of the identity ends, nobody else's | two own sessions + a bystander's | sessions_ended 2; bystander active | implemented |
| luc-03 | --keep-sessions ends nothing | one session, --keep-sessions | sessions_ended 0; still active; password changed | implemented |
| luc-04 | every refusal changes nothing | no source, two sources, empty, no --user, unknown user | each error code, exit 1; old password, session active | implemented |
| luc-05 | the address moves with its memberships on every site, trashed included; a re-addressed membership is untouched | three memberships across three sites, one trashed, one re-addressed | identity + two memberships moved, third unchanged; no session ends; repeat is action none | implemented |
| luc-06 | an address held by another identity, live or soft-deleted, is refused | the other identities' emails | email_in_use, message names the deleted identity; nothing changed | implemented |
| luc-07 | a missing or malformed --email is refused | '' and 'not-an-address' | email_required, email_invalid; nothing changed | implemented |
