<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MimeTypes;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Mail\Rsx_Mail_Text;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Email_Attachment_Model;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
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
 *   embed_local_images() every local <img> becomes a cid: part, recorded as a blob
 *   derive_text()        the text part, derived when no <Class>_Text blade exists
 *
 * compose() is the first, second and fourth steps as ONE assembly - the blade, the
 * inlined stylesheet and the text part - and it is shared by the two paths that turn an
 * email into a body: build() (a queue row, for sending) and compose_preview() (an
 * unsaved email instance, for Rsx_Mail::preview()). The two differ only in what happens
 * to a local image afterwards: a send embeds it as a recorded part, a preview collects
 * its bytes so Rsx_Mail can show it as a data: URI. Both walk the images with the same
 * _localize_images(), so a preview names exactly the parts a send would embed.
 *
 * build() runs all four, assembles the Email, and RECORDS the rendered bodies and the
 * headers it added on the row before returning. That write is deliberate: a message
 * that ends up SUPPRESSED or FAILED still carries exactly what would have gone out,
 * which is the only way to answer "what did we almost send them" six months later.
 * compose_preview() records nothing anywhere.
 */
class Rsx_Mail_Builder
{
    /**
     * Build the MIME message for one queued row.
     */
    public static function build(Email_Queue_Model $row): Email
    {
        $composed = static::compose(
            (string) $row->email_class,
            $row->template_data ?? [],
            (string) $row->subject,
            static::_unsubscribe_url($row)
        );

        $message = new Email();

        $html = static::embed_local_images($message, $composed['html'], $row);
        $text = $composed['text'];

        static::_apply_envelope($message, $row);
        $headers = static::_apply_headers($message, $row);

        $message->html($html, 'utf-8');
        $message->text($text, 'utf-8');

        $file_attachments = $row->attachments()
            ->where('disposition_id', Email_Attachment_Model::DISPOSITION_ATTACHMENT)
            ->get();

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
    // PREVIEW
    // =========================================================================

    /**
     * An unsaved email instance through the send's own assembly - Rsx_Mail::preview()'s
     * engine. Persists nothing: no queue row, no attachment row, no blob.
     *
     * The body is compose()'s, with the email's own subject() and data() and, for a
     * category that offers one, Rsx_Mail::preview_unsubscribe_url() standing in for the
     * signed link (a preview has no recipient to sign it for). Local images are walked
     * by _localize_images() exactly as a send walks them, so each root-relative src
     * becomes the cid: the send would embed it under; instead of recording a part, the
     * asset's CURRENT bytes are collected. Every ->embed() part the email declared is
     * collected the same way, from its source. A template naming a cid: nothing was
     * embedded under throws, as the send would.
     *
     * The HTML returned still carries cid: references; `parts` holds the bytes behind
     * each (cid => {bytes, mime_type}). Rsx_Mail::preview() turns them into data: URIs.
     *
     * @return array{subject: string, html: string, text: string, parts: array<string, array{bytes: string, mime_type: string}>}
     */
    public static function compose_preview(Rsx_Email_Abstract $email): array
    {
        $category_id = $email::category();
        $subject = $email->subject();

        $composed = static::compose(
            $email::view_id(),
            $email->data(),
            $subject,
            static::category_offers_unsubscribe($category_id) ? Rsx_Mail::preview_unsubscribe_url() : null
        );

        $parts = [];

        foreach ($email->_envelope()['attachments'] as $spec) {
            if ((int) $spec['disposition'] === Rsx_Email_Abstract::DISPOSITION_INLINE) {
                $parts[(string) $spec['cid']] = static::_embed_spec_bytes($spec);
            }
        }

        $html = static::_localize_images(
            $composed['html'],
            static function (string $cid) use (&$parts) {
                if (!isset($parts[$cid])) {
                    throw static::_unembedded_cid_exception($cid);
                }
            },
            static function (string $path, string $cid) use (&$parts) {
                $bytes = file_get_contents($path);

                if ($bytes === false) {
                    throw new \RuntimeException("Could not read the email image asset at {$path}.");
                }

                $parts[$cid] = ['bytes' => $bytes, 'mime_type' => static::_asset_mime_type($path)];
            }
        );

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $composed['text'],
            'parts' => $parts,
        ];
    }

