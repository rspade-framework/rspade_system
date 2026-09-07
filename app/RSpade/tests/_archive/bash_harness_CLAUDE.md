# RSpade Testing Framework - AI Agent Instructions

**PURPOSE**: Guidelines for AI agents working with RSpade framework tests.

## QUICK START

```bash
cd /system/app/RSpade/tests

# Run all tests (creates fresh database snapshot first)
./run_all_tests.sh

# Run all tests using existing snapshot (faster)
./run_all_tests.sh --use-existing-snapshot

# Run with verbose output
./run_all_tests.sh --full-output

# Run a single test directly
./basic/01_framework_verification/run_test.sh
```

## TESTING PHILOSOPHY

### The "Press Button, Light Turns Green" Philosophy

If the button doesn't turn the light green, **FIX THE BUTTON**. Don't paint the light green.

**Tests must be**:
- **Fully automated** - One command to run, no manual intervention
- **Deterministic** - Same input always produces same output
- **Repeatable** - Can run multiple times with identical results

**Manual intervention is FORBIDDEN.** If you find yourself running commands manually to fix test state, STOP and fix the test infrastructure instead.

## CRITICAL MANDATES

### 1. NEVER Switch to Production Database

Tests use `rspade_test` exclusively. Never touch `rspade`.

```bash
# WRONG
mysql -urspade -prspadepass rspade -e "SELECT * FROM users"

# CORRECT
test_db_query "SELECT * FROM users"  # Uses rspade_test
```

### 2. NEVER Hide Test Failures

```bash
# WRONG - Hiding failure
if ! some_command; then
    echo "PASS"  # Lying
    exit 0
fi

# CORRECT - Fail loud
if [ "$count" -ne 5 ]; then
    echo "FAIL: $TEST_NAME - Expected 5, found $count"
    exit 1
fi
```

### 3. Fix the Problem or Ask the User

Priority: Analyze error → Fix underlying issue → Ask user if unclear

Never work around failures. They reveal real issues.

## TEST STRUCTURE

```
/system/app/RSpade/tests/
├── run_all_tests.sh                # Test runner
├── _lib/
│   ├── test_env.sh                 # Test mode functions
│   ├── db_reset.sh                 # Database reset
│   ├── db_snapshot_create.sh       # Create snapshot
│   └── test_db_snapshot.sql        # Snapshot file
├── basic/
│   ├── 01_framework_verification/run_test.sh
│   └── 02_database_connection/run_test.sh
└── tasks/
    └── 01_task_dispatch_and_execution/run_test.sh
```

## TEST SCRIPT PATTERN

```bash
#!/bin/bash
set -e

TEST_NAME="Descriptive Test Name"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

source "$TEST_DIR/../../_lib/test_env.sh"

SKIP_RESET=false
for arg in "$@"; do
    case $arg in
        --skip-reset) SKIP_RESET=true; shift ;;
    esac
done

trap test_trap_exit EXIT

echo "[SETUP] Preparing test..." >&2
test_mode_enter

if [ "$SKIP_RESET" = false ]; then
    "$TEST_DIR/../../_lib/db_reset.sh"
fi

echo "[TEST] Running assertions..." >&2

# Test logic here
count=$(test_db_count "users")
if [ "$count" -ne 0 ]; then
    echo "FAIL: $TEST_NAME - Expected 0 users, found $count"
    exit 1
fi

echo "PASS: $TEST_NAME"
exit 0
```

## TEST ENVIRONMENT FUNCTIONS

Source in every test:
```bash
source /system/app/RSpade/tests/_lib/test_env.sh
```

### test_mode_enter()

Switches to test database. Backs up `.env`, sets `DB_DATABASE=rspade_test`.

**Auto-recovery**: If `.env` is stuck in test mode from a previous interrupted run, automatically restores from backup and continues.

### test_mode_exit()

Restores original `.env`. Called automatically by trap on script exit.

### test_db_query(sql)

Execute SQL on test database, return single value:
```bash
name=$(test_db_query "SELECT name FROM users WHERE id=1")
```

### test_db_count(table [WHERE])

Count rows:
```bash
count=$(test_db_count "users WHERE active=1")
```

## SNAPSHOT SYSTEM

The test runner uses database snapshots for speed:

1. **First run** creates a snapshot after running all migrations
2. **Subsequent resets** restore from snapshot instead of re-running migrations
3. **After schema changes**, run without `--use-existing-snapshot` to recreate

### Expected Migration Output

When restoring from snapshot, you'll see migration errors like:
```
[ERROR] Migration failed!
Error: Table 'sessions' already exists
```

**This is normal.** The snapshot already contains the tables. The migration runner attempts to run them anyway but the restore already succeeded. Tests will pass.

## WRITING NEW TESTS

### 1. Create Directory

```bash
mkdir -p /system/app/RSpade/tests/category/NN_test_name
```

Naming: `category/NN_test_name` (e.g., `basic/03_routing`)

### 2. Create run_test.sh

Use the pattern above. Make executable: `chmod +x run_test.sh`

### 3. Verify Repeatability

Run 3+ times to confirm deterministic:
```bash
./run_test.sh && ./run_test.sh && ./run_test.sh
```

## DEBUGGING

### Read Error Messages

```
FAIL: Task Dispatch - Expected status 'completed', got 'pending'
```
Analysis: Task dispatched but didn't complete. Issue is in processing.

### Add Debug Output

```bash
echo "[DEBUG] Status: $status" >&2
test_db_query "SELECT * FROM _tasks" >&2
```

### Fix Root Cause

- Fix the code being tested, OR
- Fix the test infrastructure, OR
- Ask user for guidance

Never skip assertions or switch databases.

## TEST COMPOSITION

Use `--skip-reset` when chaining tests:

```bash
# Test B depends on Test A's state
./test_a/run_test.sh
./test_b/run_test.sh --skip-reset  # Don't reset, keep Test A's data
```

## AI AGENT GUIDELINES

**When tests fail:**
1. Read the error message
2. Analyze root cause
3. Fix underlying issue or ask user
4. Never work around failures

**Red flags - ask user:**
- Test fails only on subsequent runs
- Test requires manual setup
- Output shows errors but test passes
- Unclear what test should verify

**Create tests for:**
- Core framework systems
- Framework utilities
- Critical infrastructure

**Don't create tests for:**
- User application features (unless requested)
- Features not production-ready
