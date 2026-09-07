# Concern: settings

## Domain overview & applicability

`Rsx_Settings` is a database-backed key/value store for application behaviour
values -- credentials (e.g. a QuickBooks API key) and operational preferences
(e.g. `billing_dow_cutoff`). Like config values, but runtime-editable and stored
in the DB, with a per-key definition (label, type, scope, default). PHP-only by
design: no Eloquent model, no JS stub, no ORM/auto-API exposure; values reach the
client only when explicitly returned from an `#[Ajax_Endpoint]`.

## Source files

- `app/RSpade/Core/Settings/Rsx_Settings.php` - the facade (define/get/set/forget/all/is_defined)
- `database/migrations/2026_06_22_054710_create_settings_tables.php` - `_settings` + `_setting_values`

## Man page(s)

- `man/settings.txt`

## Testable surface

- define/get default, set override, forget revert. (php - commits, needs reset)
- All value types round-trip correctly: text, integer, decimal (string, precision
  preserved), float, boolean, date, datetime, json. (php)
- Scoping: site-scoped values per site; session-site default when omitted; global
  ignores site. (php)
- Guards: get/set on undefined key throws; type validation rejects mistyped value;
  define() is idempotent. (php)
- `all()` lists definitions with resolved values. (php)

`Settings_Test` sets `$requires_db_reset = true` + `$use_database_transactions =
false` because define()/set() commit and are read back; each test uses a unique
key.

## Documents

- `test_catalog.md` - full catalog.
- (no issues_encountered.md - feature authored alongside its tests.)
