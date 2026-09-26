<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Logs\_Sys_Log_Reader;
use App\RSpade\Sys\App\Sys\Logs\_Sys_Logs_Controller;

/**
 * The Logs screen: which names resolve to a file (the listing, never a path join), windowed
 * reads by byte offset (tail, backward pages, Follow), rotation detection, .gz reads and
 * the laravel entry grouping.
 *
 * Runs against a fixture directory under tmp/ (_Sys_Log_Reader::$directory_for_tests),
 * never the install's own logs, beside a sibling directory the symlink-out fixture points
 * into.
 */
class Sys_Logs_Controller_Test extends Rsx_Test_Abstract
{
    private static string $root;

    private static string $dir;

    public static function setup()
    {
        static::__acting_as_user(1);

        self::$root = Rsx_Project_Paths::tmp_path('sys_logs_test_' . getmypid() . '_' . uniqid());
        self::$dir = self::$root . '/logs';
        mkdir(self::$dir, 0777, true);
        mkdir(self::$root . '/outside');
        mkdir(self::$dir . '/subdir');

        file_put_contents(self::$root . '/outside/secret.log', "secret\n");
        file_put_contents(self::$dir . '/app.log', self::__numbered(1000));
        file_put_contents(self::$dir . '/.hidden', "hidden\n");
        file_put_contents(self::$dir . '/subdir/nested.log', "nested\n");
        symlink(self::$root . '/outside/secret.log', self::$dir . '/escape.log');
        symlink(self::$dir . '/app.log', self::$dir . '/alias.log');

        _Sys_Log_Reader::$directory_for_tests = self::$dir;
    }

    public static function teardown()
    {
        _Sys_Log_Reader::$directory_for_tests = null;
        rmdir_recursive(self::$root);
    }

    /**
     * Lines "line 0001 ..." to "line NNNN ...", each 100 bytes with its newline, so a
     * 1000-line file crosses the reader's 64KB chunk boundary.
     */
    private static function __numbered(int $count): string
    {
        $text = '';
        for ($i = 1; $i <= $count; $i++) {
            $text .= str_pad(sprintf('line %04d', $i), 99, '.') . "\n";
        }

        return $text;
    }

    private static function __texts(array $window): array
    {
        return array_column($window['entries'], 'text');
    }

    /**
     * RP-LOGS-01 - The listing is the directory's top-level regular files: no dotfile, no
     * subdirectory, no symlink (in or out), each with size, ISO modified time, format and
     * compression.
     */
    public static function test_listing_is_regular_top_level_files()
    {
        file_put_contents(self::$dir . '/laravel.log.3.gz', gzencode("x\n"));

        $rows = array_column(_Sys_Log_Reader::list_files(), null, 'name');
        $names = array_keys($rows);
        sort($names);

        static::__assert_equals(['app.log', 'laravel.log.3.gz'], $names, 'only the regular files are listed');
        static::__assert_equals(100000, $rows['app.log']['size'], 'size in bytes');
        static::__assert_equals(bytes_to_human(100000), $rows['app.log']['size_human'], 'size_human is bytes_to_human()');
        static::__assert_true((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $rows['app.log']['modified']), 'modified is ISO UTC');
        static::__assert_equals('plain', $rows['app.log']['format']);
        static::__assert_true($rows['laravel.log.3.gz']['compressed'], 'a .gz is compressed');
        static::__assert_equals('laravel', $rows['laravel.log.3.gz']['format'], 'the format ignores .gz');

        unlink(self::$dir . '/laravel.log.3.gz');
    }

