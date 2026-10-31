# Test catalog: filesystem

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: php. Last updated: 2026-10-28.

## File_Put_Contents_Safe_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fs-01 | writes full content, returns byte count | write 12 bytes | returns 12, content readable | implemented |
| fs-02 | overwrite preserves permissions | 0600 file overwritten | new content, mode still 0600 | implemented |
| fs-03 | same-filesystem detection | tmp vs base_path | true | implemented |
| fs-04 | cross-fs write leaves no staging dir | dest on /dev/shm | content written, no .tmp_* left | implemented |

## Clean_Directory_Concurrent_Writer_Test (php, no DB)

`Clean_Command::clear_directory()` against a process writing into the tree mid-clean (the
`$before_rmdir_for_tests` seam stands in for the writer).

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fs-05 | undisturbed tree emptied, root kept | nested scratch tree | nothing left, root empty | implemented |
| fs-06 | directory written once mid-clean still removed | file added before its rmdir, once | nothing left, root empty | implemented |
| fs-07 | directory written on every attempt is left and reported | file added before every rmdir | that directory returned once, left in place; rest cleared | implemented |
| fs-08 | entry removed by another process first is not an error | directory removed before its rmdir | nothing left, no throw | implemented |

## Planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| fs-p-01 | returns false (not throw) on rename failure into a missing dir | php | needs a controlled unwritable/missing target | planned |
| fs-p-02 | rsx:clean removes orphaned .tmp_* dirs across the tree | cli | command-level; assert sweep behavior | planned |
| fs-p-03 | concurrent writers never yield a truncated read | php | hard to make deterministic; concurrency test | deferred (complexity) |
