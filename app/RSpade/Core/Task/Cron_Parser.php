<?php

namespace App\RSpade\Core\Task;

use DateTime;
use DateTimeZone;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Cron_Parser
 *
 * Parses cron expressions and calculates next run times.
 * Supports standard cron syntax: minute hour day month weekday
 *
 * Examples:
 *   Every minute: "* * * * *"
 *   Every hour at minute 0: "0 * * * *"
 *   Daily at midnight: "0 0 * * *"
 *   Daily at 3 AM: "0 3 * * *"
 *   Weekly on Sunday at midnight: "0 0 * * 0"
 *   Monthly on the 1st at midnight: "0 0 1 * *"
 *
 * Human-readable syntax is also accepted (normalized to cron internally). This
 * exists so #[Schedule] never has to contain the cron "step" token (an asterisk,
 * a slash, then N) -- that asterisk-slash pair is also the terminator of a
 * C-style block comment, so writing it in any docblock closes the comment early
 * and silently corrupts the file. Prefer the phrases below in code and comments:
 *   "every minute"                -> "* * * * *"
 *   "every 5 minutes"             -> "(every-5) * * * *"
 *   "every 6 hours"               -> "0 (every-6) * * *"
 *   "hourly"                      -> "0 * * * *"
 *   "daily" / "daily at 2am"      -> "0 0 * * *" / "0 2 * * *"
 *   "daily at 14:30"              -> "30 14 * * *"
 *   "weekly" / "weekly on monday at 9:30am" -> "0 0 * * 0" / "30 9 * * 1"
 *   "monthly"                     -> "0 0 1 * *"
 *
 * THE MAINTENANCE WINDOW. "maintenance" runs a task once a day at the top of the hour
 * rsx.tasks.maintenance_hour names (default 2, i.e. 2am), so every heavy housekeeping
 * task an install runs shares one off-peak slot that the operator moves in one place:
 *   "maintenance"                       -> "0 H * * *"
 *   "maintenance weekly on sunday"      -> "0 H * * 0"
 *   "maintenance monthly"               -> "0 H 1 * *"
 * A maintenance task also declares #[Exclusive('maintenance')], so the window's tasks
 * run one at a time (rsx:man tasks, THE MAINTENANCE WINDOW).
 *
 * TIMEZONE: the expression is read on the wall clock of the server timezone
 * (Rsx_Time::get_server_timezone(), RSX_SERVER_TIMEZONE, UTC when unset), so "daily at
 * 2am" with America/Chicago configured is 2am Chicago time. Run times are returned as
 * UNIX timestamps, so nothing downstream ever sees a local time.
 *
 * DAYLIGHT SAVING follows cron's rule for a FIXED-TIME job (neither the minute nor the
 * hour field begins with an asterisk):
 *   - a wall time skipped by a forward jump runs at the first instant after the jump
 *     (2:30am on the spring-forward day runs at 3:00am);
 *   - a wall time repeated by a backward jump runs once, at its first occurrence.
 * A job with an asterisk in the minute or hour field ("every 5 minutes", "hourly") runs
 * on real elapsed time and is unaffected by either transition.
 */
#[Instantiatable]
class Cron_Parser
{
    private array $parts;
    private string $expression;
    private DateTimeZone $timezone;

    /**
     * True when neither the minute nor the hour field begins with an asterisk - the jobs
     * the daylight-saving rule in the class docblock applies to.
     */
    private bool $fixed_time;

    /**
     * Parse a cron expression
     *
     * @param string $expression Cron expression (minute hour day month weekday)
     * @param string|null $timezone IANA zone whose wall clock the expression is read on;
     *                              null = the server timezone
     * @throws \InvalidArgumentException If expression is invalid
     */
    public function __construct(string $expression, ?string $timezone = null)
    {
        $this->expression = $expression;
        $this->timezone = new DateTimeZone($timezone ?? Rsx_Time::get_server_timezone());

        $cron = self::_normalize_expression($expression);
        $this->parts = $this->parse($cron);

        $fields = preg_split('/\s+/', trim($cron));
        $this->fixed_time = !str_starts_with($fields[0], '*') && !str_starts_with($fields[1], '*');
    }

