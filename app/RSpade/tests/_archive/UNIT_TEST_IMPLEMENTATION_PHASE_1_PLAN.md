# Unit Test Implementation - Phase 1 Plan

Created: 2025-12-25

## Objective

Implement automated bash tests from .expect files, focusing on backend features testable via artisan commands. Mark coverage in .expect files and add TODOs for incomplete coverage.

## Scope

**168 total .expect files** categorized as:

| Category | Count | Phase 1 Focus |
|----------|-------|---------------|
| Commands/Rsx | 18 | HIGH - artisan testable |
| Commands/Migrate | 12 | HIGH - critical infrastructure |
| Commands/Database | 5 | MEDIUM - requires test DB |
| Commands/Restricted | 14 | SKIP - destructive operations |
| Commands/Framework | 2 | SKIP - git operations |
| Commands/Refactor | 7 | SKIP - elaborate setup |
| Commands/Thumbnails | 3 | SKIP - file system heavy |
| Core/Database | 12 | MEDIUM - model behaviors |
| Core/Models | 10 | MEDIUM - model specifics |
| Core/Ajax | 6 | MEDIUM - API behaviors |
| Core/Dispatch | 8 | MEDIUM - routing behaviors |
| Core/Js | 23 | SKIP - frontend JavaScript |
| Core/SPA | 7 | SKIP - frontend SPA |
| Core/Manifest | 5 | LOW - complex internals |
| Core/Providers | 6 | LOW - bootstrap internals |
| Core/Bundle | 7 | SKIP - compilation heavy |
| Core/Session | 1 | MEDIUM - session behaviors |
| Core/Task | 1 | HIGH - already has test |
| Other | 21 | SKIP - various |

## Phase 1 Priorities

### Priority 1: Artisan Commands (Easy to Test)

These commands have clear input/output that can be tested via bash:

1. **rsx:man** - Documentation lookup
   - List all docs
   - Term matching (exact, partial)
   - --agent-helper-message flag
   - Error handling (not found)

2. **rsx:manifest:show** - Manifest inspection
   - Show class details
   - List all classes

3. **rsx:bundle:show** - Bundle inspection
   - List bundles
   - Show bundle details

4. **migrate:status** - Migration status
   - No session active
   - Session active display

5. **rsx:check** - Code quality
   - Clean output
   - Violation detection

6. **rsx:manifest:stats** - Manifest statistics
   - Count output

### Priority 2: Database/Model Behaviors

Require test database but straightforward:

1. **Rsx_Model_Abstract** - Core model behaviors
   - Mass assignment prevention
   - Eager loading prevention
   - Enum system

2. **Session** - Session management
   - Login state checks

### Priority 3: Skip for Now

- Frontend JavaScript (Core/Js/*)
- SPA routing (Core/SPA/*)
- Destructive commands (Commands/Restricted/*)
- Complex refactoring (Commands/Refactor/*)
- Git operations (Commands/Framework/*)
- Thumbnail operations (Commands/Thumbnails/*)
- Bundle compilation (Core/Bundle/*)

## Test Implementation Pattern

```bash
#!/bin/bash
set -e

TEST_NAME="Descriptive Test Name"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

source "$TEST_DIR/../../_lib/test_env.sh"

trap test_trap_exit EXIT

echo "[SETUP] Preparing test..." >&2
test_mode_enter

# Test assertions
output=$(php artisan command:name 2>&1)
if ! echo "$output" | grep -q "expected string"; then
    echo "FAIL: $TEST_NAME - Expected 'expected string' in output"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
```

## Coverage Marking Format

When a test covers an expectation in a .expect file:

```
## Behavior Name

EXPECT: Description
GIVEN: Precondition
WHEN: Action
THEN: Result
# Covered: tests/category/NN_test_name/run_test.sh
# Added: 2025-12-24
---
```

For incomplete coverage:

```
# Covered: tests/category/NN_test_name/run_test.sh
# TODO: Edge case X not tested
# Added: 2025-12-24
---
```

## Test Directory Structure

```
/system/app/RSpade/tests/
├── basic/
│   ├── 01_framework_verification/
│   ├── 02_database_connection/
│   └── 03_man_command/          # NEW
├── commands/
│   ├── 01_manifest_show/        # NEW
│   ├── 02_bundle_show/          # NEW
│   ├── 03_migrate_status/       # NEW
│   └── 04_rsx_check/            # NEW
├── models/
│   ├── 01_mass_assignment/      # NEW
│   └── 02_enum_system/          # NEW
└── tasks/
    └── 01_task_dispatch_and_execution/
```

## Execution Order

1. Create test directories
2. Implement Priority 1 tests (artisan commands)
3. Mark coverage in .expect files
4. Implement Priority 2 tests (database/models)
5. Mark remaining coverage
6. Run full test suite to verify

## Success Criteria

- All Priority 1 tests pass
- .expect files marked with coverage
- TODOs added for edge cases not covered
- Test suite runs cleanly with `./run_all_tests.sh`
