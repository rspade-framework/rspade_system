<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT SUBMODULE SYNC GUARD
 *
 * `system/` is a git submodule. A plain `git pull` updates the RECORDED gitlink
 * without checking the submodule out, so the project says "framework release B"
 * while the code actually executing is release A. Nothing fails. The application
 * boots, serves pages, and runs a framework version nobody chose - until some
 * behaviour is wrong in a way that makes no sense against the code in front of
 * you.
 *
 * That is the whole reason this file exists: the mismatch is invisible, and git
 * will not resolve it for you. `git status` shows ` M system` and `git submodule
 * status` prefixes the line with `+`, but nobody reads either before every
 * request.
 *
 * TWO VALUES, NO GIT PROCESS:
 *
 *   RECORDED - the gitlink entry for `system` in `.git/index`. The index is
 *              always a plain file, never packed, so this needs no object
 *              database, no zlib and no packfile reader. Versions 2, 3 and 4 are
 *              parsed (v4 prefix-compresses path names and drops the padding).
 *   ACTUAL   - `system/.git` is a text file naming the real git dir; that
 *              directory's HEAD holds the raw sha, because a submodule checkout
 *              is detached. Two file reads.
 *
 * Running `git` twice per boot would cost ~5ms of process spawn on every web
 * request. Reading two files costs microseconds - and the cache below usually
 * removes even the parse.
 *
 * THE CACHE IS A SUCCESS RECORD, NOT A RESULT CACHE. The file exists only when a
 * parse has PROVEN the two shas equal, and it names the exact (mtime, size) of
 * the inputs that proved it. A matching stamp is therefore not "the last answer
 * was pass" - it is "the bytes have not changed since they were pass". Anything
 * else re-parses. A FAILURE deletes the file, so a broken checkout can never be
 * blessed by a stale record.
 *
 * Both stamped inputs matter. `.git/index` changes on pull, checkout and commit -
 * the operations that move the gitlink. The submodule's HEAD changes when someone
 * checks out a different commit INSIDE system/, which the index knows nothing
 * about. Keying on one alone would miss half the ways this drifts.
 *
 * UNKNOWN IS NOT FAILURE. An index version this parser does not understand, a
 * sha256 repository, an unreadable file - all of these mean "cannot tell", and
 * refusing to boot because we could not read someone's index would be a worse bug
 * than the one being prevented. The guard degrades to silence and leaves the
 * question to `git submodule status`.
 *
 * Required by BOTH entrypoints (`system/artisan` and `system/public/index.php`)
 * before anything reads configuration. It must therefore run with no autoloader,
 * no framework and no config: plain filesystem calls only.
 */

(static function (): void {
    $system_dir   = dirname(__DIR__);
    $project_root = dirname($system_dir);
    $submodule    = basename($system_dir);          // normally "system"

    // ---------------------------------------------------------------------
    // Applicability. Not a submodule -> not our problem: the framework monorepo
    // (where system/ IS the authored source) and any project not yet migrated
    // both land here, and both are legitimate.
    // ---------------------------------------------------------------------
    $dot_git = $system_dir . '/.git';
    if (!file_exists($dot_git)) {
        return;
    }

    $parent_git = $project_root . '/.git';
    $index_file = is_dir($parent_git) ? $parent_git . '/index' : null;
    if ($index_file === null || !is_file($index_file)) {
        return;
    }

    // ---------------------------------------------------------------------
    // Resolve the submodule's real git dir, and with it the HEAD we must stamp.
    // ---------------------------------------------------------------------
    $git_dir = rsx_submodule_sync_resolve_git_dir($system_dir);
    if ($git_dir === null) {
        return;
    }
    $head_file = $git_dir . '/HEAD';

    // ---------------------------------------------------------------------
    // The stamp: (mtime, size) of every file whose bytes decide the answer.
    // ---------------------------------------------------------------------
    $stamp = rsx_submodule_sync_stamp([$index_file, $head_file]);
    if ($stamp === null) {
        return;
    }

    $cache_file = rsx_submodule_sync_cache_path($project_root, $system_dir);

    // A matching stamp means these exact bytes were proven in sync. Nothing to do,
    // and nothing to write - the file already says what we would write.
    if ($cache_file !== null && is_file($cache_file)) {
        if (trim((string) @file_get_contents($cache_file)) === $stamp) {
            return;
        }
    }

    // ---------------------------------------------------------------------
    // Parse.
    // ---------------------------------------------------------------------
    $recorded = rsx_submodule_sync_gitlink_from_index($index_file, $submodule);
    $actual   = rsx_submodule_sync_head_sha($git_dir);

    if ($recorded === null || $actual === null) {
        // Cannot tell. Say nothing, cache nothing, block nothing.
        return;
    }

    if ($recorded === $actual) {
        rsx_submodule_sync_write_cache($cache_file, $stamp);
        return;
    }

    // ---------------------------------------------------------------------
    // Mismatch. Drop any success record first - it is now a lie - and refuse.
    // ---------------------------------------------------------------------
    if ($cache_file !== null && is_file($cache_file)) {
        @unlink($cache_file);
    }

    rsx_submodule_sync_fail($submodule, $recorded, $actual);
})();

