# kernel - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| KERNEL-01 | Declared global middleware is APPENDED - app middleware runs after the framework stack, which is untouched | php | `['global' => [A, B]]` | framework entries unchanged and first; A, B in order at the end | implemented | 2026-08-19 |
| KERNEL-02 | A group key appends to THAT group only, at its end | php | `['web' => [A]]` | `api` identical; `web` = framework entries + A | implemented | 2026-08-19 |
| KERNEL-03 | An unknown group key is a typo, not a new group - it throws and lists the valid keys | php | `['wbe' => [A]]` | RuntimeException naming 'wbe', 'web', 'api' | implemented | 2026-08-19 |
| KERNEL-04 | A new alias joins the framework aliases without disturbing them | php | `['aliases' => ['no_empty_strings' => A]]` | alias present; `auth` still the framework class | implemented | 2026-08-19 |
| KERNEL-05 | Rebinding an existing alias throws, naming BOTH classes, and the framework binding survives | php | `['aliases' => ['auth' => A]]` | RuntimeException naming 'auth', A and Authenticate; `auth` unchanged | implemented | 2026-08-19 |
| KERNEL-06 | A class that does not exist fails loudly rather than registering nothing | php | a misspelled class in `global`, then in `aliases` | RuntimeException naming the class / "which does not exist" | implemented | 2026-08-19 |
| KERNEL-07 | Re-declaring something already present is a silent no-op (the merge is idempotent) | php | a config naming already-present global/group/alias entries, merged twice | state identical after both merges; each entry appears exactly once | implemented | 2026-08-19 |
| KERNEL-08 | The SHIPPED empty config changes nothing - the path every real request takes | php | `[]` and the shipped all-empty block | all three properties byte-identical; `config('rsx.middleware')` is all-empty | implemented | 2026-08-19 |
| KERNEL-09 | Laravel's `Class::class.':params'` spelling survives: the class part is checked, the whole string registered | php | `['web' => [ThrottleRequests::class . ':30,1']]` | the full parameterised string at the end of `web` | implemented | 2026-08-19 |
| KERNEL-10 | `app/Http/Kernel.php` is an owned file: a breaking change hard-syncs, a local edit is tamper-gated, `--force` restores pristine | cli | fixture v1 -> v2 with the kernel planted | covered by `framework_update/cli t25` (PULL-26e); not duplicated here | implemented | 2026-08-19 |
| KERNEL-11 | A declared middleware actually EXECUTES on a live request | http | a no-op middleware declared in config | deferred - would require permanently shipping a no-op middleware into the real request stack; the merge (KERNEL-01/02) plus Laravel's own dispatch cover it honestly | deferred | 2026-08-19 |
| KERNEL-12 | The ownership PAIR cannot go half-declared (`OWNED_FILES` vs `OWNED_ZONE_FILES`) | php | both declarations | planned - no seam reads the bash array from PHP today; the pair is enforced by the documented mandate and reviewed together | planned | 2026-08-19 |
