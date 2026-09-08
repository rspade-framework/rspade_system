---
name: code-quality-rules
description: "Writing and changing an RSpade code-quality rule against the driver contract - kind() per_file vs cross_file, depends_on() and fingerprint_extra(), the Manifest_Rule_Driver loop, Source_Cache (content/tokens/ast/class_node/declared_members - a rule never opens, parses or caches a file), the Validation_Ledger and its derived slots, where a build-time memo may live, @RULE-ID-EXCEPTION markers, and the tests/code_quality conventions. Use when adding a rule under CodeQuality/Rules/, making one manifest-time, diagnosing a rule that stopped firing or one that is slow on a cold build, or hitting Rule_Private_Cache_Test, Rule_Discovery_Depth_Test, or shouldnt_happen('Manifest::get_build_key() called before manifest was loaded')."
---

# Writing a code-quality rule

**THE DRIVER OWNS THE PASS; A RULE ONLY CHECKS.** Everything a pass costs - discovery,
pattern compilation, reading, tokenizing, parsing, and the decision to skip work already
done - is decided in `CodeQuality/Manifest_Rule_Driver.php` and nowhere else. A rule gets a
path, the contents and the manifest metadata, and says what is wrong with them.

`rsx:check` and the manifest-time pass run the SAME driver, so there is one loop, one
discovery, one source cache and one ledger contract for both.

Contract tier: `rsx:man code_quality`. Build context: `rsx:man manifest_build`.

---

## The two kinds

`kind()` decides how the driver runs the rule.

| | `KIND_PER_FILE` (default) | `KIND_CROSS_FILE` |
|---|---|---|
| Judges | one file, from its own bytes plus its manifest metadata | the TREE - duplicate names, an index, a relation between files |
| Called | once per CHANGED file whose basename matches `get_file_patterns()` | ONCE per pass, after the per-file pass |
| Skipped when | the file's hash already passed at this rule's fingerprint | nothing it declares in `depends_on()` moved |
| Handed | that file's path, contents and metadata | the FIRST indexed file matching its patterns - a starting point only; the rule iterates the index itself |

```php
public function kind(): string
{
    return self::KIND_CROSS_FILE;
}

public function depends_on(): array
{
    // A manifest section name under Manifest::$data['data'], or 'files:<basename glob>'.
    return ['files:*.php', 'php_classes', 'php_subclass_index'];
}
```

**A cross-file rule needs no self-guard** - the driver calls it once. Seventeen existing rules
still open `check()` with a never-reset `static $already_checked` from before `kind()` existed;
it is inert under the driver and WRONG in a process that runs two passes. Do not add one
(backlog B-114).

`DuplicateCaseFiles` is the cautionary tale for getting the kind wrong: declared per-file, it
guarded itself with a static flag it RESET at the end of every call, so it rebuilt a map of the
whole tree once per changed file - **18.5 s of a cold build** spent answering the same question
1,400 times.

### `depends_on()`

Each entry is a manifest section name (`php_classes`, `php_subclass_index`, `auth`, `routes`,
`models`, `js_classes`, ...) or `files:<basename glob>`, whose fingerprint is the sorted list of
matching indexed paths and their hashes.

**When in doubt, list `files:<your own patterns>`.** An UNDER-stated dependency is a rule that
silently stops firing; an over-stated one only costs time. An EMPTY `depends_on()` means "I read
the whole index" and the driver fingerprints against the build key, so the rule runs whenever
anything changed.

### `fingerprint_extra()`

The driver fingerprints a rule as `md5(<the rule's own file>) . fingerprint_extra()` and files
every ledger entry under `"<RULE ID>@<that fingerprint>"`. **Editing a rule retires every verdict
it ever recorded.** Return the hash of anything ELSE that decides the answer - a helper script, a
generated index, a config value. NAME-RESERVED-02 returns the hash of the reserved-name index, so
moving a framework name retires the verdicts recorded against the old one.

The `<id>@<fingerprint>` shape is GENERATIONAL: recording under one generation drops the older
ones, so the ledger holds one generation per rule rather than a graveyard.

---

## A rule NEVER opens, parses or caches a file

