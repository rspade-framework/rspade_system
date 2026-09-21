#!/bin/bash
# t25: the two APPEND-APPEND files are merged by the proxy, and the operation completes.
#
# .migration_whitelist and framework_update_history.dat conflict for one reason: both
# sides appended to the end of them. Git cannot settle either (it does not know the
# whitelist's closing brace belongs to both sides), and the damage from the markers it
# leaves is out of all proportion - a whitelist that is not JSON makes `migrate` read an
# empty map and declare EVERY migration in the tree unauthorized.
#
# Neither file has content two sides can legitimately disagree about, so the proxy owns
# the merge: key-union for the whitelist, line-union for the log. When they were the only
# conflicts, the merge is carried to completion and the caller sees the clean pull they
# asked for.
#
# Covered here: the merge path (the fixture pulls with pull.rebase false) and the rebase
# path, which stops once per conflicting commit.

TEST_NAME="git_proxy/cli t25 whitelist union merge"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init

WL_REL="rsx/resource/migrations/.migration_whitelist"
HIST_REL="rsx/resource/framework_update_history.dat"

# -----------------------------------------------------------------------------
# t25_write_whitelist <repo> <basename>...
#
# Writes the whitelist in the shape make:migration:safe writes it: json_encode with
# JSON_PRETTY_PRINT and NO trailing newline (file_put_contents_safe adds none).
# -----------------------------------------------------------------------------
t25_write_whitelist() {
    local repo="$1"
    shift
    php -r '
        $path = $argv[1];
        $migrations = [];
        foreach (array_slice($argv, 2) as $name) {
            $migrations[$name] = [
                "created_at" => "2026-09-21T00:00:00+00:00",
                "created_by" => "fixture",
                "command"    => "php artisan make:migration:safe " . $name,
            ];
        }
        ksort($migrations);
        file_put_contents($path, json_encode([
            "description" => "This file tracks migrations created via php artisan make:migration",
            "purpose"     => "Prevents manually created migrations from running to avoid timestamp conflicts",
            "migrations"  => $migrations,
        ], JSON_PRETTY_PRINT));
    ' -- "$repo/$WL_REL" "$@"
}

# t25_mint <repo> <basename> - the migration file plus its whitelist entry.
t25_mint() {
    local repo="$1" name="$2"
    shift 2
    mkdir -p "$repo/rsx/resource/migrations"
    printf '<?php\n// %s\n' "$name" > "$repo/rsx/resource/migrations/$name"
    t25_write_whitelist "$repo" "$@" "$name"
}

# t25_append_history <repo> <sha> - one self-delimiting framework-update section.
t25_append_history() {
    local repo="$1" sha="$2"
    mkdir -p "$repo/rsx/resource"
    printf '## RSPADE-UPDATE 2026-09-21T00:00:00Z from=%s to=%s\nDate: 2026-09-21 00:00 UTC\n\n' \
        "${sha}aaa" "${sha}bbb" >> "$repo/$HIST_REL"
}

ANCESTOR="2026_01_01_000000_create_ancestor_table.php"
OURS_MIG="2026_09_20_101010_create_ours_table.php"
THEIRS_MIG="2026_09_20_202020_create_theirs_table.php"

# =============================================================================
# Scenario 1: a merge whose only conflicts are the two owned files.
# =============================================================================
fx_build_project

t25_mint "$PROJECT" "$ANCESTOR"
t25_append_history "$PROJECT" "000"
git -C "$PROJECT" add -A >/dev/null 2>&1
git -C "$PROJECT" commit -q -m "baseline migrations"

fx_build_upstream

# THEIRS: a colleague mints a migration and records a framework update.
THEIR_WORK="$FX_ROOT/theirs"
git clone -q "$BARE" "$THEIR_WORK"
git -C "$THEIR_WORK" config user.email theirs@example.com
git -C "$THEIR_WORK" config user.name Theirs
git -C "$THEIR_WORK" config commit.gpgsign false
t25_mint "$THEIR_WORK" "$THEIRS_MIG" "$ANCESTOR"
t25_append_history "$THEIR_WORK" "222"
git -C "$THEIR_WORK" add -A >/dev/null 2>&1
git -C "$THEIR_WORK" commit -q -m "theirs: one migration"
git -C "$THEIR_WORK" push -q origin master

# OURS: the same, locally, on top of the same ancestor.
t25_mint "$PROJECT" "$OURS_MIG" "$ANCESTOR"
t25_append_history "$PROJECT" "111"
git -C "$PROJECT" add -A >/dev/null 2>&1
git -C "$PROJECT" commit -q -m "ours: one migration"

fx_run pull --no-rebase origin master

