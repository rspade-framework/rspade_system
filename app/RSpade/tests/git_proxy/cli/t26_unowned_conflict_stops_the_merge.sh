#!/bin/bash
# t26: a conflict the proxy does NOT own stops the merge, even when it merged the ones
# it does.
#
# Resolving the two append-append files is not a licence to finish somebody else's merge.
# When an ordinary file is still conflicted the tree is left exactly as git left it, git's
# own exit code stands, and no merge commit is made - the developer resolves the real
# conflict and commits. The whitelist is still merged and staged, because the value of
# that is independent: it is the one file whose markers break `migrate` for every
# migration in the tree.

TEST_NAME="git_proxy/cli t26 unowned conflict stops the merge"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib_fixture"

trap fx_cleanup EXIT
fx_init
fx_build_project

WL_REL="rsx/resource/migrations/.migration_whitelist"

t26_write_whitelist() {
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

t26_mint() {
    local repo="$1" name="$2"
    shift 2
    mkdir -p "$repo/rsx/resource/migrations"
    printf '<?php\n// %s\n' "$name" > "$repo/rsx/resource/migrations/$name"
    t26_write_whitelist "$repo" "$@" "$name"
}

ANCESTOR="2026_01_01_000000_create_ancestor_table.php"
OURS_MIG="2026_09_20_101010_create_ours_table.php"
THEIRS_MIG="2026_09_20_202020_create_theirs_table.php"

t26_mint "$PROJECT" "$ANCESTOR"
printf 'ancestor readme\n' > "$PROJECT/README.md"
git -C "$PROJECT" add -A >/dev/null 2>&1
git -C "$PROJECT" commit -q -m "baseline migrations and readme"

fx_build_upstream

# THEIRS: a migration, and an incompatible edit to an ordinary file.
THEIR_WORK="$FX_ROOT/theirs"
git clone -q "$BARE" "$THEIR_WORK"
git -C "$THEIR_WORK" config user.email theirs@example.com
git -C "$THEIR_WORK" config user.name Theirs
git -C "$THEIR_WORK" config commit.gpgsign false
t26_mint "$THEIR_WORK" "$THEIRS_MIG" "$ANCESTOR"
printf 'their readme\n' > "$THEIR_WORK/README.md"
git -C "$THEIR_WORK" add -A >/dev/null 2>&1
git -C "$THEIR_WORK" commit -q -m "theirs"
git -C "$THEIR_WORK" push -q origin master

# OURS: the same two files, changed differently.
t26_mint "$PROJECT" "$OURS_MIG" "$ANCESTOR"
printf 'our readme\n' > "$PROJECT/README.md"
git -C "$PROJECT" add -A >/dev/null 2>&1
git -C "$PROJECT" commit -q -m "ours"

HEAD_BEFORE="$(git -C "$PROJECT" rev-parse HEAD)"

fx_run pull --no-rebase origin master

[ "$FX_RC" -ne 0 ] || fx_fail "a merge with an unresolved README conflict must keep git's failing exit code"

fx_assert_out "$WL_REL merged (ours 2 + theirs 2 -> 3 entries)"

# The whitelist is resolved and STAGED.
git -C "$PROJECT" ls-files -u -- "$WL_REL" | grep -q . \
    && fx_fail "the whitelist is still unmerged"
php -r '
    $d = json_decode(file_get_contents($argv[1]), true);
    exit(is_array($d) && count($d["migrations"] ?? []) === 3 ? 0 : 1);
' -- "$PROJECT/$WL_REL" || fx_fail "the staged whitelist is not the three-entry union"

# The README is not, and the merge was not completed behind the developer's back.
git -C "$PROJECT" ls-files -u -- README.md | grep -q . \
    || fx_fail "README.md should still be conflicted - it is not the proxy's to merge"

[ -f "$PROJECT/.git/MERGE_HEAD" ] \
    || fx_fail "the merge should still be in progress"

[ "$(git -C "$PROJECT" rev-parse HEAD)" = "$HEAD_BEFORE" ] \
    || fx_fail "a merge commit was made while a conflict the proxy does not own was open"

fx_pass
