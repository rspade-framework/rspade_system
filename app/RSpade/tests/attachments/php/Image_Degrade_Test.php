<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Unparseable_Upload_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unparseable-image degrade behavior (image-degrade epic, #8).
 *
 * An uploaded image whose BYTES fail to parse (ImageMagick chokes on the CONTENT - NOT a missing
 * binary) is, by default, ACCEPTED and DEGRADED to a generic, non-previewable file:
 * preview_unavailable=1, file_type_id=OTHER, pipeline_mime()=application/octet-stream, dimensions
 * null, while the RAW served mime_type column is left untouched (the serve-time Content-Type
 * security boundary). config('rsx.attachments.reject_unparseable_images')=true flips this to a hard
 * reject: create_from_upload() throws Unparseable_Upload_Exception and cleans up its own orphan row
 * (it is NOT transactional). Also covers the developer-facing extension allowlist helper and a
 * well-formed-image positive control.
 *
 * Fixture note: a corrupt-image fixture MUST be a TRUNCATED REAL PNG (a valid 8-byte PNG signature
 * + IHDR) so the raw byte sniff still reports image/png and the upload enters the image pipeline;
 * arbitrary bytes sniff as application/octet-stream and never reach the Imagick step being tested.
 */
class Image_Degrade_Test extends Rsx_Test_Abstract
{
    /**
     * Resolve a real seeded site id and set it as the session site so the site-scoped save hook and
     * the site FK on _file_attachments are both satisfied in the CLI test harness.
     */
    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        \App\RSpade\Core\Session\Session::set_site_id($id);
        return $id;
    }

    /** Valid PNG bytes for a small solid-color image (parses cleanly). */
    private static function __valid_png_bytes(): string
    {
        $img = new \Imagick();
        $img->newImage(8, 8, new \ImagickPixel('red'));
        $img->setImageFormat('png');
        $bytes = $img->getImageBlob();
        $img->destroy();

        return $bytes;
    }

    /**
     * A TRUNCATED real PNG: the 8-byte signature + the IHDR chunk, with the image data (IDAT) cut
     * off. Sniffs as image/png (so it enters the image pipeline) but ImageMagick cannot decode it.
     */
    private static function __truncated_png_bytes(): string
    {
        // 8-byte signature + 25-byte IHDR chunk (4 length + 4 type + 13 data + 4 CRC) = 33 bytes.
        return substr(static::__valid_png_bytes(), 0, 33);
    }

    /**
     * Build a test-mode UploadedFile over freshly-written bytes on disk. test=true bypasses the
     * is_uploaded_file() check; create_from_upload() reads the real path + sniffs the content.
     */
    private static function __uploaded_file(string $bytes, string $original_name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_upl_');
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, $original_name, null, null, true);
    }

    // --- degrade path (default config) -----------------------------------------------------------

    public static function test_unparseable_image_degrades_to_generic_file()
    {
        config(['rsx.attachments.reject_unparseable_images' => false]);

        $file = static::__uploaded_file(static::__truncated_png_bytes(), 'broken.png');
        $attachment = File_Attachment_Model::create_from_upload($file, ['site_id' => static::__site_id()]);

        // Read a fresh row (never ->refresh(): it trips the no-lazy-load guard).
        $fresh = File_Attachment_Model::find($attachment->id);
        static::__assert_not_null($fresh, 'degraded upload is accepted (row persists)');

        static::__assert_equals(1, (int) $fresh->preview_unavailable, 'degraded row flagged preview_unavailable');
        static::__assert_equals(
            File_Attachment_Model::FILE_TYPE_OTHER,
            (int) $fresh->file_type_id,
            'degraded row demoted to FILE_TYPE_OTHER'
        );
        static::__assert_null($fresh->width, 'no width extracted (Imagick never parsed it)');
        static::__assert_null($fresh->height, 'no height extracted');

        static::__assert_equals(
            'application/octet-stream',
            $fresh->pipeline_mime(),
            'pipeline_mime generalizes a degraded attachment to octet-stream'
        );
        static::__assert_false($fresh->is_image(), 'a degraded attachment is no longer an image');
        static::__assert_false($fresh->is_preview_available(), 'preview is not available on a degraded attachment');

        // The RAW served mime_type column is the Content-Type security boundary - it stays intact.
        static::__assert_equals('image/png', $fresh->mime_type, 'raw sniffed mime_type is left untouched');
    }

    // --- strict reject mode ----------------------------------------------------------------------

    public static function test_strict_mode_rejects_and_leaves_no_orphan()
    {
        config(['rsx.attachments.reject_unparseable_images' => true]);

        $site_id = static::__site_id();
        // Retention semantics: the reject rollback force_destroy()s its orphan, which leaves a
        // permanent audit TOMBSTONE row (destroyed_at set, blob claim released). "No orphan"
        // therefore means no LIVE-OR-RETAINED row - count only destroyed_at IS NULL.
        $count_before = (int) DB::selectOne('SELECT COUNT(*) AS c FROM _file_attachments WHERE destroyed_at IS NULL')->c;

        $file = static::__uploaded_file(static::__truncated_png_bytes(), 'broken.png');

        static::__assert_throws(
            Unparseable_Upload_Exception::class,
            function () use ($file, $site_id) {
                File_Attachment_Model::create_from_upload($file, ['site_id' => $site_id]);
            },
            'could not be parsed'
        );

        $count_after = (int) DB::selectOne('SELECT COUNT(*) AS c FROM _file_attachments WHERE destroyed_at IS NULL')->c;
        static::__assert_equals($count_before, $count_after, 'strict-mode reject leaves no live-or-retained orphan (tombstone only)');

        // Reset for any later methods (config is process-global; the DB is transaction-isolated).
        config(['rsx.attachments.reject_unparseable_images' => false]);
    }

    // --- extension allowlist API -----------------------------------------------------------------

    public static function test_is_allowed_extension_empty_allowlist_allows_all()
    {
        config(['rsx.attachments.allowed_extensions' => []]);

        static::__assert_true(File_Attachment_Model::is_allowed_extension('png'), 'empty allowlist allows png');
        static::__assert_true(File_Attachment_Model::is_allowed_extension('exe'), 'empty allowlist allows exe');
        static::__assert_true(File_Attachment_Model::is_allowed_extension('.anything'), 'empty allowlist allows all');
    }

    public static function test_is_allowed_extension_populated_allowlist()
    {
        config(['rsx.attachments.allowed_extensions' => ['png', 'PDF', '.docx']]);

        // Case-insensitive.
        static::__assert_true(File_Attachment_Model::is_allowed_extension('PNG'), 'match is case-insensitive (PNG)');
        static::__assert_true(File_Attachment_Model::is_allowed_extension('pdf'), 'match is case-insensitive (pdf)');

        // Dot-tolerant on the argument AND on the configured entry (.docx).
        static::__assert_true(File_Attachment_Model::is_allowed_extension('.png'), 'leading dot on argument tolerated');
        static::__assert_true(File_Attachment_Model::is_allowed_extension('docx'), 'leading dot on config entry tolerated');

        // Denies anything not listed.
        static::__assert_false(File_Attachment_Model::is_allowed_extension('exe'), 'unlisted extension denied');
        static::__assert_false(File_Attachment_Model::is_allowed_extension('gif'), 'unlisted extension denied');

        // Reset.
        config(['rsx.attachments.allowed_extensions' => []]);
    }

    // --- positive control: a well-formed image still processes normally ---------------------------

    public static function test_well_formed_image_processes_normally()
    {
        config(['rsx.attachments.reject_unparseable_images' => false]);

        $file = static::__uploaded_file(static::__valid_png_bytes(), 'good.png');
        $attachment = File_Attachment_Model::create_from_upload($file, ['site_id' => static::__site_id()]);

        $fresh = File_Attachment_Model::find($attachment->id);
        static::__assert_not_null($fresh, 'well-formed image is accepted');

        static::__assert_equals(0, (int) $fresh->preview_unavailable, 'well-formed image is not flagged unavailable');
        static::__assert_equals(
            File_Attachment_Model::FILE_TYPE_IMAGE,
            (int) $fresh->file_type_id,
            'well-formed image keeps FILE_TYPE_IMAGE'
        );
        static::__assert_equals(8, (int) $fresh->width, 'dimensions extracted (width)');
        static::__assert_equals(8, (int) $fresh->height, 'dimensions extracted (height)');
        static::__assert_true($fresh->is_image(), 'well-formed image reports is_image');
        static::__assert_true($fresh->is_preview_available(), 'preview available for a well-formed image');
    }
}
