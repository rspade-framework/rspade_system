<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT BUILD LINK GUARD
 *
 * Laravel boots from `system/`, so a path anybody writes relative to the framework
 * tree resolves inside it. `system/build` is a symlink onto `<project>/build`, which
 * is where the build outputs live - beside `system/` and `rsx/`, so that a production
 * box can make all three read-only together.
 *
 * WHY A SYMLINK AND NOT A REAL DIRECTORY. `system/` is a git submodule - a checkout of
 * the framework's own repository, replaced wholesale on every update and cleaned of
 * untracked files. Nothing volatile can live inside a directory that is `git clean
 * -fdx`ed as a matter of routine.
 *
 * ONLY build/ HAS A LINK. `tmp/` and `storage/` are RELOCATABLE, and a convenience
 * link cannot promise where a relocated root is: an operator who edits `RSX_TMP_PATH`
 * on a sealed box would be left with a link the runtime refuses to repair, in a tree
 * it cannot write. Code reaches those two through the path owner, which reads the
 * live value. `build/` is fixed, so its link is a constant.
 *
 * The LINK is repaired silently - it is one machine-made string - but the TARGET is
 * never created here: the build and `rsx:clean` own that, so a production box with no
 * build fails loud naming the build command. A DANGLING `system/build` is therefore a
 * correct, expected state.
 *
 * A real directory squatting on `system/build` is refused with the remedy, with one
 * exception: the two-child `cache/`+`temp/` shape an older artifact cache left there,
 * which holds nothing anybody wants and is removed by
 * `bin/environment_updates/110_relocate_build_tmp.sh`.
 *
 * Required by BOTH entrypoints (`system/artisan` and `system/public/index.php`) before
 * anything reads configuration. It must therefore run with no autoloader, no framework
 * and no config: plain filesystem calls only.
 */

require_once __DIR__ . '/rsx_paths.php';

(static function (): void {
    // build: outputs. Repair the link; never create the target.
    rsx_tree_link_guard_build(rsx_paths_system_dir(), rsx_paths_build_root());
})();

/**
 * The `system/build` invariant.
 *
 * It holds only machine-made state, so a wrong or missing LINK is repaired in place
 * with nothing to report. The TARGET is never created: build outputs are the build's
 * to produce, and a production box with none must fail loud naming it.
 */
function rsx_tree_link_guard_build(string $system_dir, string $target): void
{
    $name = 'build';
    $link = $system_dir . '/' . $name;
    $link_target = '../' . $name;

    if (is_link($link)) {
        if (readlink($link) === $link_target) {
            return;
        }

        // A dangling build link is CORRECT while no build exists, so the comparison
        // above is against the link's own text, never against what it resolves to.
        @unlink($link);
        @symlink($link_target, $link);

        return;
    }

    if (is_dir($link)) {
        if (rsx_tree_link_guard_is_stale_artifact_cache($link)) {
            // The two-child cache/temp shape an older artifact cache left behind.
            // It holds nothing anybody wants; the 110 environment update removes it,
            // and until it runs this refuses rather than deleting a directory on a
            // developer's behalf.
            rsx_storage_link_fail([
                "system/{$name} is the old artifact-cache directory. It must be a symlink.",
                '',
                '  Everything inside it is regenerated on demand. Remove it and let the',
                '  environment update (or the two commands below) put the link in place:',
                '',
                ...rsx_storage_link_remedy($system_dir, $name, $target),
            ]);
        }

        rsx_storage_link_fail([
            "system/{$name} is a real directory. It must be a symlink to {$target}.",
            '',
            '  system/ is a git submodule - replaced wholesale on every framework update -',
            '  so nothing volatile can live inside it. RSpade will not remove a directory',
            '  it did not make. Move anything that matters out, then replace it:',
            '',
            ...rsx_storage_link_remedy($system_dir, $name, $target),
        ]);
    }

    if (file_exists($link)) {
        rsx_storage_link_fail([
            "system/{$name} exists but is not a symlink (it is a file).",
            '',
            "  It must be a symlink to {$target}.",
            '',
            ...rsx_storage_link_remedy($system_dir, $name, $target),
        ]);
    }

    @symlink($link_target, $link);
}

/**
 * Is this directory the two-child artifact cache, and nothing else?
 */
function rsx_tree_link_guard_is_stale_artifact_cache(string $dir): bool
{
    $entries = @scandir($dir);

    if ($entries === false) {
        return false;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if ($entry !== 'cache' && $entry !== 'temp') {
            return false;
        }
    }

    return true;
}

/**
 * The two commands that put a link back. One place, so every message agrees.
 */
function rsx_storage_link_remedy(string $system_dir, string $name, string $target): array
{
    $link_target = '../' . $name;

    return [
        '      rm -rf ' . escapeshellarg($system_dir . '/' . $name),
        '      ln -s ' . escapeshellarg($link_target) . ' ' . escapeshellarg($system_dir . '/' . $name),
        '',
    ];
}

/**
 * Refuse, on whichever channel is listening.
 */
function rsx_storage_link_fail(array $lines): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "\n[ERROR] RSpade build layout is wrong.\n\n");
        foreach ($lines as $line) {
            fwrite(STDERR, $line . "\n");
        }
        exit(1);
    }

    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 60');
    }

    echo "503 - RSpade build layout is wrong\n\n";
    foreach ($lines as $line) {
        echo $line . "\n";
    }
    exit(1);
}
