<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\CodeQuality\Rules\Blade;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

/**
 * EMAIL-TEMPLATE-01 - an email template is not a web page, and two habits that are
 * harmless in a browser are broken in an inbox.
 *
 * AN EMAIL HAS NO BROWSER. Nothing in a mail client re-formats a datetime for the
 * reader's timezone, and nothing runs afterwards to fix one that went out wrong: the
 * message is a frozen artifact the moment the transport takes it. So a raw
 * `{{ $created_at }}` mails a database string - a UTC ISO stamp, in the sender's
 * notion of time - to somebody who will read it as a local wall clock. Every datetime
 * an email prints is formatted SERVER-SIDE, in the recipient's zone, before it is
 * rendered.
 *
 * AN EMAIL HAS NO ORIGIN. A page can link to "/clients/4" because the browser knows
 * what host it came from. A mail client does not: a root-relative href in a message is
 * a link to nowhere, and it fails silently - the recipient sees a link, clicks it, and
 * lands on their mail provider's error page. Route() produces a PATH; only
 * rsx_absolute_url() turns it into something a recipient can follow.
 *
 * SCOPE IS THE EMAIL DIRECTORIES ONLY. Both rules are WRONG for an ordinary page: a
 * page's datetimes are localized in the browser, and a page's links must stay relative
 * so they work behind any host or prefix. Applying either one outside an email
 * template would be actively harmful, so the rule refuses to look.
 *
 * WHY IT IS HIGH: both failures are invisible to the sender. Nothing throws, no build
 * breaks, the queue row says SENT - the defect exists only in a message that has
 * already left, in somebody else's inbox. This began life as a grep in the prelaunch
 * checklist, which is the strongest possible evidence that it needed to be a rule.
 */
