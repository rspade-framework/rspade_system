<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\Exif_Gps;

/**
 * Zip_Stream - a hand-rolled streaming ZIP container writer (PKWARE APPNOTE).
 *
 * Emits a ZIP archive member-by-member with CONSTANT memory regardless of file size:
 * each member is read in fixed chunks, its CRC-32 accumulated incrementally
 * (hash_init('crc32b')) and its bytes compressed incrementally (deflate_init with
 * ZLIB_ENCODING_RAW), so a multi-gigabyte source never lands in memory. No composer or
 * apt dependency is used - pure PHP zlib + hashing.
 *
 * Every member is written in "data descriptor" mode (general-purpose flag bit 3): the
 * true CRC-32 + compressed + uncompressed sizes are appended AFTER the bytes. This is what
 * lets us stream a source whose length is not known up front. Filenames are UTF-8 (flag
 * bit 11) with forward slashes.
 *
 * EVERY archive is ZIP64-framed, whatever its size - one layout, no per-member decision,
 * so there is no archive size, member size or entry count the writer cannot encode. The
 * framing follows PKWARE APPNOTE 6.3.x:
 *
 *   - Local file header (4.3.7): version needed 4.5 (4.4.3.2, ZIP64), CRC-32 zero and both
 *     32-bit sizes 0xFFFFFFFF, followed by a ZIP64 extended information extra field
 *     (header 0x0001, 4.5.3) holding the original then the compressed size, both ZERO.
 *     4.5.3 requires the local ZIP64 field to carry BOTH sizes, and allows a field to
 *     appear only when its 32-bit counterpart is 0xFFFFFFFF - hence the 0xFFFFFFFF
 *     markers; the zero values are the bit-3 "not known here" sizes of 4.4.4.
 *   - Data descriptor (4.3.9): signature, CRC-32, then the compressed and uncompressed
 *     sizes as 8-byte values, which 4.3.9.2 prescribes for an entry whose local header
 *     carries the ZIP64 field.
 *   - Central directory header (4.3.12): version needed 4.5, both sizes and the local
 *     header offset 0xFFFFFFFF, with a ZIP64 extra field carrying all three as 8-byte
 *     values in the fixed order original size, compressed size, local header offset.
 *   - ZIP64 end of central directory record (4.3.14) + locator (4.3.15), then the classic
 *     end of central directory record (4.3.16) with its counts 0xFFFF and its size and
 *     offset 0xFFFFFFFF, directing the reader to the ZIP64 record (4.4.1.4).
 *
 * Readers: Windows 11 Explorer, macOS, 7-Zip, Info-ZIP unzip, libarchive (bsdtar) and
 * Python's zipfile all read this layout. The Windows 10 built-in Explorer extractor, which
 * does not read ZIP64, is not a target.
 *
 * Memory: each member streams in constant memory; the central directory is held as one
 * small record per member until finish() writes it.
 *
 * Byte layout produced (per member):
 *   local file header (+ ZIP64 extra) -> [compressed data chunks] -> data descriptor
 * Then once at finish(): central directory records -> ZIP64 end-of-central-directory
 * record -> ZIP64 end-of-central-directory locator -> end-of-central-directory record.
 *
 * Usage:
 *   $zip = new Zip_Stream();
 *   $zip->open();                                  // echo + flush() per chunk (streaming)
 *   $zip->add_file_from_path('a/b.pdf', $abs, $mime);
 *   $zip->add_empty_entry('~ERROR~gone.pdf.inf');
 *   $zip->finish();
 *
 * For tests, pass an emit callback that writes to a stream instead of echoing:
 *   $zip->open(function (string $bytes) use ($fh) { fwrite($fh, $bytes); });
 */
#[Instantiatable]
class Zip_Stream
{
    // ZIP record signatures.
    private const SIG_LOCAL = 0x04034b50;
    private const SIG_DATA_DESCRIPTOR = 0x08074b50;
    private const SIG_CENTRAL = 0x02014b50;
    private const SIG_ZIP64_EOCD = 0x06064b50;
    private const SIG_ZIP64_LOCATOR = 0x07064b50;
    private const SIG_EOCD = 0x06054b50;

    // ZIP64 extended information extra field header id (APPNOTE 4.5.3).
    private const EXTRA_ZIP64 = 0x0001;

    // The 32-bit / 16-bit "value is in the ZIP64 record" markers (APPNOTE 4.4.1.4).
    private const MARK_32 = 0xFFFFFFFF;
    private const MARK_16 = 0xFFFF;

