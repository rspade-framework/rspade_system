<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Mail\Rsx_Mail_Text;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Email_Attachment_Model;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Integrations\Scss\Scss_BundleProcessor;

/**
 * Rsx_Mail_Builder - one queue row becomes one MIME message.
 *
 * The row is the whole message: the envelope, the frozen subject and template data,
 * and the attachments. Nothing here asks the email CLASS anything - the class did its
 * talking at enqueue time, and a template that renders next Tuesday renders the values
 * the caller had today.
 *
 * The pipeline, in order, each step exposed as its own public static so a test can
 * examine it without sending anything:
 *
 *   render_html()        the blade, with subject/unsubscribe_url/branding added
 *   inline_css()         the compiled email stylesheet, onto the elements AND in <head>
 *   embed_local_images() every local <img> becomes a cid: part
 *   derive_text()        the text part, derived when no <Class>_Text blade exists
 *
 * build() runs all four, assembles the Email, and RECORDS the rendered bodies and the
 * headers it added on the row before returning. That write is deliberate: a message
 * that ends up SUPPRESSED or FAILED still carries exactly what would have gone out,
 * which is the only way to answer "what did we almost send them" six months later.
 */
class Rsx_Mail_Builder
{
    /**
     * Build the MIME message for one queued row.
     */
    public static function build(Email_Queue_Model $row): Email
    {
        $html = static::render_html($row);
        $html = static::inline_css($html);

        $message = new Email();

        $inline_attachments = [];
        $file_attachments = [];

        foreach ($row->attachments()->get() as $attachment) {
            if ((int) $attachment->disposition_id === Email_Attachment_Model::DISPOSITION_INLINE) {
                $inline_attachments[(string) $attachment->cid] = $attachment;
                continue;
            }

            $file_attachments[] = $attachment;
        }

        $html = static::embed_local_images($message, $html, $inline_attachments);

        $text = static::_text_part($row, $html);

        static::_apply_envelope($message, $row);
        $headers = static::_apply_headers($message, $row);

        $message->html($html, 'utf-8');
        $message->text($text, 'utf-8');

        foreach ($file_attachments as $attachment) {
            $message->attachFromPath(
                static::_attachment_path($attachment),
                $attachment->file_name,
                $attachment->mime_type
            );
        }

        $row->rendered_html = $html;
        $row->rendered_text = $text;
        $row->headers = $headers;
        $row->save();

        return $message;
    }

    // =========================================================================
    // 1. HTML
    // =========================================================================

    /**
     * Render the row's blade template.
     *
     * On top of the frozen template_data every email gets three things it did not have
     * to ask for: its own subject, the unsubscribe URL (only when the category permits
     * unsubscribing - a transactional email gets null and its footer prints no link),
     * and the branding block, so a template never reaches into config for chrome.
     *
     * The unsubscribe link is minted for the ORIGINAL recipient. On a dev host the
     * envelope may have been redirected to a catchall, and unsubscribing the catchall
     * address would silence a developer's inbox instead of the person who asked.
     */
    public static function render_html(Email_Queue_Model $row): string
    {
        $data = $row->template_data ?? [];

        $data['subject'] = $row->subject;
        $data['branding'] = config('rsx.mail.branding', []);
        $data['unsubscribe_url'] = static::_unsubscribe_url($row);

        return rsx_view($row->email_class, $data)->render();
    }

    /**
     * The unsubscribe URL for this row, or null when the category does not offer one.
     */
    private static function _unsubscribe_url(Email_Queue_Model $row): ?string
    {
        if ((int) $row->category_id === Email_Queue_Model::CATEGORY_TRANSACTIONAL) {
            return null;
        }

        return Rsx_Mail::unsubscribe_url(
            $row->dev_original_to ?: $row->to_address,
            (int) $row->category_id,
            (int) $row->site_id
        );
    }

    // =========================================================================
    // 2. CSS
    // =========================================================================

