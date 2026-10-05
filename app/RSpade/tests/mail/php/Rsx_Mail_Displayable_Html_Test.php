<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Email_Attachment_Model;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Mail_Displayable_Html_Test - a stored message body made displayable from the
 * DATABASE alone.
 *
 * rendered_html names its inline images by cid:, which only resolves inside the MIME
 * message that carried the part. Rsx_Mail::displayable_html() rewrites each reference to
 * a data: URI of the bytes recorded on THAT row's own _email_attachments rows, and a
 * reference with nothing recorded to a visible placeholder. Every row here is planted
 * directly with the body and attachment rows it needs, so the function is examined
 * without the builder in the way.
 */
class Rsx_Mail_Displayable_Html_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * An _email_queue row carrying $html as its stored body.
     */
    private static function __row(?string $html): Email_Queue_Model
    {
        $id = (int) DB::table('_email_queue')->insertGetId([
            'site_id' => self::SITE_ID,
            'to_address' => 'displayable-' . uniqid() . '@example.com',
            'subject' => 'Displayable probe',
            'email_class' => 'Mail_Notification_Fixture_Email',
            'category_id' => Email_Queue_Model::CATEGORY_NOTIFICATION,
            'status_id' => Email_Queue_Model::STATUS_SENT,
            'attempt_count' => 1,
            'rendered_html' => $html,
            'created_at' => now(),
        ]);

        return Email_Queue_Model::find($id);
    }

    /**
     * Store $bytes as a blob and record them on $row as an inline part under $cid.
     */
    private static function __part(Email_Queue_Model $row, string $cid, string $bytes, string $mime_type): File_Storage_Model
    {
        $path = Rsx_Project_Paths::tmp_path('displayable_probe_' . uniqid() . '.bin');
        file_put_contents_safe($path, $bytes);

        try {
            return File_Storage_Model::store_blob(
                $path,
                fn (File_Storage_Model $storage) => Email_Attachment_Model::record_part(
                    $row, $storage, $cid, $mime_type, Email_Attachment_Model::DISPOSITION_INLINE, $cid, 0
                )
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * The decoded payload of a base64 data: URI, plus its media type.
     *
     * @return array{0: string, 1: string}
     */
    private static function __decode(string $data_uri): array
    {
        static::__assert_true(
            (bool) preg_match('#^data:([^;,]+);base64,([A-Za-z0-9+/=]+)$#', $data_uri, $match),
            "'" . substr($data_uri, 0, 60) . "...' is a base64 data: URI"
        );

        return [$match[1], base64_decode($match[2])];
    }

    /**
     * Every src="..." value in $html, in order.
     *
     * @return array<int,string>
     */
    private static function __srcs(string $html): array
    {
        preg_match_all('/src="([^"]*)"/', $html, $matches);

        return $matches[1];
    }

    /**
     * A recorded cid becomes a data: URI of the row's own bytes; the rest of the HTML is
     * untouched.
     */
    public static function test_a_recorded_cid_becomes_a_data_uri_of_the_recorded_bytes()
    {
        $bytes = 'logo-bytes-' . uniqid();
        $row = static::__row('<html><body><p>Hi</p><img src="cid:abc_logo.png" alt="Logo"><p>Bye</p></body></html>');
        static::__part($row, 'abc_logo.png', $bytes, 'image/png');

        $html = Rsx_Mail::displayable_html($row);
        $srcs = static::__srcs($html);

        static::__assert_count(1, $srcs, 'one image');
        [$mime_type, $decoded] = static::__decode($srcs[0]);
        static::__assert_equals('image/png', $mime_type, 'typed as the row records it');
        static::__assert_equals($bytes, $decoded, 'carrying exactly the recorded bytes');
        static::__assert_equals(
            '<html><body><p>Hi</p><img src="' . $srcs[0] . '" alt="Logo"><p>Bye</p></body></html>',
            $html,
            'and nothing else in the document changed'
        );
    }

    /**
     * A cid with no recorded row - a message built before inline parts were recorded -
     * becomes a visible placeholder naming it, never a silent broken image.
     */
    public static function test_an_unrecorded_cid_becomes_a_visible_placeholder()
    {
        $row = static::__row('<p><img src="cid:0123456789ab_logo.png"></p>');

        $srcs = static::__srcs(Rsx_Mail::displayable_html($row));
        [$mime_type, $svg] = static::__decode($srcs[0]);

        static::__assert_equals('image/svg+xml', $mime_type, 'the placeholder is an image');
        static::__assert_contains('Inline image not recorded', $svg, 'saying what happened');
        static::__assert_contains('cid:0123456789ab_logo.png', $svg, 'and naming the reference');
    }

    /**
     * A recorded row whose bytes are no longer on disk is shown as unavailable.
     */
    public static function test_a_recorded_cid_whose_blob_is_gone_becomes_a_placeholder()
    {
        $row = static::__row('<p><img src="cid:gone"></p>');
        $storage = static::__part($row, 'gone', 'vanishing-bytes-' . uniqid(), 'image/png');
        unlink($storage->get_full_path());

        [, $svg] = static::__decode(static::__srcs(Rsx_Mail::displayable_html($row))[0]);

        static::__assert_contains('Inline image unavailable', $svg, 'the placeholder says the bytes are gone');
    }

    /**
     * Resolution is from THIS row's attachments - another message's part recorded under
     * the same cid is not borrowed.
     */
    public static function test_only_the_rows_own_parts_resolve()
    {
        $other = static::__row('<p><img src="cid:shared_name"></p>');
        static::__part($other, 'shared_name', 'other-message-bytes', 'image/png');

        $row = static::__row('<p><img src="cid:shared_name"></p>');

        [$mime_type] = static::__decode(static::__srcs(Rsx_Mail::displayable_html($row))[0]);

        static::__assert_equals('image/svg+xml', $mime_type, 'the row has no part of its own, so it gets the placeholder');
    }

    /**
     * Attribute and CSS url() spellings are rewritten alike; a mention of "cid:" in prose
     * is left alone.
     */
    public static function test_every_reference_spelling_is_rewritten()
    {
        $row = static::__row(
            '<table background="cid:bg"><tr><td style="background-image: url(&quot;cid:bg&quot;)">'
            . '<img src=\'cid:bg\'> Content ids look like cid:bg in the source.</td></tr></table>'
        );
        static::__part($row, 'bg', 'background-bytes', 'image/gif');

        $html = Rsx_Mail::displayable_html($row);
        $data_uri = 'data:image/gif;base64,' . base64_encode('background-bytes');

        static::__assert_contains('background="' . $data_uri . '"', $html, 'a background attribute');
        static::__assert_contains('url(&quot;' . $data_uri . '&quot;)', $html, 'a CSS url() inside a style attribute');
        static::__assert_contains("src='" . $data_uri . "'", $html, 'a single-quoted src');
        static::__assert_contains('look like cid:bg in the source', $html, 'and prose is not an image reference');
    }

    /**
     * HTML with no cid: references comes back byte-for-byte, and an unrendered row is null.
     */
    public static function test_html_without_cids_is_unchanged_and_null_is_null()
    {
        $html = '<html><head><title>x</title></head><body><img src="https://example.com/a.png"><img src="data:image/gif;base64,R0lGOD"></body></html>';

        static::__assert_equals($html, Rsx_Mail::displayable_html(static::__row($html)), 'nothing to rewrite, nothing rewritten');
        static::__assert_null(Rsx_Mail::displayable_html(static::__row(null)), 'a row that was never rendered has nothing to display');
    }
}
