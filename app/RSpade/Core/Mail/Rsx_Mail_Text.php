<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

/**
 * Rsx_Mail_Text - the plain-text part, derived from the HTML one.
 *
 * Every message goes out multipart/alternative, so every message needs a text part.
 * An email class may write one deliberately (a `<Class>_Text` blade); when it has not,
 * this converter derives it: the same words, with the structure the HTML
 * carried in tags carried in newlines instead, and every link's destination spelled
 * out so a text reader can still act on the message.
 *
 * It is deliberately small and dependency-free. It is not an HTML renderer and it is
 * not trying to be lynx - it handles the shapes an email template actually contains.
 */
class Rsx_Mail_Text
{
    /**
     * Block-level elements whose CLOSE ends a line of text.
     */
    private const BLOCK_ELEMENTS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr', 'td', 'th',
        'table', 'thead', 'tbody', 'ul', 'ol', 'blockquote', 'section', 'article',
        'header', 'footer', 'pre', 'address',
    ];

    /**
     * Turn a rendered HTML email into its plain-text equivalent.
     */
    public static function from_html(string $html): string
    {
        $text = $html;

        // Whole subtrees that carry no reading matter at all.
        $text = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $text);

        // A horizontal rule reads as one.
        $text = preg_replace('#<hr\b[^>]*/?>#i', "\n----------\n", $text);

        // Explicit line breaks.
        $text = preg_replace('#<br\b[^>]*/?>#i', "\n", $text);

        // List items get a bullet BEFORE their content; the close adds the newline.
        $text = preg_replace('#<li\b[^>]*>#i', "- ", $text);

        // An image contributes its alt text, or nothing.
        $text = preg_replace_callback('#<img\b[^>]*>#i', static function ($matches) {
            $alt = static::_attribute($matches[0], 'alt');

            return $alt !== null && trim($alt) !== '' ? trim($alt) : '';
        }, $text);

        // A link becomes "text (url)" - unless the text already IS the url, in which
        // case repeating it helps nobody.
        $text = preg_replace_callback('#<a\b[^>]*>(.*?)</a>#is', static function ($matches) {
            $href = static::_attribute($matches[0], 'href');
            $label = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $href = $href !== null ? trim($href) : '';

            // Nothing to spell out: no destination, or an in-document anchor that
            // means nothing outside the rendered page.
            if ($href === '' || str_starts_with($href, '#')) {
                return $label;
            }

            if ($label === '') {
                return $href;
            }

            if (strcasecmp($label, $href) === 0) {
                return $label;
            }

            return $label . ' (' . $href . ')';
        }, $text);

        // Block closes end a line.
        $block_pattern = '#</(' . implode('|', self::BLOCK_ELEMENTS) . ')\s*>#i';
        $text = preg_replace($block_pattern, "\n", $text);

        // Everything else is markup with no textual meaning.
        $text = strip_tags($text);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize line endings, then collapse the whitespace the markup left behind:
        // runs of spaces/tabs inside a line, trailing space, and any run of blank lines
        // down to one (a paragraph break survives; eight of them do not).
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/ *\n */', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Read one attribute out of a start tag, or null when it is not there.
     */
    private static function _attribute(string $tag, string $name): ?string
    {
        if (preg_match('#\b' . preg_quote($name, '#') . '\s*=\s*"([^"]*)"#i', $tag, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match("#\\b" . preg_quote($name, '#') . "\\s*=\\s*'([^']*)'#i", $tag, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match('#\b' . preg_quote($name, '#') . '\s*=\s*([^\s>]+)#i', $tag, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }
}
