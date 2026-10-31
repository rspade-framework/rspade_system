<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Time;

/**
 * Rsx_Timestamp_Format - the engine behind Rsx_Time::format_timestamp(),
 * format_timestamp_short(), format_time_range(), format_date(), format_time() and
 * get_timezone_abbr(), written ONCE per language.
 *
 * Its JavaScript twin is Core/Time/vendor/rsx_timestamp_format.js (the vendor bundle, beside
 * the generated abbreviation table rsx_timezone_abbreviations.js). The two implement the same
 * algorithm over the same data, line for line, so a PHP-rendered label and a browser-rendered
 * one are the same string - which is the whole reason this is an engine and not Carbon's
 * format() on one side and Intl on the other (they disagree on commas and on every zone name
 * outside the US). A change here is a change there.
 *
 * WHAT IT DOES NOT DECIDE: which zone. Callers (Rsx_Time) pass the zone to print in and the
 * viewer's zone; the engine compares them for the suffix.
 *
 * THE PATTERN LANGUAGE is a CLOSED subset of PHP date() letters, implemented here and in JS:
 *
 *     d j m n M F y Y D l g G h H i s A a
 *
 * plus literal punctuation and spaces; a backslash makes the next character literal. Any
 * other ASCII letter is refused (validate_pattern()), because a letter one language
 * implements and the other does not is exactly the drift this engine exists to remove.
 *
 * THE ZONE ABBREVIATION comes from ONE committed table, vendor/rsx_timezone_abbreviations.js,
 * which this class and the browser both read: per zone, offset (seconds) => a name. Both
 * languages read the same file, so they cannot disagree on any box, whatever tz database its
 * PHP or its browser carries. The table is GENERATED from PHP's tz database by
 * rsx:time:abbreviations (generate_abbreviation_table()): each offset a zone has used between
 * 1970 and the moment of generation, named by the most recent such transition, a numeric name
 * ("-03", "+0530") written as "UTC-3" / "UTC+5:30". Transitions after that moment are not read:
 * they are PHP's expansion of a rule, not data, and one PHP build was seen to expand Winnipeg's
 * into "EST". An offset the table does not know (an instant before 1970, a zone it does not
 * carry) prints in the offset form.
 */
class Rsx_Timestamp_Format
{
    /** The letters a display pattern may use. */
    public const PATTERN_LETTERS = 'djmnMFyYDlgGhHisAa';

    /** The default long-form patterns (rsx.datetime.display). */
    public const DEFAULT_DATE_PATTERN = 'M j, Y';
    public const DEFAULT_TIME_PATTERN = 'g:i A';

