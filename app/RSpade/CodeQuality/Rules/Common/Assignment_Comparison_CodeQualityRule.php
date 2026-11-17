<?php

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

/**
 * COMMON-ASSIGN-01: an assignment where a comparison was meant - `if ($x = 5)` for
 * `if ($x == 5)` - is valid code that runs, assigns, and takes the branch on the assigned
 * value, so nothing fails where the typo is. RSpade also refuses the deliberate form,
 * `if ($row = fetch())`: assignment and truthiness check go on separate lines. Judged in if,
 * elseif (PHP) and a for loop's condition clause; while is the accepted iteration idiom and
 * is never judged.
 *
 * Each condition is captured whole, to its matching parenthesis, from a copy of the line with
 * comments and string literals removed; `=>` (an array key, an arrow function) is never read
 * as an assignment.
 */
class Assignment_Comparison_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'COMMON-ASSIGN-01';
    }

    public function get_name(): string
    {
        return 'Assignment vs Comparison Check';
    }

    public function get_description(): string
    {
        return 'Detects assignment operator (=) used where comparison (== or ===) expected';
    }

    public function get_file_patterns(): array
    {
        return ['*.php', '*.js', '*.jsx', '*.ts', '*.tsx'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Check if file is in allowed directories using same logic as rsx:check command
     */
    private function is_file_in_allowed_directories(string $file_path): bool
    {
        // Get scan directories from config
        $scan_directories = config('rsx.manifest.scan_directories', []);
        $relative_path = str_replace(base_path() . '/', '', $file_path);

        // Special case: Allow Console Command files
        if (str_starts_with($relative_path, 'app/Console/Commands/')) {
            return true;
        }

        // Check against configured scan directories
        foreach ($scan_directories as $scan_path) {
            // Skip specific file entries in scan_directories
            if (str_contains($scan_path, '.')) {
                // This is a specific file, check exact match
                if ($relative_path === $scan_path) {
                    return true;
                }
            } else {
                // This is a directory, check if file is within it
                if (str_starts_with($relative_path, rtrim($scan_path, '/') . '/') ||
                    rtrim($relative_path, '/') === rtrim($scan_path, '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Blank the contents of every quoted string literal on a line to spaces, quotes kept, so
     * an = or a parenthesis inside a string (a regex pattern, a URL query string, an HTML
     * attribute) can never read as code. Length-preserving, so an offset into the result is
     * the same offset into the line. Escape-aware: \" \' and \\ inside a literal are
     * consumed as pairs, and JS template literals (backticks) are covered too.
     *
     * WHY THE STRIPPING HAPPENS ON THE WHOLE LINE, BEFORE the if/for/elseif condition is
     * captured - both halves of a live false positive (2026-09-04, a test asserting on
     * "charset=utf-8" inside a preg_match pattern):
     *   1. The old stripper used [^\\1], which is NOT a backreference inside a character
     *      class - it means "anything except a backslash or the digit 1" - so any string
     *      containing a backslash was left unstripped, = signs and all.
     *   2. The old order captured the condition with [^)]+ FIRST, so a ')' sitting INSIDE
     *      a string cut the condition in half, leaving an unbalanced quote that no
     *      stripper could ever pair up.
     * Stripping first removes both failure modes: parens and slashes inside strings are
     * gone before any structural pattern looks at the line.
     */
    private function strip_string_literals(string $line): string
    {
        return preg_replace_callback(
            '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"|`(?:\\\\.|[^`\\\\])*`/',
            static fn (array $match): string => $match[0][0] . str_repeat(' ', strlen($match[0]) - 2) . $match[0][0],
            $line
        );
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Use the same directory filtering logic as rsx:check command
        if (!$this->is_file_in_allowed_directories($file_path)) {
            return;
        }

        // Skip vendor and node_modules directories
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        // Skip CodeQuality directory
        if (str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        // Determine file type
        $is_php = str_ends_with($file_path, '.php');
        $is_js = str_ends_with($file_path, '.js') || str_ends_with($file_path, '.jsx') ||
                 str_ends_with($file_path, '.ts') || str_ends_with($file_path, '.tsx');

        if (!$is_php && !$is_js) {
            return;
        }

        // Comments are dead to this rule: blanked over the whole file, line-preserving, so a
        // condition quoted in a comment or docblock is never judged. $contents is the raw file
        // or the checker's sanitized copy; blanking is idempotent, so either reads the same.
        $code = $is_php
            ? FileSanitizer::sanitize_php($contents)['content']
            : FileSanitizer::blank_js_comments($contents);

        // The finding quotes the line as written; the analysed copy may have blanked part of it.
        $raw_lines = explode("\n", $this->source()->content($file_path));

        // Each condition is judged when it opens and closes on one line: a condition that
        // continues onto the next line is not judged (the rare case, let through for simplicity).
        foreach (explode("\n", $code) as $line_num => $code_line) {
            if (trim($code_line) === '') {
                continue;
            }

            $line_number = $line_num + 1;
            $raw_line = $raw_lines[$line_num] ?? '';
            $quoted = trim($raw_line) !== '' ? trim($raw_line) : trim($code_line);

            // String literals are dead to this rule from here on - see the stripper's
            // docblock for the two failure modes this ordering closes.
            $stripped = $this->strip_string_literals($code_line);

            foreach ($this->conditions_of($stripped, 'if') as [$start, $length]) {
                $target = $this->assignment_in(substr($stripped, $start, $length), substr($code_line, $start, $length));

                if ($target !== null) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Assignment operator (=) used in if statement where comparison expected (assigns {$target}).",
                        $quoted,
                        "Assignment and truthiness checks must be on separate lines. " .
                        "The pattern 'if (\$var = function())' is not acceptable in RSpade code. " .
                        "Split into two lines: '\$var = function(); if (\$var) { ... }'. " .
                        "If you meant comparison, use == or === instead of =. " .
                        "This rule enforces code clarity by separating assignment from condition evaluation.",
                        'high'
                    );
                }
            }

            // while and do...while are not judged - `while ($row = next_row())` is the
            // accepted iteration idiom. A for loop's condition is its middle clause.
            foreach ($this->conditions_of($stripped, 'for') as [$start, $length]) {
                $clauses = $this->split_top_level(substr($stripped, $start, $length), ';');

                if (count($clauses) !== 3) {
                    continue;
                }

                // Length-preserving stripping: the middle clause sits at the same offset in
                // the line as written.
                $middle = $start + $clauses[1][0];
                $target = $this->assignment_in(substr($stripped, $middle, strlen($clauses[1][1])), substr($code_line, $middle, strlen($clauses[1][1])));

                if ($target !== null) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Assignment operator (=) used in for loop condition where comparison expected (assigns {$target}).",
                        $quoted,
                        "Use == or === for comparison in the for loop condition (second part). " .
                        "Assignment in the condition will always evaluate to the assigned value, not perform a comparison. " .
                        "Example: change 'for(i=0; i=5; i++)' to 'for(i=0; i==5; i++)'.",
                        'high'
                    );
                }
            }

            // PHP-specific: elseif. JavaScript's `else if` is an if, judged above.
            if (!$is_php) {
                continue;
            }

            foreach ($this->conditions_of($stripped, 'elseif') as [$start, $length]) {
                $target = $this->assignment_in(substr($stripped, $start, $length), substr($code_line, $start, $length));

                if ($target !== null) {
                    $this->add_violation(
                        $file_path,
                        $line_number,
                        "Assignment operator (=) used in elseif statement where comparison expected (assigns {$target}).",
                        $quoted,
                        "Use == or === for comparison in elseif statements. " .
                        "Assignment in an elseif condition will execute the assignment and evaluate the assigned value, not perform a comparison. " .
                        "Example: change 'elseif(x = 5)' to 'elseif(x == 5)' or 'elseif(x === 5)'.",
                        'high'
                    );
                }
            }
        }
    }

    /**
     * Where the parenthesised text after each `<keyword> (` on a string-free line lies, as
     * [offset, length] pairs, captured to the MATCHING close parenthesis, so a call inside the condition (`if (count($a) > 1)`,
     * `if (array_filter([...]) === [])`) neither cuts the condition short nor hides what
     * follows it. A condition whose parentheses do not close on this line is not returned.
     * The keyword must stand alone: `$if (`, `->if(`, `.if(` and `elseif` are not `if`.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function conditions_of(string $line, string $keyword): array
    {
        if (!preg_match_all('/(?<![\w$.>])' . $keyword . '\s*\(/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $conditions = [];
        $length = strlen($line);

        foreach ($matches[0] as [$text, $offset]) {
            $start = $offset + strlen($text);
            $depth = 1;

            for ($i = $start; $i < $length; $i++) {
                if ($line[$i] === '(') {
                    $depth++;
                } elseif ($line[$i] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $conditions[] = [$start, $i - $start];
                        break;
                    }
                }
            }
        }

        return $conditions;
    }

    /**
     * $text split on $separator wherever it is outside every (), [] and {} - a for header's
     * three clauses, whatever calls or array literals they hold - as [offset, text] pairs.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    private function split_top_level(string $text, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $begin = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === $separator && $depth === 0) {
                $parts[] = [$begin, substr($text, $begin, $i - $begin)];
                $begin = $i + 1;
            }
        }

        $parts[] = [$begin, substr($text, $begin)];

        return $parts;
    }

    /**
     * What a condition assigns to, or null when it holds no assignment to judge. $condition
     * is the string-blanked text that is judged; $as_written is the same span of the line with
     * its strings intact, used only to name the target.
     *
     * An assignment is an `=` that is not part of a comparison (`==`, `===`, `!=`, `!==`,
     * `<=`, `>=`, `<=>`) and not an arrow: `=>` - a PHP array key or arrow function, a
     * JavaScript arrow function - is never an assignment. A compound assignment (`+=`, `.=`,
     * `??=`) is one. A condition that also holds a comparison is not judged: the assignment
     * is then an operand being compared (`if (($x = f()) !== null)`).
     *
     * The answer names the assignment's target as written (`$x`, `$row['id']`, `x`), so a
     * finding says which assignment it found.
     */
    private function assignment_in(string $condition, string $as_written): ?string
    {
        if (trim($condition) === '') {
            return null;
        }

        if (!preg_match('/(?<![=!<>])=(?![=>])/', $condition, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        if (preg_match('/==|!=|<=|>=/', $condition)) {
            return null;
        }

        $left = rtrim(rtrim(substr($as_written, 0, $match[0][1])), '+-*/%.&|^?');

        if (preg_match('/([\w$](?:[\w$]|->|::|\.|\[[^\]]*\])*)\s*$/', $left, $target)) {
            return $target[1];
        }

        return trim($left) !== '' ? trim($left) : 'a value';
    }
}
