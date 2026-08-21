#!/usr/bin/env bash
#
# 020 - Remove the RSpade pre-commit hook.
#
# WHAT IT USED TO DO. This slot installed a pre-commit hook that kept the vendored
# framework tree out of ordinary app commits: it ran `rsx:clean` (which downstream
# resets system/ to its last commit) and unstaged any system/ path still staged
# afterwards, on the rule that system/ is committed ONLY by rsx:framework:pull.
#
# WHY IT IS GONE. That rule is a consequence of system/ being a VENDORED tree -
# ordinary tracked files in the app's own repository, written by the updater and
# owned by nobody else. A guard was needed precisely because git could not tell the
# framework's files from the application's: they lived in one index. Where system/
# is its own checkout that distinction is structural, git enforces it without help,
# and a hook that reaches into the app's index to unstage paths is a hazard rather
# than a safeguard - it can refuse a commit the developer meant to make.
#
# SO THIS SLOT NOW UNINSTALLS. A downstream environment that ever received the hook
# still has it: git does not distribute .git/hooks, so nothing removes it on its own,
# and it would keep unstaging and keep refusing commits long after the reason expired.
# Deleting the installer would leave every one of those boxes carrying it forever.
# The slot therefore stays occupied, doing the inverse job, until the fleet has turned
# over.
#
# ONLY OUR OWN HOOK IS TOUCHED, identified by the RSPADE-PRECOMMIT-V1 marker the
# installer wrote into every copy. A pre-commit hook without that marker is the
# developer's own and is left exactly as it is - the same rule the installer followed
# in reverse.
#
# Contract (environment_updates/CLAUDE.md): container-gated, self-detecting,
# idempotent, silent when there is nothing to do, non-fatal.

set -uo pipefail

# CONTAINER GATE. Every environment_updates script runs ONLY inside the RSpade
# container. These scripts modify the environment around the project - git hooks,
# editor and agent settings, the on-disk storage layout - and that environment is
# the container's, not the host's. Run on a host they would install container
# assumptions into somebody's own machine, silently and with no way to know it
# happened. Absent marker, absent consent: exit 0 and say nothing (the contract
# is silent-when-not-applicable, and this is a normal state, not a failure).
[ -f /.rspade_container ] || exit 0

PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"

# NOT gated on IS_FRAMEWORK_DEVELOPER. The installer never ran in the monorepo, so
# there is normally nothing here to find - but "remove it if it is there" is the
# right instruction in every context, and a monorepo that somehow acquired the hook
# should lose it too. The marker check below is what makes running everywhere safe.

git -C "$PROJECT_ROOT" rev-parse --git-dir >/dev/null 2>&1 || exit 0

# --git-path honors core.hooksPath; the result may be relative to PROJECT_ROOT.
hooks_dir="$(git -C "$PROJECT_ROOT" rev-parse --git-path hooks 2>/dev/null)"
[ -n "$hooks_dir" ] || exit 0
case "$hooks_dir" in
    /*) : ;;
    *)  hooks_dir="$PROJECT_ROOT/$hooks_dir" ;;
esac

hook="$hooks_dir/pre-commit"
marker="RSPADE-PRECOMMIT-V1"

# Nothing there: the overwhelmingly common path once the fleet has turned over.
[ -f "$hook" ] || exit 0

# Somebody else's hook. Not ours to delete, and saying nothing is correct - this is
# not a problem, it is a developer with their own pre-commit hook.
grep -q "$marker" "$hook" 2>/dev/null || exit 0

if rm -f "$hook"; then
    echo "[env] Removed the RSpade pre-commit hook ($hook) - system/ is no longer guarded out of app commits."
else
    echo "[env] pre-commit: failed to remove $hook - delete it by hand." >&2
    exit 1
fi

exit 0