    /**
     * Translate a human-readable schedule phrase into a standard cron expression.
     *
     * Standard cron passes through untouched. Recognized phrases are converted to
     * canonical cron so the rest of the parser has a single code path. Anything
     * unrecognized throws with the list of supported forms.
     *
     * @param string $expression Raw schedule expression (cron or phrase)
     * @return string Standard 5-field cron expression
     * @throws \InvalidArgumentException If a phrase is unrecognized or out of range
     */
    private static function _normalize_expression(string $expression): string
    {
        $raw = trim($expression);

        // Already standard cron? (only cron characters — digits, * / , - and spaces)
        if (preg_match('/^[\d*\/,\-\s]+$/', $raw)) {
            return $raw;
        }

        $text = strtolower($raw);

        // Named shortcuts
        $shortcuts = [
            'every minute' => '* * * * *',
            'hourly'       => '0 * * * *',
            'every hour'   => '0 * * * *',
            'daily'        => '0 0 * * *',
            'every day'    => '0 0 * * *',
            'nightly'      => '0 0 * * *',
            'weekly'       => '0 0 * * 0',
            'monthly'      => '0 0 1 * *',
            'yearly'       => '0 0 1 1 *',
            'annually'     => '0 0 1 1 *',
        ];
        if (isset($shortcuts[$text])) {
            return $shortcuts[$text];
        }

        // "maintenance" / "maintenance weekly on <day>" / "maintenance monthly"
        if (preg_match('/^maintenance(?:\s+(weekly\s+on\s+(sunday|monday|tuesday|wednesday|thursday|friday|saturday)|monthly))?$/', $text, $m)) {
            $hour = self::maintenance_hour();
            if (empty($m[1])) {
                return "0 {$hour} * * *";
            }
            if ($m[1] === 'monthly') {
                return "0 {$hour} 1 * *";
            }
            return "0 {$hour} * * " . self::_parse_weekday($m[2]);
        }

        // "every N minutes" / "every N hours"
        if (preg_match('/^every\s+(\d+)\s+(minutes?|mins?|hours?|hrs?)$/', $text, $m)) {
            $n = (int) $m[1];

            if (str_starts_with($m[2], 'min')) {
                if ($n < 1 || $n > 59) {
                    throw new \InvalidArgumentException("Invalid schedule '{$expression}': minute interval must be 1-59");
                }
                return "*/{$n} * * * *";
            }

            // hours
            if ($n < 1 || $n > 23) {
                throw new \InvalidArgumentException("Invalid schedule '{$expression}': hour interval must be 1-23");
            }
            return "0 */{$n} * * *";
        }

        // "daily at <time>" / "daily <time>" / "every day at <time>"
        if (preg_match('/^(?:daily|every day)\s+(?:at\s+)?(.+)$/', $text, $m)) {
            [$hour, $minute] = self::_parse_time($m[1], $expression);
            return "{$minute} {$hour} * * *";
        }

        // "weekly on <day> [at <time>]" / "every <day> [at <time>]"
        if (preg_match('/^(?:weekly\s+on|every)\s+(sunday|monday|tuesday|wednesday|thursday|friday|saturday)(?:\s+(?:at\s+)?(.+))?$/', $text, $m)) {
            $weekday = self::_parse_weekday($m[1]);
            [$hour, $minute] = empty($m[2]) ? [0, 0] : self::_parse_time($m[2], $expression);
            return "{$minute} {$hour} * * {$weekday}";
        }

        throw new \InvalidArgumentException(
            "Unrecognized schedule expression: '{$expression}'. Use standard cron " .
            "(e.g. '0 2 * * *') or a phrase like 'every 5 minutes', 'every 6 hours', " .
            "'hourly', 'daily at 2am', 'weekly on monday at 9:30am', 'monthly', 'maintenance', " .
            "'maintenance weekly on sunday', 'maintenance monthly'."
        );
    }

