<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE HUMAN-FACING TYPE LABEL - File_Attachment_Model::file_type_label_for(), the
 * file_type_label column the model writes on every save, and the regeneration walk.
 *
 * file_type_id is a seven-value engine enum, so a Type column built from it reads
 * "Document" for a PDF, a Word file, a workbook and a CSV alike. file_type_label is the
 * format name a person recognises, stored and indexed so a listing can sort on it.
 *
 * Proves: the derivation table across the document / image / archive families; that the
 * label is decided on the PIPELINE mime, so a .docx sniffing as application/zip is a
 * "Word Document" and a webp saved as .png is a "WebP Image" (the sniff wins, exactly as
 * it does for file_type_id); the generic fallbacks for an unknown extension and for no
 * extension at all; that the MODEL writes the column on every save and overwrites whatever
 * a caller assigned; that regenerate_file_type_labels() repairs stale rows including
 * trashed ones and reports only the rows it actually changed; and that the label rides the
 * attachment payload.
 */
class File_Type_Label_Test extends Rsx_Test_Abstract
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /**
     * A real seeded site id, set as the session site so the site-scoped save hook and the
     * site FK are both satisfied in the CLI harness.
     */
    private static function __site_id(): int
    {
        $id = (int) Site_Model::where('id', '>', 0)->orderBy('id')->value('id');
        Session::set_site_id($id);

        return $id;
    }

    private static function __make(string $file_name): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string(
            'label-' . uniqid(),
            $file_name,
            ['site_id' => static::__site_id()]
        );
    }

    // --- file_type_label_for: the derivation table ----------------------------------------------

    /** The documents a listing most needs to tell apart - all four are file_type_id DOCUMENT. */
    public static function test_document_family_is_distinguished()
    {
        static::__assert_equals(
            'PDF Document',
            File_Attachment_Model::file_type_label_for('application/pdf', 'pdf'),
            'a pdf is a PDF Document'
        );
        static::__assert_equals(
            'Word Document',
            File_Attachment_Model::file_type_label_for(self::DOCX_MIME, 'docx'),
            'a docx is a Word Document'
        );
        static::__assert_equals(
            'Excel Spreadsheet',
            File_Attachment_Model::file_type_label_for(self::XLSX_MIME, 'xlsx'),
            'an xlsx is an Excel Spreadsheet'
        );
        static::__assert_equals(
            'PowerPoint Presentation',
            File_Attachment_Model::file_type_label_for(self::PPTX_MIME, 'pptx'),
            'a pptx is a PowerPoint Presentation'
        );
        static::__assert_equals(
            'CSV File',
            File_Attachment_Model::file_type_label_for('text/plain', 'csv'),
            'a csv is a CSV File, not a Text File - the document extension resolves it to text/csv'
        );
        static::__assert_equals(
            'Text File',
            File_Attachment_Model::file_type_label_for('text/plain', 'txt'),
            'a txt is a Text File'
        );
    }

    /**
     * THE POINT OF DERIVING FROM THE PIPELINE MIME. libmagic's OOXML sniff is per-file
     * flaky - a perfectly valid .docx routinely sniffs as application/zip - and a label
     * built on the raw sniff would call it a ZIP Archive.
     */
    public static function test_docx_sniffed_as_zip_is_still_a_word_document()
    {
        static::__assert_equals(
            'Word Document',
            File_Attachment_Model::file_type_label_for('application/zip', 'docx'),
            'the document extension wins over a zip sniff, as it does for file_type_id'
        );
    }

    /** And the other half of the same policy: for images the SNIFF wins over the extension. */
    public static function test_webp_saved_as_png_is_a_webp_image()
    {
        static::__assert_equals(
            'WebP Image',
            File_Attachment_Model::file_type_label_for('image/webp', 'png'),
            'a webp saved as .png is a WebP Image - the image sniff is unambiguous'
        );
    }

    public static function test_image_and_archive_families()
    {
        static::__assert_equals(
            'JPEG Image',
            File_Attachment_Model::file_type_label_for('image/jpeg', 'jpg'),
            'a jpg is a JPEG Image'
        );
        static::__assert_equals(
            'PNG Image',
            File_Attachment_Model::file_type_label_for('image/png', 'png'),
            'a png is a PNG Image'
        );
        static::__assert_equals(
            'ZIP Archive',
            File_Attachment_Model::file_type_label_for('application/zip', 'zip'),
            'a genuine zip is a ZIP Archive'
        );
        static::__assert_equals(
            '7-Zip Archive',
            File_Attachment_Model::file_type_label_for('application/x-7z-compressed', '7z'),
            'a 7z is a 7-Zip Archive'
        );
    }

    /**
     * THE CONTAINER FORMATS. A macro-enabled workbook, a Keynote deck, a Java archive and a
     * KeePass database all sniff as the ZIP or the opaque binary they are built on; the
     * extension is the only thing that says what they are, and it wins over that sniff.
     * The macro formats are also document extensions, so their pipeline mime resolves and
     * the mime table answers for them exactly as it does for .docx.
     */
    public static function test_container_formats_are_named_by_extension()
    {
        static::__assert_equals(
            'Excel Spreadsheet (Macro-Enabled)',
            File_Attachment_Model::file_type_label_for('application/zip', 'xlsm'),
            'an xlsm sniffing as a zip is a macro-enabled workbook'
        );
        static::__assert_equals(
            'Word Document (Macro-Enabled)',
            File_Attachment_Model::file_type_label_for('application/zip', 'docm'),
            'a docm likewise'
        );
        static::__assert_equals(
            'Excel Binary Spreadsheet',
            File_Attachment_Model::file_type_label_for('application/zip', 'xlsb'),
            'an xlsb is named by extension - it is not a document extension, so the sniff is not authoritative'
        );
        static::__assert_equals(
            'Keynote Presentation',
            File_Attachment_Model::file_type_label_for('application/zip', 'keynote'),
            'a Keynote package sniffing as a zip'
        );
        static::__assert_equals(
            'Java Archive',
            File_Attachment_Model::file_type_label_for('application/zip', 'jar'),
            'a jar sniffing as a zip'
        );
        static::__assert_equals(
            'KeePass Database',
            File_Attachment_Model::file_type_label_for('application/octet-stream', 'kdbx'),
            'an opaque binary named by its extension'
        );
        static::__assert_equals(
            'Windows Executable',
            File_Attachment_Model::file_type_label_for('application/x-dosexec', 'exe'),
            'an exe by either signal'
        );
        static::__assert_equals(
            'ZIP Archive',
            File_Attachment_Model::file_type_label_for('application/zip', 'zip'),
            'and a genuine zip is still a ZIP Archive'
        );
    }

    /** .key is shared by a Keynote deck and a private key, so it is answered by the sniff alone. */
    public static function test_an_ambiguous_extension_is_not_named_by_extension()
    {
        static::__assert_equals(
            'ZIP Archive',
            File_Attachment_Model::file_type_label_for('application/zip', 'key'),
            'a .key sniffing as a zip is reported as what the bytes are'
        );
        static::__assert_equals(
            'KEY File',
            File_Attachment_Model::file_type_label_for('application/octet-stream', 'key'),
            'and an opaque .key gets the generic form'
        );
    }

    /**
     * The generic forms. A format the tables have never heard of still gets a label a person
     * can read, and a file with no extension at all is simply a File.
     */
    public static function test_generic_fallbacks()
    {
        static::__assert_equals(
            'XYZ File',
            File_Attachment_Model::file_type_label_for('application/octet-stream', 'xyz'),
            'an unknown extension is uppercased into the generic form'
        );
        static::__assert_equals(
            'TMP File',
            File_Attachment_Model::file_type_label_for('application/octet-stream', 'tmp'),
            'so is an extension the tables deliberately do not name'
        );
        static::__assert_equals(
            'File',
            File_Attachment_Model::file_type_label_for('application/octet-stream', ''),
            'no extension at all is the bare word File'
        );
        static::__assert_equals(
            'File',
            File_Attachment_Model::file_type_label_for(null, null),
            'nulls are tolerated and answer the same'
        );
    }

    // --- the column is written by the MODEL, on every save --------------------------------------

    /** A row created through a factory arrives labelled; nothing had to ask for it. */
    public static function test_create_from_string_labels_the_row()
    {
        $attachment = static::__make('notes.txt');

        static::__assert_equals(
            'Text File',
            $attachment->file_type_label,
            'the created attachment carries its label'
        );

        $reloaded = File_Attachment_Model::find($attachment->id);
        static::__assert_equals(
            'Text File',
            $reloaded->file_type_label,
            'and the label is in the database, not only on the instance'
        );
    }

    /**
     * EVERY save, not only the create. repoint_storage() and the re-derivation paths change
     * mime_type / file_extension on an EXISTING row, and the label is a projection of exactly
     * those two columns.
     */
    public static function test_save_re_derives_the_label_when_the_bytes_change_identity()
    {
        $attachment = static::__make('notes.txt');

        $attachment->file_extension = 'pdf';
        $attachment->mime_type = 'application/pdf';
        $attachment->save();

        static::__assert_equals(
            'PDF Document',
            File_Attachment_Model::find($attachment->id)->file_type_label,
            'the label follows the mime and extension on an update'
        );
    }

    /** The caller supplies the bytes; the model derives the label. A caller that sets it loses. */
    public static function test_a_caller_assigned_label_is_overwritten()
    {
        $attachment = static::__make('notes.txt');

        $attachment->file_type_label = 'Whatever I Like';
        $attachment->save();

        static::__assert_equals(
            'Text File',
            File_Attachment_Model::find($attachment->id)->file_type_label,
            'save() overwrites a hand-assigned label with the derived one'
        );
    }

    // --- regeneration ---------------------------------------------------------------------------

    /**
     * THE MAINTENANCE CONTRACT. Changing the map leaves every stored label stale, so the
     * change ships a migration whose whole body is this call. The walk covers TRASHED rows
     * too - the label is a property of the bytes, not of the row's state - and reports only
     * the rows it actually changed, so a second run is a no-op.
     */
    public static function test_regeneration_repairs_stale_rows_including_trashed_ones()
    {
        $live = static::__make('report.pdf');
        $trashed = static::__make('sheet.txt');
        $trashed->delete();

        DB::table('_file_attachments')
            ->whereIn('id', [$live->id, $trashed->id])
            ->update(['file_type_label' => 'Stale Value']);

        $changed = File_Attachment_Model::regenerate_file_type_labels();

        static::__assert_equals(2, $changed, 'both stale rows were repaired, the trashed one included');

        $rows = DB::table('_file_attachments')
            ->whereIn('id', [$live->id, $trashed->id])
            ->orderBy('id')
            ->pluck('file_type_label', 'id');

        static::__assert_equals(
            'PDF Document',
            $rows[$live->id],
            'the live row was recomputed'
        );
        static::__assert_equals(
            'Text File',
            $rows[$trashed->id],
            'the soft-deleted row was recomputed too'
        );

        static::__assert_equals(
            0,
            File_Attachment_Model::regenerate_file_type_labels(),
            'a second run writes nothing - only a row whose label actually differs is touched'
        );
    }

    // --- the payload ------------------------------------------------------------------------------

    /**
     * The column is an ordinary column, so it rides toArray() - the payload every fetch(),
     * relationship and upload response is built from - with no $appends entry and no per-app
     * plumbing. field_length() answers for it because it is in the schema.
     */
    public static function test_the_label_rides_the_attachment_payload()
    {
        $attachment = static::__make('contract.pdf');
        $payload = $attachment->toArray();

        static::__assert_array_has_key(
            'file_type_label',
            $payload,
            'file_type_label is in the serialized attachment'
        );
        static::__assert_equals(
            'PDF Document',
            $payload['file_type_label'],
            'and carries the derived value'
        );
        static::__assert_equals(
            64,
            File_Attachment_Model::field_length('file_type_label'),
            'the JS side gets the column length from the schema, not from a restated number'
        );
    }
}
