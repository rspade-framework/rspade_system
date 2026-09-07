#!/bin/bash
# t34: the vendored -> submodule CONVERSION records `ignore = dirty` in .gitmodules.
#
# `git submodule add` writes path/url/branch and nothing else, so a converted project
# would inherit git's default (`none`) and report ` M system` on every status forever -
# system/ is permanently dirty with the build's .php <-> .php.upstream churn. The answer
# operators reach for is a repo-wide `[diff] ignoreSubmodules = all`, which hides the
# recorded POINTER as well: the framework version then changes with no trace in status,
# diff or the commit summary (field report, 2026-08-22).
#
# `dirty` is the one value that shows a moved pointer and hides the churn, and it belongs
# in .gitmodules, which is TRACKED - so every clone of the converted project inherits it.
# bin/publish writes the same line into the starter; this proves a converted project ends
# up the same shape as a freshly cloned one.
#
# Boxes converted BEFORE this landed are healed by
# system/bin/environment_updates/080_submodule_ignore_dirty.sh (covered by
# environment_updates/cli t6).

TEST_NAME="framework_update/cli t34 conversion sets ignore=dirty"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT

[ -f /.rspade_container ] || { echo "SKIP: $TEST_NAME - the conversion is container-only"; exit 0; }

fx_init
fx_build_upstream

# A project still carrying system/ as ordinary tracked files - the pre-submodule shape.
fx_build_downstream_vendored v1

# The conversion CLONES the distribution into system/, and the fixture's distribution is a
# local bare repo. git refuses file:// submodule clones by default (CVE-2022-39253), and a
# repo-LOCAL protocol.file.allow is not honored by the clone subprocess - GIT_CONFIG_* is,
# so it reaches every git process the updater spawns. This is fixture plumbing only; the
# real upstream is https.
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=protocol.file.allow GIT_CONFIG_VALUE_0=always

fx_run_pull --no-rebuild --yes
[ "$FX_RC" -eq 0 ] || fx_fail "expected exit 0 from the converting pull, got $FX_RC. Output: $FX_OUT"

# The conversion happened at all.
[ -f "$PROJECT/.gitmodules" ] || fx_fail "no .gitmodules - the conversion did not run. Output: $FX_OUT"
[ "$(git -C "$PROJECT" ls-tree HEAD -- system | awk '{print $1}')" = "160000" ] \
    || fx_fail "system is not recorded as a gitlink after the conversion"

# The setting itself.
ignore="$(git config --file "$PROJECT/.gitmodules" --get submodule.system.ignore 2>/dev/null || true)"
[ "$ignore" = "dirty" ] || fx_fail "submodule.system.ignore is '$ignore', expected 'dirty'"

# It is COMMITTED, not merely written: an uncommitted .gitmodules reaches no clone.
committed="$(git -C "$PROJECT" show HEAD:.gitmodules 2>/dev/null | grep -c 'ignore = dirty' || true)"
[ "$committed" -ge 1 ] || fx_fail ".gitmodules carries ignore=dirty in the worktree but not in the commit"

# And it does what it is for: build churn inside the submodule stays out of status,
# while a MOVED pointer does not.
printf 'churn\n' > "$PROJECT/system/scratch_churn.txt"
status="$(cd "$PROJECT" && git status --porcelain 2>/dev/null)"
printf '%s' "$status" | grep -q '^ M system$' \
    && fx_fail "a dirty submodule leaked into status despite ignore=dirty. status: $status"
rm -f "$PROJECT/system/scratch_churn.txt"

fx_pass
