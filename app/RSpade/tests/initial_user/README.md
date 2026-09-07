# initial_user - the one account an application is born with

## Domain

`Rsx_Initial_User` is the single implementation of "create this application's first
account". Four paths reach it - the first-run setup screen, the `RSPADE_DEFAULT_*`
post-migrate step, the test-suite baseline seed, and an operator calling it
directly - and all four get the same two rows:

- `login_users` id **1** (activated, verified, active) - the credential.
- `users` id **1** - the site profile, enabled, in the resolved site.

The id is a CONTRACT, so it is ASSIGNED rather than left to AUTO_INCREMENT (the
counter can already have advanced past 1). A row already occupying id 1 on either
table is an impossible condition: `shouldnt_happen()`, because each caller has its
own "does this application need an initial user" check.

On success it fires the action **`user.initial.created`** with
`{user, login_user, site_id, source}`, handlers running INLINE in the creating
request or command. That event is where an application attaches its founder's own
rows - a group membership, admin ACLs - instead of a migration hardcoding user id 1.

## Source under test

- `app/RSpade/Core/Env/Rsx_Initial_User.php` - `create()`, `is_needed()`,
  `resolve_site_id()`, the id-1 assertion, the event fire.
- `app/RSpade/Core/Env/Rsx_First_User_Setup.php` - the first-run screen calling it.
- `app/RSpade/Commands/Rsx/Rsx_Test_Command.php` - `seed_test_baseline_user()`.
- `app/RSpade/Commands/Migrate/Maint_Migrate.php` - `create_initial_user_if_needed()`,
  the post-migrate step (after the final normalize pass; skipped by `--_no-initial-user`).
- `rsx/handlers/Initial_User_Handlers.php` - the reference application's handler.

## Man page

`php artisan rsx:man initial_user` (and the event's catalog row in
`php artisan rsx:man event_hooks`).

## Testable surface

- **php**: id assignment against an advanced AUTO_INCREMENT counter; the second-call
  assertion; `is_needed()`; the event payload and its `source`; that a handler in
  `/rsx/handlers/` fires; that the committed test baseline carries the handler's rows
  (proof the event fired during provisioning); that a caller-chosen role is not
  overruled by a handler.
- **not tested here**: the first-run setup screen's rendering (a web surface with its
  own dev-mode-only gating), and the post-migrate step's `RSPADE_DEFAULT_*` blank-value
  branches (environment-dependent - they turn on the running mode and the identity of
  the database being migrated).