    /**
     * RP-LOGS-02 - A name resolves only when the listing holds it: traversal, an absolute
     * path, a symlink out, a symlink in, a subdirectory, a nested file, a dotfile and an
     * unknown name are all null; the controller answers them not_found.
     */
    public static function test_names_resolve_only_from_the_listing()
    {
        static::__assert_equals(realpath(self::$dir . '/app.log'), _Sys_Log_Reader::resolve('app.log'), 'a listed name resolves to its realpath');

        $refused = [
            '../outside/secret.log',
            'subdir/../app.log',
            './app.log',
            self::$dir . '/app.log',
            '/etc/passwd',
            'escape.log',
            'alias.log',
            'subdir',
            'subdir/nested.log',
            '.hidden',
            'missing.log',
            '',
            "app.log\0",
        ];

        foreach ($refused as $name) {
            static::__assert_null(_Sys_Log_Reader::resolve($name), "'" . addcslashes($name, "\0") . "' is refused");

            $response = _Sys_Logs_Controller::read(Request::create('/'), ['file' => $name]);
            static::__assert_true($response instanceof Error_Response, "read refuses '" . addcslashes($name, "\0") . "'");
        }
    }

    /**
     * RP-LOGS-03 - The tail is the last N lines ending at the file's size; each
     * backward page ends where the previous one started, down to byte 0; the windows are
     * contiguous and cover the whole file exactly once.
     */
    public static function test_tail_then_backward_pages_by_offset()
    {
        $path = _Sys_Log_Reader::resolve('app.log');
        $tail = _Sys_Log_Reader::read_window($path, 'app.log', null, 100);

        static::__assert_equals(100000, $tail['end'], 'the tail ends at the size');
        static::__assert_equals(90000, $tail['start'], 'and starts 100 lines back');
        static::__assert_true($tail['has_earlier']);
        static::__assert_equals(100, $tail['line_count']);
        static::__assert_equals(str_pad('line 0901', 99, '.'), $tail['entries'][0]['text'], 'the first tail line');
        static::__assert_equals(90000, $tail['entries'][0]['offset'], 'each entry carries its byte offset');
        static::__assert_equals(str_pad('line 1000', 99, '.'), end($tail['entries'])['text'], 'the last line');

        // Page back to the start; 300 lines per page crosses the 64KB chunk boundary.
        $seen = self::__texts($tail);
        $before = $tail['start'];
        $pages = 0;
        do {
            $page = _Sys_Log_Reader::read_window($path, 'app.log', $before, 300);
            static::__assert_equals($before, $page['end'], 'a page ends where the previous one started');
            $seen = array_merge(self::__texts($page), $seen);
            $before = $page['start'];
            $pages++;
        } while ($page['has_earlier']);

        static::__assert_equals(3, $pages, '900 lines before the tail are three pages of 300');
        static::__assert_equals(0, $page['start'], 'the last page starts at byte 0');
        static::__assert_equals(explode("\n", rtrim(self::__numbered(1000), "\n")), $seen, 'every line exactly once, in order');

        // A window in the middle, and a before past the end is the end.
        $middle = _Sys_Log_Reader::read_window($path, 'app.log', 50000, 2);
        static::__assert_equals([49800, 50000], [$middle['start'], $middle['end']], 'a mid-file window');
        static::__assert_equals(['line 0499', 'line 0500'], array_map(fn ($t) => substr($t, 0, 9), self::__texts($middle)));
        static::__assert_equals(100000, _Sys_Log_Reader::read_window($path, 'app.log', 999999, 1)['end'], 'before is clamped to the size');

        // A final line with no newline is part of the tail.
        file_put_contents(self::$dir . '/partial.log', "one\ntwo\nthree");
        $partial = _Sys_Log_Reader::read_window(_Sys_Log_Reader::resolve('partial.log'), 'partial.log', null, 10);
        static::__assert_equals(['one', 'two', 'three'], self::__texts($partial), 'an unterminated last line is shown');
        static::__assert_false($partial['has_earlier']);
        unlink(self::$dir . '/partial.log');

        static::__assert_equals(1000, _Sys_Log_Reader::clamp_lines(5000), 'lines are capped at MAX_LINES');
        static::__assert_equals(1, _Sys_Log_Reader::clamp_lines(0), 'and floored at 1');
        static::__assert_equals(_Sys_Log_Reader::DEFAULT_LINES, _Sys_Log_Reader::clamp_lines(null));
    }