/**
 * Where the success record lives: the framework's own durable storage, at
 * <project>/storage - one level above system/, which is a submodule and is
 * replaced wholesale on every update.
 */
function rsx_submodule_sync_cache_path(string $project_root, string $system_dir): ?string
{
    $dir = $project_root . '/storage/rsx-framework';
    if (!is_dir($dir)) {
        return null;
    }

    return $dir . '/.submodule_sync_ok';
}

/**
 * Write the success record. Best-effort by design: a read-only or missing storage
 * directory costs a re-parse next boot, which is not worth failing over.
 */
function rsx_submodule_sync_write_cache(?string $cache_file, string $stamp): void
{
    if ($cache_file === null) {
        return;
    }
    @file_put_contents($cache_file, $stamp . "\n");
}

/**
 * (mtime, size) of every input file, as one comparable string. Null when any of
 * them cannot be stat'ed - an answer we could not stamp is one we must not cache.
 */
function rsx_submodule_sync_stamp(array $files): ?string
{
    $parts = [];
    foreach ($files as $file) {
        $stat = @stat($file);
        if ($stat === false) {
            return null;
        }
        $parts[] = basename($file) . ':' . $stat['mtime'] . ':' . $stat['size'];
    }

    return implode(' ', $parts);
}

/**
 * Resolve system/.git to the real git directory.
 *
 * A submodule normally has a FILE there holding `gitdir: ../.git/modules/system`.
 * An older-style submodule (or a plain nested clone) has a real directory.
 */
function rsx_submodule_sync_resolve_git_dir(string $system_dir): ?string
{
    $dot_git = $system_dir . '/.git';

    if (is_dir($dot_git)) {
        return $dot_git;
    }

    if (!is_file($dot_git)) {
        return null;
    }

    $contents = trim((string) @file_get_contents($dot_git));
    if (strncmp($contents, 'gitdir:', 7) !== 0) {
        return null;
    }

    $path = trim(substr($contents, 7));
    if ($path === '') {
        return null;
    }

    // Relative paths are relative to the submodule's working directory.
    if ($path[0] !== '/') {
        $path = $system_dir . '/' . $path;
    }

    $real = realpath($path);

    return $real === false ? null : $real;
}

/**
 * The sha the submodule is ACTUALLY at.
 *
 * Detached HEAD is the normal state for a submodule and holds the sha directly.
 * The branch forms are handled anyway - a developer who ran `git checkout master`
 * inside system/ is exactly the person this guard is for.
 */
function rsx_submodule_sync_head_sha(string $git_dir): ?string
{
    $head = trim((string) @file_get_contents($git_dir . '/HEAD'));
    if ($head === '') {
        return null;
    }

    if (preg_match('/^[0-9a-f]{40}$/', $head)) {
        return $head;
    }

    if (strncmp($head, 'ref: ', 5) !== 0) {
        return null;
    }

    $ref = trim(substr($head, 5));

    $loose = $git_dir . '/' . $ref;
    if (is_file($loose)) {
        $sha = trim((string) @file_get_contents($loose));

        return preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : null;
    }

    $packed = @file_get_contents($git_dir . '/packed-refs');
    if ($packed !== false
        && preg_match('/^([0-9a-f]{40})[ \t]+' . preg_quote($ref, '/') . '$/m', $packed, $m)) {
        return $m[1];
    }

    return null;
}

/**
 * The gitlink sha recorded for $want in .git/index.
 *
 * INDEX FORMAT, the parts that matter here. Header: "DIRC", version, entry count.
 * Then each entry: 40 bytes of stat data, a 20-byte sha, a 2-byte flags field
 * whose low 12 bits are the name length, an optional 2-byte extended-flags field
 * (v3+, when bit 0x4000 is set), then the path.
 *
 * VERSIONS 2 AND 3 store the path plainly and pad each entry with NULs to a
 * multiple of 8 bytes.
 *
 * VERSION 4 does neither. The path is prefix-compressed against the PREVIOUS
 * entry's path: a variable-width integer N says how many bytes to strip from the
 * end of the previous path, and the NUL-terminated string that follows replaces
 * them. There is no padding at all. The integer uses git's offset encoding (the
 * same one OFS_DELTA pack entries use), which is NOT ordinary LEB128 - each
 * continuation adds one before shifting, so encodings are unique.
 *
 * A gitlink is mode 0160000. Returns null on anything it cannot read confidently,
 * which the caller treats as "cannot tell" rather than as failure.
 */
