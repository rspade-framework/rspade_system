<?php

namespace App\RSpade\CodeQuality\Rules\Scss;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

/**
 * SCSS-PHANTOM-TOKEN-01: a custom-property read with a LITERAL default,
 * `var(--token, #2563eb)`.
 *
 * The literal makes an undefined token look fine. When --token is not declared anywhere the
 * browser quietly paints the literal, so the surface stops following the theme, dark mode
 * never reaches it, and nothing anywhere reports that the token does not exist. A downstream
 * field report found dozens of these, several naming tokens that had never been declared, and
 * one composing `hsl(var(--primary-hsl) / 0.1)` from a missing token - an invalid declaration
 * that painted nothing at all for months with no error.
 *
 * A token exists or it does not; the default is the hedge that hides which. So:
 *
 *   - FLAGGED: any default that is not itself a var() read - a colour, a length, a number, a
 *     keyword, a Sass variable or interpolation (each compiles to a literal), or an empty
 *     default `var(--x,)`.
 *   - NOT FLAGGED: a chain `var(--a, var(--b))`. Each var() is judged on its own, so a chain
 *     that ENDS in a literal - `var(--a, var(--b, #fff))` - is flagged at the inner read.
 *
 * Scope: every .scss file the checker sees, the framework's own included, except vendored
 * trees and minified files. A value that needs a default is DECLARED once as a custom property
 * (on :root, beside the rule that reads it) and read bare - a magic number becomes a named,
 * reusable constant. Core's own hooks do exactly that: Rsx_Form declares --rsx-form-veil and
 * Button_Utils its loader knobs on :root, and an application's theme redeclares them.
 *
 * Exception: `@SCSS-PHANTOM-TOKEN-01-EXCEPTION <rationale>` in a comment, honored by the
 * driver for the whole file.
 */
class ScssPhantomToken_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'SCSS-PHANTOM-TOKEN-01';
    }

    public function get_name(): string
    {
        return 'No Literal Default on a Custom Property';
    }

    public function get_description(): string
    {
        return 'Prohibits var(--token, <literal>): the literal hides an undefined token, so the surface silently stops following the theme. '
            . 'A var(--a, var(--b)) chain is allowed.';
    }

    public function get_file_patterns(): array
    {
        return ['*.scss'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (str_contains($file_path, '/vendor/') || str_contains($file_path, '/node_modules/')) {
            return;
        }

        if (str_ends_with($file_path, '.min.scss')) {
            return;
        }

        // rsx:check hands the comment-blanked copy already; the manifest pass and a unit test
        // hand raw bytes. Blanking is idempotent and keeps every offset, so line numbers hold.
        $text = FileSanitizer::blank_scss_comments($contents);
        $lines = explode("\n", $text);

        foreach (static::literal_defaults($text) as $hit) {
            $this->add_violation(
                $file_path,
                $hit['line'],
                "var({$hit['token']}) carries the literal default '{$hit['default']}'. "
                    . "If {$hit['token']} is not declared, the literal is painted silently and the theme never reaches this surface.",
                trim($lines[$hit['line'] - 1] ?? ''),
                static::__remediation($hit['token']),
                'high'
            );
        }
    }

    /**
     * Every var() read in $text whose default is not itself a var() read.
     *
     * $text must already have its comments blanked. Each default runs to the `)` that closes
     * its var(, at paren depth zero and outside a quoted string, so a default containing
     * `rgba(0, 0, 0, .5)` or a quoted font name is read whole.
     *
     * @return array<int,array{line:int,token:string,default:string}>
     */
    public static function literal_defaults(string $text): array
    {
        $hits = [];

        if (!preg_match_all('/(?<![A-Za-z0-9_-])var\(\s*(--[A-Za-z0-9_-]+)\s*,/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $hits;
        }

        $length = strlen($text);

        foreach ($matches[0] as $index => [$matched, $offset]) {
            $start = $offset + strlen($matched);
            $depth = 0;
            $quote = null;
            $end = $length;

            for ($i = $start; $i < $length; $i++) {
                $char = $text[$i];

                if ($quote !== null) {
                    if ($char === '\\') {
                        $i++;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }

                    continue;
                }

                if ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    if ($depth === 0) {
                        $end = $i;

                        break;
                    }

                    $depth--;
                }
            }

            $default = trim(preg_replace('/\s+/', ' ', substr($text, $start, $end - $start)));

            if (preg_match('/^var\(/i', $default)) {
                continue;
            }

            $hits[] = [
                'line' => substr_count($text, "\n", 0, $offset) + 1,
                'token' => $matches[1][$index][0],
                'default' => $default,
            ];
        }

        return $hits;
    }

    private static function __remediation(string $token): string
    {
        return "Remove the literal and read the token alone: var({$token}).\n\n"
            . "WHY: a token either exists or it does not. When it exists, the literal is dead code. When it does not -\n"
            . "a typo, a token renamed or never declared - the literal is painted instead, the element looks right in\n"
            . "the theme it was written against, ignores every other theme and dark mode, and nothing reports it.\n"
            . "Without the literal, an undeclared token shows up at once as a missing colour or size.\n\n"
            . "IF THE TOKEN IS MISSING: declare it where the theme declares its tokens (rsx/theme/), with its\n"
            . "dark-mode value beside it, or use a token that already exists.\n\n"
            . "A CHAIN IS FINE: var({$token}, var(--other-token)) reads a second token, never a literal.\n\n"
            . "A deliberate literal default (a component knob that is intentionally optional) is granted with\n"
            . '`// @SCSS-PHANTOM-TOKEN-01-EXCEPTION <why the token may legitimately be absent>` in the file - '
            . 'ask the project owner before adding one.';
    }
}