No `file_get_contents()`. No `token_get_all()` or `PhpToken::tokenize()`. No
`new ParserFactory`. No static property. No `mkdir()` / `storage_path()` / cache directory of
its own. All of them were once written per rule, and the retained streams and ASTs were
**1.12 GB of a 1,237 MB cold build** - memory proportional to FILES PARSED rather than to the
index being produced.

`tests/code_quality/php/Rule_Private_Cache_Test.php` greps `CodeQuality/Rules/` for every
spelling of both mistakes. **Nothing is whitelisted.**

The driver hands every rule ONE `Source_Cache`, reached as `$this->source()`:

```php
$this->source()->content($path, $hash)                  // raw bytes, '' when unreadable
$this->source()->tokens($path, $hash)                   // PhpToken::tokenize() output
$this->source()->ast($path, $hash)                      // one nikic parse, null on a syntax error
$this->source()->class_node($path, $class, $hash)       // that class's own AST node
$this->source()->declared_members($path, $class, $hash) // an AST-FREE summary (below)
$this->source()->token_array($code)                     // token_get_all() over a string in hand
$this->source()->php_tokens_of($code)                   // PhpToken::tokenize() over a string
```

Pass the manifest hash whenever you have the metadata: it is what says the bytes moved.

**Failure posture**: an unreadable file yields `''` / `[]` / `null` rather than throwing, and so
does a file with a syntax error. The lint stage reports syntax; a rule must not turn an
unparseable file into its own kind of failure.

State that must outlive one `check()` call is an INSTANCE property - the rule object dies with
the pass, a static array does not.

### `declared_members()` - ask for the summary, not the AST

A flat, node-free summary of what one class declares in one file:

```
methods    [lower name => name, line, abstract, static, visibility, attributes,
            has_body, parent_calls, exceptions]
properties [name => name, line, static, visibility, attributes, exceptions,
            has_default, default]   // `default` is the LITERAL default
```

`attributes` are lower-cased simple attribute names. `exceptions` are the `@<RULE-ID>-EXCEPTION`
markers found on that member. `parent_calls` names the methods a body calls through `parent::`
(plus `'*'` for a dynamic `parent::{$x}()`).

**It is the one bucket the LRU does not evict, and that is the whole point.** The four ancestry
rules (PHP-PARENT-CHAIN-01, SEALED-01, REVISION-01, POLY-01) each iterate the index and ask
structural questions about a class and its ancestors. The LRU holds 16 files and 700 do not fit
in 16, so each rule paid for its own full parse of the tree: **5,212 ms of a 10.3 s cold build**.
Sharing the AST could not fix that - the AST is exactly what the memory budget forbids retaining.
A summary is proportional to the INDEX, which is what the budget permits to grow. The file is
parsed ONCE for the pass and every later question is answered from the summary:
**5,212 ms -> 1,532 ms, cold build 10.3 s -> ~6.5 s, for +4.1 MB of peak.**

The pattern to copy, in order of preference:

1. answer from `declared_members()` and hold no nodes (SEALED-01, PHP-PARENT-CHAIN-01 - no AST
   at all);
2. bail on the summary before parsing (REVISION-01: `#[Revision_Parent]` is rare, and a class
   carrying none never needs nodes - **3 ms**);
3. bail on a content test you have already paid for (POLY-01: `str_contains($contents, 'morph')`,
   the read being the exception check it was doing anyway - **158 ms**, parsing only the files
   that do make morph calls);
4. parse, and accept that you are the rule that populates the summary for the tree.

One measured trap: computing `parent_calls` with a `NodeFinder` traversal per method made
PHP-PARENT-CHAIN-01 *worse* (3,153 ms), because every method paid for a walk where only methods
with a declarer used to. Two string tests decide it now - does the FILE contain `parent::`, then
does the method's own line range - with the AST authoritative for the ones that pass.

---

## Where a build-time memo may live

There are exactly three sanctioned homes, and none of them is a directory a rule creates.

| Want | Home |
|---|---|
| "this file already passed this check" | `Validation_Ledger` - and the DRIVER writes it, not you |
| a derived FILE (a sanitized copy, a parse tree) | `App\RSpade\Core\Cache\File_Content_Cache`, which owns `storage/rsx-tmp/derived/<namespace>/` |
| a derived VALUE that was expensive to compute | `RsxCache::get_persistent()` / `set_persistent()`, keyed on a CONTENT HASH |