    /**
     * Put the email stylesheet on the elements, and keep a copy in <head>.
     *
     * BOTH, deliberately. Inlining is what actually styles the message - Gmail and
     * most of Outlook's history strip <style> outright - but a media query cannot be
     * inlined onto anything, so the compiled sheet also rides in the head for the
     * clients that do read it.
     */
    public static function inline_css(string $html): string
    {
        $css = static::compiled_css();

        if (trim($css) === '') {
            return $html;
        }

        $inlined = (new CssToInlineStyles())->convert($html, $css);

        return static::_inject_style_block($inlined, $css);
    }

    /**
     * The compiled email stylesheet.
     *
     * Source is rsx/emails/email.scss when the application wrote one, else the
     * framework default. ONE FILE WINS - there is no merge, because a merge would mean
     * an app could never remove a framework rule it did not want.
     *
     * Compiled through the same node-sass step the bundle build uses and cached under
     * storage/rsx-tmp by the SOURCE's content hash: edit the stylesheet and the next
     * message compiles, touch nothing and nothing recompiles.
     */
    public static function compiled_css(): string
    {
        $source = static::css_source_path();
        $scss = file_get_contents($source);

        if ($scss === false) {
            throw new \RuntimeException("Could not read the email stylesheet at {$source}.");
        }

        $cache_file = storage_path('rsx-tmp/email_css_' . hash('sha256', $scss) . '.css');

        if (is_file($cache_file)) {
            return (string) file_get_contents($cache_file);
        }

        // The node compile step writes its script next to the INPUT file, so the input
        // is a copy in a directory we own rather than the stylesheet where it lives.
        $work_dir = storage_path('rsx-tmp/email_css_build_' . random_hash());
        ensure_directory($work_dir);

        try {
            $input = $work_dir . '/email.scss';
            $output = $work_dir . '/email.css';

            file_put_contents_safe($input, $scss);

            // No source map: this CSS is inlined into a message, and a base64 map
            // comment riding along in every email is bytes nobody will ever read.
            Scss_BundleProcessor::compile_file($input, $output, [
                'minify' => false,
                'source_maps' => false,
            ]);

            $css = (string) file_get_contents($output);

            file_put_contents_safe($cache_file, $css);

            return $css;
        } finally {
            static::_remove_directory($work_dir);
        }
    }

    /**
     * Which stylesheet is in play - the application's, or the framework default.
     */
    public static function css_source_path(): string
    {
        $app_stylesheet = rsx_project_file_path('rsx/emails/email.scss');

        if (is_file($app_stylesheet)) {
            return $app_stylesheet;
        }

        return __DIR__ . '/resource/email.scss';
    }

    /**
     * Put the compiled sheet into <head>, or at the front when there is no head.
     */
    private static function _inject_style_block(string $html, string $css): string
    {
        $style = "<style>\n" . $css . "\n</style>";

        if (stripos($html, '</head>') !== false) {
            return preg_replace('#</head>#i', $style . "\n</head>", $html, 1);
        }

        return $style . "\n" . $html;
    }

    // =========================================================================
    // 3. INLINE IMAGES
    // =========================================================================