class EmailTemplate_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Path segments that make a blade an EMAIL template.
     *
     * `emails/` is the application's directory (rsx/emails/, and any module-local one an
     * app writes). `Core/Mail/` is the framework's own - Rsx_Mail_Test_Email lives beside
     * the class that sends it, not in an emails/ directory, and it is held to the same
     * contract as anything an application ships.
     */
    protected const EMAIL_PATH_SEGMENTS = [
        '/emails/',
        '/Core/Mail/',
    ];

    /**
     * A column name ending in one of these NAMES A MOMENT, and a moment printed raw is
     * the defect. Deliberately suffix-based rather than a type lookup: the template has
     * no schema in front of it, and `_at` / `_date` is the framework's own naming
     * convention for exactly these columns (see rsx:man database_schema_architecture).
     */
    protected const DATETIME_SUFFIXES = ['_at', '_date'];

    /**
     * The formatters that make a datetime safe to print. Either class, any method:
     * format_datetime, format_date, relative, format - the rule cares that the value
     * went THROUGH one, not which one.
     */
    protected const DATETIME_FORMATTERS = ['Rsx_Time::', 'Rsx_Date::'];

    public function get_id(): string
    {
        return 'EMAIL-TEMPLATE-01';
    }

    public function get_name(): string
    {
        return 'Email Template Correctness';
    }

    public function get_description(): string
    {
        return 'An email template formats every datetime server-side and makes every Route() URL absolute';
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function get_file_patterns(): array
    {
        return ['*.blade.php'];
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (!static::_is_email_template($file_path)) {
            return;
        }

        // Work from the ORIGINAL bytes where they are readable: the checker may hand a
        // sanitized copy, and this rule's subject is the interpolation expressions.
        $original = is_readable($file_path) ? file_get_contents($file_path) : $contents;

        // Blade comments are documentation, not markup. A footer example showing
        // `{{ $sent_at }}` is not a message anybody receives.
        $scannable = FileSanitizer::blank_template_comments($original);

        $original_lines = explode("\n", $original);
        $lines = explode("\n", $scannable);

        foreach ($lines as $index => $line) {
            $line_number = $index + 1;
            $source_line = trim($original_lines[$index] ?? $line);

            foreach ($this->_unformatted_datetimes($line) as $expression) {
                // The marker lives INSIDE a blade comment, which the sanitizer blanked -
                // so the original bytes are where it is still readable. Both are checked
                // so a marker written outside a comment works too.
                if (static::_line_is_excepted($lines, $index)
                    || static::_line_is_excepted($original_lines, $index)) {
                    continue;
                }

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Raw datetime in an email template: {{ {$expression} }}",
                    $source_line,
                    $this->_datetime_remediation($expression),
                    'high'
                );
            }

            foreach ($this->_relative_route_calls($line) as $call) {
                if (static::_line_is_excepted($lines, $index)
                    || static::_line_is_excepted($original_lines, $index)) {
                    continue;
                }

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Route() URL in an email template is not absolute: {$call}",
                    $source_line,
                    $this->_absolute_url_remediation($call),
                    'high'
                );
            }
        }
    }

    // =====================================================================
    // Scope
    // =====================================================================

    /**
     * Is this blade an email template?
     *
     * PATH-BASED, deliberately: co-location is the convention (an email class lives
     * beside the blade that renders it), and the alternative - resolving the blade's
     * @rsx_id back to an Rsx_Email_Abstract subclass - would make a lint rule depend on
     * a built manifest.
     */
    protected static function _is_email_template(string $file_path): bool
    {
        $normalized = '/' . ltrim(str_replace('\\', '/', $file_path), '/');

        if (str_contains($normalized, '/vendor/')
            || str_contains($normalized, '/node_modules/')
            || str_contains($normalized, '/CodeQuality/')) {
            return false;
        }

        foreach (static::EMAIL_PATH_SEGMENTS as $segment) {
            if (str_contains($normalized, $segment)) {
                return true;
            }
        }

        return false;
    }

    // =====================================================================
    // (a) Datetimes
    // =====================================================================

    /**
     * Every interpolated expression on this line that prints a datetime raw.
     *
     * @return array<int,string>
     */
    protected function _unformatted_datetimes(string $line): array
    {
        $found = [];

        foreach (static::_interpolations($line) as $expression) {
            if (static::_goes_through_a_formatter($expression)) {
                continue;
            }

            if (static::_names_a_datetime($expression)) {
                $found[] = $expression;
            }
        }

        return $found;
    }

    /**
     * Every `{{ ... }}` and `{!! ... !!}` expression on one line, unwrapped and trimmed.
     *
     * @return array<int,string>
     */
    protected static function _interpolations(string $line): array
    {
        $expressions = [];

        // The escaped form is matched first and the raw form second; `{!!` cannot be
        // confused with `{{` because the openers differ in their second character.
        foreach (['/\{!!(.*?)!!\}/s', '/\{\{(.*?)\}\}/s'] as $pattern) {
            if (preg_match_all($pattern, $line, $matches)) {
                foreach ($matches[1] as $expression) {
                    $expressions[] = trim($expression);
                }
            }
        }

        return $expressions;
    }

    protected static function _goes_through_a_formatter(string $expression): bool
    {
        foreach (static::DATETIME_FORMATTERS as $formatter) {
            if (str_contains($expression, $formatter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the expression a BARE read of something whose name ends in _at or _date?
     *
     * Bare is the operative word. `$row->due_date` and `$data['sent_at']` are reads of a
     * stored value and are flagged; anything that calls a function, does arithmetic or
     * concatenates has left this rule's reach, and guessing at it would produce the
     * false positives that train people to ignore the checker.
     *
     * A `?? <default>` tail is stripped first: `{{ $expires_at ?? '' }}` is the same
     * unformatted read with a blank guard in front of it.
     */
    protected static function _names_a_datetime(string $expression): bool
    {
        $expression = trim(preg_replace('/\?\?.*$/s', '', $expression));

        // $var, then any run of ->property and ['key'] / ["key"] accesses.
        $accessor = '/^\$[A-Za-z_][A-Za-z0-9_]*'
            . '((->[A-Za-z_][A-Za-z0-9_]*)|(\[\s*[\'"][^\'"]*[\'"]\s*\]))*$/';

        if (!preg_match($accessor, $expression)) {
            return false;
        }

        $name = static::_last_accessed_name($expression);

        foreach (static::DATETIME_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The final name in an accessor chain: `$row->meta['due_date']` -> `due_date`.
     */
    protected static function _last_accessed_name(string $expression): string
    {
        $pattern = '/(?:->([A-Za-z_][A-Za-z0-9_]*))|(?:\[\s*[\'"]([^\'"]*)[\'"]\s*\])/';

        if (preg_match_all($pattern, $expression, $matches, PREG_SET_ORDER)) {
            $last = end($matches);

            // PREG_SET_ORDER does not pad a trailing unmatched group, so the bracket
            // capture is simply absent when the last access was an arrow.
            $bracket = $last[2] ?? '';

            return $bracket !== '' ? $bracket : ($last[1] ?? '');
        }

        return ltrim($expression, '$');
    }

    // =====================================================================
    // (b) Absolute URLs
    // =====================================================================

    /**
     * Every Route() call on this line that is not wrapped in rsx_absolute_url().
     *
     * "Wrapped" is decided by what sits immediately to the LEFT of the call, whitespace
     * ignored. That is the only shape the correct form takes -
     * `rsx_absolute_url(Rsx::Route(...))` - and reading a single token backwards can
     * never mistake a NEIGHBOURING absolute URL on the same line for this one's wrapper.
     *
     * @return array<int,string>
     */
    protected function _relative_route_calls(string $line): array
    {
        if (!preg_match_all('/\b(Rsx|Rsx_Portal)::Route\s*\(/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $found = [];

        foreach ($matches[0] as $match) {
            [$call, $offset] = $match;

            $preceding = rtrim(substr($line, 0, $offset));

            if (str_ends_with($preceding, 'rsx_absolute_url(')) {
                continue;
            }

            $found[] = rtrim($call, " \t(") . '(...)';
        }

        return $found;
    }

    // =====================================================================
    // The exception marker
    // =====================================================================

    /**
     * Does the line, or the line above it, carry the marker WITH a rationale?
     *
     * A bare marker suppresses nothing: an exception that does not say why is the thing
     * this rule exists to prevent, written one line higher.
     */
    protected static function _line_is_excepted(array $lines, int $index): bool
    {
        $marker = '@EMAIL-TEMPLATE-01' . '-EXCEPTION';

        foreach ([$index, $index - 1] as $candidate) {
            if ($candidate < 0 || !isset($lines[$candidate])) {
                continue;
            }

            $position = strpos($lines[$candidate], $marker);

            if ($position === false) {
                continue;
            }

            $rationale = substr($lines[$candidate], $position + strlen($marker));

            // Whatever closes the comment the marker sits in is not a rationale.
            $rationale = preg_replace('/(--%>|--\}\}|-->|\*\/|\?>|%>).*$/s', '', $rationale);
            $rationale = trim((string) $rationale, " \t-:*");

            if (preg_match('/[A-Za-z]{3}/', $rationale)) {
                return true;
            }
        }

        return false;
    }

    // =====================================================================
    // Remediation
    // =====================================================================

    protected function _datetime_remediation(string $expression): string
    {
        $lines = [];
        $lines[] = 'An email has no browser to localize this. Nothing in a mail client re-formats a';
        $lines[] = 'datetime for the reader, and nothing runs later to correct one that went out wrong -';
        $lines[] = 'the message is frozen the moment the transport takes it. Printed raw, this is a';
        $lines[] = 'database string in the SENDER\'s notion of time, read as a local wall clock by';
        $lines[] = 'somebody in another one.';
        $lines[] = '';
        $lines[] = 'Format it SERVER-SIDE, in the recipient\'s timezone, before it is rendered:';
        $lines[] = '    {{ Rsx_Time::format_datetime(' . $expression . ') }}      a moment';
        $lines[] = '    {{ Rsx_Date::format(' . $expression . ') }}               a calendar date';
        $lines[] = '';
        $lines[] = 'Better still, format it in the email class\'s data() and hand the template a string -';
        $lines[] = 'the values are frozen into the queue row there, so the message says the same thing';
        $lines[] = 'whether it is sent now or after a retry tomorrow.';
        $lines[] = '';
        $lines[] = 'A value ending in _at or _date that is NOT a datetime declares itself, on the line or';
        $lines[] = 'the line above, and says so:';
        $lines[] = '    {{-- @EMAIL-TEMPLATE-01' . '-EXCEPTION - <why this is not a datetime> --}}';
        $lines[] = '';
        $lines[] = 'Formatters, timezone resolution and the two classes: rsx:man time';

        return implode("\n", $lines);
    }

    protected function _absolute_url_remediation(string $call): string
    {
        $lines = [];
        $lines[] = 'A mail client has no origin. Route() produces a PATH, and a root-relative link in a';
        $lines[] = 'message points at nothing a recipient can reach - it fails silently, in somebody';
        $lines[] = 'else\'s inbox, with the queue row still saying SENT.';
        $lines[] = '';
        $lines[] = 'Wrap it so the message carries a whole URL:';
        $lines[] = '    {{ rsx_absolute_url(' . rtrim($call, '.)') . '...)) }}';
        $lines[] = '';
        $lines[] = 'rsx_absolute_url() takes the scheme, host and non-default port from APP_URL (the';
        $lines[] = 'single hostname source) when there is no request to read them from - which is';
        $lines[] = 'always, because email is rendered in a background task.';
        $lines[] = '';
        $lines[] = 'A URL that is deliberately relative declares itself, on the line or the line above,';
        $lines[] = 'and says why:';
        $lines[] = '    {{-- @EMAIL-TEMPLATE-01' . '-EXCEPTION - <why a relative URL is correct here> --}}';
        $lines[] = '';
        $lines[] = 'Route spellings and parameters: rsx:man routing. Email rendering: rsx:man email';

        return implode("\n", $lines);
    }
}
