<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\Markdown_Rendition;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A markdown attachment previews as the DOCUMENT its author wrote, and the conversion is
 * closed against the document that is trying to do something else.
 *
 * Three properties, each independent:
 *
 *   1. Markdown_Rendition::render() emits the elements GFM promises - headings, tables, task
 *      lists, strikethrough, fenced code, autolinks - and emits NOTHING executable: raw HTML
 *      in the source is discarded, and a javascript: link is written out inert. Two layers
 *      stand behind that (a parser configured closed, then an HTMLPurifier allowlist), and
 *      this test asserts the OUTCOME, which is what a page depends on.
 *   2. The viewer registry routes text/markdown to Markdown_Viewer without disturbing the
 *      text/* entry below it - the map is first-match, so ordering is the whole contract.
 *   3. The endpoint renders a real uploaded .md and answers 'available' with the markup.
 */
class Markdown_Rendition_Test extends Rsx_Test_Abstract
{
    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    /**
     * A GFM document exercising every construct the renderer is expected to carry, plus the
     * two it is expected to defuse: a raw <script> block and a javascript: link.
     */
    private const SAMPLE = <<<'MD'
# Release Notes

A line with **bold**, ~~struck~~ and `inline code`.

| Platform | Version |
|----------|--------:|
| Linux    | 6.8     |

- [x] Parser wired
- [ ] Docs written

```php
echo "fenced";
```

<script>alert('nope')</script>
<div class="raw">a raw block</div>

See <https://example.com/docs> and [click](javascript:alert(1)).
MD;

