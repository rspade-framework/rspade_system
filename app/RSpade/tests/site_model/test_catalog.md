# site_model - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SM-01 | A subclass overriding `booted()` and chaining `parent::booted()` first gets the `site` global scope installed, and its own `booted()` body still runs | php | anonymous `Rsx_Site_Model_Abstract` subclass overriding `booted()` w/ `parent::booted()` | `getGlobalScopes()['site']` set; extension flag true | implemented | 2026-08-03 |
| SM-02 | Site scope isolates reads: a foreign-site row is invisible to `find()` from another site, but still exists unscoped | php | seed row in site B, act as site A | `find()` null cross-site; visible in-site; unscoped find not null | implemented | 2026-08-03 |

Note: the parent-call chaining rule that catches a `booted()` override missing
`parent::booted()` is `PHP-PARENT-CHAIN-01`; its detection tests live in the
`code_quality` concern, not here. This concern covers the runtime isolation
behavior of the sealed protections.
