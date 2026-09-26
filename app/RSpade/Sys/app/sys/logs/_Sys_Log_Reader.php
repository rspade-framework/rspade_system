<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Logs;

use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * The Logs screen's file access: the directory listing, the one name-to-path
 * resolution, and windowed reads by BYTE OFFSET.
 *
 * WHICH FILES. The top-level regular files of Rsx_Project_Paths::logs_dir(), dotfiles
 * and symlinks excluded. A requested name is looked up IN THAT LISTING (never joined
 * onto the directory from input), and its realpath must sit directly inside the
 * directory's realpath - so a traversal, an absolute path, a symlink out and an
 * unknown name all resolve to null. Nothing here writes.
 *
 * WINDOWS. Every read is a window of whole lines addressed by byte offsets into the
 * file (into the DECOMPRESSED stream for a .gz), so memory is bounded by the window,
 * never by the file:
 *   read_window($name, $before)  - the N lines ending at byte $before (null = the end
 *                                  of the file): the tail, then "Load earlier" with
 *                                  $before = the previous window's start.
 *   read_forward($name, $offset) - the complete lines appended at or after $offset:
 *                                  Follow. Refused for a .gz.
 * A plain file is read backward in 64KB chunks from $before. A .gz cannot be seeked
 * backward, so it is decompressed FORWARD from byte 0 up to $before, keeping only the
 * last lines in a ring: each .gz page costs one decompression pass over everything
 * before it, in time, and one window in memory.
 *
 * FORMATS (format_of(), by name, the .gz suffix ignored):
 *   laravel - laravel*.log[.N]: lines grouped into entries, a "[time] env.LEVEL: msg"
 *             header owning the lines under it (a stack trace). A backward window is
 *             widened back to its first entry's header when one is within another
 *             page of lines; otherwise it opens on a "continued" entry.
 *   csp     - csp_violations.log[.N]: one JSON document per line, pretty-printed.
 *   plain   - anything else: one entry per line.
 *
 * The text filter is applied HERE, to the window's entries (a laravel entry matches on
 * its header or any line under it); the window's offsets are the unfiltered ones, so
 * paging is unchanged by a filter.
 *
 * ROTATION. read_window() answers the file's identity (inode plus a hash of its first
 * bytes). read_forward() compares it: a different inode, a changed head or a size
 * below the offset means the file was rotated or truncated, and it answers
 * {rotated: true} instead of lines.
 */
class _Sys_Log_Reader
{
    public const DEFAULT_LINES = 200;

    public const MAX_LINES = 1000;

    private const CHUNK_BYTES = 65536;

    private const HEAD_BYTES = 256;

