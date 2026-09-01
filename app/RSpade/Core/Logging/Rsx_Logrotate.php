<?php

namespace App\RSpade\Core\Logging;

/**
 * Rsx_Logrotate
 *
 * The framework's own log rotation, because nothing else here does it. Laravel's
 * 'daily' channel rotates only the dated files Monolog itself wrote, never
 * compresses, and never prunes; the OS `logrotate` binary is NOT assumed to exist
 * anywhere - the shipped containers carry its config files and not the program.
 *
 * Pure and static: it takes a directory and two numbers and reports what it did.
 * It reads no config, resolves no paths and knows nothing about tasks or commands -
 * Log_Maintenance_Service and Rsx_Logrotate_Command supply the arguments.
 *
 * WHAT IT TOUCHES: files matching `*.log` at the TOP LEVEL of $directory. Never
 * recursive, never any other extension. A subdirectory, a `.txt`, a `.log.old` -
 * all invisible to this class.
 *
 * THE GENERATIONS are logrotate-style numeric suffixes:
 *
 *     name.log -> name.log.1 -> name.log.2 -> ... -> name.log.N[.gz]
 *
 *     1 .. $days_uncompressed                       stay plain
 *     $days_uncompressed+1 .. $days_retention       gzipped, named .N.gz
 *     past $days_retention                          deleted
 *
 * Since the scheduled sweep runs once a day, a generation number is a day count.
 *
 * A NUMBER IS ONE SLOT, WHATEVER THE FORM. The shift moves .N.gz to .N+1.gz
 * exactly as it moves .N to .N+1, so a compressed chain is never overrun by a
 * plain one. And before shifting, the generations on disk are RENUMBERED
 * contiguously from 1 in AGE ORDER (mtime descending; plain before gz on a tie).
 * That repair is not a courtesy for hand-edited directories - this algorithm
 * produces the states it fixes: raising $days_uncompressed between runs leaves a
 * plain generation standing on a number the previous run's gz chain already
 * holds, and an interrupted run leaves gaps. Both are legitimate distinct
 * generations, so they are renumbered - through temporary names, so no rename
 * can clobber a file - and never deleted or refused.
 *
 * RENAME-BASED, NOT COPYTRUNCATE. The current log is RENAMED to `.1` and a fresh
 * empty `name.log` is created with the original file's mode. Every per-call
 * appender - Monolog opening the file per request, php-fpm, the CSP collector -
 * therefore lands in the new file immediately. A LONG-LIVED process that is
 * holding an open handle keeps writing into the renamed `.1` through its inode
 * until it reopens; that is benign (the lines are not lost, they are one
 * generation early) and it is the same behavior OS logrotate has without a
 * postrotate reopen. Copytruncate would avoid it at the cost of losing whatever
 * was written between the copy and the truncate, which is the worse trade.
 *
 * FAILURES ARE LOUD. A rename or a gzip that does not succeed throws naming the
 * path; nothing here is suppressed with @ and nothing is skipped silently except
 * the two documented cases (a missing log, and a 0-byte log with nothing in it
 * worth a generation). A numbering the rotation itself can produce is not a
 * failure and does not throw - see the renumbering above.
 *
 * See: php artisan rsx:man logrotate
 */
class Rsx_Logrotate
{
    /**
     * Bytes moved per read/write while gzipping. Not a limit on anything - purely
     * the chunk size that keeps a multi-gigabyte log off the heap.
     */
    private const COPY_CHUNK_BYTES = 262144;

