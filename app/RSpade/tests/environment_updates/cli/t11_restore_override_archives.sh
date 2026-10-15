#!/bin/bash
# t11: 130_restore_override_archives.sh - restore framework files an earlier override pass
# archived as <file>.upstream, and delete the archives.
#
# A scratch git repository stands in for the system/ submodule, so `git checkout` restores a
# real tracked file. Proven:
#   1. a MISSING tracked file is restored from git and its archive deleted;
#   2. when both exist, the tracked file wins and the archive is deleted (it is stale);
#   3. vendor/ and node_modules/ are never touched;
#   4. an archive whose sibling git does not know is reported and left in place;
#   5. a second run is silent on both streams;
#   6. the monorepo (IS_FRAMEWORK_DEVELOPER=true) does nothing;
#   7. quiet mode still does the work and says nothing on stdout.

TEST_NAME="environment_updates/cli t11 restore override archives"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

SCRIPT_NAME="130_restore_override_archives.sh"
SYS="$FX_APP/system"

# ---- The scratch framework repository. -----------------------------------------------
mkdir -p "$SYS/app/RSpade/Core/Models" "$SYS/app/RSpade/Core/Ui" "$SYS/vendor/acme" "$SYS/node_modules/pkg"
printf '<?php\nclass Region_Model extends Region_Model_Abstract {}\n' > "$SYS/app/RSpade/Core/Models/Region_Model.php"
printf '<Define:Probe_Spinner tag="div"></Define:Probe_Spinner>\n' > "$SYS/app/RSpade/Core/Ui/Probe_Spinner.jqhtml"
printf '.Probe_Spinner { color: red; }\n' > "$SYS/app/RSpade/Core/Ui/Probe_Spinner.scss"

git -C "$SYS" init --quiet
git -C "$SYS" -c user.email=t@example.com -c user.name=t add -A
git -C "$SYS" -c user.email=t@example.com -c user.name=t commit --quiet -m fixture

TRACKED_PHP="$(cat "$SYS/app/RSpade/Core/Models/Region_Model.php")"

# The shape an earlier override pass left: the file renamed away (missing), and, for the
# jqhtml, an archive beside a file a framework update brought back.
mv "$SYS/app/RSpade/Core/Models/Region_Model.php" "$SYS/app/RSpade/Core/Models/Region_Model.php.upstream"
printf 'stale archived copy\n' > "$SYS/app/RSpade/Core/Ui/Probe_Spinner.jqhtml.upstream"

# Never in scope: vendor/ and node_modules/.
printf 'x\n' > "$SYS/vendor/acme/Thing.php.upstream"
printf 'x\n' > "$SYS/node_modules/pkg/index.js.upstream"

# ---- 1 + 2 + 3. Restore the missing file, drop both archives, leave vendor alone. -----
fx_run "$SCRIPT_NAME"
fx_assert_rc 0

[ -f "$SYS/app/RSpade/Core/Models/Region_Model.php" ] || fx_fail "the missing tracked file was not restored"
[ "$(cat "$SYS/app/RSpade/Core/Models/Region_Model.php")" = "$TRACKED_PHP" ] || fx_fail "the restored file is not the tracked content"
[ -e "$SYS/app/RSpade/Core/Models/Region_Model.php.upstream" ] && fx_fail "the archive of a restored file was not deleted"

[ -e "$SYS/app/RSpade/Core/Ui/Probe_Spinner.jqhtml.upstream" ] && fx_fail "an archive beside its tracked file was not deleted"
grep -q 'Define:Probe_Spinner' "$SYS/app/RSpade/Core/Ui/Probe_Spinner.jqhtml" || fx_fail "the tracked file lost to its stale archive"

[ -f "$SYS/vendor/acme/Thing.php.upstream" ] || fx_fail "a file under vendor/ was touched"
[ -f "$SYS/node_modules/pkg/index.js.upstream" ] || fx_fail "a file under node_modules/ was touched"

fx_assert_out "Removed 2 class-override archive(s)"
fx_assert_out "restoring 1 framework file(s)"
[ -z "$FX_ERR" ] || fx_fail "expected no stderr on a clean restore. stderr: $FX_ERR"
[ -z "$(git -C "$SYS" status --porcelain -- app)" ] || fx_fail "the framework tree is not pristine after the restore: $(git -C "$SYS" status --porcelain -- app)"

# ---- 4. An archive git cannot restore is reported and kept. ---------------------------
printf 'only copy\n' > "$SYS/app/RSpade/Core/Ui/Untracked_Thing.js.upstream"

fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_err "Untracked_Thing.js.upstream"
[ -f "$SYS/app/RSpade/Core/Ui/Untracked_Thing.js.upstream" ] || fx_fail "an archive with no tracked sibling was deleted - it may be the only copy"
rm -f "$SYS/app/RSpade/Core/Ui/Untracked_Thing.js.upstream"

# ---- 5. Idempotent: the second run is silent. -----------------------------------------
fx_run "$SCRIPT_NAME"
fx_assert_rc 0
fx_assert_silent_stdout "an already-applied environment update must print nothing"
[ -z "$FX_ERR" ] || fx_fail "expected no stderr on a no-op run. stderr: $FX_ERR"

# ---- 6. Downstream only. --------------------------------------------------------------
mv "$SYS/app/RSpade/Core/Ui/Probe_Spinner.scss" "$SYS/app/RSpade/Core/Ui/Probe_Spinner.scss.upstream"

fx_run "$SCRIPT_NAME" IS_FRAMEWORK_DEVELOPER=true
fx_assert_rc 0
fx_assert_silent_stdout "the monorepo must do nothing"
[ -f "$SYS/app/RSpade/Core/Ui/Probe_Spinner.scss.upstream" ] || fx_fail "the monorepo run touched an archive"
[ -e "$SYS/app/RSpade/Core/Ui/Probe_Spinner.scss" ] && fx_fail "the monorepo run restored a file"

# ---- 7. Quiet: the work is done, nothing on stdout. -----------------------------------
fx_run "$SCRIPT_NAME" RSPADE_ENV_UPDATE_QUIET=true
fx_assert_rc 0
fx_assert_silent_stdout "a quiet run says nothing about what it applied"
[ -f "$SYS/app/RSpade/Core/Ui/Probe_Spinner.scss" ] || fx_fail "a quiet run must still restore"
[ -e "$SYS/app/RSpade/Core/Ui/Probe_Spinner.scss.upstream" ] && fx_fail "a quiet run must still delete the archive"

fx_pass
