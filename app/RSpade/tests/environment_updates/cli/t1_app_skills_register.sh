#!/bin/bash
# t1: 070 registers one relative symlink per authored skill, ignores a directory that
# carries no SKILL.md, and is silent on a healthy re-run. Quiet mode suppresses the
# informational line on the CREATE path.

TEST_NAME="environment_updates/cli t1 app skills register"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

fx_skill invoice-imports
mkdir -p "$FX_APP/rsx/resource/skills/notes"          # no SKILL.md: not a skill
printf 'scratch\n' > "$FX_APP/rsx/resource/skills/notes/README.md"

fx_run 070_app_skills.sh
fx_assert_rc 0
fx_assert_out "Linked .claude/skills/invoice-imports -> ../../rsx/resource/skills/invoice-imports"
fx_assert_link invoice-imports '../../rsx/resource/skills/invoice-imports'
fx_assert_absent notes

# Idempotent AND silent - the contract's first rule.
fx_run 070_app_skills.sh
fx_assert_rc 0
fx_assert_silent_stdout "a healthy environment must stay silent"
[ -z "$FX_ERR" ] || fx_fail "a healthy environment must not warn: $FX_ERR"

# A second skill added later is linked without disturbing the first.
fx_skill weekly-report
fx_run 070_app_skills.sh
fx_assert_rc 0
fx_assert_out "Linked .claude/skills/weekly-report"
fx_refute_out "invoice-imports"
fx_assert_link invoice-imports '../../rsx/resource/skills/invoice-imports'
fx_assert_link weekly-report '../../rsx/resource/skills/weekly-report'

# QUIET: the create path prints nothing on stdout, and still does the work.
rm -f "$FX_APP/.claude/skills/weekly-report"
fx_run 070_app_skills.sh RSPADE_ENV_UPDATE_QUIET=true
fx_assert_rc 0
fx_assert_silent_stdout "quiet mode must suppress the informational create line"
fx_assert_link weekly-report '../../rsx/resource/skills/weekly-report'

# The monorepo runs the identical wiring (BOTH contexts).
rm -f "$FX_APP/.claude/skills/weekly-report"
fx_run 070_app_skills.sh IS_FRAMEWORK_DEVELOPER=true
fx_assert_rc 0
fx_assert_link weekly-report '../../rsx/resource/skills/weekly-report'

fx_pass