    /**
     * Rotate every top-level *.log in a directory.
     *
     * @param string $directory Directory to rotate (e.g. storage_path('logs'))
     * @param int $days_uncompressed Generations 1..this stay plain
     * @param int $days_retention Oldest generation kept; higher numbers are deleted
     * @return array Report keyed by log basename:
     *               [
     *                 'laravel.log' => [
     *                   'rotated'    => bool,   // false when there was nothing to rotate
     *                   'skipped'    => ?string,// why, when rotated is false
     *                   'renumbered' => array,  // the pre-shift repair, ['laravel.log.4.gz' => 'laravel.log.6.gz', ...]
     *                   'shifted'    => array,  // ['laravel.log.1' => 'laravel.log.2', ...]
     *                   'compressed' => array,  // ['laravel.log.4', ...] (names BEFORE .gz)
     *                   'deleted'    => array,  // ['laravel.log.22.gz', ...]
     *                 ],
     *               ]
     */
    public static function rotate(string $directory, int $days_uncompressed, int $days_retention): array
    {
        self::validate_settings($days_uncompressed, $days_retention);

        if (!is_dir($directory)) {
            shouldnt_happen("Rsx_Logrotate: not a directory: {$directory}");
        }

        $report = [];

        foreach (self::_find_logs($directory) as $log_name) {
            $report[$log_name] = self::_rotate_one($directory, $log_name, $days_uncompressed, $days_retention);
        }

        ksort($report);

        return $report;
    }

    /**
     * Assert a rotation setting pair is coherent.
     *
     * Broken here means broken config, which is an impossible-condition assertion
     * rather than an expected input error - the caller reads these out of
     * rsx.logging.rotation or off the command line and both have declared defaults.
     *
     * @param int $days_uncompressed Generations 1..this stay plain
     * @param int $days_retention Oldest generation kept
     * @return void
     */
    public static function validate_settings(int $days_uncompressed, int $days_retention): void
    {
        if ($days_uncompressed < 1) {
            shouldnt_happen("Rsx_Logrotate: days_uncompressed must be a positive integer, got {$days_uncompressed}");
        }

        if ($days_retention < 1) {
            shouldnt_happen("Rsx_Logrotate: days_retention must be a positive integer, got {$days_retention}");
        }

        if ($days_retention < $days_uncompressed) {
            shouldnt_happen(
                "Rsx_Logrotate: days_retention ({$days_retention}) must be >= " .
                "days_uncompressed ({$days_uncompressed}) - the compressed band cannot be negative"
            );
        }
    }

    /**
     * List the top-level *.log basenames in a directory.
     *
     * @param string $directory Directory to scan
     * @return array Basenames, sorted
     */
    private static function _find_logs(string $directory): array
    {
        $logs = [];

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!str_ends_with($entry, '.log')) {
                continue;
            }

            if (!is_file($directory . '/' . $entry)) {
                continue;
            }

