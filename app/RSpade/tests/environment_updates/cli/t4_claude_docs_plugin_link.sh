#!/bin/bash
# t4: 060 owns the framework PLUGIN entry, .claude/skills/rspade. It creates it when the
# docs tree exists, repairs a dead one, and never touches a link pointing outside the
# framework tree. Quiet mode suppresses the informational lines and the advisories.

TEST_NAME="environment_updates/cli t4 claude docs plugin link"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

# 060 links the entry only when the docs tree is really there.
mkdir -p "$FX_APP/system/app/RSpade/docs/.claude-plugin"
printf '{}\n' > "$FX_APP/system/app/RSpade/docs/.claude-plugin/plugin.json"
links="$FX_APP/.claude/skills"

# The downstream path also rewrites rsx/resource/CLAUDE.md and can touch a home symlink;
# point the home seam at the sandbox so the real /root is never in play.
run_060() { fx_run 060_claude_docs.sh RSPADE_CLAUDE_HOME_DIR="$FX_ROOT/home" "$@"; }

run_060
fx_assert_rc 0
fx_assert_out "Linked .claude/skills/rspade -> ../../system/app/RSpade/docs"
fx_assert_link rspade '../../system/app/RSpade/docs'

run_060
fx_assert_rc 0
fx_assert_silent_stdout "a healthy environment must stay silent"

# A dead link is repaired.
rm -f "$links/rspade"
ln -s '../../system/app/RSpade/old_docs_location' "$links/rspade"
run_060
fx_assert_rc 0
fx_assert_out "Repaired the dead .claude/skills/rspade symlink"
fx_assert_link rspade '../../system/app/RSpade/docs'

# A link pointing OUTSIDE the framework tree is reported and left alone.
rm -f "$links/rspade"
mkdir -p "$FX_APP/my_own_docs"
ln -s '../../my_own_docs' "$links/rspade"
run_060
fx_assert_rc 0
fx_assert_err "points outside the framework tree"
[ "$(readlink "$links/rspade")" = '../../my_own_docs' ] || fx_fail "a foreign rspade link must be left untouched"

# QUIET: the create path says nothing on stdout, advisories included.
rm -f "$links/rspade"
run_060 RSPADE_ENV_UPDATE_QUIET=true
fx_assert_rc 0
fx_assert_silent_stdout "quiet mode must suppress 060's informational lines and advisories"
fx_assert_link rspade '../../system/app/RSpade/docs'

fx_pass
