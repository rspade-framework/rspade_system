# Test catalog: man

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: cli. Last updated: 2026-06-16.

## cli/man_command.sh (cli - shell)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| man-01 | lists pages with no term | `rsx:man` | output includes spa, jqhtml | implemented |
| man-02 | exact term renders page | `rsx:man spa` | SPA content shown | implemented |
| man-03 | partial / fuzzy term match | partial term | resolves to a page | implemented |

(See the script for exact assertions; migrated from the prior bash suite.)

## Planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| man-p-01 | not-found term handled gracefully | cli | assert clean not-found message/exit | planned |
| man-p-02 | --agent-helper-message flag output | cli | flag-specific behavior | planned |
| man-p-03 | re-express as PHP cli test via Artisan::call | cli (php) | move off shell where in-process capture is clean | planned |

## Man_Tags_Test (php)

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| man-tag-01 | The TAGS section is parsed | php | a tagged and an untagged page | lowercased, trimmed, unique tags; none | implemented |
| man-tag-02 | --tag lists only the tagged topics | php | --tag=time | time listed, an untagged page not | implemented |
| man-tag-03 | Repeated --tag requires every tag | php | time+api; time+portal | time; none | implemented |
| man-tag-04 | --tags lists the vocabulary with counts | php | --tags | every vocabulary tag, time counted | implemented |
