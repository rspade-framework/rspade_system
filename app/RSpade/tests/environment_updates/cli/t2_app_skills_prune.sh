#!/bin/bash
# t2: the prune rule. A DANGLING symlink whose LITERAL target names one of our two
# prefixes is removed; a dangling FOREIGN link, a healthy link and a real directory are
# never touched. Authorship is decided on the literal target, never a resolved path.

TEST_NAME="environment_updates/cli t2 app skills prune"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

links="$FX_APP/.claude/skills"

# A skill that exists, and its healthy link.
fx_skill kept
fx_run 070_app_skills.sh
fx_assert_rc 0
fx_assert_link kept '../../rsx/resource/skills/kept'

# Two dead links of OURS - one per recognised prefix.
ln -s '../../rsx/resource/skills/deleted-skill' "$links/deleted-skill"
ln -s '../../system/app/RSpade/docs' "$links/rspade-gone"
# And a dead link that is NOT ours.
ln -s '../../somewhere/else' "$links/foreign-dead"
# And a real directory a developer put there by hand.
mkdir -p "$links/handmade"
printf 'x\n' > "$links/handmade/SKILL.md"

fx_run 070_app_skills.sh
fx_assert_rc 0
fx_assert_out "Pruned the dangling .claude/skills/deleted-skill symlink"
fx_assert_out "Pruned the dangling .claude/skills/rspade-gone symlink"

fx_assert_absent deleted-skill
fx_assert_absent rspade-gone
[ -L "$links/foreign-dead" ] || fx_fail "a dangling FOREIGN link must be left alone"
[ -d "$links/handmade" ] || fx_fail "a real directory must be left alone"
fx_assert_link kept '../../rsx/resource/skills/kept'

# A skill whose link went dead (target replaced by nothing) is retargeted, not left.
rm -f "$links/kept"
ln -s '../../rsx/resource/skills/kept-old-name' "$links/kept"
fx_run 070_app_skills.sh
fx_assert_rc 0
fx_assert_link kept '../../rsx/resource/skills/kept'

# QUIET suppresses the prune line too, and still prunes.
ln -s '../../rsx/resource/skills/gone-again' "$links/gone-again"
fx_run 070_app_skills.sh RSPADE_ENV_UPDATE_QUIET=true
fx_assert_rc 0
fx_assert_silent_stdout "quiet mode must suppress the informational prune line"
fx_assert_absent gone-again

fx_pass
