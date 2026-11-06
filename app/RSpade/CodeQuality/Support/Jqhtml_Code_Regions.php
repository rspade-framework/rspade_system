<?php

namespace App\RSpade\CodeQuality\Support;

use App\RSpade\CodeQuality\Support\FileSanitizer;

/**
 * The JavaScript inside a .jqhtml template, at its own byte offsets.
 *
 * A template is markup with JavaScript in four places: `<% %>` statements, `<%= %>` and
 * `<%!= %>` expressions, and the values of `@event=` and `$arg=` attributes. A rule that
 * judges JavaScript in a template (JS-NAVIGATE-01, JS-NEW-TAB-01) asks for a MASK: a copy of
 * the template with every byte that is not one of those blanked to a space and every newline
 * kept, so an offset into the mask is an offset into the file and a line number is the
 * template's own. Template comments (`<%-- --%>`, `<!-- -->`) are blanked first, and then
 * JavaScript comments inside the code.
 *
 * It does not parse the JavaScript - a template's code is fragments (`<% if (x) { %>`) that
 * only become a program once the template compiler stitches them together - so a rule reads
 * the mask with patterns, and sees each region separately when it needs "the same block".
 *
 * Attribute values: a parenthesised value is taken to its balancing `)`, a bare value to the
 * next whitespace or tag end; a QUOTED value counts only for an `@event=` attribute, because
 * `$arg="text"` passes a string, not code. A value that holds `<%` is left to the block pass.
 */
class Jqhtml_Code_Regions
{
    /**
     * @return array{mask: string, regions: array<int,array{0:int,1:int}>} The mask, and each
     *         code region as [start offset, end offset) into it.
     */
    public static function extract(string $template): array
    {
        $source = FileSanitizer::blank_template_comments($template);
        $length = strlen($source);
        $regions = [];

        if (preg_match_all('/<%(?!--)(?:!?=)?(.*?)%>/s', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as [$code, $offset]) {
                $regions[] = [$offset, $offset + strlen($code)];
            }
        }

        if (preg_match_all('/(?<=\s)([@$])[A-Za-z_][\w.:-]*=/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => [$attribute, $offset]) {
                $region = static::__attribute_value($source, $offset + strlen($attribute), $matches[1][$index][0] === '@');

                if ($region !== null && !str_contains(substr($source, $region[0], $region[1] - $region[0]), '<%')) {
                    $regions[] = $region;
                }
            }
        }

        usort($regions, fn ($a, $b) => $a[0] <=> $b[0]);

        $mask = preg_replace('/[^\n]/', ' ', $source);

        foreach ($regions as [$start, $end]) {
            $mask = substr_replace($mask, substr($source, $start, $end - $start), $start, $end - $start);
        }

        return [
            'mask' => strlen($mask) === $length ? FileSanitizer::blank_js_comments($mask) : $mask,
            'regions' => $regions,
        ];
    }

    /**
     * The 1-based line of a byte offset.
     */
    public static function line_of(string $text, int $offset): int
    {
        return substr_count($text, "\n", 0, $offset) + 1;
    }

    /**
     * The [start, end) of an attribute value beginning at $start, or null when there is none.
     */
    private static function __attribute_value(string $source, int $start, bool $is_event): ?array
    {
        $length = strlen($source);

        if ($start >= $length) {
            return null;
        }

        $first = $source[$start];

        if ($first === '"' || $first === "'") {
            if (!$is_event) {
                return null;
            }

            $close = strpos($source, $first, $start + 1);

            return $close === false ? null : [$start + 1, $close];
        }

        if ($first === '(') {
            $depth = 0;
            $quote = null;

            for ($i = $start; $i < $length; $i++) {
                $char = $source[$i];

                if ($quote !== null) {
                    if ($char === '\\') {
                        $i++;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($char === '"' || $char === "'" || $char === '`') {
                    $quote = $char;
                } elseif ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;

                    if ($depth === 0) {
                        return [$start, $i + 1];
                    }
                }
            }

            return null;
        }

        if (!preg_match('/\G[^\s>]+/', $source, $match, 0, $start)) {
            return null;
        }

        $value = rtrim($match[0], '/');

        return $value === '' ? null : [$start, $start + strlen($value)];
    }
}