fx_assert_rc 0 "the pull completes: its only conflicts were the proxy's to merge"
fx_assert_out "$WL_REL merged (ours 2 + theirs 2 -> 3 entries)"
fx_assert_out "$HIST_REL merged (union)"

git -C "$PROJECT" ls-files -u 2>/dev/null | grep -q . \
    && fx_fail "the tree still has unmerged paths after the pull"

[ "$(git -C "$PROJECT" rev-list --count --merges -1 HEAD)" = "1" ] \
    || fx_fail "HEAD is not the merge commit the pull should have made"

# The whitelist PARSES, and holds the ancestor plus both new keys in sorted order.
keys="$(php -r '
    $decoded = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($decoded) || !isset($decoded["migrations"])) { exit(1); }
    echo implode(",", array_keys($decoded["migrations"]));
' -- "$PROJECT/$WL_REL")" || fx_fail "the merged whitelist is not valid JSON"

[ "$keys" = "$ANCESTOR,$OURS_MIG,$THEIRS_MIG" ] \
    || fx_fail "expected the ancestor plus both new keys in sorted order, got: $keys"

# The description survives - it is a constant, taken from ours.
php -r '
    $d = json_decode(file_get_contents($argv[1]), true);
    exit(isset($d["description"], $d["purpose"]) ? 0 : 1);
' -- "$PROJECT/$WL_REL" || fx_fail "the merged whitelist lost its description/purpose"

# The log holds every side's sections.
fx_assert_file_contains "$PROJECT/$HIST_REL" "from=000aaa"
fx_assert_file_contains "$PROJECT/$HIST_REL" "from=111aaa"
fx_assert_file_contains "$PROJECT/$HIST_REL" "from=222aaa"
grep -q '<<<<<<<\|>>>>>>>' "$PROJECT/$HIST_REL" && fx_fail "conflict markers survived in the history log"

# =============================================================================
# Scenario 2: the REBASE path. A rebase stops once per conflicting commit, so the
# proxy has to resolve and continue in a loop.
# =============================================================================
fx_cleanup
fx_init
fx_build_project

t25_mint "$PROJECT" "$ANCESTOR"
t25_append_history "$PROJECT" "000"
git -C "$PROJECT" add -A >/dev/null 2>&1
git -C "$PROJECT" commit -q -m "baseline migrations"

fx_build_upstream

THEIR_WORK="$FX_ROOT/theirs"
git clone -q "$BARE" "$THEIR_WORK"
git -C "$THEIR_WORK" config user.email theirs@example.com
git -C "$THEIR_WORK" config user.name Theirs
git -C "$THEIR_WORK" config commit.gpgsign false
t25_mint "$THEIR_WORK" "$THEIRS_MIG" "$ANCESTOR"
t25_append_history "$THEIR_WORK" "222"
git -C "$THEIR_WORK" add -A >/dev/null 2>&1
git -C "$THEIR_WORK" commit -q -m "theirs: one migration"
git -C "$THEIR_WORK" push -q origin master

# TWO local commits, each touching both files, so the rebase stops twice.
t25_mint "$PROJECT" "$OURS_MIG" "$ANCESTOR"
t25_append_history "$PROJECT" "111"
git -C "$PROJECT" add -A >/dev/null 2>&1
git -C "$PROJECT" commit -q -m "ours: first migration"

OURS_MIG_2="2026_09_20_303030_create_ours_second_table.php"
t25_mint "$PROJECT" "$OURS_MIG_2" "$ANCESTOR" "$OURS_MIG"
t25_append_history "$PROJECT" "333"
git -C "$PROJECT" add -A >/dev/null 2>&1
git -C "$PROJECT" commit -q -m "ours: second migration"

fx_run pull --rebase origin master

fx_assert_rc 0 "the rebase completes across both stopping points"

git -C "$PROJECT" ls-files -u 2>/dev/null | grep -q . \
    && fx_fail "the tree still has unmerged paths after the rebase"

[ -d "$PROJECT/.git/rebase-merge" ] || [ -d "$PROJECT/.git/rebase-apply" ] \
    && fx_fail "a rebase is still in progress"

keys="$(php -r '
    $decoded = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($decoded) || !isset($decoded["migrations"])) { exit(1); }
    echo implode(",", array_keys($decoded["migrations"]));
' -- "$PROJECT/$WL_REL")" || fx_fail "the rebased whitelist is not valid JSON"

[ "$keys" = "$ANCESTOR,$OURS_MIG,$THEIRS_MIG,$OURS_MIG_2" ] \
    || fx_fail "expected all four keys in sorted order after the rebase, got: $keys"

fx_assert_file_contains "$PROJECT/$HIST_REL" "from=222aaa"
fx_assert_file_contains "$PROJECT/$HIST_REL" "from=333aaa"

fx_pass
