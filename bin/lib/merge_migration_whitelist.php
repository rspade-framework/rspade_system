<?php
/**
 * Merge three stages of a conflicted .migration_whitelist into the key-union of the
 * two sides' `migrations` maps.
 *
 *     php merge_migration_whitelist.php <base> <ours> <theirs>
 *
 * The merged JSON goes to stdout in the shape make:migration:safe writes it
 * (JSON_PRETTY_PRINT, no trailing newline - file_put_contents_safe adds none). One
 * accounting line goes to stderr: `ours=N theirs=M merged=K`. Exit 2, with a message
 * on stderr naming the stage, when a stage is not a whitelist.
 *
 * PLAIN PHP, NO FRAMEWORK. bin/rsx-git.sh runs it, and the proxy is boot-free by
 * contract: it has to work on a tree too broken to boot, and booting to run a git
 * command can itself rebuild the manifest.
 *
 * WHY A UNION IS THE ONLY CORRECT RESULT. The keys are migration filenames, minted by
 * make:migration:safe as a `YYYY_MM_DD_HHMMSS_` timestamp plus a slug, and the values
 * are provenance stamps about that one file, written once and never edited. Two
 * developers therefore cannot mint the same key, and a key present on both sides came
 * from a shared ancestor with identical values - so there is nothing for the two sides
 * to disagree about, and `+` (ours wins a collision) can only ever pick between two
 * copies of the same stamp.
 *
 * The BASE stage does not contribute keys: both sides only ever append, so ours union
 * theirs already covers every ancestor entry. It is read to prove it parses, because a
 * stage that is not a whitelist means the conflict is not the one this resolver
 * understands.
 */

$argv = $_SERVER['argv'];

if (count($argv) !== 4) {
    fwrite(STDERR, "usage: merge_migration_whitelist.php <base> <ours> <theirs>\n");
    exit(2);
}

/**
 * Read one stage. An EMPTY base file means there was no merge base - the whitelist is
 * new on both sides - and reads as an empty whitelist. Anywhere else, empty is a parse
 * failure like any other.
 */
function rsx_read_stage(string $label, string $path, bool $empty_is_blank): array
{
    if (!is_file($path)) {
        if ($empty_is_blank) {
            return ['migrations' => []];
        }

        fwrite(STDERR, "stage '{$label}' is missing: {$path}\n");
        exit(2);
    }

    $raw = file_get_contents($path);

    if ($raw === false) {
        fwrite(STDERR, "stage '{$label}' could not be read: {$path}\n");
        exit(2);
    }

    if (trim($raw) === '' && $empty_is_blank) {
        return ['migrations' => []];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        fwrite(STDERR, "stage '{$label}' is not valid JSON\n");
        exit(2);
    }

    if (!isset($decoded['migrations']) || !is_array($decoded['migrations'])) {
        fwrite(STDERR, "stage '{$label}' has no 'migrations' map\n");
        exit(2);
    }

    return $decoded;
}

$base   = rsx_read_stage('base', $argv[1], true);
$ours   = rsx_read_stage('ours', $argv[2], false);
$theirs = rsx_read_stage('theirs', $argv[3], false);

unset($base);

$merged = $ours;
$merged['migrations'] = $ours['migrations'] + $theirs['migrations'];

// Filename keys are timestamps, so a plain key sort restores mint order.
ksort($merged['migrations']);

fwrite(STDERR, sprintf(
    "ours=%d theirs=%d merged=%d\n",
    count($ours['migrations']),
    count($theirs['migrations']),
    count($merged['migrations'])
));

fwrite(STDOUT, json_encode($merged, JSON_PRETTY_PRINT));

exit(0);
