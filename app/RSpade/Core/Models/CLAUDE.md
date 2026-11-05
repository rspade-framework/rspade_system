# Core/Models — the framework's own models

**Writing an application model? Skill `rspade:models` and `rsx:man model`. Customizing one of
these? `rsx:man class_override`.** This file says what is in this DIRECTORY, why every model
here is split in two, and what must stay true when editing it.

## The split: an abstract base plus an empty shell

Every model here is TWO files. `X_Model_Abstract.php` carries the whole implementation (table,
`$enums`, relationships, methods); `X_Model.php` is a shell and nothing else:

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
credential models, ...); this directory holds the identity, tenancy and messaging ones.

## What is here

- **Identity and tenancy**
  - `Login_User_Model` — the cross-site login IDENTITY (`login_users`): email, password,
    verification, `is_developer`, `timezone`, `dark_mode`. Not site-scoped, by definition; an
    actor (`Rsx_Actor_Model_Abstract`).
  - `User_Model` — that identity's MEMBERSHIP on one site (`users`): name, `role_id`,
    `is_enabled`, `is_api_access_enabled`. Site-scoped actor. `is_active()` / `->active()` is
    the one "usable membership" definition (the user and its site both enabled).
  - `Site_Model` — the tenant boundary (`sites`, `is_enabled`; site 0 can never be disabled).
  - `Portal_User_Model` — the client portal identity, one row per site; a site-scoped actor.
  - `User_Profile_Model` — 1:1 auxiliary profile columns, created on access.
  - `User_Permission_Model` — one GRANT/DENY row layered over a role (`_user_permissions`).
- **Preferences**: `Has_Preference_Variables` — the `get_variable()` / `set_variable()` /
  `forget_variable()` / `has_variable()` store on `Login_User_Model`, `User_Model` and
  `Portal_User_Model`, for a per-person fact not worth a column. A lifelong preference is a
  column; a value that dies with the browser is `Session::put_value()`.
- **Messaging**: `Email_Queue_Model`, `Email_Recipient_Model`, `Email_Attachment_Model`,
  `Email_Blocked_Address_Model`, `Sms_Queue_Model`, `Sms_Recipient_Model` — the outbound queue
  `Core/Mail/` and `Core/Sms/` drain; `Portal_Notification_Model` — portal-user notifications.
- **Reference data**: `Country_Model`, `Region_Model` (ISO 3166, seeded by
  `rsx:seed:geographic-data`).

## Overriding: which models are likely

`User_Model`, `Login_User_Model`, `Site_Model`, `Portal_User_Model` (with
`File_Attachment_Model` / `File_Storage_Model` in `Core/Files/`) are the likely-overridden set:
a change to one that the override must state for itself ships a `breaking_changes` document
(`breaking_changes/CLAUDE.md`, the fourth trigger). **A seam beats an override for policy**:
`Staff_Authorizable` / `Portal_Authorizable`, `fetch()`, `#[OnEvent]`. An actor must never be
hard-deleted, and its override keeps extending the base so the actor layer stays in place
(`ACTOR-01`).

## Invariants

- Behaviour goes on the base, never on the shell; the shell stays empty.
- An identity's timezone and dark mode live on `login_users`, set only by the person.
- The common model machinery (site scope, audit stamping, `$neverExport`, revisions) is
  `Core/Database/Models/Rsx_Model_Abstract` / `Rsx_Site_Model_Abstract`, not here.

## References

`rsx:man class_override`, `rsx:man model`, `rsx:man acls`, `rsx:man user_preference_variables`
· skills `rspade:models`, `rspade:actors-and-authorship`, `rspade:permissions-acl` ·
siblings `Core/Database/`, `Core/Manifest/` (the override map), `Core/Session/`.