    /**
     * RP-LOGS-04 - Follow reads the complete lines appended after the offset; a line with
     * no newline yet waits for the next poll; a full page reports more.
     */
    public static function test_follow_reads_appended_bytes_from_the_offset()
    {
        $path = self::$dir . '/follow.log';
        file_put_contents($path, "a\nb\n");
        $path = _Sys_Log_Reader::resolve('follow.log');
        $tail = _Sys_Log_Reader::read_window($path, 'follow.log', null, 10);

        $none = _Sys_Log_Reader::read_forward($path, 'follow.log', $tail['end'], $tail['identity'], 10);
        static::__assert_false($none['rotated']);
        static::__assert_equals([], $none['entries'], 'nothing appended, nothing read');
        static::__assert_equals(4, $none['end']);

        file_put_contents($path, "c\nd\ne\npart", FILE_APPEND);
        $next = _Sys_Log_Reader::read_forward($path, 'follow.log', $tail['end'], $tail['identity'], 10);
        static::__assert_equals(['c', 'd', 'e'], self::__texts($next), 'the appended complete lines');
        static::__assert_equals(10, $next['end'], 'the end stops before the unterminated line');
        static::__assert_equals([4, 6, 8], array_column($next['entries'], 'offset'));
        static::__assert_false($next['more']);

        file_put_contents($path, "ial\nf\n", FILE_APPEND);
        $rest = _Sys_Log_Reader::read_forward($path, 'follow.log', $next['end'], $tail['identity'], 1);
        static::__assert_equals(['partial'], self::__texts($rest), 'the completed line arrives whole');
        static::__assert_true($rest['more'], 'a full page with bytes left says more');

        $response = _Sys_Logs_Controller::follow(Request::create('/'), [
            'file' => 'follow.log', 'offset' => $rest['end'], 'identity' => $tail['identity'],
        ]);
        static::__assert_equals(['f'], self::__texts($response), 'the endpoint forwards to read_forward');

        unlink($path);
    }

    /**
     * RP-LOGS-05 - Rotation: a truncate-and-rewrite (same inode, new head), a
     * rename-and-recreate (new inode) and a shrink below the offset all answer rotated.
     */
    public static function test_follow_detects_rotation()
    {
        $path = self::$dir . '/rotate.log';
        file_put_contents($path, "[2026-01-01 00:00:00] local.INFO: first\nsecond\n");
        $path = _Sys_Log_Reader::resolve('rotate.log');
        $tail = _Sys_Log_Reader::read_window($path, 'rotate.log', null, 10);

        // Truncated and rewritten past the old offset: same inode, different head.
        file_put_contents($path, "[2026-01-02 00:00:00] local.INFO: another first line, longer than before\n");
        static::__assert_true(_Sys_Log_Reader::read_forward($path, 'rotate.log', $tail['end'], $tail['identity'], 10)['rotated'], 'a rewritten head is a rotation');

        // Shrunk below the offset.
        $tail = _Sys_Log_Reader::read_window($path, 'rotate.log', null, 10);
        file_put_contents($path, '');
        static::__assert_true(_Sys_Log_Reader::read_forward($path, 'rotate.log', $tail['end'], $tail['identity'], 10)['rotated'], 'a truncated file is a rotation');

        // Renamed away and recreated with the same head: a new inode.
        file_put_contents($path, "same head\n");
        $tail = _Sys_Log_Reader::read_window($path, 'rotate.log', null, 10);
        rename($path, self::$dir . '/rotate.log.1');
        file_put_contents(self::$dir . '/rotate.log', "same head\nmore\n");
        static::__assert_true(_Sys_Log_Reader::read_forward(_Sys_Log_Reader::resolve('rotate.log'), 'rotate.log', $tail['end'], $tail['identity'], 10)['rotated'], 'a new inode is a rotation');

        // And an untouched file is not.
        $tail = _Sys_Log_Reader::read_window(_Sys_Log_Reader::resolve('rotate.log'), 'rotate.log', null, 10);
        static::__assert_false(_Sys_Log_Reader::read_forward(_Sys_Log_Reader::resolve('rotate.log'), 'rotate.log', $tail['end'], $tail['identity'], 10)['rotated'], 'the same file is not');

        unlink(self::$dir . '/rotate.log');
        unlink(self::$dir . '/rotate.log.1');
    }

