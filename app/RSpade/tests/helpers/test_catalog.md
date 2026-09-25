# Test catalog: helpers

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: php. Last updated: 2026-09-25.

## Bytes_To_Human_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| helpers-01 | units climb by 1024 while the value EXCEEDS 1024 (current boundary pinned) | 0, 1023, 1024, 1025, 1048576, 1048577 | `0 B`, `1023 B`, `1024 B`, `1 KB`, `1024 KB`, `1 MB` | implemented |
| helpers-02 | the ladder continues to GB and stops at PB | 5 GiB, 3 x 1024^6 | `5 GB`, `3072 PB` | implemented |
| helpers-03 | precision rounds, default 2 | 1587, 1587 at precision 1 | `1.55 KB`, `1.5 KB` | implemented |
| helpers-04 | numeric strings format; non-numeric input answers `---` | `'2048'`, `'abc'`, null | `2 KB`, `---`, `---` | implemented |
