<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ZipDownload\Php;

use App\RSpade\Core\Files\Zip_Stream;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Zip_Stream - the hand-rolled streaming ZIP64 writer. Every archive it emits is checked
 * by three independent real readers shipped in the container image: Info-ZIP `unzip -t`
 * (CRC verified), Python's zipfile (testzip() plus the central directory it parsed) and
 * libzip through PHP's ZipArchive under CHECKCONS (local headers consistent with the
 * central directory). Content roundtrips byte-for-byte, store-vs-deflate selection
 * behaves, an empty member and a marker entry are valid zero-byte members, UTF-8 names
 * with directory prefixes survive, and a large member streams with constant memory.
 *
 * The ZIP64 framing is pinned at the byte level (version 4.5, 0xFFFFFFFF markers with the
 * 0x0001 extra field, 8-byte data-descriptor sizes, the ZIP64 end-of-central-directory
 * record + locator, the classic record's markers), and the two limits a non-ZIP64 archive
 * cannot cross are crossed for real: 70,000 entries, and a local header offset past 4GB
 * (a sparse 4GB source streamed into a sparse archive, so no 4GB of real data is written).
 *
 * No database. Archives are written to a scratch directory via the emit callback and torn
 * down in teardown().
 */
class Zip_Stream_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @var string Scratch working directory for this test class. */
    private static $work_dir = '';

    /**
     * Python zipfile reader: prints the testzip() verdict, the entry count and the parsed
     * entries (all of them up to 20, else the first 20 and the last) as JSON.
     */
    private const PYTHON_READER = <<<'PY'
import json, sys, zipfile
with zipfile.ZipFile(sys.argv[1]) as z:
    infos = z.infolist()
    shown = infos if len(infos) <= 20 else infos[:20] + infos[-1:]
    print(json.dumps({
        'testzip': z.testzip(),
        'count': len(infos),
        'entries': [[i.filename, i.file_size, i.compress_size, i.header_offset] for i in shown],
    }))
PY;

    public static function setup()
    {
        static::$work_dir = sys_get_temp_dir() . '/rsx_zip_stream_test_' . bin2hex(random_bytes(6));
        mkdir(static::$work_dir, 0755, true);
    }

    public static function teardown()
    {
        if (static::$work_dir !== '' && is_dir(static::$work_dir)) {
            foreach (glob(static::$work_dir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir(static::$work_dir);
        }
    }

    /**
     * Write a source file into the scratch directory and return its absolute path.
     */
    private static function __write_source(string $name, string $content): string
    {
        $path = static::$work_dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Open a Zip_Stream writing to a fresh scratch archive and return
     * [zip, out_path, out_handle]. Close the handle via __finish().
     *
     * With $sparse, an all-zero emission longer than one page becomes a hole in the
     * output file instead of written bytes, so a multi-gigabyte zero-filled member costs
     * no disk.
     *
     * @return array{0: Zip_Stream, 1: string, 2: resource}
     */
    private static function __open_archive(bool $sparse = false): array
    {
        $out_path = static::$work_dir . '/archive_' . bin2hex(random_bytes(4)) . '.zip';
        $fh = fopen($out_path, 'wb');
        $zip = new Zip_Stream();
        $zip->open(function (string $bytes) use ($fh, $sparse) {
            if ($sparse && strlen($bytes) > 4096 && trim($bytes, "\0") === '') {
                fseek($fh, strlen($bytes), SEEK_CUR);

                return;
            }
            fwrite($fh, $bytes);
        });

        return [$zip, $out_path, $fh];
    }

    /**
     * Finish an archive and close its output handle.
     *
     * @param Zip_Stream $zip
     * @param resource   $out_handle
     */
    private static function __finish(Zip_Stream $zip, $out_handle): void
    {
        $zip->finish();
        fclose($out_handle);
    }

    /**
     * Run a command under bash with a UTF-8 locale; return [exit code, combined output].
     *
     * @return array{0: int, 1: string}
     */
    private static function __run(string $command): array
    {
        $output = [];
        $rc = 0;
        exec_safe($command . ' 2>&1', $output, $rc, ['LC_ALL' => 'C.UTF-8']);

        return [$rc, implode("\n", $output)];
    }

    /**
     * Info-ZIP `unzip -t`: every member's CRC verified, exit 0, "No errors detected".
     */
    private static function __assert_unzip_accepts(string $out_path): void
    {
        [$rc, $output] = static::__run('unzip -t ' . escapeshellarg($out_path));
        static::__assert_equals(0, $rc, "unzip -t exits 0:\n" . substr($output, -2000));
        static::__assert_true(
            str_contains($output, 'No errors detected'),
            "unzip -t reports no errors:\n" . substr($output, -2000)
        );
    }

    /**
     * Python zipfile: opens the archive, testzip() finds no bad member (CRC verified).
     * Returns the parsed summary for further assertions.
     *
     * @return array{testzip: string|null, count: int, entries: array}
     */
    private static function __python_read(string $out_path): array
    {
        [$rc, $output] = static::__run(
            'python3 -I -c ' . escapeshellarg(self::PYTHON_READER) . ' ' . escapeshellarg($out_path)
        );
        static::__assert_equals(0, $rc, "python3 zipfile reads the archive:\n" . substr($output, -2000));

        $summary = json_decode($output, true);
        static::__assert_true(is_array($summary), 'python3 zipfile printed its summary: ' . substr($output, 0, 500));
        static::__assert_null($summary['testzip'], 'python3 zipfile testzip() finds no bad member');

        return $summary;
    }

    /**
     * libzip (PHP ZipArchive) opens the archive under CHECKCONS; returns it open.
     */
    private static function __libzip_open(string $out_path): \ZipArchive
    {
        $za = new \ZipArchive();
        static::__assert_true(
            $za->open($out_path, \ZipArchive::CHECKCONS) === true,
            'ZipArchive opens the archive with consistency check'
        );

        return $za;
    }

    /**
     * Every reader in the image accepts the archive and agrees on its member names.
     *
     * @param string[] $expected_names
     */
    private static function __assert_readers_accept(string $out_path, array $expected_names): void
    {
        static::__assert_unzip_accepts($out_path);

        $summary = static::__python_read($out_path);
        static::__assert_equals($expected_names, array_column($summary['entries'], 0), 'python3 zipfile lists the members');

        $za = static::__libzip_open($out_path);
        $names = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $names[] = $za->getNameIndex($i);
        }
        $za->close();
        static::__assert_equals($expected_names, $names, 'libzip lists the members');
    }

    // =====================================================================
    // ZS-01 / ZS-02 - two DEFLATE members: valid archive, readable, CRC OK
    // =====================================================================

    public static function test_two_deflate_members_roundtrip_and_validate()
    {
        $body_a = str_repeat("Alpha deflate content line\n", 400);
        $body_b = str_repeat("Bravo deflate content line\n", 250);
        $src_a = static::__write_source('a.txt', $body_a);
        $src_b = static::__write_source('b.txt', $body_b);

        [$zip, $out, $fh] = static::__open_archive();
        static::__assert_true($zip->add_file_from_path('a.txt', $src_a, 'text/plain'));
        static::__assert_true($zip->add_file_from_path('b.txt', $src_b, 'text/plain'));
        static::__finish($zip, $fh);

        static::__assert_readers_accept($out, ['a.txt', 'b.txt']);

        $za = static::__libzip_open($out);
        static::__assert_equals($body_a, $za->getFromName('a.txt'), 'a.txt content roundtrips');
        static::__assert_equals($body_b, $za->getFromName('b.txt'), 'b.txt content roundtrips');
        $za->close();
    }

    // =====================================================================
    // ZS-03 - STORE-mime member is stored, not deflated, and roundtrips
    // =====================================================================

    public static function test_store_mime_member_is_stored()
    {
        // Random bytes under a store mime (image/jpeg): comp size == uncompressed size.
        $bytes = random_bytes(20000);
        $src = static::__write_source('photo.jpg', $bytes);

        [$zip, $out, $fh] = static::__open_archive();
        static::__assert_true($zip->add_file_from_path('photo.jpg', $src, 'image/jpeg'));
        static::__finish($zip, $fh);

        static::__assert_readers_accept($out, ['photo.jpg']);

        $za = static::__libzip_open($out);
        $stat = $za->statIndex(0);
        static::__assert_equals(20000, $stat['size'], 'uncompressed size recorded');
        static::__assert_equals(20000, $stat['comp_size'], 'stored member is not compressed (comp == size)');
        static::__assert_equals(\ZipArchive::CM_STORE, $stat['comp_method'], 'method is STORE');
        static::__assert_equals($bytes, $za->getFromName('photo.jpg'), 'stored bytes roundtrip');
        $za->close();
    }

    // =====================================================================
    // ZS-04 - empty (marker) entry is a valid zero-byte member
    // =====================================================================

    public static function test_empty_entry_is_zero_byte_member()
    {
        [$zip, $out, $fh] = static::__open_archive();
        $zip->add_empty_entry('reports/~ERROR~gone.pdf.inf');
        static::__finish($zip, $fh);

        static::__assert_readers_accept($out, ['reports/~ERROR~gone.pdf.inf']);

        $za = static::__libzip_open($out);
        $stat = $za->statIndex(0);
        static::__assert_equals(0, $stat['size'], 'marker entry is zero bytes');
        static::__assert_equals('', $za->getFromName('reports/~ERROR~gone.pdf.inf'));
        $za->close();
    }

    // =====================================================================
    // ZS-08 - an empty SOURCE FILE streams as a valid zero-byte member
    // =====================================================================

    public static function test_empty_source_file_is_zero_byte_member()
    {
        $src = static::__write_source('empty.txt', '');

        [$zip, $out, $fh] = static::__open_archive();
        static::__assert_true($zip->add_file_from_path('empty.txt', $src, 'text/plain'));
        static::__assert_true($zip->add_file_from_path('empty.jpg', $src, 'image/jpeg'));
        static::__finish($zip, $fh);

        static::__assert_readers_accept($out, ['empty.txt', 'empty.jpg']);

        $summary = static::__python_read($out);
        static::__assert_equals(0, $summary['entries'][0][1], 'deflated empty member has size 0');
        static::__assert_equals(0, $summary['entries'][1][1], 'stored empty member has size 0');
        static::__assert_equals(0, $summary['entries'][1][2], 'stored empty member has compressed size 0');
    }

    // =====================================================================
    // ZS-05 - UTF-8 filename + directory prefix survive
    // =====================================================================

    public static function test_utf8_name_and_directory_prefix()
    {
        $name = 'proyecto/informe_espanol_' . "\u{00F1}" . '.txt';
        $name_cjk = "\u{65E5}\u{672C}/\u{8CC7}\u{6599}_\u{00FC}ber.txt";
        $body = 'unicode filename content';
        $src = static::__write_source('u.txt', $body);

        [$zip, $out, $fh] = static::__open_archive();
        static::__assert_true($zip->add_file_from_path($name, $src, 'text/plain'));
        static::__assert_true($zip->add_file_from_path($name_cjk, $src, 'text/plain'));
        static::__finish($zip, $fh);

        // python3 and libzip decode the names under the UTF-8 flag and must return them
        // exactly; unzip -t must verify both members.
        static::__assert_readers_accept($out, [$name, $name_cjk]);

        $za = static::__libzip_open($out);
        static::__assert_equals($body, $za->getFromName($name));
        static::__assert_equals($body, $za->getFromName($name_cjk));
        $za->close();
    }

    // =====================================================================
    // ZS-06 - a multi-chunk member streams with a constant memory footprint
    // =====================================================================

    public static function test_large_member_streams_with_constant_memory()
    {
        // A 3MB source (six 512KB read chunks) generated without holding it in one string.
        $src = static::$work_dir . '/big.bin';
        $fh = fopen($src, 'wb');
        $line = str_repeat('R', 1024);
        for ($i = 0; $i < 3 * 1024; $i++) {
            fwrite($fh, $line);
        }
        fclose($fh);
        $expected_size = 3 * 1024 * 1024;
        static::__assert_equals($expected_size, filesize($src));

        $peak_before = memory_get_peak_usage();

        [$zip, $out, $fh] = static::__open_archive();
        // octet-stream deflates and image/jpeg stores; neither the read nor the deflate
        // buffers the whole 3MB.
        static::__assert_true($zip->add_file_from_path('big.bin', $src, 'application/octet-stream'));
        static::__assert_true($zip->add_file_from_path('big.jpg', $src, 'image/jpeg'));
        static::__finish($zip, $fh);

        $peak_after = memory_get_peak_usage();
        $delta = $peak_after - $peak_before;

        // Streaming a 3MB file must not add anywhere near 3MB to peak memory. Allow a
        // generous 2MB ceiling for chunk buffers / zlib state.
        static::__assert_true(
            $delta < 2 * 1024 * 1024,
            'peak memory delta while streaming 3MB stays under 2MB (was ' . $delta . ' bytes)'
        );

        static::__assert_readers_accept($out, ['big.bin', 'big.jpg']);

        $summary = static::__python_read($out);
        static::__assert_equals($expected_size, $summary['entries'][0][1], 'deflated multi-chunk size recorded');
        static::__assert_equals($expected_size, $summary['entries'][1][1], 'stored multi-chunk size recorded');

        $za = static::__libzip_open($out);
        static::__assert_equals(file_get_contents($src), $za->getFromName('big.bin'), 'deflated 3MB content roundtrips');
        static::__assert_equals(file_get_contents($src), $za->getFromName('big.jpg'), 'stored 3MB content roundtrips');
        $za->close();
    }

    // =====================================================================
    // ZS-07 - a mixed archive (deflate + store + marker) validates end to end
    // =====================================================================

    public static function test_mixed_archive_end_to_end()
    {
        $txt = static::__write_source('doc.txt', str_repeat("mixed archive text\n", 100));
        $jpg = static::__write_source('img.jpg', random_bytes(5000));

        [$zip, $out, $fh] = static::__open_archive();
        static::__assert_true($zip->add_file_from_path('folder/doc.txt', $txt, 'text/plain'));
        static::__assert_true($zip->add_file_from_path('folder/img.jpg', $jpg, 'image/jpeg'));
        $zip->add_empty_entry('folder/~ERROR~missing.pdf.inf');
        static::__finish($zip, $fh);

        static::__assert_readers_accept($out, ['folder/doc.txt', 'folder/img.jpg', 'folder/~ERROR~missing.pdf.inf']);
    }

    // =====================================================================
    // ZS-09 - every record is ZIP64-framed, byte for byte
    // =====================================================================

    public static function test_archive_is_zip64_framed()
    {
        $body = str_repeat("framing\n", 64);
        $src = static::__write_source('f.txt', $body);

        [$zip, $out, $fh] = static::__open_archive();
        static::__assert_true($zip->add_file_from_path('f.txt', $src, 'image/jpeg'));
        $zip->add_empty_entry('m.inf');
        static::__finish($zip, $fh);

        $bytes = file_get_contents($out);
        $len = strlen($bytes);

        // Local file header of member 0 (APPNOTE 4.3.7 + 4.5.3).
        $local = unpack('Vsig/vversion/vflag/vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vname_len/vextra_len', $bytes, 0);
        static::__assert_equals(0x04034b50, $local['sig'], 'local header signature');
        static::__assert_equals(45, $local['version'], 'local header: version needed 4.5');
        static::__assert_equals(0x0808, $local['flag'], 'local header: data descriptor + UTF-8 flags');
        static::__assert_equals(0, $local['crc'], 'local header: CRC-32 deferred to the descriptor');
        static::__assert_equals(0xFFFFFFFF, $local['comp'], 'local header: compressed size marked 0xFFFFFFFF');
        static::__assert_equals(0xFFFFFFFF, $local['uncomp'], 'local header: uncompressed size marked 0xFFFFFFFF');
        static::__assert_equals(20, $local['extra_len'], 'local header: one 20-byte ZIP64 extra field');
        $local_extra = unpack('vid/vsize/Puncomp/Pcomp', $bytes, 30 + $local['name_len']);
        static::__assert_equals(1, $local_extra['id'], 'local ZIP64 extra id 0x0001');
        static::__assert_equals(16, $local_extra['size'], 'local ZIP64 extra carries both sizes');
        static::__assert_equals(0, $local_extra['uncomp'], 'local ZIP64 original size is zero (streamed)');
        static::__assert_equals(0, $local_extra['comp'], 'local ZIP64 compressed size is zero (streamed)');

        // Data descriptor right after the stored bytes: 8-byte sizes (APPNOTE 4.3.9).
        $descriptor_at = 30 + $local['name_len'] + $local['extra_len'] + strlen($body);
        $descriptor = unpack('Vsig/Vcrc/Pcomp/Puncomp', $bytes, $descriptor_at);
        static::__assert_equals(0x08074b50, $descriptor['sig'], 'data descriptor signature');
        static::__assert_equals(hexdec(hash('crc32b', $body)), $descriptor['crc'], 'data descriptor CRC-32');
        static::__assert_equals(strlen($body), $descriptor['comp'], 'data descriptor 8-byte compressed size');
        static::__assert_equals(strlen($body), $descriptor['uncomp'], 'data descriptor 8-byte uncompressed size');
        $member_1_at = $descriptor_at + 24;
        static::__assert_equals(0x04034b50, unpack('V', $bytes, $member_1_at)[1], 'member 1 begins right after a 24-byte descriptor');

        // Classic end of central directory record: every field marked (APPNOTE 4.4.1.4).
        $eocd = unpack('Vsig/vdisk/vcd_disk/vdisk_entries/ventries/Vcd_size/Vcd_offset/vcomment_len', $bytes, $len - 22);
        static::__assert_equals(0x06054b50, $eocd['sig'], 'classic EOCD signature at the end');
        static::__assert_equals(0xFFFF, $eocd['disk_entries'], 'classic EOCD: entries on disk marked');
        static::__assert_equals(0xFFFF, $eocd['entries'], 'classic EOCD: total entries marked');
        static::__assert_equals(0xFFFFFFFF, $eocd['cd_size'], 'classic EOCD: central directory size marked');
        static::__assert_equals(0xFFFFFFFF, $eocd['cd_offset'], 'classic EOCD: central directory offset marked');

        // ZIP64 end of central directory locator, immediately before it (APPNOTE 4.3.15).
        $locator = unpack('Vsig/Vdisk/Poffset/Vdisks', $bytes, $len - 22 - 20);
        static::__assert_equals(0x07064b50, $locator['sig'], 'ZIP64 EOCD locator signature');
        static::__assert_equals(1, $locator['disks'], 'ZIP64 EOCD locator: one disk');
        static::__assert_equals($len - 22 - 20 - 56, $locator['offset'], 'ZIP64 EOCD locator points at the 56-byte record before it');

        // ZIP64 end of central directory record (APPNOTE 4.3.14).
        $zip64_eocd = unpack(
            'Vsig/Psize/vmade_by/vneeded/Vdisk/Vcd_disk/Pdisk_entries/Pentries/Pcd_size/Pcd_offset',
            $bytes,
            $locator['offset']
        );
        static::__assert_equals(0x06064b50, $zip64_eocd['sig'], 'ZIP64 EOCD record signature');
        static::__assert_equals(44, $zip64_eocd['size'], 'ZIP64 EOCD record size counts the bytes after the field');
        static::__assert_equals(45, $zip64_eocd['needed'], 'ZIP64 EOCD record: version needed 4.5');
        static::__assert_equals(2, $zip64_eocd['entries'], 'ZIP64 EOCD record: total entries');
        static::__assert_equals(2, $zip64_eocd['disk_entries'], 'ZIP64 EOCD record: entries on this disk');
        static::__assert_equals(
            $locator['offset'] - $zip64_eocd['cd_offset'],
            $zip64_eocd['cd_size'],
            'ZIP64 EOCD record: the central directory ends where the record begins'
        );

        // Central directory header of member 1: all three values in the ZIP64 extra.
        $central_1_at = $zip64_eocd['cd_offset'];
        $central_0 = unpack('vname_len/vextra_len/vcomment_len', $bytes, $central_1_at + 28);
        $central_1_at += 46 + $central_0['name_len'] + $central_0['extra_len'] + $central_0['comment_len'];
        $central = unpack(
            'Vsig/vmade_by/vneeded/vflag/vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vname_len/vextra_len/vcomment_len/vdisk/vint_attr/Vext_attr/Voffset',
            $bytes,
            $central_1_at
        );
        static::__assert_equals(0x02014b50, $central['sig'], 'central directory header signature');
        static::__assert_equals(45, $central['needed'], 'central header: version needed 4.5');
        static::__assert_equals(0xFFFFFFFF, $central['comp'], 'central header: compressed size marked');
        static::__assert_equals(0xFFFFFFFF, $central['uncomp'], 'central header: uncompressed size marked');
        static::__assert_equals(0xFFFFFFFF, $central['offset'], 'central header: local header offset marked');
        static::__assert_equals(28, $central['extra_len'], 'central header: one 28-byte ZIP64 extra field');
        $central_extra = unpack('vid/vsize/Puncomp/Pcomp/Poffset', $bytes, $central_1_at + 46 + $central['name_len']);
        static::__assert_equals(1, $central_extra['id'], 'central ZIP64 extra id 0x0001');
        static::__assert_equals(24, $central_extra['size'], 'central ZIP64 extra carries size, size, offset');
        static::__assert_equals(0, $central_extra['uncomp'], 'marker member size in the ZIP64 extra');
        static::__assert_equals($member_1_at, $central_extra['offset'], 'local header offset in the ZIP64 extra');

        static::__assert_readers_accept($out, ['f.txt', 'm.inf']);
    }

    // =====================================================================
    // ZS-10 - more than 65,535 entries
    // =====================================================================

    public static function test_more_than_65535_entries()
    {
        $count = 70000;
        $src = static::__write_source('one.txt', 'x');

        [$zip, $out, $fh] = static::__open_archive();
        for ($i = 0; $i < $count; $i++) {
            static::__assert_true($zip->add_file_from_path(sprintf('d/%05d.txt', $i), $src, 'text/plain'));
        }
        static::__finish($zip, $fh);

        static::__assert_unzip_accepts($out);

        $summary = static::__python_read($out);
        static::__assert_equals($count, $summary['count'], 'python3 zipfile reads all 70,000 entries');
        static::__assert_equals('d/69999.txt', $summary['entries'][20][0], 'the last entry is the 70,000th');
        static::__assert_equals(1, $summary['entries'][20][1], 'the last entry has its size');

        $za = static::__libzip_open($out);
        static::__assert_equals($count, $za->numFiles, 'libzip reads all 70,000 entries');
        static::__assert_equals('x', $za->getFromName('d/69999.txt'), 'the 70,000th entry roundtrips');
        $za->close();
    }

    // =====================================================================
    // ZS-11 - a member larger than 4GB and a local header offset past 4GB
    // =====================================================================

    public static function test_member_and_offset_past_four_gigabytes()
    {
        // A sparse 4GB+ source of zeros (no disk blocks), stored so it streams through the
        // writer unchanged; the sparse sink turns those zeros back into a hole, so the
        // archive itself costs a few kilobytes of disk.
        $huge_size = 0x100000000 + 123456;
        $huge = static::$work_dir . '/huge.bin';
        $h = fopen($huge, 'wb');
        ftruncate($h, $huge_size);
        fclose($h);

        $tail_body = str_repeat("after the 4GB mark\n", 50);
        $tail = static::__write_source('tail.txt', $tail_body);

        [$zip, $out, $fh] = static::__open_archive(true);
        static::__assert_true($zip->add_file_from_path('huge.bin', $huge, 'image/jpeg'));
        static::__assert_true($zip->add_file_from_path('after/tail.txt', $tail, 'text/plain'));
        static::__finish($zip, $fh);
        @unlink($huge);

        static::__assert_true(filesize($out) > 0xFFFFFFFF, 'the archive is larger than 4GB');

        // unzip -t and python3 testzip() read every byte of the 4GB member and verify its CRC.
        static::__assert_unzip_accepts($out);

        $summary = static::__python_read($out);
        static::__assert_equals(['huge.bin', 'after/tail.txt'], array_column($summary['entries'], 0));
        static::__assert_equals($huge_size, $summary['entries'][0][1], 'the >4GB size comes from the ZIP64 extra');
        static::__assert_equals($huge_size, $summary['entries'][0][2], 'the stored >4GB compressed size comes from the ZIP64 extra');
        static::__assert_true($summary['entries'][1][3] > 0xFFFFFFFF, 'the second local header sits past 4GB');

        $za = static::__libzip_open($out);
        static::__assert_equals($tail_body, $za->getFromName('after/tail.txt'), 'the member past 4GB roundtrips');
        $za->close();
        @unlink($out);
    }
}