    /** The range separator: an en dash. */
    private const DASH = "\u{2013}";

    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];

    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** @var array<string, array<int|string, string>>|null the committed table, per process */
    private static ?array $table = null;

    /** @var array{date: string, time: string}|null */
    private static ?array $patterns = null;

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * The application's long-form patterns, rsx.datetime.display.date / .time, validated.
     * Read once per process; an unsupported letter THROWS naming it and the key.
     *
     * @return array{date: string, time: string}
     */
    public static function display_patterns(): array
    {
        if (self::$patterns === null) {
            $date = config('rsx.datetime.display.date', self::DEFAULT_DATE_PATTERN);
            $time = config('rsx.datetime.display.time', self::DEFAULT_TIME_PATTERN);

            self::validate_pattern($date, 'rsx.datetime.display.date');
            self::validate_pattern($time, 'rsx.datetime.display.time');

            self::$patterns = ['date' => $date, 'time' => $time];
        }

        return self::$patterns;
    }

    /**
     * Forget the memoized patterns (a test that changes the configuration).
     */
    public static function _reset(): void
    {
        self::$patterns = null;
    }

    /**
     * Refuse a pattern holding a letter outside the closed set.
     *
     * @param mixed $pattern
     * @param string $key Named in the message
     * @return void
     */
    public static function validate_pattern($pattern, string $key): void
    {
        if (!is_string($pattern) || $pattern === '') {
            throw new \RuntimeException("{$key} must be a non-empty date pattern (e.g. 'M j, Y').");
        }

        self::__tokenize($pattern, $key);
    }

    // =========================================================================
    // THE THREE FORMATTERS
    // =========================================================================

    /**
     * The long form: date pattern + ' ' + time pattern, plus ' <ABBR>' when $zone differs
     * from $viewer_zone at that instant.
     */
    public static function timestamp(int $epoch, string $zone, string $viewer_zone): string
    {
        $patterns = self::display_patterns();

        return self::__long_part($epoch, $zone, $patterns) . self::__suffix($epoch, $zone, $viewer_zone);
    }

    /**
     * The dense form: "10-09 2:30p", "10-09-25 2:30p" in another year than the current one
     * (both judged in $zone), plus the suffix.
     */
    public static function timestamp_short(int $epoch, string $zone, string $viewer_zone, int $now_epoch): string
    {
        return self::__short_part($epoch, $zone, $now_epoch) . self::__suffix($epoch, $zone, $viewer_zone);
    }

    /**
     * A start and an end, in one zone: the date once when both fall on the same local day,
     * both sides in full otherwise; the suffix once, at the end, judged at the start.
     */
    public static function time_range(int $start, ?int $end, string $zone, string $viewer_zone, bool $short, int $now_epoch): string
    {
        if ($end === null) {
            return $short
                ? self::timestamp_short($start, $zone, $viewer_zone, $now_epoch)
                : self::timestamp($start, $zone, $viewer_zone);
        }

        $a = self::__parts($start, $zone);
        $b = self::__parts($end, $zone);
        $same_day = $a['Y'] === $b['Y'] && $a['n'] === $b['n'] && $a['j'] === $b['j'];

        if ($short) {
            if ($same_day) {
                $meridiem_shared = $a['a'] === $b['a'];
                $body = self::__render(self::__tokenize(self::__short_date_pattern($a, $zone, $now_epoch)), $a)
                    . ' ' . self::__short_clock($a, !$meridiem_shared) . self::DASH . self::__short_clock($b, true);
            } else {
                $body = self::__short_part($start, $zone, $now_epoch) . ' ' . self::DASH . ' ' . self::__short_part($end, $zone, $now_epoch);
            }
        } else {
            $patterns = self::display_patterns();

            if ($same_day) {
                $time_tokens = self::__tokenize($patterns['time']);
                $start_tokens = $a['a'] === $b['a'] ? self::__without_meridiem($time_tokens) : $time_tokens;
                $body = self::__render(self::__tokenize($patterns['date']), $a) . ' '
                    . trim(self::__render($start_tokens, $a)) . ' ' . self::DASH . ' ' . self::__render($time_tokens, $b);
            } else {
                $body = self::__long_part($start, $zone, $patterns) . ' ' . self::DASH . ' ' . self::__long_part($end, $zone, $patterns);
            }
        }

        return $body . self::__suffix($start, $zone, $viewer_zone);
    }

    /**
     * One pattern applied to an instant in a zone (format_date() / format_time()).
     */
    public static function pattern(int $epoch, string $zone, string $pattern): string
    {
        return self::__render(self::__tokenize($pattern), self::__parts($epoch, $zone));
    }

    // =========================================================================
    // ZONE ABBREVIATIONS
    // =========================================================================

    /**
     * The abbreviation $zone shows at an instant ("CDT", "IST", "UTC-3").
     */
    public static function zone_abbreviation(int $epoch, string $zone): string
    {
        return self::abbreviation_for_offset($zone, self::__offset($epoch, $zone));
    }

    /**
     * The abbreviation for one offset of one zone: the table's, else the offset form.
     */
    public static function abbreviation_for_offset(string $zone, int $offset): string
    {
        return self::__committed_table()[$zone][$offset] ?? self::__offset_name($offset);
    }

    /**
     * The committed table, read from the file the browser loads.
     *
     * @return array<string, array<int|string, string>>
     */
    private static function __committed_table(): array
    {
        if (self::$table === null) {
            $content = file_get_contents(self::abbreviation_table_js_path());
            $start = strpos($content, '= {');
            $end = strrpos($content, '};');

            if ($content === false || $start === false || $end === false) {
                shouldnt_happen('Rsx_Timestamp_Format: ' . self::abbreviation_table_js_path() . ' is not the generated table; run php artisan rsx:time:abbreviations');
            }

            self::$table = json_decode(substr($content, $start + 2, $end - $start - 1), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$table;
    }

    /**
     * Every zone's table, zone => [offset seconds => abbreviation], generated from this box's
     * tz database now - what rsx:time:abbreviations writes.
     *
     * @return array<string, array<int, string>>
     */
    public static function generate_abbreviation_table(): array
    {
        $table = [];

        foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $zone) {
            $map = self::__generate_zone_table($zone);
            if ($map !== []) {
                $table[$zone] = $map;
            }
        }

        return $table;
    }

    /** Where the generated JS table lives, beside the JS engine. */
    public static function abbreviation_table_js_path(): string
    {
        return __DIR__ . '/vendor/rsx_timezone_abbreviations.js';
    }

    /**
     * The generated table file's whole content: generate_abbreviation_table() as
     * window.Rsx_Timezone_Abbreviations, one zone per line. rsx:time:abbreviations writes it;
     * a framework test asserts the committed file still equals it.
     */
    public static function abbreviation_table_js(): string
    {
        $lines = [];

        foreach (self::generate_abbreviation_table() as $zone => $map) {
            $lines[] = '    ' . json_encode($zone, JSON_UNESCAPED_SLASHES) . ': ' . json_encode((object) $map, JSON_UNESCAPED_SLASHES);
        }

        return "/**\n"
            . " * GENERATED by php artisan rsx:time:abbreviations from PHP's tz database - do not edit.\n"
            . " *\n"
            . " * Per IANA zone: UTC offset (seconds) => the abbreviation Rsx_Timestamp_Format prints for it\n"
            . " * (Core/Time/Rsx_Timestamp_Format.php). Read by Rsx_Timestamp_Format in BOTH languages.\n"
            . " */\n"
            . "window.Rsx_Timezone_Abbreviations = {\n"
            . implode(",\n", $lines) . "\n"
            . "};\n";
    }

    /**
     * One zone's offset => abbreviation map: each offset its zone has used from 1970 to now,
     * named by the most recent transition carrying it.
     *
     * @return array<int, string>
     */
    private static function __generate_zone_table(string $zone): array
    {
        $map = [];

        // The backward-compatible list carries a few names PHP cannot construct
        // ('leapseconds'); such a name has no table, and prints in the offset form.
        $timezone = null;
        if (in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            try {
                $timezone = new \DateTimeZone($zone);
            } catch (\Exception $e) {
                $timezone = null;
            }
        }

        if ($timezone !== null) {
            // A zone with no transition data (getTransitions() answers false - a fixed
            // offset alias) has the one offset and name it shows at any instant.
            $transitions = $timezone->getTransitions(0, time());
            if ($transitions === false) {
                $epoch = (new \DateTimeImmutable('@0'))->setTimezone($timezone);
                $transitions = [['offset' => $epoch->getOffset(), 'abbr' => $epoch->format('T')]];
            }

            foreach ($transitions as $transition) {
                $map[(int) $transition['offset']] = self::__normalize_abbreviation((string) $transition['abbr'], (int) $transition['offset']);
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * A numeric tz database abbreviation ("-03", "+0530", "+05") as the offset form; a named
     * one as itself.
     */
    private static function __normalize_abbreviation(string $abbreviation, int $offset): string
    {
        return preg_match('/^[+-]\d{2,4}$/', $abbreviation) ? self::__offset_name($offset) : $abbreviation;
    }

    /**
     * "UTC", "UTC-3", "UTC+5:30".
     */
    private static function __offset_name(int $offset): string
    {
        if ($offset === 0) {
            return 'UTC';
        }

        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);
        $hours = intdiv($offset, 3600);
        $minutes = intdiv($offset % 3600, 60);

        return 'UTC' . $sign . $hours . ($minutes > 0 ? ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : '');
    }

    // =========================================================================
    // INTERNALS - mirrored in rsx_timestamp_format.js
    // =========================================================================

    /** ' <ABBR>' when $zone differs from $viewer_zone at the instant (offset or name), else ''. */
    private static function __suffix(int $epoch, string $zone, string $viewer_zone): string
    {
        if ($zone === $viewer_zone) {
            return '';
        }

        $offset = self::__offset($epoch, $zone);
        $abbreviation = self::abbreviation_for_offset($zone, $offset);
        $viewer_offset = self::__offset($epoch, $viewer_zone);

        if ($offset === $viewer_offset && $abbreviation === self::abbreviation_for_offset($viewer_zone, $viewer_offset)) {
            return '';
        }

        return ' ' . $abbreviation;
    }

    private static function __long_part(int $epoch, string $zone, array $patterns): string
    {
        $parts = self::__parts($epoch, $zone);

        return self::__render(self::__tokenize($patterns['date']), $parts) . ' ' . self::__render(self::__tokenize($patterns['time']), $parts);
    }

    private static function __short_part(int $epoch, string $zone, int $now_epoch): string
    {
        $parts = self::__parts($epoch, $zone);

        return self::__render(self::__tokenize(self::__short_date_pattern($parts, $zone, $now_epoch)), $parts) . ' ' . self::__short_clock($parts, true);
    }

    /** "m-d" this year, "m-d-y" another - the year judged in the printed zone. */
    private static function __short_date_pattern(array $parts, string $zone, int $now_epoch): string
    {
        return $parts['Y'] === self::__parts($now_epoch, $zone)['Y'] ? 'm-d' : 'm-d-y';
    }

    /** "2:30p", or "2:30" without the meridiem. */
    private static function __short_clock(array $parts, bool $with_meridiem): string
    {
        return $parts['g'] . ':' . $parts['i'] . ($with_meridiem ? $parts['a'][0] : '');
    }

    /** The wall-clock fields of an instant in a zone, as strings keyed by pattern letter. */
    private static function __parts(int $epoch, string $zone): array
    {
        $local = (new \DateTimeImmutable('@' . $epoch))->setTimezone(new \DateTimeZone($zone));
        [$year, $month, $day, $hour, $minute, $second, $weekday] = array_map('intval', explode(' ', $local->format('Y n j G i s w')));

        $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;
        $pad = fn (int $value) => str_pad((string) $value, 2, '0', STR_PAD_LEFT);

        return [
            'd' => $pad($day), 'j' => (string) $day,
            'm' => $pad($month), 'n' => (string) $month,
            'M' => substr(self::MONTHS[$month - 1], 0, 3), 'F' => self::MONTHS[$month - 1],
            'y' => $pad($year % 100), 'Y' => (string) $year,
            'D' => substr(self::DAYS[$weekday], 0, 3), 'l' => self::DAYS[$weekday],
            'g' => (string) $hour12, 'G' => (string) $hour,
            'h' => $pad($hour12), 'H' => $pad($hour),
            'i' => $pad($minute), 's' => $pad($second),
            'A' => $hour < 12 ? 'AM' : 'PM', 'a' => $hour < 12 ? 'am' : 'pm',
        ];
    }

    /** The zone's UTC offset at an instant, in seconds. */
    private static function __offset(int $epoch, string $zone): int
    {
        return (new \DateTimeZone($zone))->getOffset(new \DateTimeImmutable('@' . $epoch));
    }

    /**
     * A pattern as tokens: ['letter', 'x'] or ['literal', 'text'].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function __tokenize(string $pattern, string $key = 'A date pattern'): array
    {
        $tokens = [];
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $char = $pattern[$index];

            if ($char === '\\') {
                $index++;
                if ($index < $length) {
                    $tokens[] = ['literal', $pattern[$index]];
                }
                continue;
            }

            if (ctype_alpha($char)) {
                if (strpos(self::PATTERN_LETTERS, $char) === false) {
                    throw new \RuntimeException(
                        "{$key} uses the letter '{$char}' in '{$pattern}', which RSpade date patterns do not support. "
                        . 'Use only: ' . implode(' ', str_split(self::PATTERN_LETTERS)) . " (escape a literal letter with a backslash)."
                    );
                }
                $tokens[] = ['letter', $char];
                continue;
            }

            $tokens[] = ['literal', $char];
        }

        return $tokens;
    }

    /** The tokens with every meridiem letter, and the literal spaces right before it, removed. */
    private static function __without_meridiem(array $tokens): array
    {
        $kept = [];

        foreach ($tokens as $token) {
            if ($token[0] === 'letter' && ($token[1] === 'A' || $token[1] === 'a')) {
                while (!empty($kept) && $kept[count($kept) - 1] === ['literal', ' ']) {
                    array_pop($kept);
                }
                continue;
            }
            $kept[] = $token;
        }

        return $kept;
    }

    private static function __render(array $tokens, array $parts): string
    {
        $out = '';

        foreach ($tokens as [$kind, $value]) {
            $out .= $kind === 'letter' ? $parts[$value] : $value;
        }

        return $out;
    }
}