    /**
     * RP-LOGS-06 - A .gz reads as its decompressed text, with the same offsets and pages
     * as the plain file; only the tail knows the size; no identity; Follow is refused.
     */
    public static function test_gz_reads_the_decompressed_stream()
    {
        file_put_contents(self::$dir . '/app.log.2.gz', gzencode(self::__numbered(1000)));
        $gz = _Sys_Log_Reader::resolve('app.log.2.gz');
        $plain = _Sys_Log_Reader::resolve('app.log');

        $gz_tail = _Sys_Log_Reader::read_window($gz, 'app.log.2.gz', null, 100);
        $plain_tail = _Sys_Log_Reader::read_window($plain, 'app.log', null, 100);

        static::__assert_equals(100000, $gz_tail['size'], 'the tail measures the decompressed size');
        static::__assert_equals([$plain_tail['start'], $plain_tail['end']], [$gz_tail['start'], $gz_tail['end']], 'the same offsets');
        static::__assert_equals(self::__texts($plain_tail), self::__texts($gz_tail), 'the same lines');
        static::__assert_null($gz_tail['identity'], 'a .gz has no follow identity');

        $gz_page = _Sys_Log_Reader::read_window($gz, 'app.log.2.gz', 50000, 250);
        $plain_page = _Sys_Log_Reader::read_window($plain, 'app.log', 50000, 250);
        static::__assert_equals(self::__texts($plain_page), self::__texts($gz_page), 'a backward page matches');
        static::__assert_equals($plain_page['start'], $gz_page['start']);
        static::__assert_null($gz_page['size'], 'a page that stopped early does not know the size');

        $response = _Sys_Logs_Controller::follow(Request::create('/'), [
            'file' => 'app.log.2.gz', 'offset' => 0, 'identity' => ['inode' => 1, 'head_length' => 0, 'head_hash' => ''],
        ]);
        static::__assert_true($response instanceof Error_Response, 'a .gz is not followed');

        unlink(self::$dir . '/app.log.2.gz');
    }