    /** A laravel (Monolog LineFormatter) entry header: [time] env.LEVEL: message */
    public const LARAVEL_HEADER = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*)\] ([^\s.]+)\.([A-Z]+): ?(.*)$/';

    /**
     * The directory to read instead of logs_dir(), for tests only.
     */
    public static ?string $directory_for_tests = null;

    /**
     * The log directory's realpath.
     */
    public static function directory(): string
    {
        $dir = static::$directory_for_tests ?? Rsx_Project_Paths::logs_dir();
        // @REALPATH-EXCEPTION - Security: path traversal prevention. The containment check
        // in resolve() compares a file's physical parent against this physical directory.
        $real = realpath($dir);

        if ($real === false || !is_dir($real)) {
            shouldnt_happen("The log directory does not exist: {$dir}");
        }

        return $real;
    }

    /**
     * Every readable log: the directory's top-level regular files, no dotfiles, no
     * symlinks.
     *
     * @return array [{name, size, size_human, modified, compressed, format}]
     */
    public static function list_files(): array
    {
        $dir = static::directory();
        clearstatcache();

        $rows = [];
        foreach (scandir($dir) as $name) {
            if ($name[0] === '.') {
                continue;
            }

            $path = $dir . '/' . $name;
            if (is_link($path) || !is_file($path)) {
                continue;
            }

            $size = (int) filesize($path);
            $rows[] = [
                'name' => $name,
                'size' => $size,
                'size_human' => bytes_to_human($size),
                'modified' => Rsx_Time::to_iso((int) filemtime($path)),
                'compressed' => str_ends_with($name, '.gz'),
                'format' => static::format_of($name),
            ];
        }

        return $rows;
    }

    /**
     * The absolute path of a listed log, or null when $name is not one.
     *
     * @param string $name A file name exactly as list_files() gives it
     * @return string|null
     */
    public static function resolve(string $name): ?string
    {
        if (!in_array($name, array_column(static::list_files(), 'name'), true)) {
            return null;
        }

        $dir = static::directory();
        $path = $dir . '/' . $name;
        // @REALPATH-EXCEPTION - Security: path traversal prevention requires symlink resolution
        $real = realpath($path);

        if ($real === false || is_link($path) || dirname($real) !== $dir || !is_file($real)) {
            return null;
        }

        return $real;
    }

    /**
     * laravel | csp | plain, from the name (a .gz suffix is ignored).
     */
    public static function format_of(string $name): string
    {
        $base = preg_replace('/\.gz$/', '', $name);

        if (preg_match('/^laravel.*\.log(\.\d+)?$/', $base)) {
            return 'laravel';
        }

        if (preg_match('/^csp_violations\.log(\.\d+)?$/', $base)) {
            return 'csp';
        }

        return 'plain';
    }

    /**
     * The lines count a request may ask for, clamped into [1, MAX_LINES].
     */
    public static function clamp_lines($lines): int
    {
        $lines = $lines === null || $lines === '' ? static::DEFAULT_LINES : (int) $lines;

        return min(max(1, $lines), static::MAX_LINES);
    }

    /**
     * The window of $lines lines ending at byte $before.
     *
     * @param string $path A path resolve() returned
     * @param string $name Its listed name (decides the format and compression)
     * @param int|null $before The window's end offset; null = the end of the file
     * @param int $lines How many lines, before a laravel window widens to its header
     * @param string $filter Case-insensitive text an entry must contain ('' = all)
     * @return array {format, compressed, size, start, end, has_earlier, line_count,
     *                entries, identity}. size is null for a .gz page that stopped
     *                before the end (only the tail read measures it); identity is null
     *                for a .gz.
     */
    public static function read_window(string $path, string $name, ?int $before, int $lines, string $filter = ''): array
    {
        $format = static::format_of($name);
        $compressed = str_ends_with($name, '.gz');

        // A laravel window collects up to one more page behind it, to reach back to the
        // header of the entry it opens in.
        $want = $lines + ($format === 'laravel' ? $lines : 0);

        if ($compressed) {
            [$collected, $size, $end] = self::__gz_lines_before($path, $before, $want);
        } else {
            clearstatcache(true, $path);
            $size = (int) filesize($path);
            $end = $before === null ? $size : min(max(0, $before), $size);
            $collected = self::__lines_before($path, $end, $want);
        }

        $window = array_slice($collected, -$lines);

        if ($format === 'laravel' && !empty($window) && !preg_match(self::LARAVEL_HEADER, $window[0][1])) {
            for ($i = count($collected) - count($window) - 1; $i >= 0; $i--) {
                if (preg_match(self::LARAVEL_HEADER, $collected[$i][1])) {
                    $window = array_slice($collected, $i);
                    break;
                }
            }
        }

        $start = empty($window) ? $end : $window[0][0];

        return [
            'format' => $format,
            'compressed' => $compressed,
            'size' => $size,
            'start' => $start,
            'end' => $end,
            'has_earlier' => $start > 0,
            'line_count' => count($window),
            'entries' => static::filter_entries(static::entries($format, $window), $filter),
            'identity' => $compressed ? null : static::identity($path),
        ];
    }

    /**
     * The complete lines at or after byte $offset (at most $lines of them), or
     * {rotated: true} when the file is no longer the one $identity described.
     *
     * A trailing line with no newline yet is left for the next call.
     *
     * @param string $path A path resolve() returned (never a .gz)
     * @param string $name Its listed name
     * @param int $offset Where the previous read ended
     * @param array $identity What read_window() answered for the file
     * @param int $lines The most lines to return
     * @param string $filter As read_window()
     * @return array {rotated, size, start, end, more, line_count, entries}
     */
    public static function read_forward(string $path, string $name, int $offset, array $identity, int $lines, string $filter = ''): array
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);

        if ($offset < 0 || $offset > $size || !static::same_identity($path, $identity)) {
            return ['rotated' => true, 'size' => $size];
        }

        $handle = fopen($path, 'rb');
        fseek($handle, $offset);

        $collected = [];
        $position = $offset;
        $buffer = '';

        while (count($collected) < $lines) {
            $chunk = fread($handle, self::CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
            $cursor = 0;

            while (count($collected) < $lines && ($newline = strpos($buffer, "\n", $cursor)) !== false) {
                $collected[] = [$position, self::__clean(substr($buffer, $cursor, $newline - $cursor))];
                $position += $newline - $cursor + 1;
                $cursor = $newline + 1;
            }

            $buffer = substr($buffer, $cursor);
        }

        fclose($handle);

        return [
            'rotated' => false,
            'size' => $size,
            'start' => $offset,
            'end' => $position,
            'more' => $position < $size && count($collected) >= $lines,
            'line_count' => count($collected),
            'entries' => static::filter_entries(static::entries(static::format_of($name), $collected), $filter),
        ];
    }

    /**
     * What makes this file THIS file for Follow: its inode, and a hash of its first
     * bytes (a truncate-and-rewrite keeps the inode but not the head).
     *
     * @return array {inode, head_length, head_hash}
     */
    public static function identity(string $path): array
    {
        clearstatcache(true, $path);
        $length = min(self::HEAD_BYTES, (int) filesize($path));

        return [
            'inode' => (int) fileinode($path),
            'head_length' => $length,
            'head_hash' => md5(self::__head($path, $length)),
        ];
    }

    /**
     * Whether the file at $path is still the one $identity described.
     */
    public static function same_identity(string $path, array $identity): bool
    {
        clearstatcache(true, $path);
        $length = (int) ($identity['head_length'] ?? -1);

        if ($length < 0 || (int) fileinode($path) !== (int) ($identity['inode'] ?? -1)) {
            return false;
        }

        $head = self::__head($path, $length);

        return strlen($head) === $length && md5($head) === (string) ($identity['head_hash'] ?? '');
    }

    /**
     * Group a window's lines into entries for its format.
     *
     * laravel: {offset, time, env, level, text, body[]} - a line under no header in the
     *          window opens a {time: null, continued: true} entry
     * csp:     {offset, text, pretty} - pretty is null when the line is not JSON; blank
     *          lines are dropped
     * plain:   {offset, text}
     *
     * @param string $format laravel | csp | plain
     * @param array $lines [[offset, text], ...] ascending
     * @return array
     */
    public static function entries(string $format, array $lines): array
    {
        $entries = [];

        if ($format === 'laravel') {
            $current = null;

            foreach ($lines as [$offset, $text]) {
                if (preg_match(self::LARAVEL_HEADER, $text, $match)) {
                    if ($current !== null) {
                        $entries[] = $current;
                    }
                    $current = [
                        'offset' => $offset, 'time' => $match[1], 'env' => $match[2],
                        'level' => $match[3], 'text' => $match[4], 'body' => [], 'continued' => false,
                    ];
                } elseif ($current === null) {
                    $current = [
                        'offset' => $offset, 'time' => null, 'env' => null,
                        'level' => null, 'text' => $text, 'body' => [], 'continued' => true,
                    ];
                } else {
                    $current['body'][] = $text;
                }
            }

            if ($current !== null) {
                $entries[] = $current;
            }

            return $entries;
        }

        foreach ($lines as [$offset, $text]) {
            if ($format === 'csp') {
                if (trim($text) === '') {
                    continue;
                }

                $decoded = json_decode($text, true);
                $entries[] = [
                    'offset' => $offset,
                    'text' => $text,
                    'pretty' => is_array($decoded)
                        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : null,
                ];
            } else {
                $entries[] = ['offset' => $offset, 'text' => $text];
            }
        }

        return $entries;
    }

    /**
     * The entries containing $filter, case-insensitively: a laravel entry is searched
     * as its whole original text (header line and every line under it).
     */
    public static function filter_entries(array $entries, string $filter): array
    {
        $filter = trim($filter);
        if ($filter === '') {
            return $entries;
        }

        return array_values(array_filter($entries, function ($entry) use ($filter) {
            $haystack = $entry['text'];

            if (isset($entry['level']) && $entry['level'] !== null) {
                $haystack = "[{$entry['time']}] {$entry['env']}.{$entry['level']}: " . $haystack;
            }

            if (!empty($entry['body'])) {
                $haystack .= "\n" . implode("\n", $entry['body']);
            }

            return mb_stripos($haystack, $filter) !== false;
        }));
    }

    /**
     * Up to $count lines ending at byte $before of a plain file, read backward in
     * chunks. A line is [offset, text]; the result is ascending. $before is a line
     * start or the end of the file (a final line with no newline is included).
     */
    private static function __lines_before(string $path, int $before, int $count): array
    {
        if ($before <= 0 || $count <= 0) {
            return [];
        }

        $handle = fopen($path, 'rb');
        $reversed = [];
        $position = $before;
        $carry = '';
        $first = true;

        while (count($reversed) < $count && $position > 0) {
            $read = min(self::CHUNK_BYTES, $position);
            $position -= $read;
            fseek($handle, $position);
            $chunk = self::__read_exactly($handle, $read);

            $parts = explode("\n", $chunk . $carry);

            // The window ends at a line start: the empty piece after the final newline
            // is not a line.
            if ($first && end($parts) === '' && count($parts) > 1) {
                array_pop($parts);
            }
            $first = false;

            // Offsets of each piece: piece 0 starts at $position.
            $offsets = [];
            $at = $position;
            foreach ($parts as $index => $part) {
                $offsets[$index] = $at;
                $at += strlen($part) + 1;
            }

            // Pieces 1.. are whole lines; piece 0 may continue into the previous chunk.
            for ($index = count($parts) - 1; $index >= 1 && count($reversed) < $count; $index--) {
                $reversed[] = [$offsets[$index], self::__clean($parts[$index])];
            }

            $carry = $parts[0];
        }

        if ($position === 0 && count($reversed) < $count) {
            $reversed[] = [0, self::__clean($carry)];
        }

        fclose($handle);

        return array_reverse($reversed);
    }

    /**
     * Up to $count lines ending at decompressed byte $before of a .gz (null = its
     * end), decompressing forward from the start and keeping only a ring of lines.
     *
     * @return array [lines, size (the decompressed size when read to the end, else
     *               null), end]
     */
    private static function __gz_lines_before(string $path, ?int $before, int $count): array
    {
        $handle = gzopen($path, 'rb');
        $ring = [];
        $offset = 0;
        $reached_end = true;

        while (true) {
            if ($before !== null && $offset >= $before) {
                $reached_end = false;
                break;
            }

            $line = gzgets($handle);
            if ($line === false) {
                break;
            }

            $ring[] = [$offset, self::__clean(rtrim($line, "\n"))];
            $offset += strlen($line);

            if (count($ring) >= $count * 2) {
                $ring = array_slice($ring, -$count);
            }
        }

        gzclose($handle);

        return [array_slice($ring, -$count), $reached_end ? $offset : null, $offset];
    }

    /**
     * A line as it travels: no trailing CR, valid UTF-8 (a JSON response cannot carry
     * anything else).
     */
    private static function __clean(string $text): string
    {
        return mb_scrub(rtrim($text, "\r"), 'UTF-8');
    }

    private static function __head(string $path, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $handle = fopen($path, 'rb');
        $head = self::__read_exactly($handle, $length);
        fclose($handle);

        return $head;
    }

    /**
     * fread until $length bytes or the end of the file (a single fread may return less).
     */
    private static function __read_exactly($handle, int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $piece = fread($handle, $length - strlen($data));
            if ($piece === false || $piece === '') {
                break;
            }
            $data .= $piece;
        }

        return $data;
    }
}
