# Test catalog: settings

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: php. Last updated: 2026-06-22.

## Settings_Test (php, $requires_db_reset + no-tx)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| set-01 | define + get returns the default | define text default 'Acme' | get == 'Acme' | implemented |
| set-02 | set overrides the default | set 'we do things' | get == 'we do things' | implemented |
| set-03 | forget reverts to the default | set then forget | get == default | implemented |
| set-04 | integer round-trips as int | default 1, set 5 | int 1 then int 5 | implemented |
| set-05 | decimal preserves precision as string | default '8.25' | string '8.25' | implemented |
| set-06 | float round-trips as float | default 1.5 | float ~1.5 | implemented |
| set-07 | boolean round-trips as bool | default false, set true | false then true | implemented |
| set-08 | date type | default '2026-01-01' | '2026-01-01' | implemented |
| set-09 | datetime normalizes to ISO | default 'Z' datetime | non-null ISO string | implemented |
| set-10 | json round-trips as array | set nested array | array, nested preserved | implemented |
| set-11 | site-scoped values are per site | set site 1 + 2 | per-site values; unset site -> default | implemented |
| set-12 | site scope uses session site when omitted | acting_as_site(7) | implicit site 7 resolves | implemented |
| set-13 | is_defined | undefined vs defined key | false then true | implemented |
| set-14 | get undefined throws | get bad key | throws | implemented |
| set-15 | set undefined throws | set bad key | throws | implemented |
| set-16 | integer validation rejects non-integer | set 'not a number' | throws | implemented |
| set-17 | define is idempotent (updates) | define key twice | latest definition wins | implemented |
| set-18 | all() lists definitions with values | define a + b | both present with values | implemented |

## Deferred / planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| set-d-01 | define() called from a real migration seeds a definition | php | needs a migration fixture; covered indirectly by direct define() tests | deferred |
| set-d-02 | admin editor screen renders an input per type and saves | playwright | UI lives in the template app; browser test | planned |
| set-d-03 | secret values are masked in the admin UI / never sent to JS | playwright | UI-level behaviour | planned |
| set-d-04 | decimal scale from meta enforced/normalized on read | php | scale is currently advisory; decide enforcement first | deferred |
| set-d-05 | explicit null value vs forget() distinction | php | low-level edge; behaviour defined but untested | planned |