function rsx_submodule_sync_gitlink_from_index(string $index_file, string $want): ?string
{
    $data = @file_get_contents($index_file);
    if ($data === false || strlen($data) < 12 || substr($data, 0, 4) !== 'DIRC') {
        return null;
    }

    $version = unpack('N', substr($data, 4, 4))[1];
    $count   = unpack('N', substr($data, 8, 4))[1];

    if ($version < 2 || $version > 4) {
        return null;
    }

    $len   = strlen($data);
    $off   = 12;
    $prev  = '';            // previous entry's path (v4 prefix compression)

    for ($i = 0; $i < $count; $i++) {
        $start = $off;

        // 40 bytes stat + 20 bytes sha + 2 bytes flags
        if ($off + 62 > $len) {
            return null;
        }

        $mode  = unpack('N', substr($data, $off + 24, 4))[1];
        $sha   = bin2hex(substr($data, $off + 40, 20));
        $flags = unpack('n', substr($data, $off + 60, 2))[1];
        $off  += 62;

        if ($version >= 3 && ($flags & 0x4000)) {
            $off += 2;      // extended flags
        }

        if ($version === 4) {
            $strip = rsx_submodule_sync_read_offset_varint($data, $off);
            if ($strip === null) {
                return null;
            }
            if ($strip > strlen($prev)) {
                return null;
            }

            $nul = strpos($data, "\0", $off);
            if ($nul === false) {
                return null;
            }
            $suffix = substr($data, $off, $nul - $off);
            $name   = substr($prev, 0, strlen($prev) - $strip) . $suffix;
            $off    = $nul + 1;         // no padding in v4
        } else {
            $name_len = $flags & 0x0FFF;

            if ($name_len < 0x0FFF) {
                $name = substr($data, $off, $name_len);
                $off += $name_len;
            } else {
                // Names >= 4095 bytes are NUL-terminated instead of length-prefixed.
                $nul = strpos($data, "\0", $off);
                if ($nul === false) {
                    return null;
                }
                $name = substr($data, $off, $nul - $off);
                $off  = $nul;
            }

            // At least one NUL, then pad the whole entry to a multiple of 8.
            $off = $start + (intdiv(($off - $start) + 8, 8) * 8);
        }

        $prev = $name;

        if ($name === $want && $mode === 0160000) {
            return $sha;
        }
    }

    return null;
}

/**
 * Git's offset encoding, as used for the v4 path-strip count and OFS_DELTA.
 *
 * Not LEB128: every continuation byte adds one to the accumulated value before
 * the next shift, which makes each number's encoding unique. Advances $off past
 * the integer. Null on a truncated or absurdly long encoding.
 */
function rsx_submodule_sync_read_offset_varint(string $data, int &$off): ?int
{
    $len = strlen($data);
    if ($off >= $len) {
        return null;
    }

    $byte  = ord($data[$off++]);
    $value = $byte & 0x7F;
    $guard = 0;

    while ($byte & 0x80) {
        if ($off >= $len || ++$guard > 8) {
            return null;
        }
        $value += 1;
        $byte  = ord($data[$off++]);
        $value = ($value << 7) + ($byte & 0x7F);
    }

    return $value;
}

/**
 * Refuse, on whichever channel is listening.
 *
 * The message names both shas and prints the one command that fixes it. An error
 * that only says "out of sync" leaves somebody guessing at submodule syntax they
 * may never have used.
 */
function rsx_submodule_sync_fail(string $submodule, string $recorded, string $actual): void
{
    $short_recorded = substr($recorded, 0, 12);
    $short_actual   = substr($actual, 0, 12);

    $lines = [
        "This project records framework release {$short_recorded}, but {$submodule}/ is checked out at {$short_actual}.",
        '',
        "  {$submodule}/ is a git submodule. A plain `git pull` updates the recorded",
        '  revision without checking the submodule out, so the framework running right',
        '  now is NOT the one this project says it uses. Nothing else would have told',
        '  you: the application boots and serves pages against the wrong version.',
        '',
        '  Fix it:',
        '',
        '      git submodule update --init',
        '',
        '  If that reports the recorded revision is not on the remote (the framework',
        '  history was rewritten upstream), move onto the current line instead:',
        '',
        '      php artisan rsx:framework:pull',
        '',
        '  To stop it happening again, pull through the proxy, which moves the',
        '  submodule to the recorded revision itself and never recurses into it:',
        '',
        '      php artisan rsx:git pull',
        '',
    ];

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "\n[ERROR] Framework version mismatch.\n\n");
        foreach ($lines as $line) {
            fwrite(STDERR, ($line === '' ? '' : $line) . "\n");
        }
        exit(1);
    }

    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 60');
    }

    echo "503 - Framework version mismatch\n\n";
    foreach ($lines as $line) {
        echo $line . "\n";
    }
    exit(1);
}
