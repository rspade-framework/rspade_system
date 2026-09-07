# Test catalog: git_proxy

The git proxy (`system/bin/rsx-git.sh`) under the SUBMODULE model. Its entire surface is
now one job: after a git operation that can move the RECORDED revision of the system/
submodule, make the submodule agree. Everything else is passthrough.

The tests that used to live here described the vendored model - staging and read
exclusions, commit unstaging, `commit -a` rewriting, stash escapes, index-lock retry on a
100k-entry index, and PHASE 0 release reconciliation - and were deleted with it.

Fixture: `cli/_lib_fixture`. `fx_build_project` builds a project whose system/ is a
submodule of a fake framework repo carrying two revisions; `fx_build_project framework_dev`
builds the MONOREPO shape instead (system/ vendored, no submodule), because that is what
the monorepo actually is. `fx_publish_framework_update` simulates a colleague moving the
revision and pushing - the only state the proxy exists to fix.

| ID | Purpose (what it proves) | Expected |
|----|--------------------------|----------|
| GP-03 | A pull that moves the revision syncs the submodule, inside a maintenance window | submodule follows the record; window raised and lowered; a second pull is silent |
| GP-07 | FAIL-OPEN: a missing maintenance script, a broken artisan, an unknown subcommand - git still works | the sync still lands; the operation's own exit code always reaches the caller |
| GP-08 | Passthrough fidelity: reads are byte-identical to plain git, exit codes are git's, nothing announced. A dirty submodule is hidden by `ignore = dirty`, a MOVED revision is not | identical output; ` M system` absent for churn, present for a real move |
| GP-09 | Monorepo inertness: with IS_FRAMEWORK_DEVELOPER=true the proxy is exactly git | nothing announced, nothing wrapped |
| GP-11 | The Claude Code git-guard installer swaps cleanly | see the test |
| GP-16 | The subcommand classifier steps past git's global options, fires for every ref-moving operation, and never for the others | a pull behind `-c`/`--no-pager` is still a pull; status/log/diff never wrapped |
| GP-17 | The sync leaves system/ PRISTINE: tracked edits, untracked files and ignored residue all discarded, silently. Project storage (outside the submodule) survives. rsx:clean runs AFTER the checkout (artisan refuses to boot while gitlink and HEAD disagree) and with --_no-system-reset | submodule clean at the recorded revision; nothing reported about the discarded work |
| GP-19 | CWD fidelity: `RSX_GIT_CWD` is honoured, a cwd of system/ is undone to the project root, a genuinely-chosen cwd is respected | a relative pathspec finds its history from any directory |
| GP-21 | A CONFLICTED gitlink is reported, never resolved - which release to run is a decision, not a merge | the three resolving commands printed; nothing automated; conflict left in place |
| GP-23 | After a pull/merge that succeeded and MOVED HEAD, the proxy runs `post-update.sh --quiet` - and never when HEAD did not move, never for another subcommand, and never at the cost of the pull's exit code | invoked exactly once, with --quiet; a failing post-update is a warning only |
| GP-24 | A pull across a REWRITTEN framework history: the proxied invocation never recurses into system/, so intermediate gitlinks naming discarded framework revisions are never asked for and the reconciliation gets to run. An explicit --recurse-submodules still reaches git | pre-fix proxy aborts on "not our ref" with HEAD unmoved; shipped proxy merges and system/ lands on the recorded revision; the flag still recurses |
