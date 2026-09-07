<?php

namespace App\RSpade\Tests\Logrotate\Php;

use App\RSpade\Core\Logging\Rsx_Logrotate;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Logrotate - the rotation mechanics.
 *
 * Every test builds its own fixture directory under storage/rsx-tmp; the real
 * storage/logs is never named here.
 */
class Rsx_Logrotate_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Create an empty fixture directory for one test.
     *
     * @param string $label Distinguishes concurrent fixtures
     * @return string Absolute path
     */
    private static function __fixture_dir(string $label): string
    {
        $dir = storage_path('rsx-tmp/test-logrotate/' . $label);

        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $file) {
                unlink($file);
            }
        } else {
            ensure_directory($dir);
        }

        return $dir;
    }

    /**
     * The first rotation moves the log to .1 and leaves a fresh empty log wearing
     * the original mode.
     */
    public static function test_first_rotation_creates_generation_one()
    {
        $dir = self::__fixture_dir('first');

        file_put_contents($dir . '/app.log', "first\n");
        chmod($dir . '/app.log', 0640);

        $report = Rsx_Logrotate::rotate($dir, 3, 21);

        static::__assert_true($report['app.log']['rotated'], 'app.log should have rotated');
        static::__assert_equals('first' . "\n", file_get_contents($dir . '/app.log.1'));
        static::__assert_equals('', file_get_contents($dir . '/app.log'));
        static::__assert_equals(0640, fileperms($dir . '/app.log') & 0777, 'mode should be preserved');
        static::__assert_equals(['app.log' => 'app.log.1'], $report['app.log']['shifted']);
        static::__assert_empty($report['app.log']['compressed']);
        static::__assert_empty($report['app.log']['deleted']);
    }

    /**
     * A second rotation shifts .1 to .2 before claiming .1 for itself.
     */
    public static function test_second_rotation_shifts_generations()
    {
        $dir = self::__fixture_dir('second');

        file_put_contents($dir . '/app.log', "one\n");
        Rsx_Logrotate::rotate($dir, 3, 21);

        file_put_contents($dir . '/app.log', "two\n");
        $report = Rsx_Logrotate::rotate($dir, 3, 21);

        static::__assert_equals("two\n", file_get_contents($dir . '/app.log.1'));
        static::__assert_equals("one\n", file_get_contents($dir . '/app.log.2'));
        static::__assert_equals('app.log.2', $report['app.log']['shifted']['app.log.1']);
    }

    /**
     * The three bands: plain up to days_uncompressed, gzipped up to days_retention,
     * gone past it. days_uncompressed=1 / days_retention=3 makes .1 plain, .2 and .3
     * gz, and a fourth generation deleted.
     */
    public static function test_compression_and_retention_bands()
    {
        $dir = self::__fixture_dir('bands');

        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($dir . '/app.log', "run {$i}\n");
            Rsx_Logrotate::rotate($dir, 1, 3);
        }

        static::__assert_true(is_file($dir . '/app.log.1'), '.1 should be plain');
        static::__assert_false(is_file($dir . '/app.log.1.gz'), '.1 should not be compressed');
        static::__assert_true(is_file($dir . '/app.log.2.gz'), '.2 should be compressed');
        static::__assert_true(is_file($dir . '/app.log.3.gz'), '.3 should be compressed');
        static::__assert_false(is_file($dir . '/app.log.2'), 'the plain .2 should be gone');
        static::__assert_false(is_file($dir . '/app.log.4'), 'nothing past retention survives');
        static::__assert_false(is_file($dir . '/app.log.4.gz'), 'nothing past retention survives');

        // The compressed generation still holds its bytes.
        static::__assert_equals("run 4\n", gzdecode(file_get_contents($dir . '/app.log.2.gz')));
    }

    /**
     * A 0-byte log has nothing worth a generation, so it is left exactly as it is.
     */
    public static function test_empty_log_is_not_rotated()
    {
        $dir = self::__fixture_dir('empty');

        file_put_contents($dir . '/app.log', '');

        $report = Rsx_Logrotate::rotate($dir, 3, 21);

        static::__assert_false($report['app.log']['rotated'], 'an empty log does not rotate');
        static::__assert_equals('empty', $report['app.log']['skipped']);
        static::__assert_false(is_file($dir . '/app.log.1'), 'no generation should have been created');
    }

    /**
     * Only top-level *.log files are touched: another extension, a directory, and a
     * log inside that directory are all invisible.
     */
    public static function test_only_top_level_log_files_are_touched()
    {
        $dir = self::__fixture_dir('scope');

        file_put_contents($dir . '/app.log', "rotate me\n");
        file_put_contents($dir . '/notes.txt', "leave me\n");
        file_put_contents($dir . '/app.log.old', "leave me\n");
        ensure_directory($dir . '/nested');
        file_put_contents($dir . '/nested/inner.log', "leave me\n");

        $report = Rsx_Logrotate::rotate($dir, 3, 21);

        static::__assert_equals(['app.log'], array_keys($report), 'only app.log is in scope');
        static::__assert_equals("leave me\n", file_get_contents($dir . '/notes.txt'));
        static::__assert_equals("leave me\n", file_get_contents($dir . '/app.log.old'));
        static::__assert_equals("leave me\n", file_get_contents($dir . '/nested/inner.log'));
        static::__assert_false(is_file($dir . '/nested/inner.log.1'), 'never recursive');

        unlink($dir . '/nested/inner.log');
        rmdir($dir . '/nested');
    }

    /**
     * The report names every file and carries the six documented keys.
     */
    public static function test_report_shape()
    {
        $dir = self::__fixture_dir('report');

        file_put_contents($dir . '/a.log', "a\n");
        file_put_contents($dir . '/b.log', '');

        $report = Rsx_Logrotate::rotate($dir, 3, 21);

        static::__assert_equals(['a.log', 'b.log'], array_keys($report));

        foreach (['rotated', 'skipped', 'renumbered', 'shifted', 'compressed', 'deleted'] as $key) {
            static::__assert_array_has_key($key, $report['a.log']);
            static::__assert_array_has_key($key, $report['b.log']);
        }
    }

    /**
     * The settings are asserted, not coerced: a non-positive number or an inverted
     * pair is a broken config and says so.
     */
    public static function test_incoherent_settings_are_refused()
    {
        $dir = self::__fixture_dir('validate');

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Rsx_Logrotate::rotate($dir, 0, 21),
            'days_uncompressed must be a positive integer'
        );

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Rsx_Logrotate::rotate($dir, 3, 0),
            'days_retention must be a positive integer'
        );

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Rsx_Logrotate::rotate($dir, 21, 3),
            'must be >='
        );
    }

    /**
     * A directory that is not there is an impossible condition, not a quiet no-op.
     */
    public static function test_missing_directory_is_loud()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn () => Rsx_Logrotate::rotate(storage_path('rsx-tmp/test-logrotate/does-not-exist'), 3, 21),
            'not a directory'
        );
    }

    /**
     * Every generation of one log, keyed by filename, with .gz entries decoded.
     *
     * Content is the only identity a generation has once it has been renumbered,
     * so the repair tests assert on this rather than on numbers.
     *
     * @param string $dir Fixture directory
     * @param string $log_name Basename of the log
     * @return array filename => plain-text content
     */
    private static function __generation_contents(string $dir, string $log_name): array
    {
        $contents = [];

        foreach (scandir($dir) as $file) {
            if (!preg_match('/^' . preg_quote($log_name, '/') . '\\.(\\d+)(\\.gz)?$/', $file, $matches)) {
                continue;
            }

            $raw = file_get_contents($dir . '/' . $file);

            $contents[$file] = ($matches[2] ?? '') === '.gz' ? gzdecode($raw) : $raw;
        }

        ksort($contents);

        return $contents;
    }

    /**
     * Assert the generations of one log form a contiguous 1..K with one form each.
     *
     * @param string $dir Fixture directory
     * @param string $log_name Basename of the log
     * @return array number => '' | '.gz'
     */
    private static function __assert_contiguous(string $dir, string $log_name): array
    {
        $forms = [];

        foreach (array_keys(self::__generation_contents($dir, $log_name)) as $file) {
            preg_match('/\\.(\\d+)(\\.gz)?$/', $file, $matches);

            $number = (int) $matches[1];

            static::__assert_false(
                isset($forms[$number]),
                "generation {$number} of {$log_name} must exist in exactly one form"
            );

            $forms[$number] = $matches[2] ?? '';
        }

        ksort($forms);

        static::__assert_equals(
            range(1, count($forms)),
            array_keys($forms),
            'generations should be numbered contiguously from 1'
        );

        return $forms;
    }

    /**
     * The state this box was found in: a plain chain and an older gz chain
     * overlapping on numbers 4 and 5. Both files at a shared number are real
     * generations, so the rotation renumbers them by age instead of refusing.
     */
    public static function test_plain_and_gz_collision_is_repaired()
    {
        $dir = self::__fixture_dir('collision');

        $newer = 1756000000;
        $older = 1755900000;

        file_put_contents($dir . '/app.log', "current\n");

        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($dir . '/app.log.' . $i, "plain {$i}\n");
            touch($dir . '/app.log.' . $i, $newer);
        }

        for ($i = 4; $i <= 6; $i++) {
            file_put_contents($dir . '/app.log.' . $i . '.gz', gzencode("gz {$i}\n"));
            touch($dir . '/app.log.' . $i . '.gz', $older);
        }

        $report = Rsx_Logrotate::rotate($dir, 3, 21);

        static::__assert_true($report['app.log']['rotated'], 'the collision must not stop the rotation');
        static::__assert_not_empty($report['app.log']['renumbered'], 'the repair should be narrated');

        $forms = self::__assert_contiguous($dir, 'app.log');

        static::__assert_equals(9, count($forms), 'all nine generations survive');
        static::__assert_equals('', $forms[1], '.1 is the log just rotated, plain');
        static::__assert_equals('', $forms[2]);
        static::__assert_equals('', $forms[3]);
        static::__assert_equals('.gz', $forms[4], 'past days_uncompressed everything is gz');

        for ($number = 4; $number <= 9; $number++) {
            static::__assert_equals('.gz', $forms[$number]);
        }

        // Every original byte is still present, exactly once.
        $contents = array_values(self::__generation_contents($dir, 'app.log'));

        sort($contents);

        $expected = ["current\n"];

        for ($i = 1; $i <= 5; $i++) {
            $expected[] = "plain {$i}\n";
        }

        for ($i = 4; $i <= 6; $i++) {
            $expected[] = "gz {$i}\n";
        }

        sort($expected);

        static::__assert_equals($expected, $contents, 'no generation may be lost or duplicated');
    }

    /**
     * Gaps in the numbering are healed by the same repair, in age order.
     */
    public static function test_gaps_are_healed()
    {
        $dir = self::__fixture_dir('gaps');

        file_put_contents($dir . '/app.log', "current\n");

        foreach ([1 => 1756000000, 3 => 1755900000, 7 => 1755800000] as $number => $mtime) {
            file_put_contents($dir . '/app.log.' . $number, "gen {$number}\n");
            touch($dir . '/app.log.' . $number, $mtime);
        }

        $report = Rsx_Logrotate::rotate($dir, 10, 21);

        static::__assert_equals(
            ['app.log.3' => 'app.log.2', 'app.log.7' => 'app.log.3'],
            $report['app.log']['renumbered']
        );

        self::__assert_contiguous($dir, 'app.log');

        static::__assert_equals("current\n", file_get_contents($dir . '/app.log.1'));
        static::__assert_equals("gen 1\n", file_get_contents($dir . '/app.log.2'));
        static::__assert_equals("gen 3\n", file_get_contents($dir . '/app.log.3'));
        static::__assert_equals("gen 7\n", file_get_contents($dir . '/app.log.4'));
    }

    /**
     * A compressed generation occupies the same kind of slot as a plain one: the
     * shift moves .N.gz to .N+1.gz.
     */
    public static function test_shift_moves_compressed_generations()
    {
        $dir = self::__fixture_dir('shift-gz');

        file_put_contents($dir . '/app.log', "current\n");

        // Already contiguous, so the repair has nothing to do and the shift is
        // the only thing under test.
        file_put_contents($dir . '/app.log.1', "plain 1\n");
        touch($dir . '/app.log.1', 1756000000);
        file_put_contents($dir . '/app.log.2', "plain 2\n");
        touch($dir . '/app.log.2', 1755990000);
        file_put_contents($dir . '/app.log.3.gz', gzencode("gz 3\n"));
        touch($dir . '/app.log.3.gz', 1755900000);
        file_put_contents($dir . '/app.log.4.gz', gzencode("gz 4\n"));
        touch($dir . '/app.log.4.gz', 1755800000);

        $report = Rsx_Logrotate::rotate($dir, 2, 21);

        static::__assert_empty($report['app.log']['renumbered'], 'a contiguous set needs no repair');
        static::__assert_equals('app.log.4.gz', $report['app.log']['shifted']['app.log.3.gz']);
        static::__assert_equals('app.log.5.gz', $report['app.log']['shifted']['app.log.4.gz']);

        static::__assert_equals("gz 3\n", gzdecode(file_get_contents($dir . '/app.log.4.gz')));
        static::__assert_equals("gz 4\n", gzdecode(file_get_contents($dir . '/app.log.5.gz')));
        static::__assert_false(is_file($dir . '/app.log.3'), 'the shifted .3 was compressed out of the plain band');
        static::__assert_equals("plain 2\n", gzdecode(file_get_contents($dir . '/app.log.3.gz')));
    }

    /**
     * Shrinking days_retention between runs prunes to exactly the newest
     * generations, because the band pass runs on a settled contiguous numbering.
     */
    public static function test_retention_shrink_keeps_the_newest()
    {
        $dir = self::__fixture_dir('shrink');

        for ($i = 1; $i <= 10; $i++) {
            file_put_contents($dir . '/app.log', "run {$i}\n");
            Rsx_Logrotate::rotate($dir, 10, 10);
        }

        static::__assert_count(10, self::__generation_contents($dir, 'app.log'));

        file_put_contents($dir . '/app.log', "run 11\n");
        Rsx_Logrotate::rotate($dir, 3, 5);

        $contents = self::__generation_contents($dir, 'app.log');

        static::__assert_equals(
            ["run 11\n", "run 10\n", "run 9\n", "run 8\n", "run 7\n"],
            array_values($contents),
            'exactly the five newest runs survive, newest first'
        );

        self::__assert_contiguous($dir, 'app.log');
    }

    /**
     * Rotating twice with nothing new written is stable: the second pass finds a
     * 0-byte log and skips it, so the layout the first pass produced is exactly
     * the layout that remains.
     */
    public static function test_rotating_twice_is_stable()
    {
        $dir = self::__fixture_dir('idempotent');

        file_put_contents($dir . '/app.log', "only\n");

        Rsx_Logrotate::rotate($dir, 3, 21);

        $before = self::__generation_contents($dir, 'app.log');

        $report = Rsx_Logrotate::rotate($dir, 3, 21);

        static::__assert_false($report['app.log']['rotated'], 'an empty log is not rotated again');
        static::__assert_equals('empty', $report['app.log']['skipped']);
        static::__assert_equals($before, self::__generation_contents($dir, 'app.log'), 'the layout is unchanged');

        self::__assert_contiguous($dir, 'app.log');
    }
}