**The build-scoped `RsxCache::get()` / `set()` is unusable at manifest time, by construction.**
Its key is prefixed with `Manifest::get_build_key()`, and during a build the manifest is not
ready - the accessor raises
`shouldnt_happen('Manifest::get_build_key() called before manifest was loaded')`. That is an
owner ruling made visible as a fatal: a build-scoped memo would be a memo of the build that is
still being computed. Use `get_persistent()`, key it on a content hash so a stale entry is
impossible rather than merely unlikely, and version the key prefix when the payload's shape
changes (`Manifest_unindexed_framework_classes_v1_<fingerprint>` is the worked example;
JQHTML-IMPL-01 keys on the file's own manifest hash).

**Never a VIOLATION LIST.** Record the clean verdict and recompute the few files that are not
clean - their violations are then reported from live source rather than from a memo. JS-THIS-01
kept 275 documents, almost all of them the two bytes `[]`; JS-JQHTML-01 kept 316 averaging 47
bytes.

### The Validation_Ledger, in the shape a rule author needs

One `var_export`'d array at `storage/rsx-tmp/persistent/validation_ledger.php`, shape version 2:

```php
['version' => 2,
 'rules'   => [ '<RULE ID>@<fingerprint>' => [ <file sha1> => 1 ] ],
 'derived' => [ '<RULE ID>@<fingerprint>' => [ 'deps' => <key>, 'tdeps' => <key> ] ]]
```

- **Per-file** verdicts live in `rules`, keyed by the manifest's own file hash - which is what
  lets a verdict SURVIVE a manifest clear.
- **Cross-file** premises live in `derived`, one slot per rule holding the CURRENT key rather
  than a set: `deps` is the fingerprint of the rule's `depends_on()` inputs taken over the tree
  WITHOUT the test trees, and `tdeps` - written only under a test run - the same over both
  halves. Both must match for the rule to be skipped.

**Why `derived` is a separate bucket**: `flush()` prunes every hash the manifest does not know,
and a cross-file premise is not a file hash - so filing it among the file hashes had it deleted
on every single flush. The cross-file skip had never once fired across processes, and every
build re-ran every cross-file rule. Two halves of the same defect sat beside it: the fixer's
structure map and the dependency fingerprint both counted the 635 test-tree files, so the
fingerprint flipped in both directions every time the suite started or stopped.

`flush()` is registered as a shutdown function on the first `record_pass()` AND called at the end
of the pass, so a later fatal costs nothing already earned. `rsx:clean` wipes `rsx-tmp`, the
ledger with it.

---

## `@RULE-ID-EXCEPTION` markers

A marker is `@<RULE ID>-EXCEPTION <rationale>` in a comment. **The driver reads it off the RAW
bytes, never off `$contents`** - `$contents` may be the SANITIZED copy with comments blanked, and
checking that would find the marker in no file at all.

Three levels, and which one you get depends on who honors it:

| Level | Honored by | Granularity |
|---|---|---|
| file | the driver (`honor_exception_comments`) and `CodeQualityChecker` | the whole file, before `check()` is called |
| member | `Source_Cache::declared_members()`, in `exceptions` | the method or property |
| line | the RULE itself, reading the marker on or above the line | one occurrence |

The manifest-time driver does not apply `CodeQualityChecker`'s generic file-level handling, so a
rule that needs its marker honored during a BUILD reads it itself. `SESSION-ID-01`
(`SessionIdNullCheck_CodeQualityRule`) is the worked example, and reading it itself is exactly
what gives it LINE granularity.

**A bare marker with no rationale suppresses nothing**, and in several rules it is itself a
violation. Test both.

---

## Manifest-time

`is_called_during_manifest_scan()` returning `true` makes a rule run on **every file change in
development** and lets it ABORT THE BUILD. It defaults to `false` and stays there unless a
framework maintainer says otherwise. The bar: a critical convention that would break the
application, needing feedback before the code runs.

The mechanism is save-then-check with a poison flag: `Manifest_Store::_save()` writes a CLEAN
index and clears `manifest_is_bad`, then `Manifest_Indexer::_run_manifest_time_code_quality_checks()`
runs the pass. A violation raises the sidecar flag and throws `YoureDoingItWrongException`; the
flag makes `_load_cached_data()` refuse the cache, so the next build is a FULL one, the same
violation re-fires, and the source stays broken until it is fixed. **An index is never written
from a failure path - the flag is.**

A manifest-time rule is also the rule whose cost you can see:

```bash
CONSOLE_DEBUG_FILTER=MANIFEST php artisan rsx:manifest:build
```

prints `Cross-file rule <ID>: <n>ms` for every cross-file rule and `Module <name>: <n>ms` for
every module. A cross-file rule is the most expensive thing a build does; this is how you learn
which one. **A run that lost the build lock measured a LOAD, not a build** (peak ~46 MB, no rule
lines) - discard it, and take medians of runs that actually built.

---

## Writing the rule

Place it under `CodeQuality/Rules/<Category>/` (Blade, Common, Convention, Database, JavaScript,
Jqhtml, Manifest, Meta, Models, PHP, Scss) named `DescriptiveName_CodeQualityRule.php`. Discovery
RECURSES (`RuleDiscovery` uses `RecursiveIteratorIterator`) - its predecessor was
`glob('Rules/**' . '/*.php')`, and **PHP's `glob()` has no `**`**, so a rule filed two levels deep
would silently never have run. `Rule_Discovery_Depth_Test` writes one and proves it is found.

Required: `get_id()`, `get_name()`, `get_description()`, `get_file_patterns()`, `check()`.
Optional and `#[Replaceable]`: `kind()`, `depends_on()`, `fingerprint_extra()`,
`get_default_severity()`, `is_called_during_manifest_scan()`, `supports_console_commands()`.

**The docblock carries the WHY.** Every shipped rule's header says what the defect looks like in
the field, why it is silent at runtime, and why it is fatal rather than advisory - that text is
what a developer reads when the rule fires, and `rsx:check` prints the remediation as
authoritative.

**Never name a constant after a PHP keyword.** `const NAMESPACE = ...` is legal PHP, but
`self::NAMESPACE` LEXES as `T_NAMESPACE`, and `Php_Fixer` walks tokens to find where a file's
`use` block belongs - it read every use of the constant as the top of a new file and inserted
import blocks there, corrupting three files mid-build.

---

## Tests

Every shipped rule gets `tests/code_quality/php/<Rule>_Rule_Test.php`, plus a row in that
concern's `README.md` (what the rule is and what the test drives) and `test_catalog.md`.

The conventions, all of them learned the hard way:

- **Drive the rule's public `check()` over SYNTHETIC fixture sources**, with hand-built metadata
  where the rule reads it. Nothing is written to the real tree unless the rule reads the
  filesystem; several rules take a throwaway root.
- **Assemble the offending construct from STRING CONSTANTS.** The manifest scans the test tree
  under a test run, so a test file containing a real violation of the rule it tests is a
  violation - `Eval_Usage_Rule_Test` and `Session_Id_Null_Check_Rule_Test` both do this.
- **Fixture PATHS matter.** A rule that scopes itself by `rsx.manifest.scan_directories`, by
  `Rsx_Paths::under_application()`, or by an `rsx/` prefix judges the path you hand it - so a
  fixture path carries a real scan-directory segment even when no such file exists.
- **Name REAL symbols where the rule resolves them through the manifest.** An invented name
  passes for the wrong reason and proves nothing (`Name_Reserved_Reference_Rule_Test` names
  `_Sys_Controller`, `Manifest_Store`, `Ajax::_is_internal_call`).
- **Cover the negatives** - they are the half that keeps a rule honest. The construct inside a
  comment, inside a string literal, inside a docblock; a legitimate use of the same value; an
  identifier that merely ends in the token; vendor / `node_modules` / `.cdn-cache`.
- **Drive `CodeQualityChecker` instead of the rule** for the one row that tests FILE-level
  `@RULE-ID-EXCEPTION` suppression, because the checker is what honors it - unless the rule
  honors it itself, in which case say so in the docblock.
- **A rationale-less marker gets its own row.**

Run them: `php artisan rsx:test --framework --group=code_quality`.

Two meta-tests hold every rule regardless: `Rule_Private_Cache_Test` (no private cache, no
private parsing, no static property; no whitelist) and `Rule_Discovery_Depth_Test`.
