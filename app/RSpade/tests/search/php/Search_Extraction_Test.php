<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Search\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Libreoffice;
use App\RSpade\Core\Search\Libreoffice_Text_Extractor;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Search\Search_Index_Service;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * End-to-end extraction pipeline: status classification (extracted / unsupported / failed),
 * one-row-per-dedup-blob, the FULLTEXT search_text() roundtrip, the reindex re-queue, and the
 * real binary extractors (pdftotext / soffice, probed + skipped when absent).
 *
 * This class COMMITS (the InnoDB FULLTEXT index does not see uncommitted rows, so the search
 * roundtrip cannot run inside a transaction). It therefore declares $requires_db_reset so the
 * runner provisions a clean baseline before the class and restores it afterward. Created
 * attachments are deleted in teardown to unlink their on-disk blobs.
 */
class Search_Extraction_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    public static function setup()
    {
        // Extract by direct call in these tests; disabling the master switch keeps create-time
        // kicks from spawning real task workers.
        config(['rsx.search.enabled' => false]);
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

        config(['rsx.search.enabled' => true]);
    }

    /**
     * Resolve a real seeded site id and set it as the session site, so both the site-scoped save
     * hook and the site FK on _file_attachments are satisfied in the CLI test harness.
     */
    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    /**
     * Create a tracked attachment from raw bytes.
     */
    private static function __make_attachment(string $content, string $filename): File_Attachment_Model
    {
        $attachment = File_Attachment_Model::create_from_string($content, $filename, ['site_id' => static::__site_id()]);
        static::$created_attachment_ids[] = $attachment->id;

        return $attachment;
    }

    /**
     * Run the extraction unit of work for an attachment's blob.
     */
    private static function __extract(File_Attachment_Model $attachment): Search_Index_Model
    {
        $storage = File_Storage_Model::find($attachment->file_storage_id);

        return Search_Index_Service::extract_storage($storage);
    }

    public static function test_plain_text_extraction()
    {
        $needle = 'plainneedle' . substr(md5((string) microtime(true)), 0, 8);
        $attachment = static::__make_attachment("intro paragraph {$needle} and closing", 'plain_e2e.txt');

        $index = static::__extract($attachment);
        static::__assert_equals(Search_Index_Model::STATUS_EXTRACTED, $index->status_id, 'plain text -> EXTRACTED');
        static::__assert_equals('Plain_Text_Extractor', $index->extraction_method, 'routed to the plain-text extractor');

        $fresh = File_Attachment_Model::find($attachment->id);
        static::__assert_equals(Search_Index_Model::STATUS_EXTRACTED, $fresh->get_extraction_status(), 'status readable via the attachment');
        static::__assert_contains($needle, (string) $fresh->get_extracted_text(), 'extracted text recovered via the attachment');
    }

    public static function test_unsupported_mime()
    {
        // Leading NUL bytes force mime_content_type to application/octet-stream (no extractor).
        $content = str_repeat("\x00\x01\x02\x03", 8) . random_bytes(24);
        $attachment = static::__make_attachment($content, 'blob_unsup.bin');

        $index = static::__extract($attachment);
        static::__assert_equals(Search_Index_Model::STATUS_UNSUPPORTED, $index->status_id, 'unknown mime -> UNSUPPORTED (not FAILED)');
    }

    public static function test_over_cap_text_truncates()
    {
        // Over-cap plain text is TRUNCATED and RECORDED, not FAILED (recorded truncation is not
        // silent truncation).
        config(['rsx.search.max_text_bytes' => 16]);

        try {
            $body = 'this text is definitely longer than sixteen bytes ' . uniqid();
            $attachment = static::__make_attachment($body, 'overcap.txt');
            $original_bytes = strlen($body);

            $index = static::__extract($attachment);

            static::__assert_equals(Search_Index_Model::STATUS_EXTRACTED, $index->status_id, 'over-cap plain text -> EXTRACTED (truncated, not FAILED)');
            static::__assert_null($index->error, 'a truncated extraction is not an error');
            static::__assert_less_than(17, strlen($index->content), 'content is capped to max_text_bytes');

            $metadata = $index->get_metadata();
            static::__assert_not_null($metadata, 'truncation is recorded in metadata');
            static::__assert_true($metadata['truncated'] === true, 'metadata flags the truncation');
            static::__assert_equals($original_bytes, $metadata['original_bytes'], 'metadata records the true original source size');
        } finally {
            config(['rsx.search.max_text_bytes' => 2 * 1024 * 1024]);
        }
    }

    public static function test_dedup_single_index_row()
    {
        $content = 'shared dedup content ' . substr(md5((string) microtime(true)), 0, 8);
        $a1 = static::__make_attachment($content, 'dedup_a.txt');
        $a2 = static::__make_attachment($content, 'dedup_b.txt');

        static::__assert_equals($a1->file_storage_id, $a2->file_storage_id, 'identical bytes deduplicate to one blob');

        static::__extract($a1);
        $count = Search_Index_Model::forModel('File_Storage_Model', $a1->file_storage_id)->count();
        static::__assert_equals(1, $count, 'exactly one index row per unique blob');

        // Re-extracting upserts the same row (idempotent), never a duplicate.
        static::__extract($a1);
        $count_again = Search_Index_Model::forModel('File_Storage_Model', $a1->file_storage_id)->count();
        static::__assert_equals(1, $count_again, 'idempotent upsert keeps a single row');

        $a2_fresh = File_Attachment_Model::find($a2->id);
        static::__assert_equals(Search_Index_Model::STATUS_EXTRACTED, $a2_fresh->get_extraction_status(), 'the second attachment shares the one extraction');
    }

    public static function test_search_text_roundtrip()
    {
        $needle = 'searchneedle' . substr(md5((string) microtime(true)), 0, 8);
        $attachment = static::__make_attachment("a report mentioning {$needle} among other prose", 'roundtrip.txt');

        static::__extract($attachment);

        $hit_ids = File_Attachment_Model::search_text($needle)->pluck('id')->all();
        static::__assert_true(in_array($attachment->id, $hit_ids, true), 'search_text() resolves the attachment by its extracted text');

        $miss_ids = File_Attachment_Model::search_text('absentxyztoken')->pluck('id')->all();
        static::__assert_false(in_array($attachment->id, $miss_ids, true), 'an unrelated query does not match');
    }

    public static function test_reindex_failed_requeue()
    {
        // Force a genuine FAILED row: delete the on-disk blob so extraction hits the
        // "source blob missing" fault (a real, retryable failure - unlike over-cap, which is now
        // a recorded truncation, or an encrypted doc, which is UNSUPPORTED).
        $attachment = static::__make_attachment('requeue fixture ' . uniqid(), 'requeue.txt');
        $storage = File_Storage_Model::find($attachment->file_storage_id);
        @unlink($storage->get_full_path());

        $index = static::__extract($attachment);
        static::__assert_equals(Search_Index_Model::STATUS_FAILED, $index->status_id, 'a missing blob -> FAILED');

        $storage_id = $attachment->file_storage_id;
        $before = File_Storage_Model::find($storage_id);
        static::__assert_equals(1, (int) $before->is_indexed, 'the blob is marked indexed after the attempt');

        Artisan::call('rsx:search:reindex', ['--failed' => true]);

        $after = File_Storage_Model::find($storage_id);
        static::__assert_equals(0, (int) $after->is_indexed, 'reindex --failed re-queued the blob (is_indexed back to 0)');
    }

    public static function test_storage_delete_cascades_index_row()
    {
        $content = 'cascade fixture content ' . substr(md5((string) microtime(true)), 0, 8);
        $attachment = static::__make_attachment($content, 'cascade.txt');
        $storage_id = $attachment->file_storage_id;

        static::__extract($attachment);
        static::__assert_equals(1, Search_Index_Model::forModel('File_Storage_Model', $storage_id)->count(), 'index row exists after extraction');

        // Permanently destroying the last-referencing attachment releases the storage row,
        // which must take its extracted-text index row with it (no unreachable cruft).
        // force_destroy(), not delete(): a plain delete() now only SOFT-deletes into the
        // retention window and the blob stays pinned (see rsx:man file_disposal).
        $attachment->force_destroy();
        static::$created_attachment_ids = array_values(array_diff(static::$created_attachment_ids, [$attachment->id]));

        static::__assert_null(File_Storage_Model::find($storage_id), 'storage row removed with its last attachment');
        static::__assert_equals(0, Search_Index_Model::forModel('File_Storage_Model', $storage_id)->count(), 'index row cascaded with the storage row');
    }

    public static function test_pdf_extraction_integration()
    {
        $binary = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v pdftotext 2>/dev/null')));
        if ($binary === '' || !is_executable($binary)) {
            static::__skip('pdftotext (poppler-utils) not installed');
            return;
        }

        $token = 'pdfneedle' . substr(md5((string) microtime(true)), 0, 6);
        $attachment = static::__make_attachment(static::__minimal_pdf($token), 'sample.pdf');

        $index = static::__extract($attachment);
        static::__assert_equals(Search_Index_Model::STATUS_EXTRACTED, $index->status_id, 'PDF with a text layer -> EXTRACTED');
        static::__assert_equals('Pdftotext_Text_Extractor', $index->extraction_method, 'routed to pdftotext by mime');
        static::__assert_contains($token, $index->content, 'PDF text layer recovered');
    }

    public static function test_office_extraction_integration()
    {
        if (Libreoffice::find_soffice() === null) {
            static::__skip('LibreOffice (soffice) not installed');
            return;
        }

        $token = 'docneedle' . substr(md5((string) microtime(true)), 0, 6);
        $path = sys_get_temp_dir() . '/rsx_office_test_' . uniqid() . '.fodt';
        file_put_contents($path, static::__minimal_fodt($token));

        try {
            $text = Libreoffice_Text_Extractor::extract($path, 'application/vnd.oasis.opendocument.text');
            static::__assert_contains($token, $text, 'office document text recovered via headless soffice');
        } finally {
            @unlink($path);
        }
    }

    public static function test_spreadsheet_multi_sheet_extraction()
    {
        if (Libreoffice::find_soffice() === null) {
            static::__skip('LibreOffice (soffice) not installed');
            return;
        }

        // A 2-sheet workbook: the OLD Writer 'txt:Text (encoded)' filter produced NO output for a
        // Calc document (every spreadsheet FAILED). The fods path must recover BOTH sheets - names
        // AND cell tokens - proving the all-sheets, all-cells extraction.
        $alpha = 'alphacell' . substr(md5((string) microtime(true)), 0, 6);
        $beta = 'betacell' . substr(md5((string) microtime(true) . 'b'), 0, 6);
        $path = sys_get_temp_dir() . '/rsx_calc_test_' . uniqid() . '.fods';
        file_put_contents($path, static::__minimal_fods($alpha, $beta));

        try {
            $text = Libreoffice_Text_Extractor::extract($path, 'application/vnd.oasis.opendocument.spreadsheet');
            static::__assert_contains('SheetAlpha', $text, 'first sheet name present');
            static::__assert_contains('SheetBeta', $text, 'second sheet name present');
            static::__assert_contains($alpha, $text, 'first sheet cell token present');
            static::__assert_contains($beta, $text, 'second sheet cell token present (all sheets extracted)');
        } finally {
            @unlink($path);
        }
    }

    public static function test_presentation_extraction()
    {
        if (Libreoffice::find_soffice() === null) {
            static::__skip('LibreOffice (soffice) not installed');
            return;
        }

        // The Writer 'txt:Text (encoded)' filter ALSO produces no output for Impress documents, so
        // presentations are routed through fodp flat XML and their slide text:p nodes extracted.
        $token = 'slideneedle' . substr(md5((string) microtime(true)), 0, 6);
        $path = sys_get_temp_dir() . '/rsx_impress_test_' . uniqid() . '.fodp';
        file_put_contents($path, static::__minimal_fodp($token));

        try {
            $text = Libreoffice_Text_Extractor::extract($path, 'application/vnd.oasis.opendocument.presentation');
            static::__assert_contains($token, $text, 'presentation slide text recovered via fodp flat XML');
        } finally {
            @unlink($path);
        }
    }

    public static function test_encrypted_pdf_unsupported()
    {
        $pdftotext = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v pdftotext 2>/dev/null')));
        $gs = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v gs 2>/dev/null')));
        if ($pdftotext === '' || !is_executable($pdftotext)) {
            static::__skip('pdftotext (poppler-utils) not installed');
            return;
        }
        if ($gs === '' || !is_executable($gs)) {
            static::__skip('ghostscript (gs) not installed - cannot author an encrypted PDF fixture');
            return;
        }

        // Author a normal PDF, then encrypt it with a user (open) password via ghostscript.
        $token = 'encpdf' . substr(md5((string) microtime(true)), 0, 6);
        $plain = sys_get_temp_dir() . '/rsx_encpdf_src_' . uniqid() . '.pdf';
        $encrypted = sys_get_temp_dir() . '/rsx_encpdf_out_' . uniqid() . '.pdf';
        file_put_contents($plain, static::__minimal_pdf($token));

        $enc_bytes = null;
        try {
            $cmd = escapeshellarg($gs) . ' -q -dBATCH -dNOPAUSE -sDEVICE=pdfwrite'
                . ' -sOwnerPassword=ownerpw -sUserPassword=userpw -dEncryptionR=3 -dKeyLength=128'
                . ' -o ' . escapeshellarg($encrypted) . ' ' . escapeshellarg($plain) . ' 2>/dev/null';
            shell_exec('bash -c ' . escapeshellarg($cmd));

            if (!is_file($encrypted) || filesize($encrypted) === 0) {
                static::__skip('ghostscript did not produce an encrypted PDF fixture');
                return;
            }
            $enc_bytes = file_get_contents($encrypted);
        } finally {
            @unlink($plain);
            @unlink($encrypted);
        }

        $attachment = static::__make_attachment((string) $enc_bytes, 'encrypted.pdf');
        $index = static::__extract($attachment);

        static::__assert_equals(Search_Index_Model::STATUS_UNSUPPORTED, $index->status_id, 'encrypted PDF -> UNSUPPORTED (not FAILED)');
        static::__assert_not_null($index->error, 'the UNSUPPORTED reason is recorded');
        static::__assert_contains('password', strtolower((string) $index->error), 'the reason names the password protection');
    }

    public static function test_truncation_metadata_roundtrip()
    {
        // A source larger than a small cap: Plain returns only the byte-prefix and the service
        // records the TRUE original size in metadata. Distinct from test_over_cap_text_truncates
        // in that it asserts the get_metadata() JSON roundtrip through a fresh attachment read.
        config(['rsx.search.max_text_bytes' => 64]);

        try {
            $body = str_repeat('lorem ipsum dolor sit amet ', 20);  // ~540 bytes, well over 64
            $attachment = static::__make_attachment($body, 'trunc_roundtrip.txt');

            $index = static::__extract($attachment);
            static::__assert_equals(Search_Index_Model::STATUS_EXTRACTED, $index->status_id, 'oversized source -> EXTRACTED (truncated)');

            $fresh = Search_Index_Model::forModel('File_Storage_Model', $attachment->file_storage_id)->first();
            $metadata = $fresh->get_metadata();
            static::__assert_not_null($metadata, 'metadata persisted and re-read');
            static::__assert_array_has_key('truncated', $metadata, 'truncation flag present');
            static::__assert_true($metadata['truncated'] === true, 'truncation flag is true');
            static::__assert_equals(strlen($body), $metadata['original_bytes'], 'original_bytes equals the true source size');
        } finally {
            config(['rsx.search.max_text_bytes' => 2 * 1024 * 1024]);
        }
    }

    /**
     * Build a minimal single-page PDF with a text layer containing $token.
     */
    private static function __minimal_pdf(string $token): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objs[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
        $stream = "BT /F1 24 Tf 72 700 Td (Hello extraction {$token}) Tj ET";
        $objs[4] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        $objs[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }

        $xref_pos = strlen($pdf);
        $count = count($objs) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref_pos}\n%%EOF";

        return $pdf;
    }

    /**
     * Build a minimal flat-ODF (.fodt) text document containing $token. soffice reads FODT as a
     * native Writer format, so no zip authoring is needed.
     */
    private static function __minimal_fodt(string $token): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' office:version="1.2" office:mimetype="application/vnd.oasis.opendocument.text">' . "\n"
            . ' <office:body><office:text><text:p>Hello office ' . $token . ' more words here</text:p></office:text></office:body>' . "\n"
            . '</office:document>' . "\n";
    }

    /**
     * Build a minimal 2-sheet flat-ODF spreadsheet (.fods). Sheets are named SheetAlpha / SheetBeta
     * and carry the given cell tokens, so the extractor's all-sheets output can be asserted.
     */
    private static function __minimal_fods(string $alpha_token, string $beta_token): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' office:version="1.2" office:mimetype="application/vnd.oasis.opendocument.spreadsheet">' . "\n"
            . ' <office:body><office:spreadsheet>' . "\n"
            . '  <table:table table:name="SheetAlpha">'
            . '<table:table-row><table:table-cell office:value-type="string"><text:p>' . $alpha_token . '</text:p></table:table-cell>'
            . '<table:table-cell office:value-type="string"><text:p>hello</text:p></table:table-cell></table:table-row>'
            . '</table:table>' . "\n"
            . '  <table:table table:name="SheetBeta">'
            . '<table:table-row><table:table-cell office:value-type="string"><text:p>' . $beta_token . '</text:p></table:table-cell></table:table-row>'
            . '</table:table>' . "\n"
            . ' </office:spreadsheet></office:body>' . "\n"
            . '</office:document>' . "\n";
    }

    /**
     * Build a minimal single-slide flat-ODF presentation (.fodp) whose slide carries $token.
     */
    private static function __minimal_fodp(string $token): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' office:version="1.2" office:mimetype="application/vnd.oasis.opendocument.presentation">' . "\n"
            . ' <office:body><office:presentation>' . "\n"
            . '  <draw:page draw:name="Slide1"><draw:frame><draw:text-box>'
            . '<text:p>' . $token . ' slide body content</text:p>'
            . '</draw:text-box></draw:frame></draw:page>' . "\n"
            . ' </office:presentation></office:body>' . "\n"
            . '</office:document>' . "\n";
    }
}
