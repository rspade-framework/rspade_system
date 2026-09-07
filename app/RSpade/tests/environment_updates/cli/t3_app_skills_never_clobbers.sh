#!/bin/bash
# t3: augment-never-clobber. A real directory or a foreign symlink occupying a skill's
# name is REPORTED on stderr and left exactly as it is; the reserved name `rspade` (the
# framework plugin, owned by 060) is refused. None of these is a failure - they follow
# 060's exit semantics: only a filesystem operation that could not be performed exits 1.

TEST_NAME="environment_updates/cli t3 app skills never clobbers"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

links="$FX_APP/.claude/skills"

# 1. A real directory on the name.
fx_skill occupied
mkdir -p "$links/occupied"
printf 'developer content\n' > "$links/occupied/SKILL.md"

fx_run 070_app_skills.sh
fx_assert_rc 0 "a blocked name is reported, not a failure"
fx_assert_err "exists and is not a symlink"
fx_assert_err "Move it aside"
[ -d "$links/occupied" ] || fx_fail "the developer's directory must survive"
[ ! -L "$links/occupied" ] || fx_fail "the developer's directory must not be replaced by a symlink"
grep -q "developer content" "$links/occupied/SKILL.md" || fx_fail "the developer's content must be untouched"

# 2. A foreign symlink on the name, pointing somewhere that RESOLVES (so it is not dead).
rm -rf "$links/occupied"
mkdir -p "$FX_APP/elsewhere/occupied"
ln -s '../../elsewhere/occupied' "$links/occupied"
fx_run 070_app_skills.sh
fx_assert_rc 0
fx_assert_err "points elsewhere"
[ "$(readlink "$links/occupied")" = '../../elsewhere/occupied' ] || fx_fail "a foreign symlink must be left untouched"

# 3. The reserved plugin name.
fx_skill rspade
fx_run 070_app_skills.sh
fx_assert_rc 0
fx_assert_err "reserved name"
[ ! -e "$links/rspade" ] && [ ! -L "$links/rspade" ] || fx_fail "the reserved name must never be linked by 070"

# Problems are NOT suppressed by quiet mode.
fx_run 070_app_skills.sh RSPADE_ENV_UPDATE_QUIET=true
fx_assert_rc 0
fx_assert_silent_stdout "quiet mode suppresses only informational lines"
fx_assert_err "reserved name"
fx_assert_err "points elsewhere"

fx_pass
