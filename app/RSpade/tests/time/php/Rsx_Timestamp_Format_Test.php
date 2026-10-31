<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\Time\Rsx_Timestamp_Format;

/**
 * format_timestamp(), format_timestamp_short(), format_time_range() and the zone suffix -
 * and the property they exist for: PHP and JavaScript print the SAME string.
 *
 * The parity test runs one fixture of instants x zones x viewer zones through the PHP engine
 * and, under node, through the two vendor files the browser loads
 * (Core/Time/vendor/rsx_timezone_abbreviations.js + rsx_timestamp_format.js), and compares
 * them byte for byte. The committed abbreviation table is checked against the one PHP's tz
 * database generates now.
 */
class Rsx_Timestamp_Format_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const NOW = '2026-03-01T00:00:00Z';

    public static function teardown(): void
    {
        Rsx_Timestamp_Format::_reset();
    }

    private static function __epoch(string $iso): int
    {
        return Rsx_Time::parse($iso)->getTimestamp();
    }

    /**
     * tf-01: the browser's table is what PHP's tz database answers today.
     */
    public static function test_the_committed_table_is_current()
    {
        static::__assert_true(
            file_get_contents(Rsx_Timestamp_Format::abbreviation_table_js_path()) === Rsx_Timestamp_Format::abbreviation_table_js(),
            'Core/Time/vendor/rsx_timezone_abbreviations.js is stale: run php artisan rsx:time:abbreviations'
        );
    }

    /**
     * tf-02: the shapes, and the suffix rule, as stated.
     */
    public static function test_the_long_and_short_forms_and_the_suffix()
    {
        $july = static::__epoch('2026-07-09T19:30:00Z');
        $now = static::__epoch(self::NOW);

        static::__assert_equals('Jul 9, 2026 2:30 PM', Rsx_Timestamp_Format::timestamp($july, 'America/Chicago', 'America/Chicago'), 'the viewer\'s own zone: no suffix');
        static::__assert_equals('Jul 10, 2026 1:00 AM IST', Rsx_Timestamp_Format::timestamp($july, 'Asia/Kolkata', 'America/Chicago'));
        static::__assert_equals('Jul 9, 2026 2:30 PM', Rsx_Timestamp_Format::timestamp($july, 'America/Winnipeg', 'America/Chicago'), 'same offset, same name: no suffix');
        static::__assert_equals('Jul 9, 2026 4:30 PM UTC-3', Rsx_Timestamp_Format::timestamp($july, 'America/Sao_Paulo', 'America/Chicago'), 'a numeric tz name prints as UTC-3');

        // The instant decides standard vs daylight, for the printed zone and the viewer's.
        static::__assert_equals('CST', Rsx_Timestamp_Format::zone_abbreviation(static::__epoch('2026-01-15T12:00:00Z'), 'America/Chicago'));
        static::__assert_equals('CDT', Rsx_Timestamp_Format::zone_abbreviation($july, 'America/Chicago'));
        // Phoenix keeps MST; in July a Phoenix viewer shares Los Angeles's offset, not its name.
        static::__assert_equals('Jul 9, 2026 12:30 PM PDT', Rsx_Timestamp_Format::timestamp($july, 'America/Los_Angeles', 'America/Phoenix'));

        static::__assert_equals('07-09-26 2:30p', Rsx_Timestamp_Format::timestamp_short($july, 'America/Chicago', 'America/Chicago', static::__epoch('2025-12-01T00:00:00Z')), 'another year: the year');
        static::__assert_equals('07-09 2:30p', Rsx_Timestamp_Format::timestamp_short($july, 'America/Chicago', 'America/Chicago', $now));
        static::__assert_equals('07-10 1:00a IST', Rsx_Timestamp_Format::timestamp_short($july, 'Asia/Kolkata', 'America/Chicago', $now));

        // The year is judged in the PRINTED zone: 05:30 UTC on Jan 1 is still Dec 31 in Chicago.
        $new_year = static::__epoch('2026-01-01T05:30:00Z');
        static::__assert_equals('12-31-25 11:30p', Rsx_Timestamp_Format::timestamp_short($new_year, 'America/Chicago', 'America/Chicago', $now));
        static::__assert_equals('01-01 11:00a IST', Rsx_Timestamp_Format::timestamp_short($new_year, 'Asia/Kolkata', 'America/Chicago', $now));
    }

    /**
     * tf-03: ranges - one day with a shared or a mixed meridiem, across midnight, no end.
     */
    public static function test_ranges()
    {
        $dash = "\u{2013}";
        $now = static::__epoch(self::NOW);
        $range = fn (string $a, ?string $b, bool $short = false, string $zone = 'America/Chicago') =>
            Rsx_Timestamp_Format::time_range(static::__epoch($a), $b === null ? null : static::__epoch($b), $zone, 'America/Chicago', $short, $now);

        static::__assert_equals("Oct 9, 2026 2:30 {$dash} 4:00 PM", $range('2026-10-09T19:30:00Z', '2026-10-09T21:00:00Z'));
        static::__assert_equals("Oct 9, 2026 11:30 AM {$dash} 1:00 PM", $range('2026-10-09T16:30:00Z', '2026-10-09T18:00:00Z'));
        static::__assert_equals("Oct 9, 2026 11:00 PM {$dash} Oct 10, 2026 1:00 AM", $range('2026-10-10T04:00:00Z', '2026-10-10T06:00:00Z'));
        static::__assert_equals('Oct 9, 2026 2:30 PM', $range('2026-10-09T19:30:00Z', null), 'no end: the start alone');

        static::__assert_equals("10-09 2:30{$dash}4:00p", $range('2026-10-09T19:30:00Z', '2026-10-09T21:00:00Z', true));
        static::__assert_equals("10-09 11:30a{$dash}1:00p", $range('2026-10-09T16:30:00Z', '2026-10-09T18:00:00Z', true));
        static::__assert_equals("10-09 11:00p {$dash} 10-10 1:00a", $range('2026-10-10T04:00:00Z', '2026-10-10T06:00:00Z', true));

        static::__assert_equals("Oct 10, 2026 1:00 {$dash} 2:30 AM IST", $range('2026-10-09T19:30:00Z', '2026-10-09T21:00:00Z', false, 'Asia/Kolkata'), 'the suffix once, at the end');
    }

    /**
     * tf-04: the application's patterns are honoured, and a letter outside the closed set is
     * refused when the configuration is read.
     */
    public static function test_configured_patterns_and_the_refusal()
    {
        $original = config('rsx.datetime.display');

        try {
            config(['rsx.datetime.display' => ['date' => 'D, j F Y', 'time' => 'H:i']]);
            Rsx_Timestamp_Format::_reset();

            $epoch = static::__epoch('2026-10-09T19:30:00Z');
            static::__assert_equals('Fri, 9 October 2026 14:30', Rsx_Timestamp_Format::timestamp($epoch, 'America/Chicago', 'America/Chicago'));

            $dash = "\u{2013}";
            static::__assert_equals(
                "Fri, 9 October 2026 14:30 {$dash} 16:00",
                Rsx_Timestamp_Format::time_range($epoch, $epoch + 5400, 'America/Chicago', 'America/Chicago', false, $epoch),
                'no meridiem in the pattern: both clocks in full'
            );

            config(['rsx.datetime.display' => ['date' => 'M jS, Y', 'time' => 'g:i A']]);
            Rsx_Timestamp_Format::_reset();
            static::__assert_throws(\RuntimeException::class, fn () => Rsx_Timestamp_Format::display_patterns(), "letter 'S'");

            config(['rsx.datetime.display' => ['date' => '\\D\\a\\y j', 'time' => 'g:i A']]);
            Rsx_Timestamp_Format::_reset();
            static::__assert_equals('Day 9', Rsx_Timestamp_Format::pattern($epoch, 'America/Chicago', Rsx_Timestamp_Format::display_patterns()['date']), 'an escaped letter is literal');
        } finally {
            config(['rsx.datetime.display' => $original]);
            Rsx_Timestamp_Format::_reset();
        }
    }

    /**
     * tf-05: the same fixture through the PHP engine and the browser's vendor files, byte for
     * byte - default and custom patterns, every zone against two viewers.
     */
    public static function test_php_and_javascript_print_the_same_strings()
    {
        $instants = ['2026-01-15T18:30:00Z', '2026-07-15T18:30:00Z', '2025-07-15T03:00:00Z', '2026-01-01T05:30:00Z', '2026-10-09T16:30:00Z'];
        $zones = ['America/Chicago', 'America/Winnipeg', 'Asia/Kolkata', 'Europe/Berlin', 'Australia/Sydney', 'America/Sao_Paulo', 'UTC', 'America/Phoenix'];
        $viewers = ['America/Chicago', 'Asia/Kolkata'];
        $pattern_sets = [
            ['date' => 'M j, Y', 'time' => 'g:i A'],
            ['date' => 'l, d/m/y', 'time' => 'G:i:s a'],
        ];
        $now = static::__epoch(self::NOW);

        $cases = [];
        $expected = [];
        $original = config('rsx.datetime.display');

        try {
            foreach ($pattern_sets as $patterns) {
                config(['rsx.datetime.display' => $patterns]);
                Rsx_Timestamp_Format::_reset();

                foreach ($instants as $iso) {
                    $epoch = static::__epoch($iso);
                    foreach ($zones as $zone) {
                        foreach ($viewers as $viewer) {
                            $cases[] = ['timestamp', $epoch, $zone, $viewer, $patterns];
                            $expected[] = Rsx_Timestamp_Format::timestamp($epoch, $zone, $viewer);
                            $cases[] = ['short', $epoch, $zone, $viewer, $now];
                            $expected[] = Rsx_Timestamp_Format::timestamp_short($epoch, $zone, $viewer, $now);
                            foreach ([5400, 9000, null] as $length) {
                                foreach ([false, true] as $short) {
                                    $end = $length === null ? null : $epoch + $length;
                                    $cases[] = ['range', $epoch, $end, $zone, $viewer, $short, $now, $patterns];
                                    $expected[] = Rsx_Timestamp_Format::time_range($epoch, $end, $zone, $viewer, $short, $now);
                                }
                            }
                        }
                        $cases[] = ['pattern', $epoch, $zone, $patterns['date'] . ' ' . $patterns['time']];
                        $expected[] = Rsx_Timestamp_Format::pattern($epoch, $zone, $patterns['date'] . ' ' . $patterns['time']);
                        $cases[] = ['abbr', $epoch, $zone];
                        $expected[] = Rsx_Timestamp_Format::zone_abbreviation($epoch, $zone);
                    }
                }
            }
        } finally {
            config(['rsx.datetime.display' => $original]);
            Rsx_Timestamp_Format::_reset();
        }

        $actual = static::__run_in_node($cases);

        static::__assert_count(count($expected), $actual, 'node answered every case');
        foreach ($expected as $index => $php) {
            static::__assert_equals($php, $actual[$index], 'PHP and JS disagree on case ' . json_encode($cases[$index]));
        }
    }

    /**
     * Run the cases through the vendor files under node: one JSON array in, one out.
     */
    private static function __run_in_node(array $cases): array
    {
        $vendor = dirname(Rsx_Timestamp_Format::abbreviation_table_js_path());
        $script = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            global.window = global;
            const dir = process.argv[1];
            vm.runInThisContext(fs.readFileSync(dir + '/rsx_timezone_abbreviations.js', 'utf8'));
            vm.runInThisContext(fs.readFileSync(dir + '/rsx_timestamp_format.js', 'utf8'));
            const F = window.Rsx_Timestamp_Format;
            const cases = JSON.parse(fs.readFileSync(0, 'utf8'));
            const out = cases.map((c) => {
                switch (c[0]) {
                    case 'timestamp': return F.timestamp(c[1], c[2], c[3], c[4]);
                    case 'short': return F.timestamp_short(c[1], c[2], c[3], c[4]);
                    case 'range': return F.time_range(c[1], c[2], c[3], c[4], c[5], c[6], c[7]);
                    case 'pattern': return F.pattern(c[1], c[2], c[3]);
                    case 'abbr': return F.zone_abbreviation(c[1], c[2]);
                }
                throw new Error('unknown case ' + c[0]);
            });
            process.stdout.write(JSON.stringify(out));
            JS;

        $process = new Process(['node', '-e', $script, $vendor]);
        $process->setInput(json_encode($cases));
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            static::__fail('node failed: ' . $process->getErrorOutput());
        }

        return json_decode($process->getOutput(), true);
    }
}
