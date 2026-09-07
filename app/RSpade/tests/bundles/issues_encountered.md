# Bundles - issues encountered

## The development build hash cannot see a same-size edit inside one clock second

**Found:** 2026-09-03, while converting bundle concatenation to an RPC service.

**What the code does.** In development, every per-file build hash is metadata only -
`md5(path + size + mtime)` (`_rsx_file_hash_fast()`, `system/app/RSpade/helpers.php`), chosen
so a JIT rebuild notices a changed file without reading its bytes. Production uses a
content hash instead (`_rsx_file_hash_content_based()`), so this is a development-only
property.

**Why it matters.** `filemtime()` has one-second granularity. So an edit that does not change
a file's SIZE and lands in the same clock second as the previous compile produces a
byte-identical hash, the bundle cache reports a hit, and the developer is served a STALE
artifact with no error and nothing to see. Changing `red` to `blu`, `33px` to `99px`, or any
single character is such an edit.

**How it surfaced.** `BUNDLE-WATCH-03` began failing intermittently (roughly one run in
four) the moment concatenation stopped spawning a node process per bundle. The test swaps
`33px` for `99px` - identical length - and asserts the artifact is invalidated. Nothing about
invalidation changed; the compile simply got fast enough to run twice inside one second, at
which point the dev hash could no longer tell the two states apart. The old shell-out was
slow enough (a process spawn per bundle) that the second compile almost always landed in a
later second and the hole stayed hidden.

**What was done.** Nothing in the framework. The tests now advance the fixture's mtime past
the current second when they edit it (`__edit_fixture()`), which takes the clock out of an
experiment that is about watch invalidation rather than hash granularity - the helper says so
at the call site. Eight consecutive runs pass.

**What was NOT done, and is for the owner to decide.** The underlying hole is real and is
now easier to hit on every fast machine, not just in this test. The candidate fix is to make
development hashing content-based like production's - one hash function instead of two, and
deterministic - at the cost of reading the bytes of each bundled file on a cache check
(single-digit milliseconds for a template-sized bundle, since the files are in page cache).
That is a change to a deliberately-chosen performance path in the build's hottest loop, so
it is not made as a side effect of an unrelated fix.
