# Test catalog: filesystem

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: php. Last updated: 2026-06-16.

## File_Put_Contents_Safe_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fs-01 | writes full content, returns byte count | write 12 bytes | returns 12, content readable | implemented |
| fs-02 | overwrite preserves permissions | 0600 file overwritten | new content, mode still 0600 | implemented |
| fs-03 | same-filesystem detection | rsx-tmp vs base_path | true | implemented |
| fs-04 | cross-fs write leaves no staging dir | dest on /dev/shm | content written, no .tmp_* left | implemented |

## Planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| fs-p-01 | returns false (not throw) on rename failure into a missing dir | php | needs a controlled unwritable/missing target | planned |
| fs-p-02 | rsx:clean removes orphaned .tmp_* dirs across the tree | cli | command-level; assert sweep behavior | planned |
| fs-p-03 | concurrent writers never yield a truncated read | php | hard to make deterministic; concurrency test | deferred (complexity) |