    /**
     * The hour the maintenance window opens: rsx.tasks.maintenance_hour, read on the
     * server timezone's wall clock like every other schedule.
     *
     * @return int 0-23
     * @throws \InvalidArgumentException When the setting is not a whole hour 0-23
     */
    public static function maintenance_hour(): int
    {
        $hour = config('rsx.tasks.maintenance_hour');

        if (!is_int($hour) || $hour < 0 || $hour > 23) {
            throw new \InvalidArgumentException(
                'rsx.tasks.maintenance_hour must be a whole hour 0-23 (the start of the maintenance window), got '
                . var_export($hour, true)
            );
        }

        return $hour;
    }

    /**
     * Parse a clock-time phrase into [hour, minute].
     *
     * Accepts "2am", "2pm", "2:30pm", "14:30", "09:00", "9", "noon", "midnight".
     *
     * @param string $time Time phrase
     * @param string $expression Original schedule (for error messages)
     * @return array{0:int,1:int} [hour (0-23), minute (0-59)]
     * @throws \InvalidArgumentException If the time cannot be parsed
     */
    private static function _parse_time(string $time, string $expression): array
    {
        $time = trim($time);

        if ($time === 'noon') {
            return [12, 0];
        }
        if ($time === 'midnight') {
            return [0, 0];
        }

        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/', $time, $m)) {
            $hour = (int) $m[1];
            $minute = ($m[2] ?? '') !== '' ? (int) $m[2] : 0;
            $meridiem = $m[3] ?? '';

            if ($meridiem !== '') {
                if ($hour < 1 || $hour > 12) {
                    throw new \InvalidArgumentException("Invalid time in schedule '{$expression}': '{$time}'");
                }
                if ($meridiem === 'am') {
                    $hour = ($hour === 12) ? 0 : $hour;
                } else {
                    $hour = ($hour === 12) ? 12 : $hour + 12;
                }
            }

            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                throw new \InvalidArgumentException("Invalid time in schedule '{$expression}': '{$time}'");
            }