    // Compression methods.
    private const METHOD_STORE = 0;
    private const METHOD_DEFLATE = 8;

    // General-purpose flag: bit 3 (data descriptor) + bit 11 (UTF-8 filename).
    private const GP_FLAG = 0x0808;

    // Version made by / needed to extract: 4.5 (ZIP64, APPNOTE 4.4.3.2). Made by host 0
    // (MS-DOS attribute compatibility; external attributes are zero).
    private const VERSION = 45;

    // Read size for streaming a source file: 512KB.
    private const CHUNK_SIZE = 524288;

    /**
     * Mime types whose bytes are already compressed - stored (method 0) rather than
     * deflated, since re-deflating wastes CPU for no size gain. Everything else (text,
     * office xml, pdf, ...) is deflated.
     *
     * @var string[]
     */
    private const STORE_MIMES = [
        'application/zip',
        'application/x-zip-compressed',
        'application/gzip',
        'application/x-gzip',
        'application/x-7z-compressed',
        'application/x-rar-compressed',
        'application/vnd.rar',
        'application/x-rar',
        'application/x-bzip2',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/x-msvideo',
        'video/quicktime',
    ];

    /**
     * The output sink. Receives each emitted byte string.
     *
     * @var callable(string): void
     */
    private $emit;

    /**
     * Running byte offset from the start of the archive (also the offset at which the
     * next local header begins).
     *
     * @var int
     */
    private int $offset = 0;

    /**
     * Central-directory records accumulated per member, written all at once by finish().
     *
     * @var array<int, array{name:string, method:int, crc:int, comp:int, uncomp:int, offset:int}>
     */
    private array $central = [];

    /**
     * DOS time/date fields (from open() time), applied to every member.
     *
     * @var int
     */
    private int $dos_time = 0;
    private int $dos_date = 0;

    private bool $is_open = false;
    private bool $is_finished = false;

    /**
     * Begin the archive. With no callback, bytes are echoed and flush()ed per emission
     * (the streaming-download path). Pass a callback to redirect output (e.g. to a temp
     * file in tests); a supplied callback owns its own flushing.
     *
     * @param callable(string): void|null $emit
     * @return void
     */
    public function open(?callable $emit = null): void
    {
        if ($this->is_open) {
            shouldnt_happen('Zip_Stream::open() called twice');
        }

        $this->emit = $emit ?? function (string $bytes): void {
            echo $bytes;
            flush();
        };

        // DOS time/date from the current moment (kept simple - archive creation time).
        $now = getdate();
        $this->dos_time = ($now['hours'] << 11) | ($now['minutes'] << 5) | (intdiv($now['seconds'], 2));
        $year = max(1980, $now['year']);
        // DOS date layout: bits 0-4 day, 5-8 month, 9-15 year-since-1980.
        $this->dos_date = (($year - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

        $this->is_open = true;
    }

    /**
     * Stream one file into the archive under $zip_path (forward-slashed, UTF-8).
     *
     * Reads the source in 512KB chunks with a constant memory footprint. Returns true
     * when the whole file was streamed; returns false when the source could not be fully
     * read (it vanished, or a read failed mid-member). On a mid-member failure the entry
     * is still CLOSED validly with the actual streamed counts in its data descriptor, so
     * the archive stays well-formed - the caller then appends an error-marker entry.
     *
     * @param string      $zip_path    Path inside the archive.
     * @param string      $source_path Absolute path to the source file.
     * @param string|null $mime        Source mime (selects store vs deflate).
     * @param array<int, string> $patches Byte ranges overlaid on the source as it streams
     *                                    (offset => replacement, Exif_Gps::apply_patches()).
     * @return bool True if fully streamed, false if truncated / unreadable.
     */
    public function add_file_from_path(string $zip_path, string $source_path, ?string $mime = null, array $patches = []): bool
    {
        $this->__assert_writable();

        $handle = @fopen($source_path, 'rb');
        if ($handle === false) {
            // Source vanished before we wrote anything - no member is emitted, the caller
            // handles the miss (error marker). Nothing to close.
            return false;
        }

        $method = $this->__method_for_mime($mime);

        $this->__write_local_header($zip_path, $method);

        $crc_ctx = hash_init('crc32b');
        $deflate = null;
        if ($method === self::METHOD_DEFLATE) {
            $deflate = deflate_init(ZLIB_ENCODING_RAW);
        }

        $uncompressed = 0;
        $compressed = 0;
        $truncated = false;

        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE);
            if ($chunk === false) {
                // Read failed mid-member (e.g. the file was unlinked while streaming).
                $truncated = true;
                break;
            }
            if ($chunk === '') {
                break;
            }

            if ($patches !== []) {
                $chunk = Exif_Gps::apply_patches($chunk, $uncompressed, $patches);
            }

            hash_update($crc_ctx, $chunk);
            $uncompressed += strlen($chunk);

            if ($method === self::METHOD_DEFLATE) {
                $out = deflate_add($deflate, $chunk, ZLIB_NO_FLUSH);
            } else {
                $out = $chunk;
            }

            if ($out !== '') {
                $compressed += strlen($out);
                $this->__emit($out);
            }
        }

        fclose($handle);

        if ($method === self::METHOD_DEFLATE && !$truncated) {
            $out = deflate_add($deflate, '', ZLIB_FINISH);
            if ($out !== '') {
                $compressed += strlen($out);
                $this->__emit($out);
            }
        }

        $crc = hexdec(hash_final($crc_ctx, false));

        $this->__write_data_descriptor($zip_path, $method, $crc, $compressed, $uncompressed);

        return !$truncated;
    }