    /**
     * RP-LOGS-07 - laravel lines group under their header (level, time, env, message,
     * the trace below); a window opening inside an entry widens back to its header when
     * one is within a page, else opens on a "continued" entry; the filter keeps an entry
     * whose trace matches; csp lines pretty-print; plain lines stand alone.
     */
    public static function test_laravel_grouping_filter_and_formats()
    {
        $log = "[2026-09-26 06:00:00] local.INFO: started\n"
            . "[2026-09-26 06:00:01] local.ERROR: it broke {\"exception\":\"x\n"
            . "[stacktrace]\n#0 /a.php(1): f()\n#1 {main}\n\"}\n"
            . "[2026-09-26 06:00:02] production.WARNING: careful\n";
        file_put_contents(self::$dir . '/laravel.log', $log);
        $path = _Sys_Log_Reader::resolve('laravel.log');

        $all = _Sys_Log_Reader::read_window($path, 'laravel.log', null, 100);
        static::__assert_equals('laravel', $all['format']);
        static::__assert_equals(['INFO', 'ERROR', 'WARNING'], array_column($all['entries'], 'level'), 'one entry per header');
        $error = $all['entries'][1];
        static::__assert_equals('2026-09-26 06:00:01', $error['time']);
        static::__assert_equals('local', $error['env']);
        static::__assert_equals('it broke {"exception":"x', $error['text']);
        static::__assert_equals(['[stacktrace]', '#0 /a.php(1): f()', '#1 {main}', '"}'], $error['body'], 'the trace is grouped under its header');
        static::__assert_equals('production', $all['entries'][2]['env']);

        // Three lines back ends inside the error's trace: widened to its header.
        $narrow = _Sys_Log_Reader::read_window($path, 'laravel.log', null, 3);
        static::__assert_equals(['ERROR', 'WARNING'], array_column($narrow['entries'], 'level'), 'widened back to the header');
        static::__assert_equals(strlen("[2026-09-26 06:00:00] local.INFO: started\n"), $narrow['start'], 'the window starts at that header');

        // One line back from the end of the trace: the header is further than a page away.
        $trace_end = strpos($log, "[2026-09-26 06:00:02]");
        $orphan = _Sys_Log_Reader::read_window($path, 'laravel.log', $trace_end, 1);
        static::__assert_true($orphan['entries'][0]['continued'], 'a window with no header in reach opens on a continued entry');
        static::__assert_null($orphan['entries'][0]['level']);

        $filtered = _Sys_Log_Reader::read_window($path, 'laravel.log', null, 100, 'A.PHP');
        static::__assert_equals(['ERROR'], array_column($filtered['entries'], 'level'), 'a trace match keeps the entry, case-insensitively');
        static::__assert_equals($all['start'], $filtered['start'], 'the filter leaves the offsets alone');
        static::__assert_equals(7, $filtered['line_count'], 'and the line count is the window\'s');
        static::__assert_equals(['WARNING'], array_column(_Sys_Log_Reader::read_window($path, 'laravel.log', null, 100, 'production.warning')['entries'], 'level'), 'the header is searched as written');

        file_put_contents(self::$dir . '/csp_violations.log', "{\"at\":\"t\",\"report\":{\"n\":1}}\n\nnot json\n");
        $csp = _Sys_Log_Reader::read_window(_Sys_Log_Reader::resolve('csp_violations.log'), 'csp_violations.log', null, 10);
        static::__assert_equals('csp', $csp['format']);
        static::__assert_count(2, $csp['entries'], 'a blank line is dropped');
        static::__assert_equals("{\n    \"at\": \"t\",\n    \"report\": {\n        \"n\": 1\n    }\n}", $csp['entries'][0]['pretty'], 'a JSON line is pretty-printed');
        static::__assert_null($csp['entries'][1]['pretty'], 'a non-JSON line is left as text');

        file_put_contents(self::$dir . '/worker.log', "x\n\xff\xfebad bytes\n");
        $plain = _Sys_Log_Reader::read_window(_Sys_Log_Reader::resolve('worker.log'), 'worker.log', null, 10);
        static::__assert_equals('plain', $plain['format']);
        static::__assert_count(2, $plain['entries']);
        static::__assert_true(mb_check_encoding($plain['entries'][1]['text'], 'UTF-8'), 'invalid UTF-8 is scrubbed so the payload encodes');

        unlink(self::$dir . '/laravel.log');
        unlink(self::$dir . '/csp_violations.log');
        unlink(self::$dir . '/worker.log');
    }

    /**
     * RP-LOGS-08 - The read endpoint: the listing's facts plus the window, lines clamped.
     */
    public static function test_read_endpoint()
    {
        $response = _Sys_Logs_Controller::read(Request::create('/'), ['file' => 'app.log', 'lines' => 5000]);

        static::__assert_equals('app.log', $response['file']);
        static::__assert_equals(100000, $response['file_size']);
        static::__assert_equals(bytes_to_human(100000), $response['size_human']);
        static::__assert_equals(1000, $response['line_count'], 'lines are clamped to MAX_LINES');
        static::__assert_equals(0, $response['start']);
        static::__assert_false($response['has_earlier']);
        static::__assert_not_null($response['identity']);

        $grid = _Sys_Logs_Controller::datagrid_fetch(Request::create('/'), ['filter' => 'APP', 'sort' => 'size']);
        static::__assert_equals(['app.log'], array_column($grid['records'], 'name'), 'the file grid searches names');
        static::__assert_equals('size', $grid['sort']);
    }
}
