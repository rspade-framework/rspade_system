<?php

namespace App\RSpade\Core\Files;

use HTMLPurifier;
use HTMLPurifier_Config;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Markdown_Rendition - markdown source to HTML a page may display.
 *
 * ONE CALL, `render()`, and it is the framework's only markdown-to-HTML path. A markdown
 * document is somebody else's file: it arrives through an upload, it is authored by whoever
 * wrote it, and nothing between here and the reader's browser is entitled to assume it is
 * friendly. So the conversion is not "run a parser" - it is a parse and a scrub, in that
 * order, and neither half is optional.
 *
 * TWO INDEPENDENT LAYERS, DELIBERATELY. They are not redundancy for its own sake: they fail
 * differently, which is the whole argument for having both.
 *
 *   LAYER 1 - THE PARSER, CONFIGURED CLOSED. `html_input => 'strip'` means a raw HTML block
 *   or inline tag in the source is DISCARDED rather than passed through, and
 *   `allow_unsafe_links => false` means the parser refuses to emit a `javascript:` or `data:`
 *   href of its own. A `max_nesting_level` bounds a pathological document (ten thousand
 *   nested blockquotes) at parse time. This layer is configuration: it is correct as long as
 *   the parser honours its own settings, and a regression in it is silent.
 *
 *   LAYER 2 - THE PURIFIER, AN EXPLICIT ALLOWLIST. HTMLPurifier re-parses the OUTPUT and
 *   keeps only the elements and attributes listed below - exactly the set GFM produces, and
 *   nothing else. This layer does not care what produced the markup or why. It is what stands
 *   if the parser is ever misconfigured, upgraded into different behaviour, or handed an input
 *   its own escaping mishandles; and it is an allowlist, so a new element that appears from
 *   anywhere is dropped by DEFAULT rather than by having been anticipated.
 *
 * The layers are stacked in the same shape Spreadsheet_Rendition uses for a workbook, for the
 * same reason and with the same purifier cache directory. The difference is the allowlist:
 * a spreadsheet needs `style` and `data:` to keep its fills and pictures, and a markdown
 * document needs neither - so this one carries no `style` attribute at all, and the surviving
 * presentation is the component's stylesheet.
 *
 * WHAT IS NOT WRAPPED. `render()` returns a FRAGMENT: no container element, no document
 * shell. The consumer's own element is the container - `Markdown_Viewer__body` for the
 * preview - and wrapping here would give every consumer an element it did not ask for.
 */
class Markdown_Rendition
{
    /**
     * Parser nesting cap. A markdown document nests through blockquotes and lists, and a
     * hand-built one can nest thousands deep to make the parser recurse; GFM's own reference
     * implementation caps at this depth, and no document a person wrote comes near it.
     */
    public const MAX_NESTING_LEVEL = 100;

    /**
     * Convert markdown to sanitised HTML.
     *
     * @param string $markdown The document source.
     * @return string An HTML fragment, safe to interpolate unescaped.
     */
    public static function render(string $markdown): string
    {
        $environment = new Environment([
            // A raw <script>, <iframe> or <img onerror> written into the source is DISCARDED,
            // not escaped and not passed through. Markdown's HTML passthrough is a feature for
            // documents you wrote; this renders documents other people uploaded.
            'html_input' => 'strip',

            // The parser never emits a javascript:/vbscript:/data: href or src of its own -
            // an autolink or a [text](javascript:...) link is written out inert.
            'allow_unsafe_links' => false,

            'max_nesting_level' => static::MAX_NESTING_LEVEL,
        ]);

        // Core CommonMark, plus GitHub's additions - tables, strikethrough, task lists and
        // autolinks are what a reader expects a .md file to look like, and a document written
        // against GitHub renders as its author saw it.
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $html = (string) (new MarkdownConverter($environment))->convert($markdown);

        return static::__purify($html);
    }

    /**
     * Keep exactly what GFM produces and drop everything else.
     *
     * sanitize_rich_text_html() is deliberately not reused: its allowlist is written for user-entered rich
     * text and carries neither the table elements nor the task-list checkbox, so a markdown
     * table would arrive as a run of unformatted words.
     *
     * @param string $html
     * @return string
     */
    protected static function __purify(string $html): string
    {
        require_once base_path('vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php');

        $config = HTMLPurifier_Config::createDefault();

        $cache_dir = Rsx_Project_Paths::htmlpurifier_dir();
        ensure_directory($cache_dir);
        $config->set('Cache.SerializerPath', $cache_dir);
        $config->set('Cache.SerializerPermissions', null);

        // The GFM element set, and nothing else. No script, no style attribute, no form, no
        // iframe, no object, no id (a fragment rendered into a page must not be able to
        // collide with the host's own ids).
        $config->set('HTML.Allowed', implode(',', [
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'p', 'br', 'hr',
            'em', 'strong', 'del', 'code', 'pre',
            'blockquote',
            'ul', 'ol[start]', 'li',
            // The task-list checkbox. The renderer emits it already `disabled`, and the
            // attributes are allowed so it renders as the checked/unchecked box the author
            // wrote rather than disappearing.
            'input[type|checked|disabled]',
            'a[href|title]',
            'img[src|alt|title]',
            'table', 'thead', 'tbody', 'tr',
            'th[align]', 'td[align]',
        ]));

        // Links: the three schemes a document legitimately points at. No data:, which would
        // let a link carry its own payload, and no javascript: (the parser already refuses
        // one - this is the layer that does not depend on the parser being right).
        //
        // HTMLPurifier's scheme allowlist is document-wide, not per element, so mailto is
        // reachable from an img src as well as from an href. That is a BROKEN IMAGE and
        // nothing else - a mailto URI fetches no bytes and grants no capability - and the two
        // schemes that would matter for an image, data: and javascript:, are refused here for
        // every element alike.
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
        ]);

        // HTMLPurifier SUPPORTS NO FORM ELEMENT in any shipped doctype, so a GFM task list -
        // which is a disabled <input type="checkbox"> per item - would be stripped to a bare
        // bullet, with a PHP warning per attribute. Teaching the definition about exactly this
        // one element is the narrow fix: Empty content model, NO attribute collection (so it
        // gains no class/id/style through the back door), and the three attributes a task-list
        // checkbox carries. `type` is an Enum of one value, so nothing else can ride in on it.
        $config->set('HTML.DefinitionID', 'rsx-markdown-rendition');
        $config->set('HTML.DefinitionRev', 1);

        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition !== null) {
            $definition->addElement('input', 'Inline', 'Empty', '', [
                'type' => 'Enum#checkbox',
                'checked' => 'Bool#checked',
                'disabled' => 'Bool#disabled',
            ]);
        }

        return (new HTMLPurifier($config))->purify($html);
    }
}
