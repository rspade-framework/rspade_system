# test_runner - issues encountered

Two defects the parallel runner did not cause and cannot fix from its own side. Both were
found while reconciling a docker-mode run against a sequential one; both are recorded here
rather than worked around, because a workaround in the test image would hide a real bug in
the shipped development image and in a framework test. Issue 2 has since been fixed in the
framework; the diagnosis is kept because it is what the fix was built from.

## 1. A fresh dev container's mail catcher cannot file a message (Maildir subdirectories)

**Symptom.** Six mail tests fail inside a test container - `Mail_Catcher_Delivery_Test`'s
two send assertions and all four of `Mail_Test_Command_Cli_Test` - while
`test_the_catcher_advertises_itself_as_aiosmtpd` PASSES. So the catcher is listening, is
the right daemon, and refuses the message anyway. The container log carries:

```
('127.0.0.1', 40156) SMTP session exception
  File "/usr/lib/python3.12/mailbox.py", line 2116, in _create_carefully
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_RDWR, 0o666)
FileNotFoundError: [Errno 2] No such file or directory:
  '/var/www/html/storage/mail-catcher/tmp/1788615223.M588279P146Q1.rsx-test-w5.dev.local'
```

**Root cause.** A Maildir is a directory holding `tmp/`, `new/` and `cur/`. Python's
`mailbox.Maildir.__init__` creates those three ONLY when the maildir root does not already
exist:

```python
if not os.path.exists(self._path):
    if create:
        os.mkdir(self._path, 0o700)
        for subdir in ('tmp', 'new', 'cur'):
            os.mkdir(os.path.join(self._path, subdir), 0o700)
```

`system/bin/mail_catcher.py` calls `os.makedirs(maildir, exist_ok=True)` before
constructing the handler, and the docker entrypoint independently does
`mkdir -p storage/mail-catcher`. Either one is enough: by the time `Maildir` is
constructed the root EXISTS, the branch is skipped, and the three subdirectories are never
created. The catcher then starts, advertises itself correctly, and fails on the first
`DATA`.

**Why nobody sees it on a developer box.** A long-lived host got its `tmp/new/cur` at some
point when the root genuinely did not exist, and they have been there ever since. Every
container starts fresh, which is why the parallel runner surfaced it. It is not a test
artifact: a first-boot dev container has a broken mail catcher, silently, until somebody
sends mail.

**Remedy (NOT applied - this is a framework change, not a test-environment fix).**
`mail_catcher.py` should create the three subdirectories it needs rather than assuming the
Maildir constructor will, e.g. `os.makedirs(os.path.join(maildir, sub), exist_ok=True)`
for `tmp`, `new` and `cur`. One line, fixes the host and the container identically, and
does not touch the entrypoint.

## 2. The type-ref registry survives a test-database reset, so audit `*_type` columns resolve to the WRONG class [RESOLVED]

**Symptom.** In a parallel run, tests in `Audit_Stamp_Test` and `Audit_Delete_Stamp_Test`
fail with a resolved-but-wrong actor class:

```
test_a_table_without_the_deletion_pair_is_still_stamped_for_authorship
  Expected ''Login_User_Model'' but got ''User_Model''
test_a_record_with_no_site_stamps_the_login_user
  Expected ''Login_User_Model'' but got ''Site_Model''
test_the_created_by_relation_resolves_the_actor
  Expected instance of 'App\RSpade\Core\Models\User_Model' but got 'NULL'
```

**It is not flaky code, it is stale state.** The failures move between runs - one method in
one run, six across two classes in the next - because which classes precede which is a
function of how the queue packed that run. They pass when the class runs alone in a
container, and the whole `database` group passes in a container (74/74). So the container
environment is not the cause.

**Diagnosis.** An audit pair is `created_by_id` + `created_by_type`, where the `_type` half
is a type-ref INTEGER id resolved through `_type_refs`. Those ids are assigned in insertion
order, and the id-to-class map is CACHED. A `$requires_db_reset` class restores the test
database from the dump - which rolls `_type_refs` back to whatever the dump contains - but
nothing invalidates the cached map, so an id registered after the dump was taken keeps
pointing at the class it meant BEFORE the restore. The result is not a null: it is a
confidently wrong class, which is the worse failure mode.

The sequential runner never sees it because its class order is alphabetical and stable, so
the classes that register extra type-refs happen not to precede the audit classes. That is
luck, not isolation.

**Why the parallel runner exposes it.** The sequential runner takes classes in name order.
The docker runner seeds the queue longest-first and hands each class to whichever worker is
free, so a class can be preceded by a class it has never followed before. Class ordering
was never a guarantee the suite was entitled to lean on, but nothing had tested it.

**RESOLVED 2026-09-05.** The diagnosis above was one half of a wider defect: ANY database
transaction rollback can undo a write the cache already memoized, and the registry's process
statics and Redis entry both survive it. The per-test transaction rollback
(`Rsx_Test_Abstract::run`) hits it as squarely as the between-class restore does.

Owner ruling: if the cache cannot be reset at any moment, the cache is improperly
implemented. So the fix is not a special case for the test runner:

- `Transaction_Rollback_Cache_Reset` (wired from `Rsx_Framework_Provider::boot()`) calls
  `RsxCache::clear()` and `Type_Ref_Registry::_reset_cached_state()` on EVERY
  `TransactionRolledBack` event, at every nesting level.
- `Rsx_Test_Command::reset_test_db()` performs the same two calls, because dropping and
  restoring the database fires no rollback event.
- To make an unconditional flush affordable, non-cache state left database 0: locks and the
  task worker registry are database 1, the reduced-volatility cache (FPC, and `_RVC_`-prefixed
  cache keys) is database 2, and transient counters (`Rsx_Counter`) are database 3. The map
  lives in the `RsxCache` class header.
- Found while implementing it: `RsxCache::clear()` called `flushDb()`, which the shipped
  `redis.conf` DISABLES (`rename-command FLUSHDB ""`). It had been clearing nothing, silently,
  on every install carrying that conf. `clear()` is now SCAN + DEL and fails loud.

Regression coverage: `tests/cache/php/Cache_Rollback_Reset_Test.php` (CACHE-33, CACHE-34).

The tests were never weakened to make this pass.

**Related, and the same root cause:**
`Polymorphic_Retired_Type_Ref_Test::test_an_unreferenced_retired_type_ref_leaves_everything_else_working`
("a live class-name alias still resolves") fails intermittently in exactly the same way.

**Note for whoever picks it up:** the orchestrator does not currently log which class each
worker took in which order. Adding that to `<run_dir>/worker-N.log` (or to the queue
server's log) would turn a two-run bisect into a one-run reproduction.
