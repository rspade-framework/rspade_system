#!/bin/bash
# t11: the environment update that swaps the RETIRED pre-pull guard for the git guard.
# The old guard told the agent to run rsx:clean and then pull, which deadlocks on a live
# box (the class-override churn returns before the pull's index write lands), so it must
# be REMOVED, not left beside the new one. Foreign hooks are never touched.

TEST_NAME="git_proxy/cli t11 guard installer swap"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

INSTALLER="/var/www/html/system/bin/environment_updates/040_claude_git_guard.sh"
NEW_CMD='bash "$CLAUDE_PROJECT_DIR/system/bin/claude-git-guard.sh"'
OLD_CMD='bash "$CLAUDE_PROJECT_DIR/system/bin/claude-pull-guard.sh"'
OLD_CMD_BARE='$CLAUDE_PROJECT_DIR/system/bin/claude-pull-guard.sh'
FOREIGN='echo "developer hook"'

trap fx_cleanup EXIT
fx_init

ROOT="$FX_ROOT/app"
mkdir -p "$ROOT/system/bin" "$ROOT/.claude"
cp /var/www/html/system/bin/claude-git-guard.sh "$ROOT/system/bin/"

run_installer() {
    FX_OUT="$(PROJECT_ROOT="$ROOT" SYSTEM_DIR="$ROOT/system" IS_FRAMEWORK_DEVELOPER=false bash "$INSTALLER" 2>&1)"
    FX_RC=$?
}

# settings.json holds BOTH retired spellings plus a hook that belongs to the developer.
# Built with php so the commands (which contain double quotes) are JSON-encoded correctly.
OLD="$OLD_CMD" OLD_BARE="$OLD_CMD_BARE" FOREIGN="$FOREIGN" php -r '
    $data = [
        "hooks" => ["PreToolUse" => [
            ["matcher" => "Bash", "hooks" => [["type" => "command", "command" => getenv("OLD")]]],
            ["matcher" => "Bash", "hooks" => [
                ["type" => "command", "command" => getenv("OLD_BARE")],
                ["type" => "command", "command" => getenv("FOREIGN")],
            ]],
        ]],
        "someOtherSetting" => "preserved",
    ];
    file_put_contents($argv[1], json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$ROOT/.claude/settings.json"

run_installer
fx_assert_rc 0
fx_assert_out "Replaced the retired pre-pull guard"

settings="$ROOT/.claude/settings.json"
grep -q 'claude-git-guard.sh' "$settings" || fx_fail "the new guard must be registered"
if grep -q 'claude-pull-guard' "$settings"; then
    fx_fail "every retired pull-guard entry must be gone: $(cat "$settings")"
fi
fx_assert_file_contains "$settings" "developer hook"
fx_assert_file_contains "$settings" "someOtherSetting"

# The entry that held ONLY our retired hook is dropped; the one that also held a foreign
# hook survives with the foreign hook intact. Plus our new entry. So: 2 entries.
entries="$(php -r '
    $d = json_decode(file_get_contents($argv[1]), true);
    echo count($d["hooks"]["PreToolUse"]);
' "$settings")"
[ "$entries" -eq 2 ] || fx_fail "expected 2 PreToolUse entries, got $entries: $(cat "$settings")"

# Idempotent + silent on re-run.
run_installer
fx_assert_rc 0
[ -z "$FX_OUT" ] || fx_fail "a healthy environment must stay silent, got: $FX_OUT"

# A monorepo never gets the redirect (rsx:git is inert there).
FX_OUT="$(PROJECT_ROOT="$ROOT" SYSTEM_DIR="$ROOT/system" IS_FRAMEWORK_DEVELOPER=true bash "$INSTALLER" 2>&1)"
FX_RC=$?
fx_assert_rc 0
[ -z "$FX_OUT" ] || fx_fail "the monorepo path must be a silent no-op, got: $FX_OUT"

# A settings.json with no hooks at all is created cleanly.
rm -rf "$ROOT/.claude"
mkdir -p "$ROOT/.claude"
run_installer
fx_assert_rc 0
fx_assert_out "Registered the Claude Code git guard"
fx_assert_file_contains "$ROOT/.claude/settings.json" "claude-git-guard.sh"

fx_pass