    /**
     * The bytes and type behind one ->embed() spec, read from its source - a stored
     * attachment's blob or an absolute path - without storing anything.
     *
     * @return array{bytes: string, mime_type: string}
     */
    private static function _embed_spec_bytes(array $spec): array
    {
        $source = $spec['source'];

        if ($source instanceof File_Attachment_Model) {
            $storage = $source->file_storage_id === null ? null : File_Storage_Model::find($source->file_storage_id);

            if ($storage === null) {
                throw new \RuntimeException(
                    "Cannot embed file attachment #{$source->id} as cid:{$spec['cid']}: its bytes have been released."
                );
            }

            return [
                'bytes' => $storage->read_bytes(),
                'mime_type' => (string) ($spec['mime'] ?? $source->mime_type),
            ];
        }

        if (!is_string($source) || !is_file($source)) {
            throw new \RuntimeException("Cannot embed '" . (string) $source . "' as cid:{$spec['cid']}: no such file.");
        }

        $bytes = file_get_contents($source);

        if ($bytes === false) {
            throw new \RuntimeException("Could not read '{$source}' to embed as cid:{$spec['cid']}.");
        }

        return [
            'bytes' => $bytes,
            'mime_type' => (string) ($spec['mime'] ?? (mime_content_type($source) ?: 'application/octet-stream')),
        ];
    }

    // =========================================================================
    // 1. HTML
    // =========================================================================

    /**
     * Render the row's blade template.
     *
     * On top of the frozen template_data every email gets three things it did not have
     * to ask for: its own subject, the unsubscribe URL (only when the category permits
     * unsubscribing - a transactional or security email gets null and its footer prints no link),
     * and the branding block, so a template never reaches into config for chrome.
     *
     * The unsubscribe link is minted for the ORIGINAL recipient. On a dev host the
     * envelope may have been redirected to a catchall, and unsubscribing the catchall
     * address would silence a developer's inbox instead of the person who asked.
     */
    public static function render_html(Email_Queue_Model $row): string
    {
        $variables = static::_template_variables(
            $row->template_data ?? [],
            (string) $row->subject,
            static::_unsubscribe_url($row)
        );

        return rsx_view((string) $row->email_class, $variables)->render();
    }

    /**
     * The ONE assembly of an email's two bodies: the blade rendered with its variables,
     * the stylesheet inlined, and the text part (the email's own <Class>_Text blade when
     * it wrote one, else derived from the HTML).
     *
     * Shared by build() and compose_preview() - a preview IS the send's rendering, never
     * a second implementation of it. Local images are still root-relative (or cid:) in
     * the HTML returned; what becomes of them is the caller's step. The text part does
     * not depend on that step: an image contributes only its alt text.
     *
     * @param string      $view_id         The email's class basename (its blade @rsx_id)
     * @param array       $template_data   The email's data()
     * @param string      $subject         The email's subject()
     * @param string|null $unsubscribe_url Null for a category that offers none
     * @return array{html: string, text: string}
     */
    public static function compose(string $view_id, array $template_data, string $subject, ?string $unsubscribe_url): array
    {
        $variables = static::_template_variables($template_data, $subject, $unsubscribe_url);

        $html = static::inline_css(rsx_view($view_id, $variables)->render());

        return [
            'html' => $html,
            'text' => static::_text_part($view_id, $variables, $html),
        ];
    }

    /**
     * Whether an email of this category carries an unsubscribe link (and the
     * List-Unsubscribe headers). A transactional or security email does not: the
     * recipient cannot opt out of it, so offering to would be a lie.
     */
    public static function category_offers_unsubscribe(int $category_id): bool
    {
        return $category_id !== Email_Queue_Model::CATEGORY_TRANSACTIONAL
            && $category_id !== Email_Queue_Model::CATEGORY_SECURITY;
    }

    /**
     * The template variables: the frozen data plus the three things every email gets
     * without asking - its subject, the branding block and the unsubscribe URL.
     */
    private static function _template_variables(array $template_data, string $subject, ?string $unsubscribe_url): array
    {
        $template_data['subject'] = $subject;
        $template_data['branding'] = config('rsx.mail.branding', []);
        $template_data['unsubscribe_url'] = $unsubscribe_url;

        return $template_data;
    }