            $logs[] = $entry;
        }

        sort($logs);

        return $logs;
    }

    /**
     * Rotate one log file and settle its generations.
     *
     * @param string $directory Directory holding the log
     * @param string $log_name Basename of the current log (e.g. 'laravel.log')
     * @param int $days_uncompressed Generations 1..this stay plain
     * @param int $days_retention Oldest generation kept
     * @return array The per-file report entry
     */
    private static function _rotate_one(string $directory, string $log_name, int $days_uncompressed, int $days_retention): array
    {
        $entry = [
            'rotated' => false,
            'skipped' => null,
            'renumbered' => [],
            'shifted' => [],
            'compressed' => [],
            'deleted' => [],
        ];

        $current = $directory . '/' . $log_name;

        // An empty log has nothing worth a generation of its own, and rotating it
        // would push a real generation one step closer to deletion for no reason.
        if (filesize($current) === 0) {
            $entry['skipped'] = 'empty';
            return $entry;
        }

        // Settle whatever numbering is on disk into a contiguous 1..K before
        // touching it. A settings change between runs, or a run interrupted
        // partway, can leave a gap or leave .N and .N.gz sharing one slot - both
        // are states this algorithm itself produces, so they are repaired here
        // rather than refused.
        $entry['renumbered'] = self::_normalise_generations($directory, $log_name);

        // Shift from the highest number DOWN so a rename never lands on an
        // occupied name. A generation is ONE SLOT whatever its form: .N.gz moves
        // to .N+1.gz exactly as .N moves to .N+1.
        $generations = self::_scan_generations($directory, $log_name);

        usort($generations, fn ($a, $b) => $b['number'] <=> $a['number']);

        foreach ($generations as $generation) {
            $from = $generation['name'];
            $to = $log_name . '.' . ($generation['number'] + 1) . $generation['suffix'];

            self::_rename($directory . '/' . $from, $directory . '/' . $to);

            $entry['shifted'][$from] = $to;
        }

        // The current log becomes generation 1, and a fresh one takes its place
        // wearing the same mode so every appender keeps writing.
        $mode = fileperms($current) & 0777;

        self::_rename($current, $directory . '/' . $log_name . '.1');

        $entry['shifted'][$log_name] = $log_name . '.1';
        $entry['rotated'] = true;

        if (file_put_contents($current, '') === false) {
            throw new \RuntimeException("Rsx_Logrotate: could not create a fresh log at {$current}");
        }

        if (!chmod($current, $mode)) {
            throw new \RuntimeException("Rsx_Logrotate: could not restore mode on {$current}");
        }

        // Settle the shifted set: compress what has aged out of the plain band,
        // delete what has aged out of retention entirely.
        foreach (self::_scan_generations($directory, $log_name) as $generation) {
            $name = $generation['name'];

            if ($generation['number'] > $days_retention) {
                self::_unlink($directory . '/' . $name);
                $entry['deleted'][] = $name;
                continue;
            }

            if ($generation['number'] > $days_uncompressed && $generation['suffix'] === '') {
                self::_compress($directory . '/' . $name);
                $entry['compressed'][] = $name;
            }
        }

        sort($entry['compressed']);
        sort($entry['deleted']);

        return $entry;
    }

    /**
     * Renumber one log's generations contiguously from 1, newest first.
     *
     * THE REPAIR. The numbering on disk is not guaranteed to be a contiguous
     * 1..K: a days_uncompressed that grew between runs leaves a plain generation
     * standing on a number an older gz chain already holds, and an interrupted
     * run leaves gaps. Neither is an impossible state and neither loses data, so
     * both are settled deterministically instead of refused.
     *
     * THE ORDER IS AGE: mtime descending (newest gets 1), plain before gz on a
     * tie, then the existing number - so a plain generation and a gz generation
     * that collided on one number both survive, adjacent, in the order they were
     * written. Every file keeps its own form; nothing is compressed, deleted or
     * read here.
     *
     * The renames go through temporary names first, so no rename can ever land
     * on a file that has not moved out of the way yet.
     *
     * @param string $directory Directory holding the log
     * @param string $log_name Basename of the current log
     * @return array Only the names that MOVED: ['laravel.log.4.gz' => 'laravel.log.6.gz', ...]
     */
    private static function _normalise_generations(string $directory, string $log_name): array
    {
        $generations = self::_scan_generations($directory, $log_name);

        if (empty($generations)) {
            return [];
        }

        usort($generations, function (array $a, array $b) {
            if ($a['mtime'] !== $b['mtime']) {
                return $b['mtime'] <=> $a['mtime'];
            }

            if ($a['suffix'] !== $b['suffix']) {
                return $a['suffix'] === '' ? -1 : 1;
            }

            return $a['number'] <=> $b['number'];
        });

        $renumbered = [];

        foreach ($generations as $index => $generation) {
            $target = $log_name . '.' . ($index + 1) . $generation['suffix'];

            if ($target !== $generation['name']) {
                $renumbered[$generation['name']] = $target;
            }
        }

        if (empty($renumbered)) {
            return [];
        }

        $temporary = [];

        foreach ($generations as $index => $generation) {
            $temporary[$index] = $log_name . '.rotate-tmp-' . ($index + 1);

            self::_rename($directory . '/' . $generation['name'], $directory . '/' . $temporary[$index]);
        }

        foreach ($generations as $index => $generation) {
            $target = $log_name . '.' . ($index + 1) . $generation['suffix'];

            self::_rename($directory . '/' . $temporary[$index], $directory . '/' . $target);
        }

        return $renumbered;
    }

    /**
     * Find the existing numbered generations of one log.
     *
     * A LIST rather than a number-keyed map, because two files may legitimately
     * be standing on the same number (see _normalise_generations).
     *
     * @param string $directory Directory holding the log
     * @param string $log_name Basename of the current log
     * @return array One entry per generation file:
     *               ['name' => 'laravel.log.4.gz', 'number' => 4, 'suffix' => '.gz', 'mtime' => 1234567890]
     */
    private static function _scan_generations(string $directory, string $log_name): array
    {
        $pattern = '/^' . preg_quote($log_name, '/') . '\.(\d+)(\.gz)?$/';
        $generations = [];

        clearstatcache();

        foreach (scandir($directory) as $file) {
            if (!preg_match($pattern, $file, $matches)) {
                continue;
            }

            $path = $directory . '/' . $file;

            if (!is_file($path)) {
                continue;
            }

            $generations[] = [
                'name' => $file,
                'number' => (int) $matches[1],
                'suffix' => $matches[2] ?? '',
                'mtime' => filemtime($path),
            ];
        }

        return $generations;
    }

    /**
     * Gzip a plain generation in place, then remove the plain file.
     *
     * The plain file is unlinked ONLY after the .gz exists and is non-empty, so an
     * interrupted or failed compression costs the .gz and never the log.
     *
     * @param string $path Path to the plain generation
     * @return void
     */
    private static function _compress(string $path): void
    {
        $gz_path = $path . '.gz';

        $source = fopen($path, 'rb');

        if ($source === false) {
            throw new \RuntimeException("Rsx_Logrotate: could not open {$path} for reading");
        }

        $target = gzopen($gz_path, 'wb');

        if ($target === false) {
            fclose($source);
            throw new \RuntimeException("Rsx_Logrotate: could not open {$gz_path} for writing");
        }

        while (!feof($source)) {
            $chunk = fread($source, self::COPY_CHUNK_BYTES);

            if ($chunk === false) {
                fclose($source);
                gzclose($target);
                throw new \RuntimeException("Rsx_Logrotate: read failed on {$path}");
            }

            if ($chunk === '') {
                continue;
            }

            if (gzwrite($target, $chunk) === false) {
                fclose($source);
                gzclose($target);
                throw new \RuntimeException("Rsx_Logrotate: write failed on {$gz_path}");
            }
        }

        fclose($source);

        if (!gzclose($target)) {
            throw new \RuntimeException("Rsx_Logrotate: could not close {$gz_path}");
        }

        clearstatcache(true, $gz_path);

        if (!is_file($gz_path) || filesize($gz_path) === 0) {
            throw new \RuntimeException("Rsx_Logrotate: {$gz_path} is missing or empty after compression - {$path} left in place");
        }

        // The .gz inherits the plain file's modification time, because the
        // renumbering repair reads mtime as the generation's AGE. Left at the
        // moment of compression it would make a freshly gzipped old generation
        // look NEWER than the plain generations in front of it.
        if (!touch($gz_path, filemtime($path))) {
            throw new \RuntimeException("Rsx_Logrotate: could not carry the modification time onto {$gz_path}");
        }

        self::_unlink($path);
    }

    /**
     * Rename, or throw naming both paths.
     *
     * @param string $from Source path
     * @param string $to Destination path
     * @return void
     */
    private static function _rename(string $from, string $to): void
    {
        if (!rename($from, $to)) {
            throw new \RuntimeException("Rsx_Logrotate: could not rename {$from} to {$to}");
        }
    }

    /**
     * Unlink, or throw naming the path.
     *
     * @param string $path Path to remove
     * @return void
     */
    private static function _unlink(string $path): void
    {
        if (!unlink($path)) {
            throw new \RuntimeException("Rsx_Logrotate: could not delete {$path}");
        }
    }
}
