---
name: merge-conflicts
description: "Resolving git merge conflicts as a code review - enumerating conflicted paths, reading the other side's intent with git log -p, per-hunk resolution defaulting to keep-both, escalating instead of guessing, verifying, and reporting what was kept and dropped. Use when git prints 'CONFLICT (content)' or 'Automatic merge failed', git status shows unmerged paths during a merge, rebase, cherry-pick or rsx:git pull, or before any --ours / --theirs / -X ours / reset --hard / stash on a conflict."
---

# Resolving merge conflicts

**A merge conflict is a code-review task, not a cleanup chore.** Both sides are work someone intended to keep. Discarding a side is a deliberate, justified decision - never a default, and never a batch operation.

## Forbidden unless the user directs it for that specific conflict

- `git checkout --ours/--theirs <path>`, `git merge -X ours/theirs` or `git checkout HEAD -- <dir>` over a conflict set you have not read hunk by hunk;
- resolving by file *category* ("just build artifacts / generated files / machine churn") - **category is a hypothesis, verify it per file**;
- `git stash` or `git reset --hard` to make a conflict "go away".

## Required procedure

1. **Enumerate** - `git status`, the full conflicted-path list, and state the count.
2. **Learn the other side's intent BEFORE resolving** - `git log -p -n 3 <other-branch> -- <path>`. You cannot judge a hunk without knowing what the incoming change was FOR.
3. **Resolve per hunk, with a reason.** Default posture is **keep both**; take one side only when they are genuinely mutually exclusive, and say why.
4. **Escalate rather than guess.** One question costs less than a silent rollback.
5. **Volume is a reason for MORE care, not less.** If the set is too big to review honestly, say so and ask.
6. **Verify before committing** - build, run the relevant tests, confirm the incoming feature still works.
7. **Report** what you kept from each side and anything you dropped.

## Why this is a mandate and not advice

A blanket resolution that looks like tidying can silently revert work someone intended to keep, with no error to show for it. Nothing fails, nothing warns, and the loss surfaces weeks later as a feature that "stopped working" with no commit that obviously removed it.

## Related

- A downstream app's `system/` submodule conflict during `rsx:git pull` / `rsx:framework:pull` has its own recipe: app skill `rspade:git-operations` (downstream only).
- Commits themselves are made only when the user asks (the git-discipline fragment).