            return [$hour, $minute];
        }

        throw new \InvalidArgumentException("Could not parse time in schedule '{$expression}': '{$time}'");
    }

    /**
     * Map a weekday name to its cron number (0 = Sunday .. 6 = Saturday).
     *
     * @param string $day Lowercase weekday name (guaranteed valid by caller regex)
     * @return int
     */
    private static function _parse_weekday(string $day): int
    {
        $map = [
            'sunday'    => 0,
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
        ];

        return $map[$day];
    }

    /**
     * Parse cron expression into components
     *
     * @param string $expression
     * @return array ['minute' => [...], 'hour' => [...], 'day' => [...], 'month' => [...], 'weekday' => [...]]
     * @throws \InvalidArgumentException
     */
    private function parse(string $expression): array
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Invalid cron expression: {$expression}. Expected 5 parts (minute hour day month weekday)");
        }

        return [
            'minute'  => $this->parse_field($parts[0], 0, 59),
            'hour'    => $this->parse_field($parts[1], 0, 23),
            'day'     => $this->parse_field($parts[2], 1, 31),
            'month'   => $this->parse_field($parts[3], 1, 12),
            'weekday' => $this->parse_field($parts[4], 0, 6),  // 0 = Sunday
        ];
    }

    /**
     * Parse a single cron field
     *
     * Supports: asterisk, numbers, step values, ranges, lists
     *
     * @param string $field Field value from cron expression
     * @param int $min Minimum allowed value
     * @param int $max Maximum allowed value
     * @return array Array of allowed values
     */
    private function parse_field(string $field, int $min, int $max): array
    {
        // Asterisk means all values
        if ($field === '*') {
            return range($min, $max);
        }

        // Step values (e.g., */15)
        if (preg_match('/^\*\/(\d+)$/', $field, $matches)) {
            $step = (int) $matches[1];
            return range($min, $max, $step);
        }

        // Range (e.g., 1-5)
        if (preg_match('/^(\d+)-(\d+)$/', $field, $matches)) {
            $start = (int) $matches[1];
            $end = (int) $matches[2];

            if ($start < $min || $end > $max || $start > $end) {
                throw new \InvalidArgumentException("Invalid range in cron expression: {$field}");
            }

            return range($start, $end);
        }

        // List (e.g., 1,3,5)
        if (str_contains($field, ',')) {
            $values = array_map('intval', explode(',', $field));

            foreach ($values as $value) {
                if ($value < $min || $value > $max) {
                    throw new \InvalidArgumentException("Invalid value in cron expression: {$value}");
                }
            }

            return $values;
        }

        // Single value
        $value = (int) $field;

        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("Invalid value in cron expression: {$value}");
        }

        return [$value];
    }

    /**
     * Calculate next run time from a given timestamp
     *
     * Walks forward one REAL minute at a time (never wall-clock arithmetic, which would
     * step over a repeated hour) and reads each minute on the timezone's wall clock.
     *
     * @param int|null $from_timestamp Start time (default: current time)
     * @return int Next run timestamp
     */
    public function get_next_run_time(?int $from_timestamp = null): int
    {
        if ($from_timestamp === null) {
            $from_timestamp = time();
        }

        // Start from the next whole minute (cron runs at most once per minute)
        $timestamp = (intdiv($from_timestamp, 60) + 1) * 60;

        $zoned = new DateTime('@' . $timestamp);
        $zoned->setTimezone($this->timezone);
        $wall = new DateTime('@0');

        $zoned->setTimestamp($timestamp - 60);
        $previous_wall = $timestamp - 60 + $zoned->getOffset();

        // Try up to 4 years in the future (should be more than enough)
        $max_iterations = 60 * 24 * 365 * 4;

        for ($iterations = 0; $iterations < $max_iterations; $iterations++, $timestamp += 60) {
            $zoned->setTimestamp($timestamp);
            $wall_seconds = $timestamp + $zoned->getOffset();

            if ($this->fixed_time) {
                // A forward jump: the wall minutes between the previous minute and this one
                // never happened, and one that matches runs now, right after the jump.
                for ($skipped = $previous_wall + 60; $skipped < $wall_seconds; $skipped += 60) {
                    if ($this->matches($wall->setTimestamp($skipped))) {
                        return $timestamp;
                    }
                }
            }
            $previous_wall = $wall_seconds;

            if (!$this->matches($wall->setTimestamp($wall_seconds))) {
                continue;
            }

            // A backward jump: this wall time already happened once, and a fixed-time job
            // ran then. PHP resolves an ambiguous wall time to its first occurrence.
            if ($this->fixed_time) {
                $first = new DateTime($wall->format('Y-m-d H:i:s'), $this->timezone);
                if ($first->getTimestamp() < $timestamp) {
                    continue;
                }
            }

            return $timestamp;
        }

        throw new \RuntimeException("Could not calculate next run time for cron expression: {$this->expression}");
    }

    /**
     * Check if a datetime matches the cron expression
     *
     * @param DateTime $datetime
     * @return bool
     */
    private function matches(DateTime $datetime): bool
    {
        $minute = (int) $datetime->format('i');
        $hour = (int) $datetime->format('H');
        $day = (int) $datetime->format('d');
        $month = (int) $datetime->format('n');
        $weekday = (int) $datetime->format('w');

        return in_array($minute, $this->parts['minute'])
            && in_array($hour, $this->parts['hour'])
            && in_array($day, $this->parts['day'])
            && in_array($month, $this->parts['month'])
            && in_array($weekday, $this->parts['weekday']);
    }

    /**
     * Validate a cron expression without creating an instance
     *
     * @param string $expression
     * @return bool
     */
    public static function is_valid(string $expression): bool
    {
        try {
            new self($expression);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * The IANA timezone the expression is read in.
     *
     * @return string
     */
    public function get_timezone(): string
    {
        return $this->timezone->getName();
    }

    /**
     * Get the original cron expression
     *
     * @return string
     */
    public function get_expression(): string
    {
        return $this->expression;
    }

    /**
     * The expression as standard 5-field cron: a phrase ("daily at 3am") translated, a
     * cron expression returned as written.
     *
     * @return string
     */
    public function get_cron_expression(): string
    {
        return self::_normalize_expression($this->expression);
    }
}