    public static function setup()
    {
        // Creating an attachment queues its blob; with the kick switch off no detached worker
        // is spawned while these tests run.
        config(['rsx.search.enabled' => false]);

        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn($data) => true]);
        Event_Registry::_set_test_handlers('file.download.authorize', [fn($data) => true]);
    }

    public static function teardown()
    {
        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        Event_Registry::_clear_test_handlers();

        config(['rsx.search.enabled' => true]);
    }

    // ============================================================================================
    // THE RENDERER
    // ============================================================================================

    // DOCUMENTS-MARKDOWN-GFM: the constructs a reader expects a .md file to have survive the
    // round trip. Asserted on the OUTPUT rather than on the parser's configuration, because the
    // output is what a page renders.
    public static function test_gfm_constructs_render()
    {
        $html = Markdown_Rendition::render(static::SAMPLE);

        static::__assert_contains('<h1>Release Notes</h1>', $html, 'a heading is a heading');
        static::__assert_contains('<strong>bold</strong>', $html, 'emphasis');
        static::__assert_contains('<del>struck</del>', $html, 'strikethrough - a GFM addition, not core CommonMark');
        static::__assert_contains('<code>inline code</code>', $html, 'an inline code span');

        static::__assert_contains('<table>', $html, 'a GFM table is a table');
        static::__assert_contains('<th>Platform</th>', $html, 'with its header row');
        static::__assert_contains('align="right"', $html, 'and its column alignment, which the purifier keeps');

        static::__assert_contains('type="checkbox"', $html, 'a task list is checkboxes');
        static::__assert_contains('checked="checked"', $html, 'the ticked item is ticked');
        static::__assert_contains(
            'disabled="disabled"',
            $html,
            'and every one of them is inert - a preview is not an editor'
        );

        static::__assert_contains('<pre>', $html, 'a fenced block is preformatted');
        static::__assert_contains('echo "fenced";', $html, 'with its contents as text, not as markup - the fence is a quotation');

        static::__assert_contains(
            '<a href="https://example.com/docs">',
            $html,
            'an autolink becomes a link - another GFM addition'
        );
    }

    // DOCUMENTS-MARKDOWN-SANITISED: nothing executable survives. The parser strips raw HTML and
    // refuses an unsafe scheme, and HTMLPurifier's allowlist re-checks the output; either layer
    // alone would produce this result, which is exactly why there are two.
    public static function test_nothing_executable_survives()
    {
        $html = Markdown_Rendition::render(static::SAMPLE);

        static::__assert_false(
            str_contains(strtolower($html), '<script'),
            'a raw <script> block in the source is discarded, not passed through'
        );
        static::__assert_false(
            str_contains(strtolower($html), 'alert('),
            'and so is its body - stripping the tag alone would leave the payload as text'
        );
        static::__assert_false(
            str_contains(strtolower($html), 'javascript:'),
            'a javascript: link is written out with no href at all'
        );
        static::__assert_false(
            str_contains($html, 'a raw block'),
            'a raw HTML block is discarded whole - html_input is strip, never passthrough'
        );
        static::__assert_false(
            str_contains(strtolower($html), 'onerror'),
            'no event-handler attribute reaches the page under any name'
        );
        static::__assert_false(
            str_contains($html, 'class='),
            'the allowlist carries no class attribute, so nothing can borrow a host style'
        );

        // The link itself still renders - the TEXT is the author's and is kept; only the
        // destination is refused. A disappearing link would be a silent edit of the document.
        static::__assert_contains('click', $html, 'the refused link keeps its text');
    }

    // ============================================================================================
    // THE VIEWER REGISTRY
    // ============================================================================================

    // DOCUMENTS-MARKDOWN-RESOLVE: text/markdown resolves ABOVE the text/* entry. The map is
    // first-match, so this ordering IS the feature - reversed, every .md would be raw text.
    public static function test_markdown_resolves_above_plain_text()
    {
        static::__assert_equals(
            'Markdown_Viewer',
            File_Preview_Controller::viewer_for_mime('text/markdown'),
            'a .md previews as the rendered document'
        );
        static::__assert_equals(
            'Text_Viewer',
            File_Preview_Controller::viewer_for_mime('text/plain'),
            'and every other text/* mime still previews as itself'
        );
        static::__assert_equals(
            'Text_Viewer',
            File_Preview_Controller::viewer_for_mime('text/csv'),
            'the new entry narrows nothing below it'
        );
    }

    // ============================================================================================
    // THE ENDPOINT
    // ============================================================================================

    // DOCUMENTS-MARKDOWN-ENDPOINT: a real uploaded .md renders through the controller, and a
    // file that is not markdown is answered 'unsupported' rather than rendered as if it were.
    public static function test_the_endpoint_renders_an_uploaded_document()
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        $markdown = File_Attachment_Model::create_from_string(
            static::SAMPLE,
            'markdown_endpoint_test.md',
            ['site_id' => $site_id]
        );
        static::$created_attachment_ids[] = $markdown->id;

        $plain = File_Attachment_Model::create_from_string(
            "just words " . bin2hex(random_bytes(8)),
            'markdown_endpoint_test.txt',
            ['site_id' => $site_id]
        );
        static::$created_attachment_ids[] = $plain->id;

        $request = Request::create('/');

        $result = File_Preview_Controller::get_markdown_html($request, ['attachment_id' => $markdown->id]);

        static::__assert_equals('available', $result['status'], 'the document renders now - there is nothing to wait for');
        static::__assert_contains('<table', $result['html'], 'and the payload carries the rendered table');
        static::__assert_contains('<h1>Release Notes</h1>', $result['html'], 'and the heading');
        static::__assert_false($result['truncated'], 'a small document is read to its end');
        static::__assert_false(
            str_contains(strtolower($result['html']), '<script'),
            'the payload is sanitised before it leaves the server, which is why the component interpolates it unescaped'
        );

        // The registry says Markdown_Viewer for this file, and the endpoint agrees about what
        // it is - a disagreement between the two would show an empty pane with no explanation.
        $info = File_Preview_Controller::get_preview_info($request, ['attachment_id' => $markdown->id]);
        static::__assert_equals('Markdown_Viewer', $info['viewer'], 'get_preview_info names the same viewer');
        static::__assert_false(
            $info['should_show_text_preview'],
            'and no text pane beside it - the preview IS the document'
        );

        $refused = File_Preview_Controller::get_markdown_html($request, ['attachment_id' => $plain->id]);
        static::__assert_equals(
            'unsupported',
            $refused['status'],
            'a .txt is not markdown, and is refused rather than rendered as if it were'
        );
        static::__assert_null($refused['html'], 'with no markup on the payload');
    }
}