    /**
     * Turn every local image reference into a cid: part on the message.
     *
     * Two shapes are handled and one is left alone:
     *
     *   src="/img/logo.png"  a public asset of this application. It is resolved through
     *                        the same lookup the web server uses and embedded. An image
     *                        a message merely LINKS to is an image most clients refuse
     *                        to load, so a linked local asset is not a design choice we
     *                        leave to chance.
     *   src="cid:chart"      bound to the inline attachment the sender declared with
     *                        ->embed('chart', ...). Symfony rewrites the reference to
     *                        the part's real content id when it prepares the message.
     *   src="https://..."    left exactly as written. The sender meant a remote image.
     *
     * A local src that resolves to nothing THROWS. A broken image is a broken email;
     * the runner records the row FAILED with the reason rather than mailing a hole.
     *
     * @param array<string, Email_Attachment_Model> $inline_attachments Keyed by cid.
     */
    public static function embed_local_images(Email $message, string $html, array $inline_attachments = []): string
    {
        $embedded = [];

        $result = preg_replace_callback(
            '#<img\b[^>]*>#i',
            static function ($matches) use ($message, $inline_attachments, &$embedded) {
                $tag = $matches[0];
                $src = static::_tag_attribute($tag, 'src');

                if ($src === null || trim($src) === '') {
                    return $tag;
                }

                $src = trim($src);

                if (str_starts_with($src, 'cid:')) {
                    $cid = substr($src, 4);

                    if (!isset($inline_attachments[$cid])) {
                        throw new \RuntimeException(
                            "Email template references <img src=\"cid:{$cid}\"> but nothing was embedded "
                            . "under that name - the sender must call ->embed('{$cid}', ...)."
                        );
                    }

                    if (!isset($embedded[$cid])) {
                        $attachment = $inline_attachments[$cid];
                        $message->embedFromPath(
                            static::_attachment_path($attachment),
                            $cid,
                            $attachment->mime_type
                        );
                        $embedded[$cid] = true;
                    }

                    return $tag;
                }

                // Anything with a scheme, a protocol-relative host, or a data: payload
                // is the sender talking about somewhere else.
                if (!str_starts_with($src, '/') || str_starts_with($src, '//')) {
                    return $tag;
                }

                $path = static::_resolve_public_asset($src);
                $cid = static::_cid_for_asset($src);

                if (!isset($embedded[$cid])) {
                    $message->embedFromPath($path, $cid);
                    $embedded[$cid] = true;
                }

                return str_replace($src, 'cid:' . $cid, $tag);
            },
            $html
        );

        return $result;
    }

    /**
     * Resolve a root-relative image src to a file on disk, loudly.
     */
    private static function _resolve_public_asset(string $src): string
    {
        // A query string or fragment is for a browser cache-buster; the file is the
        // part before it.
        $path_only = preg_replace('/[?#].*$/', '', $src);

        try {
            return \App\RSpade\Core\Dispatch\AssetHandler::find_public_asset(ltrim($path_only, '/'));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            // find_public_asset() answers "missing", "forbidden" and "ambiguous" as HTTP
            // exceptions because its other caller is the web dispatcher. Here they are
            // all one thing: this message cannot be built.
            throw new \RuntimeException(
                "Email image src=\"{$src}\" does not resolve to a public asset: " . $e->getMessage()
            );
        }
    }

