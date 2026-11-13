# Core/Models — the framework's own models

**Writing an application model? Skill `rspade:models` and `rsx:man model`. Customizing one of
these? `rsx:man class_override`.** This file says what is in this DIRECTORY, why every model
here is split in two, and what must stay true when editing it.

## The split: an abstract base plus an empty shell

Every model here is TWO files. `X_Model_Abstract.php` carries the whole implementation (table,
`$columns`, relationships, methods); `X_Model.php` is a shell and nothing else:

    class User_Model extends User_Model_Abstract
    {
    }

The shell exists so an application can REPLACE it without holding a copy of the
implementation. An app declares `class User_Model extends User_Model_Abstract` under
`rsx/models/` with only the members it changes; the manifest shadows the framework shell, the
app's class becomes `User_Model` for the whole tree, and it keeps inheriting every member the
framework adds to the base. **A copy of the base's implementation is REFUSED at manifest
build** (the SPLIT refusal, `Core/Manifest/CLAUDE.md`): a frozen clone silently misses the next
members the framework adds, which is exactly what the split prevents. A base member marked
`#[Replaceable]` is replaced outright; any other override calls `parent::`
(`PHP-PARENT-CHAIN-01`). A base never names its own shell in a method signature.

The same split is used for framework models that live beside their subsystem
(`Core/Api/Api_Key_Model`, `Core/Files/File_Attachment_Model`, the two-factor and SSO
credential models, the mail and SMS queues, the portal identity, the country and region
tables, ...); this directory holds the staff identity and tenancy ones.

## What is here

- **Identity and tenancy**
  - `Login_User_Model` — the cross-site login IDENTITY (`login_users`): email, password,
    verification, `is_developer`, `timezone`, `dark_mode`. Not site-scoped, by definition; an
    actor (`Rsx_Actor_Model_Abstract`).
  - `User_Model` — that identity's MEMBERSHIP on one site (`users`): name, `role_id`,
    `is_enabled`, `is_api_access_enabled`. Site-scoped actor. `is_active()` / `->active()` is
    the one "usable membership" definition (the user and its site both enabled).
  - `Site_Model` — the tenant boundary (`sites`, `is_enabled`; site 0 can never be disabled).
  - `User_Profile_Model` — 1:1 auxiliary profile columns, created on access.
  - `User_Permission_Model` — one GRANT/DENY row layered over a role (`_user_permissions`).

Beside their feature, in `Lib/`: `Portal_User_Model` and `Portal_Notification_Model`
(`Lib/Portal/`), the mail and SMS queue models (`Lib/Messaging/Mail/`, `Lib/Messaging/Sms/`),
`Country_Model` / `Region_Model` (`Lib/Geo/Places/`) and the `Has_Preference_Variables` trait
on `Login_User_Model`, `User_Model` and `Portal_User_Model` (`Lib/Settings/`).

## Overriding: which models are likely

`User_Model`, `Login_User_Model`, `Site_Model` (with `Portal_User_Model` in `Lib/Portal/` and
`File_Attachment_Model` / `File_Storage_Model` in `Core/Files/`) are the likely-overridden set:
a change to one that the override must state for itself ships a `breaking_changes` document
(`breaking_changes/CLAUDE.md`, the fourth trigger). **A seam beats an override for policy**:
`Staff_Authorizable` / `Portal_Authorizable`, `fetch()`, `#[OnEvent]`. An actor must never be
hard-deleted, and its override keeps extending the base so the actor layer stays in place
(`ACTOR-01`).

## Invariants

- Behaviour goes on the base, never on the shell; the shell stays empty.
- An identity's timezone and dark mode live on `login_users`, set only by the person.
- The common model machinery (site scope, audit stamping, the `$columns` declarations, derived columns, revisions) is
  `Core/Database/Models/Rsx_Model_Abstract` / `Rsx_Site_Model_Abstract`, not here.

## References

`rsx:man class_override`, `rsx:man model`, `rsx:man acls`, `rsx:man user_preference_variables`
· skills `rspade:models`, `rspade:actors-and-authorship`, `rspade:permissions-acl` ·
siblings `Core/Database/`, `Core/Manifest/` (the override map), `Core/Session/`.
