# kernel - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| KERNEL-01 | Declared global middleware is APPENDED - app middleware runs after the framework stack, which is untouched | php | `['global' => [A, B]]` | framework entries unchanged and first; A, B in order at the end | implemented | 2026-08-19 |
| KERNEL-02 | Route middleware keys (`web`, `api`, `aliases`) never run - RSX requests do not pass through Laravel's router - so a non-empty one throws naming the key | php | `['web' => [A]]`, `['api' => [A]]`, `['aliases' => ['x' => A]]` | RuntimeException naming `rsx.middleware.<key>` | implemented | 2026-09-25 |
| KERNEL-03 | Any other key is a typo - it throws and names the one valid key | php | `['globl' => [A]]` | RuntimeException naming 'globl' and 'global' | implemented | 2026-09-25 |
| KERNEL-04 | (retired: aliases no longer exist - see KERNEL-02) | php | - | - | retired | 2026-09-25 |
| KERNEL-05 | (retired: aliases no longer exist - see KERNEL-02) | php | - | - | retired | 2026-09-25 |
| KERNEL-06 | A class that does not exist fails loudly rather than registering nothing | php | a misspelled class in `global` | RuntimeException naming the class | implemented | 2026-09-25 |
| KERNEL-07 | Re-declaring something already present is a silent no-op (the merge is idempotent) | php | a config naming an already-present global entry, merged twice | state identical after both merges; the entry appears exactly once | implemented | 2026-09-25 |
| KERNEL-08 | The SHIPPED empty config changes nothing - the path every real request takes; empty route-middleware keys are tolerated | php | `[]`, and all four keys empty | global stack identical; no groups, no aliases; `config('rsx.middleware')` is `['global' => []]` | implemented | 2026-09-25 |
| KERNEL-09 | Laravel's `Class::class.':params'` spelling survives: the class part is checked, the whole string registered | php | `['global' => [ThrottleRequests::class . ':30,1']]` | the full parameterised string at the end of the global stack | implemented | 2026-09-25 |
| KERNEL-10 | `app/Http/Kernel.php` is an owned file: a breaking change hard-syncs, a local edit is tamper-gated, `--force` restores pristine | cli | fixture v1 -> v2 with the kernel planted | covered by `framework_update/cli t25` (PULL-26e); not duplicated here | implemented | 2026-08-19 |
| KERNEL-11 | A declared middleware actually EXECUTES on a live request | http | a no-op middleware declared in config | deferred - would require permanently shipping a no-op middleware into the real request stack; the merge (KERNEL-01/02) plus Laravel's own dispatch cover it honestly | deferred | 2026-08-19 |
| KERNEL-12 | The ownership PAIR cannot go half-declared (`OWNED_FILES` vs `OWNED_ZONE_FILES`) | php | both declarations | planned - no seam reads the bash array from PHP today; the pair is enforced by the documented mandate and reviewed together | planned | 2026-08-19 |
| KERNEL-13 | TrimStrings trims ordinary input, flat and nested | php | `name`, `nested.city` with padding | trimmed | implemented (`Trim_Strings_Test`) | 2026-09-25 |
| KERNEL-14 | TrimStrings leaves any field whose name contains "password" alone, any case, any depth | php | four password-named keys | untouched | implemented (`Trim_Strings_Test`) | 2026-09-25 |
| KERNEL-15 | TrimStrings leaves a text-type envelope untouched | php | `{__TEXT, raw, empty}` with padded raw | identical envelope | implemented (`Trim_Strings_Test`) | 2026-09-25 |
