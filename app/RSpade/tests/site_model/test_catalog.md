# site_model - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SM-01 | A subclass overriding `booted()` and chaining `parent::booted()` first gets the `site` global scope installed, and its own `booted()` body still runs | php | anonymous `Rsx_Site_Model_Abstract` subclass overriding `booted()` w/ `parent::booted()` | `getGlobalScopes()['site']` set; extension flag true | implemented | 2026-08-03 |
| SM-02 | Site scope isolates reads: a foreign-site row is invisible to `find()` from another site, but still exists unscoped | php | seed row in site B, act as site A | `find()` null cross-site; visible in-site; unscoped find not null | implemented | 2026-08-03 |
| SM-03 | a typed portal domain normalises to a bare lowercase host (scheme, port, path, trailing dot, case, xn-- kept) | php | `normalize_portal_domain()` over eight spellings | the bare host | implemented | 2026-10-22 |
| SM-04 | an empty portal domain is null | php | null, '', spaces, 'https://', '.' | null | implemented | 2026-10-22 |
| SM-05 | a value that is not a hostname is refused (single label, IP, userinfo, bad labels, non-ASCII, too long) | php | twelve values | InvalidArgumentException "must be a hostname" | implemented | 2026-10-22 |
| SM-06 | a save stores the normalised domain, and NULL for blank | php | save, then read the row | jobs.example-agency.test / NULL | implemented | 2026-10-22 |
| SM-07 | a save refuses a domain that is not a hostname | php | 'not a host' | throws | implemented | 2026-10-22 |
| SM-08 | a domain addresses one site (uk_sites_portal_domain, after normalisation) | php | two sites, two spellings | QueryException | implemented | 2026-10-22 |
| SM-09 | is_portal_enabled is on by default and cast to a boolean | php | new site, then off | true / false | implemented | 2026-10-22 |

Note: the parent-call chaining rule that catches a `booted()` override missing
`parent::booted()` is `PHP-PARENT-CHAIN-01`; its detection tests live in the
`code_quality` concern, not here. This concern covers the runtime isolation
behavior of the sealed protections.