    /**
     * A stable, filename-shaped content id for one public asset path.
     */
    private static function _cid_for_asset(string $src): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(preg_replace('/[?#].*$/', '', $src)));

        return substr(hash('sha256', $src), 0, 12) . '_' . $base;
    }

    // =========================================================================
    // 4. TEXT
    // =========================================================================

    /**
     * The plain-text part: the email's own text template when it wrote one, else the
     * HTML converted.
     */
    private static function _text_part(Email_Queue_Model $row, string $html): string
    {
        $text_view_id = $row->email_class . '_Text';

        if (static::_view_exists($text_view_id)) {
            $data = $row->template_data ?? [];
            $data['subject'] = $row->subject;
            $data['branding'] = config('rsx.mail.branding', []);
            $data['unsubscribe_url'] = static::_unsubscribe_url($row);

            return trim(rsx_view($text_view_id, $data)->render());
        }

        return static::derive_text($html);
    }

    /**
     * Derive the plain-text part from the HTML one.
     */
    public static function derive_text(string $html): string
    {
        return Rsx_Mail_Text::from_html($html);
    }

    /**
     * Whether a blade with this @rsx_id is in the manifest.
     *
     * Asked of the manifest DATA rather than by calling find_view() and catching: that
     * function throws for "not found" and for "declared twice", and swallowing the
     * second one would hide a real build problem behind a silently missing text part.
     */
    private static function _view_exists(string $id): bool
    {
        foreach (Manifest::get_all() as $file => $metadata) {
            if (($metadata['id'] ?? null) === $id && str_ends_with($file, '.blade.php')) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // ENVELOPE AND HEADERS
    // =========================================================================

    /**
     * From, To, Cc, Bcc, Reply-To and the subject.
     */
    private static function _apply_envelope(Email $message, Email_Queue_Model $row): void
    {
        $from_address = (string) config('rsx.mail.from_address');
        $from_name = trim((string) config('rsx.mail.from_name', ''));

        if ($from_name === '') {
            $from_name = (string) config('rsx.name', '');
        }

        $message->from(new Address($from_address, $from_name));
        $message->to(static::_address($row->to_address, $row->to_name));
        $message->subject((string) $row->subject);

        foreach (($row->cc ?? []) as $entry) {
            $message->addCc(static::_address($entry['address'], $entry['name'] ?? null));
        }

        foreach (($row->bcc ?? []) as $entry) {
            $message->addBcc(static::_address($entry['address'], $entry['name'] ?? null));
        }

        if ($row->reply_to) {
            $message->replyTo(static::_address($row->reply_to, $row->reply_to_name));
        }
    }

    /**
     * The headers the framework adds, applied to the message and returned so the row
     * can record exactly what it sent.
     *
     * The Message-ID is minted HERE rather than left to the transport: the row's
     * message_id_header column is how a bounce or a support ticket is traced back to a
     * queue row, and a value the transport generates after the fact is a value we can
     * only hope to read back.
     *
     * List-Unsubscribe (RFC 2369) plus List-Unsubscribe-Post (RFC 8058) go on every
     * non-transactional message: it is what puts the mail client's own one-click
     * unsubscribe button in front of the recipient, and a recipient who can leave with
     * one click does not leave by pressing "spam" instead.
     *
     * @return array<string, string>
     */
    private static function _apply_headers(Email $message, Email_Queue_Model $row): array
    {
        $headers = $message->getHeaders();

        $headers->addIdHeader('Message-ID', $message->generateMessageId());

        $recorded = [
            'Message-ID' => $headers->get('Message-ID')->getBodyAsString(),
        ];

        $headers->addTextHeader('X-RSX-Email-Id', (string) $row->id);
        $recorded['X-RSX-Email-Id'] = (string) $row->id;

        $unsubscribe_url = static::_unsubscribe_url($row);

        if ($unsubscribe_url !== null) {
            $headers->addTextHeader('List-Unsubscribe', '<' . $unsubscribe_url . '>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            $recorded['List-Unsubscribe'] = '<' . $unsubscribe_url . '>';
            $recorded['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return $recorded;
    }

    /**
     * One address, with its display name when the row carries one.
     */
    private static function _address(string $address, ?string $name): Address
    {
        $name = $name !== null ? trim($name) : '';

        return new Address($address, $name);
    }

    // =========================================================================
    // FILES
    // =========================================================================

    /**
     * The blob backing one attachment row, verified present.
     */
    private static function _attachment_path(Email_Attachment_Model $attachment): string
    {
        $storage = $attachment->file_storage;

        if ($storage === null) {
            throw new \RuntimeException(
                "Email attachment #{$attachment->id} ({$attachment->file_name}) has no storage row."
            );
        }

        $path = $storage->get_full_path();

        if (!is_file($path)) {
            throw new \RuntimeException(
                "Email attachment #{$attachment->id} ({$attachment->file_name}) has no bytes on disk at {$path}."
            );
        }

        return $path;
    }

    // =========================================================================
    // SMALL HELPERS
    // =========================================================================

    /**
     * Read one attribute out of a start tag, or null when it is not there.
     */
    private static function _tag_attribute(string $tag, string $name): ?string
    {
        if (preg_match('#\b' . preg_quote($name, '#') . '\s*=\s*"([^"]*)"#i', $tag, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match("#\\b" . preg_quote($name, '#') . "\\s*=\\s*'([^']*)'#i", $tag, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    /**
     * Remove the temporary compile directory.
     */
    private static function _remove_directory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                static::_remove_directory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
