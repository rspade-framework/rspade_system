<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use Symfony\Component\Mime\Email;
use App\RSpade\Core\Mail\Rsx_Mail_Builder;
use App\RSpade\Core\Mail\Rsx_Mail_Text;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;

/**
 * Rsx_Mail_Builder_Test - one queue row becomes one MIME message.
 *
 * The builder is a pure function of the ROW: it never asks the email class anything,
 * because the class did its talking at enqueue time. So every test here builds from a
 * row and inspects the Symfony\Component\Mime\Email that comes back, plus the two
 * rendered bodies and the header map the builder writes BACK onto the row - that write
 * is what answers "what did we almost send them" when a message ends up SUPPRESSED or
 * FAILED.
 *
 * The MIME nesting assertions are not pedantry. multipart/alternative under
 * multipart/related under multipart/mixed is the only arrangement in which a mail
 * client shows the HTML, resolves its inline images, and still offers the attachment as
 * a file - get the nesting wrong and a client shows the logo as a second attachment, or
 * shows the plain-text part to everybody.
 */
class Rsx_Mail_Builder_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** A public asset of this application, used for the local-image embed path. */
    private const PUBLIC_IMAGE = '/favicon.ico';

    /** A 1x1 PNG, so an embedded fixture is a real image and not merely named bytes. */
    private const ONE_PIXEL_PNG =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * Queue one fixture email and hand back its row.
     *
     * The dev-host gate is whitelisted past: the test host is a `.dev.` hostname and the
     * gate has its own tests in Rsx_Email_Enqueue_Test - here it would only decide
     * whether there is a row to build at all.
     */
    private static function __queue(callable $configure = null): Email_Queue_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            $email = new Mail_Notification_Fixture_Email('Builder probe');
            $email->to('builder_' . uniqid() . '@example.com');

            if ($configure !== null) {
                $configure($email);
            }

            return $email->send();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }
    }

    private static function __png_path(): string
    {
        $path = storage_path('rsx-tmp/mail_builder_probe_' . uniqid() . '.png');
        file_put_contents_safe($path, base64_decode(self::ONE_PIXEL_PNG));

        return $path;
    }

    /**
     * The media types of a body part's children, as `type/subtype` strings.
     *
     * @return array<int,string>
     */
    private static function __subtypes(object $part): array
    {
        $types = [];

        foreach ($part->getParts() as $child) {
            $types[] = $child->getMediaType() . '/' . $child->getMediaSubtype();
        }

        return $types;
    }

    // =========================================================================
    // MIME STRUCTURE
    // =========================================================================

    /**
     * The plain shape: no attachments and no images, so text and html are the whole
     * message and multipart/alternative is the entire body.
     */
    public static function test_a_plain_message_is_multipart_alternative()
    {
        $row = static::__queue();
        $message = Rsx_Mail_Builder::build($row);

        $body = $message->getBody();

        static::__assert_equals('multipart', $body->getMediaType(), 'the body is multipart');
        static::__assert_equals('alternative', $body->getMediaSubtype(), 'text and html are alternatives of each other');
        static::__assert_equals(
            ['text/plain', 'text/html'],
            static::__subtypes($body),
            'text first, html second - a client picking the last one it understands gets the html'
        );
    }

    /**
     * The full shape: alternative inside related (so the html can resolve its cid:
     * images) inside mixed (so the attachment is still offered as a file).
     */
    public static function test_an_inline_image_and_an_attachment_nest_mixed_related_alternative()
    {
        $png = static::__png_path();

        try {
            $row = static::__queue(function ($email) use ($png) {
                $email->show_image = true;
                $email->embed('fixture_image', $png);
                $email->attach_bytes("id,name\n1,Ada\n", 'people.csv', 'text/csv');
            });

            $message = Rsx_Mail_Builder::build($row);
            $body = $message->getBody();

            static::__assert_equals('mixed', $body->getMediaSubtype(), 'the outermost part is multipart/mixed');

            $mixed_children = static::__subtypes($body);
            static::__assert_equals('multipart/related', $mixed_children[0], 'the readable message comes first');
            static::__assert_equals('text/csv', $mixed_children[1], 'and the attachment sits beside it');

            $related = $body->getParts()[0];
            $related_children = static::__subtypes($related);

            static::__assert_equals(
                'multipart/alternative',
                $related_children[0],
                'the two readable renderings are alternatives'
            );
            static::__assert_equals('image/png', $related_children[1], 'and the inline image is RELATED to them, not attached');

            $alternative = $related->getParts()[0];
            static::__assert_equals(
                ['text/plain', 'text/html'],
                static::__subtypes($alternative),
                'text and html, in that order'
            );
        } finally {
            @unlink($png);
        }
    }

    // =========================================================================
    // CSS
    // =========================================================================

    /**
     * Inlining is what actually styles the message - Gmail and most of Outlook's history
     * strip <style> outright - but a media query cannot be inlined onto anything, so the
     * compiled sheet rides in the head as well. BOTH, deliberately.
     */
    public static function test_the_stylesheet_is_inlined_onto_elements_and_kept_in_a_style_block()
    {
        $row = static::__queue();
        Rsx_Mail_Builder::build($row);

        $html = $row->fresh()->rendered_html;

        static::__assert_contains('<style>', $html, 'the compiled sheet is kept for the clients that read it');

        $matched = preg_match('/<a[^>]*class="email-button"[^>]*>/', $html, $match);

        static::__assert_equals(1, $matched, 'the button link survived rendering');
        static::__assert_contains(
            'style=',
            $match[0],
            'and the .email-button rules were inlined onto it - a mail client that strips <style> still shows a button'
        );
    }

    // =========================================================================
    // INLINE IMAGES
    // =========================================================================

    /**
     * An image a message merely LINKS to is an image most clients refuse to load, so a
     * root-relative local asset becomes a cid: part rather than staying a URL.
     */
    public static function test_a_root_relative_public_image_becomes_a_cid_part()
    {
        $message = new Email();
        $html = '<p><img src="' . self::PUBLIC_IMAGE . '" alt="Logo"></p>';

        $result = Rsx_Mail_Builder::embed_local_images($message, $html);

        static::__assert_contains('src="cid:', $result, 'the src was rewritten to a content id');
        static::__assert_false(
            str_contains($result, 'src="' . self::PUBLIC_IMAGE . '"'),
            'and no longer points at a URL the client would have to fetch'
        );
        static::__assert_count(1, $message->getAttachments(), 'the asset rode along as a part');
    }

    public static function test_an_absolute_or_protocol_relative_image_is_left_alone()
    {
        foreach (['https://cdn.example.com/logo.png', '//cdn.example.com/logo.png', 'data:image/gif;base64,R0lGOD'] as $src) {
            $message = new Email();
            $html = '<p><img src="' . $src . '" alt="Remote"></p>';

            $result = Rsx_Mail_Builder::embed_local_images($message, $html);

            static::__assert_contains('src="' . $src . '"', $result, "'{$src}' is the sender talking about somewhere else");
            static::__assert_count(0, $message->getAttachments(), 'so nothing was embedded');
        }
    }

    /**
     * A broken image is a broken email. The row is FAILED with the reason rather than a
     * hole being mailed to somebody.
     */
    public static function test_an_unresolvable_local_image_throws()
    {
        $message = new Email();
        $html = '<p><img src="/img/there-is-no-such-asset-here.png"></p>';

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Rsx_Mail_Builder::embed_local_images($message, $html),
            'does not resolve to a public asset'
        );
    }

    /**
     * `<img src="cid:chart">` is a promise the SENDER has to keep. An unbound reference
     * is a build error, not a missing picture nobody notices.
     */
    public static function test_an_unbound_cid_reference_throws()
    {
        $message = new Email();
        $html = '<p><img src="cid:chart"></p>';

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Rsx_Mail_Builder::embed_local_images($message, $html),
            "->embed('chart', ...)"
        );
    }

    // =========================================================================
    // THE TEXT PART
    // =========================================================================

    public static function test_a_link_is_spelled_out_so_a_text_reader_can_act_on_it()
    {
        $text = Rsx_Mail_Text::from_html('<p>Please <a href="https://example.com/pay">pay the invoice</a>.</p>');

        static::__assert_equals(
            'Please pay the invoice (https://example.com/pay).',
            $text,
            'the destination rides beside the label'
        );
    }

    public static function test_a_link_whose_label_is_already_the_url_is_not_repeated()
    {
        $text = Rsx_Mail_Text::from_html('<p><a href="https://example.com/x">https://example.com/x</a></p>');

        static::__assert_equals('https://example.com/x', $text, 'repeating it helps nobody');
    }

    public static function test_an_in_document_anchor_contributes_only_its_label()
    {
        $text = Rsx_Mail_Text::from_html('<p><a href="#top">Back to top</a></p>');

        static::__assert_equals('Back to top', $text, 'a fragment means nothing outside a rendered page');
    }

    public static function test_list_items_become_bullets()
    {
        $text = Rsx_Mail_Text::from_html('<ul><li>First</li><li>Second</li></ul>');

        static::__assert_equals("- First\n- Second", $text, 'each item gets a bullet and its own line');
    }

    public static function test_breaks_and_paragraphs_become_line_breaks()
    {
        $text = Rsx_Mail_Text::from_html('<p>One<br>Two</p><p>Three</p>');

        static::__assert_equals("One\nTwo\nThree", $text, 'structure the HTML carried in tags is carried in newlines');
    }

    public static function test_runs_of_blank_lines_collapse_to_one()
    {
        $text = Rsx_Mail_Text::from_html('<p>One</p><div></div><div></div><div></div><p>Two</p>');

        // Four block closes in a row would leave four newlines. One blank line - a single
        // paragraph break - is what survives: exactly one, not none and not four.
        static::__assert_equals("One\n\nTwo", $text, 'a paragraph break survives; eight of them do not');
    }

    public static function test_entities_are_decoded()
    {
        $text = Rsx_Mail_Text::from_html('<p>Ben &amp; Jerry&rsquo;s &lt;tag&gt; &quot;quoted&quot;</p>');

        static::__assert_contains('Ben & Jerry', $text, 'an ampersand reads as one');
        static::__assert_contains('<tag>', $text, 'an escaped angle bracket reads as one');
        static::__assert_contains('"quoted"', $text, 'and so does an escaped quote');
    }

    public static function test_head_style_and_script_contribute_nothing()
    {
        $html = '<html><head><title>T</title><style>.x { color: red; }</style></head>'
            . '<body><script>var x = 1;</script><p>Body text</p></body></html>';

        static::__assert_equals('Body text', Rsx_Mail_Text::from_html($html), 'only reading matter survives');
    }

    public static function test_an_image_contributes_its_alt_text_or_nothing()
    {
        static::__assert_equals(
            'Company logo',
            Rsx_Mail_Text::from_html('<p><img src="cid:logo" alt="Company logo"></p>'),
            'alt text is the only thing an image can say in plain text'
        );

        static::__assert_equals(
            '',
            Rsx_Mail_Text::from_html('<p><img src="cid:logo"></p>'),
            'and an image with nothing to say says nothing'
        );
    }

    public static function test_the_derived_text_part_carries_the_message_and_its_links()
    {
        $row = static::__queue();
        Rsx_Mail_Builder::build($row);

        $text = $row->fresh()->rendered_text;

        static::__assert_contains('Builder probe', $text, 'the body reached the text part');
        static::__assert_contains('Open the thing (http', $text, 'and the button became a spelled-out link');
        static::__assert_false(str_contains($text, '<'), 'no markup survived');
    }

    // =========================================================================
    // ENVELOPE AND HEADERS
    // =========================================================================

    public static function test_the_envelope_carries_reply_to_and_cc()
    {
        $row = static::__queue(function ($email) {
            $email->reply_to('support@example.com', 'Support Desk');
            $email->cc('watcher@example.com', 'A Watcher');
        });

        $message = Rsx_Mail_Builder::build($row);

        static::__assert_count(1, $message->getReplyTo(), 'a Reply-To was set');
        static::__assert_equals('support@example.com', $message->getReplyTo()[0]->getAddress(), 'with the row\'s address');
        static::__assert_equals('Support Desk', $message->getReplyTo()[0]->getName(), 'and its display name');

        static::__assert_count(1, $message->getCc(), 'the Cc was carried');
        static::__assert_equals('watcher@example.com', $message->getCc()[0]->getAddress(), 'with its address');
    }

    /**
     * The Message-ID is minted by the BUILDER, not left to the transport: the row's
     * message_id_header is how a bounce or a support ticket is traced back to a queue
     * row, and a value the transport generates after the fact is one we can only hope to
     * read back.
     */
    public static function test_the_message_carries_a_message_id_and_the_queue_row_id()
    {
        $row = static::__queue();
        $message = Rsx_Mail_Builder::build($row);

        $headers = $message->getHeaders();

        static::__assert_true($headers->has('Message-ID'), 'a Message-ID was minted here');
        static::__assert_equals(
            (string) $row->id,
            $headers->get('X-RSX-Email-Id')->getBodyAsString(),
            'X-RSX-Email-Id names the queue row this message came from'
        );

        $recorded = $row->fresh()->headers;

        static::__assert_equals(
            (string) $row->id,
            $recorded['X-RSX-Email-Id'],
            'and the row records the headers the builder added'
        );
        static::__assert_array_has_key('Message-ID', $recorded, 'the Message-ID among them');
    }

    /**
     * List-Unsubscribe (RFC 2369) plus List-Unsubscribe-Post (RFC 8058) put the mail
     * client's own one-click button in front of the recipient - and a recipient who can
     * leave with one click does not leave by pressing "spam" instead.
     */
    public static function test_a_notification_carries_the_one_click_unsubscribe_headers()
    {
        $row = static::__queue();
        $message = Rsx_Mail_Builder::build($row);

        $headers = $message->getHeaders();

        static::__assert_true($headers->has('List-Unsubscribe'), 'a NOTIFICATION offers an unsubscribe');
        static::__assert_contains(
            '/_mail/unsubscribe',
            $headers->get('List-Unsubscribe')->getBodyAsString(),
            'pointing at the signed endpoint'
        );
        static::__assert_equals(
            'List-Unsubscribe=One-Click',
            $headers->get('List-Unsubscribe-Post')->getBodyAsString(),
            'and declares itself one-click capable'
        );
    }

    /**
     * A transactional message cannot be unsubscribed from, so offering the button would
     * promise something block() will not do.
     */
    public static function test_a_transactional_message_offers_no_unsubscribe()
    {
        static::__acting_as_site(self::SITE_ID);

        $row = Email_Queue_Model::enqueue([
            'site_id' => self::SITE_ID,
            'to_address' => 'transactional_' . uniqid() . '@example.com',
            'subject' => 'RSpade mail test',
            'email_class' => 'Rsx_Mail_Test_Email',
            'template_data' => [
                'hostname' => 'test.example.com',
                'sent_at' => now()->toIso8601String(),
                'app_url' => 'https://test.example.com/',
            ],
            'category_id' => Email_Queue_Model::CATEGORY_TRANSACTIONAL,
        ]);

        $message = Rsx_Mail_Builder::build($row);

        static::__assert_false(
            $message->getHeaders()->has('List-Unsubscribe'),
            'a transactional message has no opt-out to offer'
        );
        static::__assert_false(
            str_contains($row->fresh()->rendered_html, '/_mail/unsubscribe'),
            'and its footer prints no link'
        );
    }

    /**
     * On a dev host the envelope may point at a catchall; unsubscribing THAT address
     * would silence a developer's inbox instead of the person who asked.
     */
    public static function test_the_unsubscribe_link_names_the_original_recipient()
    {
        static::__acting_as_site(self::SITE_ID);

        $row = Email_Queue_Model::enqueue([
            'site_id' => self::SITE_ID,
            'to_address' => 'developer@example.com',
            'subject' => 'Notice',
            'email_class' => 'Mail_Notification_Fixture_Email',
            'template_data' => ['note' => 'Notice', 'show_image' => false, 'view_url' => 'https://example.com/'],
            'category_id' => Email_Queue_Model::CATEGORY_NOTIFICATION,
            'dev_original_to' => 'the.real.person@example.com',
        ]);

        $message = Rsx_Mail_Builder::build($row);

        static::__assert_contains(
            'the.real.person%40example.com',
            $message->getHeaders()->get('List-Unsubscribe')->getBodyAsString(),
            'the link is minted for the person the message was really for'
        );
    }

    // =========================================================================
    // WHAT THE ROW RECORDS
    // =========================================================================

    /**
     * The builder writes the rendered bodies back onto the row BEFORE the transport is
     * offered the message. A row that ends SUPPRESSED or FAILED still carries exactly
     * what would have gone out, which is the only way to answer "what did we almost send
     * them" six months later.
     */
    public static function test_the_rendered_bodies_are_persisted_on_the_row()
    {
        $row = static::__queue();

        static::__assert_null($row->rendered_html, 'nothing is rendered at enqueue time');

        Rsx_Mail_Builder::build($row);

        $row = $row->fresh();

        static::__assert_not_empty($row->rendered_html, 'the html body is recorded');
        static::__assert_not_empty($row->rendered_text, 'the text body is recorded');
        static::__assert_contains('Builder probe', $row->rendered_html, 'and it is this message');
    }
}