    /**
     * Write a zero-byte entry (a marker). Used for the ~ERROR~ marker convention when a
     * member could not be materialized or was truncated.
     *
     * @param string $zip_path Path inside the archive.
     * @return void
     */
    public function add_empty_entry(string $zip_path): void
    {
        $this->__assert_writable();

        $this->__write_local_header($zip_path, self::METHOD_STORE);
        // crc/comp/uncomp are all zero for an empty entry.
        $this->__write_data_descriptor($zip_path, self::METHOD_STORE, 0, 0, 0);
    }

    /**
     * Finish the archive: emit the central directory then the end-of-central-directory
     * record. After this the archive is complete and no further entries may be added.
     *
     * @return void
     */
    public function finish(): void
    {
        $this->__assert_writable();

        $central_offset = $this->offset;
        $central_size = 0;

        foreach ($this->central as $record) {
            $name = $record['name'];
            // ZIP64 extra (APPNOTE 4.5.3): original size, compressed size, local header
            // offset - the fixed order, each present because its 32-bit field is 0xFFFFFFFF.
            $extra = pack('v', self::EXTRA_ZIP64)
                . pack('v', 24)
                . pack('P', $record['uncomp'])
                . pack('P', $record['comp'])
                . pack('P', $record['offset']);
            $bytes = pack('V', self::SIG_CENTRAL)
                . pack('v', self::VERSION)         // version made by
                . pack('v', self::VERSION)         // version needed to extract
                . pack('v', self::GP_FLAG)
                . pack('v', $record['method'])
                . pack('v', $this->dos_time)
                . pack('v', $this->dos_date)
                . pack('V', $record['crc'])
                . pack('V', self::MARK_32)         // compressed size (in the ZIP64 extra)
                . pack('V', self::MARK_32)         // uncompressed size (in the ZIP64 extra)
                . pack('v', strlen($name))
                . pack('v', strlen($extra))        // extra field length
                . pack('v', 0)                     // file comment length
                . pack('v', 0)                     // disk number start
                . pack('v', 0)                     // internal file attributes
                . pack('V', 0)                     // external file attributes
                . pack('V', self::MARK_32)         // local header offset (in the ZIP64 extra)
                . $name
                . $extra;

            $central_size += strlen($bytes);
            $this->__emit($bytes);
        }

        $entry_count = count($this->central);

        // ZIP64 end of central directory record (APPNOTE 4.3.14). The size field counts
        // the bytes after itself: 44 for a record with no extensible data sector.
        $zip64_eocd_offset = $this->offset;
        $zip64_eocd = pack('V', self::SIG_ZIP64_EOCD)
            . pack('P', 44)                // size of the remaining record
            . pack('v', self::VERSION)     // version made by
            . pack('v', self::VERSION)     // version needed to extract
            . pack('V', 0)                 // number of this disk
            . pack('V', 0)                 // disk with start of central directory
            . pack('P', $entry_count)      // entries on this disk
            . pack('P', $entry_count)      // total entries
            . pack('P', $central_size)
            . pack('P', $central_offset);

        $this->__emit($zip64_eocd);

        // ZIP64 end of central directory locator (APPNOTE 4.3.15).
        $locator = pack('V', self::SIG_ZIP64_LOCATOR)
            . pack('V', 0)                 // disk with the ZIP64 end of central directory
            . pack('P', $zip64_eocd_offset)
            . pack('V', 1);                // total number of disks

        $this->__emit($locator);

        // Classic end of central directory record (APPNOTE 4.3.16), every count, size and
        // offset marked so the reader takes them from the ZIP64 record (4.4.1.4).
        $eocd = pack('V', self::SIG_EOCD)
            . pack('v', 0)                 // number of this disk
            . pack('v', 0)                 // disk with start of central directory
            . pack('v', self::MARK_16)     // entries on this disk
            . pack('v', self::MARK_16)     // total entries
            . pack('V', self::MARK_32)     // central directory size
            . pack('V', self::MARK_32)     // central directory offset
            . pack('v', 0);                // zip file comment length

        $this->__emit($eocd);

        $this->is_finished = true;
    }

