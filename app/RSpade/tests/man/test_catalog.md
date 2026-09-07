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