    /**
     * The unsubscribe URL for this row, or null when the category does not offer one.
     */
    private static function _unsubscribe_url(Email_Queue_Model $row): ?string
    {
        if (!static::category_offers_unsubscribe((int) $row->category_id)) {
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
     * the tmp tree by the SOURCE's content hash: edit the stylesheet and the next
     * message compiles, touch nothing and nothing recompiles.
     */
    public static function compiled_css(): string
    {
        $source = static::css_source_path();
        $scss = file_get_contents($source);

        if ($scss === false) {
            throw new \RuntimeException("Could not read the email stylesheet at {$source}.");
        }

        // Keyed on the stylesheet AND the framework text-reset prelude every compilation
        // opens with: a mixin the stylesheet includes lives in that partial, so a change to
        // it is a change to this CSS.
        $prelude = (string) file_get_contents(\App\RSpade\Integrations\Scss\Scss_BundleProcessor::resets_partial_path());
        $cache_file = Rsx_Project_Paths::tmp_path('email_css_' . hash('sha256', $prelude . "\n" . $scss) . '.css');

        if (is_file($cache_file)) {
            return (string) file_get_contents($cache_file);
        }

        // The node compile step writes its script next to the INPUT file, so the input
        // is a copy in a directory we own rather than the stylesheet where it lives.
        $work_dir = Rsx_Project_Paths::tmp_path('email_css_build_' . random_hash());
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
            rmdir_recursive($work_dir);
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
     * Turn every local image reference into a cid: part on the message, and RECORD every
     * part it embeds as an inline _email_attachments row on the queue row.
     *
     * Two shapes are handled and one is left alone:
     *
     *   src="/img/logo.png"  a public asset of this application. It is resolved through
     *                        the same lookup the web server uses, its bytes go into the
     *                        content-addressed blob store, and an inline attachment row
     *                        records them under the cid the stored HTML now names. An
     *                        image a message merely LINKS to is an image most clients
     *                        refuse to load, so a linked local asset is not a design
     *                        choice we leave to chance.
     *   src="cid:chart"      bound to the inline attachment the sender declared with
     *                        ->embed('chart', ...). Symfony rewrites the reference to
     *                        the part's real content id when it prepares the message.
     *   src="https://..."    left exactly as written. The sender meant a remote image.
     *
     * EVERY EMBEDDED PART IS SENT FROM ITS BLOB. The message carries the bytes the row
     * records, under the cid the stored rendered_html names, so the stored message can be
     * reproduced later (Rsx_Mail::displayable_html()) with what was SENT - not with
     * whatever the asset path holds today. One logo across fifty thousand messages is one
     * blob and fifty thousand small rows, and each row is a live reference that keeps the
     * blob from being released (File_Disposal_Service).
     *
     * A rebuild of the same row (a retry, a resend) reuses the row already recorded for a
     * cid, re-pointing it at the asset's current bytes when they changed - the stored HTML
     * is rewritten by the same build, so the row and the body always describe one send.
     *
     * A local src that resolves to nothing THROWS. A broken image is a broken email;
     * the runner records the row FAILED with the reason rather than mailing a hole.
     */
    public static function embed_local_images(Email $message, string $html, Email_Queue_Model $row): string
    {
        $inline_attachments = [];

        foreach ($row->attachments()->where('disposition_id', Email_Attachment_Model::DISPOSITION_INLINE)->get() as $attachment) {
            $inline_attachments[(string) $attachment->cid] = $attachment;
        }

        return static::_localize_images(
            $html,
            static function (string $cid) use ($message, &$inline_attachments) {
                if (!isset($inline_attachments[$cid])) {
                    throw static::_unembedded_cid_exception($cid);
                }

                static::_embed_part($message, $inline_attachments[$cid]);
            },
            static function (string $path, string $cid) use ($message, $row, &$inline_attachments) {
                $inline_attachments[$cid] = static::_record_public_asset(
                    $row,
                    $path,
                    $cid,
                    $inline_attachments[$cid] ?? null
                );
                static::_embed_part($message, $inline_attachments[$cid]);
            }
        );
    }

    /**
     * Walk every <img> of a composed body - the one image rule both build() and
     * compose_preview() apply (see embed_local_images() for the three shapes).
     *
     *   src="cid:x"      $on_cid('x'), once per distinct cid; the tag is left as written.
     *   src="/img/a.png" resolved to its public asset LOUDLY (a src that resolves to
     *                    nothing throws), $on_asset($path, $cid) called once per distinct
     *                    asset cid, and the src rewritten to cid:<that cid>.
     *   anything else    left exactly as written.
     *
     * @param callable $on_cid   fn (string $cid): void
     * @param callable $on_asset fn (string $path, string $cid): void
     */
    private static function _localize_images(string $html, callable $on_cid, callable $on_asset): string
    {
        $seen = [];

        return preg_replace_callback(
            '#<img\b[^>]*>#i',
            static function ($matches) use ($on_cid, $on_asset, &$seen) {
                $tag = $matches[0];
                $src = static::_tag_attribute($tag, 'src');

                if ($src === null || trim($src) === '') {
                    return $tag;
                }

                $src = trim($src);

                if (str_starts_with($src, 'cid:')) {
                    $cid = substr($src, 4);

                    if (!isset($seen[$cid])) {
                        $on_cid($cid);
                        $seen[$cid] = true;
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

                if (!isset($seen[$cid])) {
                    $on_asset($path, $cid);
                    $seen[$cid] = true;
                }

                return str_replace($src, 'cid:' . $cid, $tag);
            },
            $html
        );
    }

    /**
     * The failure for a template that names a cid: nothing was embedded under.
     */
    private static function _unembedded_cid_exception(string $cid): \RuntimeException
    {
        return new \RuntimeException(
            "Email template references <img src=\"cid:{$cid}\"> but nothing was embedded "
            . "under that name - the sender must call ->embed('{$cid}', ...)."
        );
    }

    /**
     * Put one recorded inline part on the message, from its blob, under its cid.
     */
    private static function _embed_part(Email $message, Email_Attachment_Model $attachment): void
    {
        $message->embedFromPath(
            static::_attachment_path($attachment),
            (string) $attachment->cid,
            $attachment->mime_type
        );
    }

    /**
     * Store a public asset's bytes as a blob and record them as this row's inline part.
     *
     * The MIME type is the one the asset's extension names - the lookup Symfony applies
     * to a file embedded by path, so the part goes out typed exactly as an embedded file
     * would be (the blob itself has no extension to go by).
     */
    private static function _record_public_asset(
        Email_Queue_Model $row,
        string $path,
        string $cid,
        ?Email_Attachment_Model $existing
    ): Email_Attachment_Model {
        $mime_type = static::_asset_mime_type($path);
        $file_name = basename($path);

        // The part is recorded INSIDE store_blob()'s reference scope, so the blob cannot be
        // released between the dedup lookup and the row that pins it.
        $part = null;
        File_Storage_Model::store_blob($path, function (File_Storage_Model $storage) use ($row, $cid, $existing, $mime_type, $file_name, &$part) {
            if ($existing !== null) {
                if ((int) $existing->file_storage_id !== (int) $storage->id
                    || $existing->mime_type !== $mime_type
                    || $existing->file_name !== $file_name) {
                    $existing->file_storage_id = $storage->id;
                    $existing->mime_type = $mime_type;
                    $existing->file_name = $file_name;
                    $existing->save();
                }

                $part = $existing;
                return;
            }

            $last_sort_order = Email_Attachment_Model::where('email_queue_id', $row->id)->max('sort_order');

            $part = Email_Attachment_Model::record_part(
                $row,
                $storage,
                $file_name,
                $mime_type,
                Email_Attachment_Model::DISPOSITION_INLINE,
                $cid,
                $last_sort_order === null ? 0 : ((int) $last_sort_order + 1)
            );
        });

        return $part;
    }

    /**
     * The MIME type a public asset's extension names.
     */
    private static function _asset_mime_type(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return (new MimeTypes())->getMimeTypes($extension)[0] ?? 'application/octet-stream';
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
    private static function _text_part(string $view_id, array $variables, string $html): string
    {
        $text_view_id = $view_id . '_Text';

        if (static::_view_exists($text_view_id)) {
            return trim(rsx_view($text_view_id, $variables)->render());
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
     * Manifest::view_exists() is the non-throwing half of find_view(), which is the right
     * shape here: find_view() throws for "not found" AND for "declared twice", and swallowing
     * the second would hide a real build problem behind a silently missing text part. It
     * cannot arise any more - a duplicate @rsx_id is now a manifest-build failure naming both
     * files - and this is a lookup in `blade_views` rather than a scan of every indexed file
     * per rendered email part.
     */
    private static function _view_exists(string $id): bool
    {
        return Manifest::view_exists($id);
    }

    // =========================================================================
    // ENVELOPE AND HEADERS
    // =========================================================================

    /**
     * From, To, Cc, Bcc, Reply-To and the subject.
     */
    private static function _apply_envelope(Email $message, Email_Queue_Model $row): void
    {
        $message->from(new Address(Rsx_Mail_Transport::from_address(), Rsx_Mail_Transport::from_name()));
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
}