    /**
     * Emit the local file header for a member and record its central-directory entry
     * (finalized later by the data descriptor). Captures the header's offset for the
     * central directory.
     *
     * @param string $zip_path
     * @param int    $method
     * @return void
     */
    private function __write_local_header(string $zip_path, int $method): void
    {
        $header_offset = $this->offset;

        // ZIP64 extra (APPNOTE 4.5.3): the local field MUST carry both sizes, original then
        // compressed; both are zero here because the real values follow in the descriptor.
        $extra = pack('v', self::EXTRA_ZIP64)
            . pack('v', 16)
            . pack('P', 0)
            . pack('P', 0);

        $header = pack('V', self::SIG_LOCAL)
            . pack('v', self::VERSION)
            . pack('v', self::GP_FLAG)
            . pack('v', $method)
            . pack('v', $this->dos_time)
            . pack('v', $this->dos_date)
            . pack('V', 0)                 // crc-32 (in data descriptor)
            . pack('V', self::MARK_32)     // compressed size (ZIP64 extra; real value in descriptor)
            . pack('V', self::MARK_32)     // uncompressed size (ZIP64 extra; real value in descriptor)
            . pack('v', strlen($zip_path))
            . pack('v', strlen($extra))    // extra field length
            . $zip_path
            . $extra;

        $this->__emit($header);

        // Provisional central-directory record; crc/sizes filled in by the descriptor.
        $this->central[] = [
            'name' => $zip_path,
            'method' => $method,
            'crc' => 0,
            'comp' => 0,
            'uncomp' => 0,
            'offset' => $header_offset,
        ];
    }

    /**
     * Emit the data descriptor closing a member (APPNOTE 4.3.9: 8-byte sizes, because the
     * local header carried the ZIP64 extra) and backfill the member's central-directory
     * record with the real crc/sizes.
     *
     * @param string $zip_path
     * @param int    $method
     * @param int    $crc
     * @param int    $compressed
     * @param int    $uncompressed
     * @return void
     */
    private function __write_data_descriptor(string $zip_path, int $method, int $crc, int $compressed, int $uncompressed): void
    {
        $descriptor = pack('V', self::SIG_DATA_DESCRIPTOR)
            . pack('V', $crc)
            . pack('P', $compressed)
            . pack('P', $uncompressed);

        $this->__emit($descriptor);

        // Backfill the record opened by __write_local_header (the last one pushed).
        $last = count($this->central) - 1;
        $this->central[$last]['crc'] = $crc;
        $this->central[$last]['comp'] = $compressed;
        $this->central[$last]['uncomp'] = $uncompressed;
    }

    /**
     * Select the compression method for a mime type: store already-compressed content,
     * deflate everything else (unknown mime deflates).
     *
     * @param string|null $mime
     * @return int METHOD_STORE or METHOD_DEFLATE.
     */
    private function __method_for_mime(?string $mime): int
    {
        if ($mime !== null && in_array(strtolower($mime), self::STORE_MIMES, true)) {
            return self::METHOD_STORE;
        }

        return self::METHOD_DEFLATE;
    }

    /**
     * Emit bytes through the sink and advance the running offset.
     *
     * @param string $bytes
     * @return void
     */
    private function __emit(string $bytes): void
    {
        ($this->emit)($bytes);
        $this->offset += strlen($bytes);
    }

    /**
     * Guard: the archive must be open and not yet finished.
     *
     * @return void
     */
    private function __assert_writable(): void
    {
        if (!$this->is_open) {
            shouldnt_happen('Zip_Stream used before open()');
        }
        if ($this->is_finished) {
            shouldnt_happen('Zip_Stream used after finish()');
        }
    }
}
