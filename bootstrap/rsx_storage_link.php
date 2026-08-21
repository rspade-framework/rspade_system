<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT STORAGE LINK GUARD
 *
 * Laravel boots from `system/`, so every `storage_path()` in the framework and
 * every relative path anybody writes resolves under `system/storage`. That path
 * is a SYMLINK to `../storage` - the project-root directory where all volatile
 * state actually lives: the file-attachment blob store, thumbnails, renditions,
 * logs, build artifacts, locks, database snapshots.
 *
 * WHY A SYMLINK AND NOT A REAL DIRECTORY. `system/` is a git submodule - a
 * checkout of the framework's own repository, replaced wholesale on every update.
 * Volatile application data cannot live inside a directory whose contents are
 * `git clean -fdx`ed as a matter of routine. Moving storage one level up puts it
 * in the application's own space, and the symlink is what lets every existing
 * `system/storage` path keep working without a single call site changing.
 *
 * WHY THIS FAILS LOUD RATHER THAN REPAIRING ITSELF. A wrong `system/storage` is
 * ambiguous in a way `.env` never is. A real directory there may hold a previous
 * install's uploads and logs - possibly the only copy - and silently replacing it
 * with a symlink would orphan that data behind a path nothing resolves to any
 * more. The framework does not get to make that call: it says exactly what is
 * wrong and what the correct shape is, and lets a person decide what happens to
 * whatever is sitting there.
 *
 * The TARGET is different, and is created. An absent `../storage` holds nothing
 * and loses nothing - creating it is unambiguous, and refusing to start over a
 * missing empty directory would be pedantry rather than safety. Whether it is a
 * real directory or itself a symlink (onto another volume, a network mount, a
 * per-environment path) is the administrator's business and is not inspected
 * beyond "does it exist".
 *
 * Required by BOTH entrypoints (`system/artisan` and `system/public/index.php`)
 * before anything reads configuration. It must therefore run with no autoloader,
 * no framework and no config: plain filesystem calls only.
 */

(static function (): void {
    $system_dir   = dirname(__DIR__);
    $project_root = dirname($system_dir);

    $link   = $system_dir . '/storage';      // ./storage,  from Laravel's base
    $target = $project_root . '/storage';    // ../storage, one level up

    // -----------------------------------------------------------------------
    // 1. The TARGET. Absent means nothing is there to lose, so make it.
    //
    //    A symlink is as valid as a directory here - an administrator pointing
    //    storage at another volume or a network mount is doing something
    //    supported, and this guard has no opinion about it. Only genuine absence
    //    is acted on. (file_exists() follows symlinks, so a symlink pointing at
    //    nothing counts as absent, which is the right reading: it resolves to no
    //    directory, and the operator gets told below rather than silently
    //    getting a second one.)
    // -----------------------------------------------------------------------
    if (!is_link($target) && !file_exists($target)) {
        if (!@mkdir($target, 0775, true) && !is_dir($target)) {
            rsx_storage_link_fail([
                "Could not create the storage directory: {$target}",
                '',
                '  RSpade keeps all volatile state one level above the framework, and that',
                '  directory does not exist and could not be created. Almost always this is',
                '  a permissions problem on the project root.',
                '',
                '  Create it by hand:',
                '',
                "      mkdir -p " . escapeshellarg($target),
                '',
            ]);
        }
    }

    // A symlink that resolves nowhere: the operator pointed storage at something
    // that is not there. Say so rather than quietly creating a directory beside
    // it, which would leave two notions of where storage is.
    if (is_link($target) && !file_exists($target)) {
        rsx_storage_link_fail([
            "The storage directory is a symlink that points nowhere: {$target}",
            '',
            '  It points at: ' . (readlink($target) ?: '(unreadable)'),
            '',
            '  RSpade keeps all volatile state there - the file store, logs, build',
            '  artifacts, locks and database snapshots. Point it at a directory that',
            '  exists, or remove the link and let RSpade create a real directory.',
            '',
        ]);
    }

    // -----------------------------------------------------------------------
    // 2. The LINK. It must be a symlink, and it must land on the target.
    //
    //    Both spellings are accepted - the relative `../storage` the framework
    //    ships, and an absolute path that resolves to the same directory - so an
    //    operator who rebuilt the link by hand is not second-guessed over syntax.
    //    What is NOT accepted is a real directory, a file, or a symlink pointing
    //    somewhere else.
    // -----------------------------------------------------------------------
    if (is_link($link)) {
        $link_real   = realpath($link);
        $target_real = realpath($target);

        if ($link_real !== false && $target_real !== false && $link_real === $target_real) {
            return;     // correct - the overwhelmingly common path
        }

        rsx_storage_link_fail([
            "system/storage is a symlink, but it does not point at the project's storage.",
            '',
            '  It points at:   ' . (readlink($link) ?: '(unreadable)')
                . ($link_real === false ? '  (which does not resolve)' : "  -> {$link_real}"),
            "  It must reach:  {$target}",
            '',
            ...rsx_storage_link_remedy($system_dir),
        ]);
    }

    if (is_dir($link)) {
        rsx_storage_link_fail([
            'system/storage is a real directory. It must be a symlink to ../storage.',
            '',
            '  system/ is a git submodule - a checkout of the framework, replaced wholesale',
            '  on every update and cleaned of untracked files. Volatile data cannot live',
            '  there, so storage was moved one level up and system/storage became a link to',
            '  it.',
            '',
            '  THAT DIRECTORY MAY HOLD THE ONLY COPY of a previous install\'s uploads, logs',
            '  and database snapshots, so RSpade will not remove it for you. Move what',
            '  matters into ' . $project_root . '/storage, then replace it:',
            '',
            ...rsx_storage_link_remedy($system_dir),
        ]);
    }

    if (file_exists($link)) {
        rsx_storage_link_fail([
            'system/storage exists but is not a symlink (it is a file).',
            '',
            '  It must be a symlink to ../storage.',
            '',
            ...rsx_storage_link_remedy($system_dir),
        ]);
    }

    // Missing entirely. system/storage is a TRACKED symlink in the framework
    // repository, so its absence means the checkout is incomplete rather than
    // misconfigured - which is worth saying, because the fix is different.
    rsx_storage_link_fail([
        'system/storage is missing. It must be a symlink to ../storage.',
        '',
        '  This path is a tracked symlink in the framework repository, so its absence',
        '  usually means an incomplete checkout rather than a configuration mistake:',
        '',
        '      git submodule update --init --recursive',
        '',
        '  If that does not restore it, create it directly:',
        '',
        ...rsx_storage_link_remedy($system_dir),
    ]);
})();

/**
 * The two commands that put the link back. One place, so every message agrees.
 */
function rsx_storage_link_remedy(string $system_dir): array
{
    return [
        '      rm -rf ' . escapeshellarg($system_dir . '/storage'),
        '      ln -s ../storage ' . escapeshellarg($system_dir . '/storage'),
        '',
    ];
}

/**
 * Refuse, on whichever channel is listening.
 */
function rsx_storage_link_fail(array $lines): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "\n[ERROR] RSpade storage layout is wrong.\n\n");
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

    echo "503 - RSpade storage layout is wrong\n\n";
    foreach ($lines as $line) {
        echo $line . "\n";
    }
    exit(1);
}
