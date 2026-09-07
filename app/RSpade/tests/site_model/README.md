# Concern: site_model (Rsx_Site_Model_Abstract isolation)

## Domain

`Rsx_Site_Model_Abstract` is the base for every multi-tenant, site-scoped model.
Its `booted()` installs the cross-tenant isolation controls:

1. a `site` global scope (`WHERE site_id = <current_site>`) - the read filter;
2. a `creating` hook that forces `site_id` from the session;
3. a `saving` hook that validates `site_id` (no cross-site / no re-homing);

plus a defensive `retrieved` cross-check.

`booted()` is the extension point. A subclass that needs its own model event
hooks overrides `booted()` and makes `parent::booted()` its first statement, so
the site protections are installed before its own hooks run. Forgetting
`parent::booted()` would silently drop all three controls (cross-tenant reads),
with no runtime exception - a fail-open confidentiality footgun. That mistake is
now caught fatally at manifest build by the default parent-call chaining rule,
`PHP-PARENT-CHAIN-01`: `booted()` is a chain-mandatory override (deliberately NOT
`#[Replaceable]`), so an override that omits `parent::booted()` is a fatal
manifest-time error until the call is added. (The rule's own detection tests live
in the `code_quality` concern; this concern tests the runtime isolation behavior.)

## Source under test

- `app/RSpade/Core/Database/Models/Rsx_Site_Model_Abstract.php` - `booted()`
  installs the site protections and is the chain-mandatory extension point.

## Man page

`app/RSpade/man/model.txt` (SITE-BASED MULTI-TENANCY section).

## Testable surface

| Behavior | Type | Status |
|----------|------|--------|
| A subclass overriding `booted()` + chaining `parent::booted()` gets the `site` scope, and its own body still runs | php | implemented |
| Cross-tenant `find()` of a foreign-site row returns null (visible unscoped) | php | implemented |
