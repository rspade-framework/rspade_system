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
 * Zip_Stream - the hand-rolled streaming ZIP writer. Proves the emitted archives are
 * standard-conformant (PHP's ZipArchive opens them under CHECKCONS and `unzip -t`
 * passes), that content roundtrips byte-for-byte, that the store-vs-deflate method
 * selection behaves, that a marker (empty) entry is a valid zero-byte member, that UTF-8
 * names with directory prefixes survive, and that a large member streams with a constant
 * memory footprint (the core promise of the writer).
 *
 * No database. Archives are written to a scratch directory via the emit callback and torn
 * down in teardown().
 */
class Zip_Stream_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @var string Scratch working directory for this test class. */
    private static $work_dir = '';

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
     * @return array{0: Zip_Stream, 1: string, 2: resource}
     */
    private static function __open_archive(): array
    {
        $out_path = static::$work_dir . '/archive_' . bin2hex(random_bytes(4)) . '.zip';
        $fh = fopen($out_path, 'wb');
        $zip = new Zip_Stream();
        $zip->open(function (string $bytes) use ($fh) {
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
     * Run `unzip -t` over an archive; return true iff it reports no errors (exit 0).
     * Uses shell_exec (the house-sanctioned shell channel) and reads the exit code the
     * command echoes, since only pass/fail is needed.
     */
    private static function __unzip_test_passes(string $out_path): bool
    {
        $rc = trim((string) shell_exec('bash -c ' . escapeshellarg('unzip -t ' . escapeshellarg($out_path) . ' >/dev/null 2>&1; echo $?')));

        return $rc === '0';
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

        $za = new \ZipArchive();
        $rc = $za->open($out, \ZipArchive::CHECKCONS);
        static::__assert_true($rc === true, 'ZipArchive opens the archive with consistency check');
        static::__assert_equals(2, $za->numFiles);
        static::__assert_equals($body_a, $za->getFromName('a.txt'), 'a.txt content roundtrips');
        static::__assert_equals($body_b, $za->getFromName('b.txt'), 'b.txt content roundtrips');
        $za->close();

        static::__assert_true(static::__unzip_test_passes($out), 'unzip -t passes (CRC verified)');
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

        $za = new \ZipArchive();
        static::__assert_true($za->open($out, \ZipArchive::CHECKCONS) === true);
        $stat = $za->statIndex(0);
        static::__assert_equals(20000, $stat['size'], 'uncompressed size recorded');
        static::__assert_equals(20000, $stat['comp_size'], 'stored member is not compressed (comp == size)');
        static::__assert_equals(\ZipArchive::CM_STORE, $stat['comp_method'], 'method is STORE');
        static::__assert_equals($bytes, $za->getFromName('photo.jpg'), 'stored bytes roundtrip');
        $za->close();

        static::__assert_true(static::__unzip_test_passes($out));
    }

    // =====================================================================
    // ZS-04 - empty (marker) entry is a valid zero-byte member
    // =====================================================================

    public static function test_empty_entry_is_zero_byte_member()
    {
        [$zip, $out, $fh] = static::__open_archive();
        $zip->add_empty_entry('reports/~ERROR~gone.pdf.inf');
        static::__finish($zip, $fh);

        $za = new \ZipArchive();
        static::__assert_true($za->open($out, \ZipArchive::CHECKCONS) === true);
        static::__assert_equals(1, $za->numFiles);
        $stat = $za->statIndex(0);
        static::__assert_equals('reports/~ERROR~gone.pdf.inf', $stat['name']);
        static::__assert_equals(0, $stat['size'], 'marker entry is zero bytes');
        static::__assert_equals('', $za->getFromName('reports/~ERROR~gone.pdf.inf'));
        $za->close();

        static::__assert_true(static::__unzip_test_passes($out));
    }

    // =====================================================================
    // ZS-05 - UTF-8 filename + directory prefix survive
    // =====================================================================

    public static function test_utf8_name_and_directory_prefix()
    {
        $name = 'proyecto/informe_espanol_' . "\xC3\xB1" . '.txt'; // contains U+00F1 (n-tilde)
        $body = 'unicode filename content';
        $src = static::__write_source('u.txt', $body);

        [$zip, $out, $fh] = static::__open_archive();
        static::__assert_true($zip->add_file_from_path($name, $src, 'text/plain'));
        static::__finish($zip, $fh);

        $za = new \ZipArchive();
        static::__assert_true($za->open($out, \ZipArchive::CHECKCONS) === true);
        $stat = $za->statIndex(0);
        static::__assert_equals($name, $stat['name'], 'UTF-8 name with directory prefix preserved');
        static::__assert_equals($body, $za->getFromName($name));
        $za->close();

        static::__assert_true(static::__unzip_test_passes($out));
    }

    // =====================================================================
    // ZS-06 - large member streams with a constant memory footprint
    // =====================================================================

    public static function test_large_member_streams_with_constant_memory()
    {
        // Generate a 3MB source without holding it all in one PHP string.
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
        // octet-stream deflates; the point is that neither the read nor the deflate
        // buffers the whole 3MB.
        static::__assert_true($zip->add_file_from_path('big.bin', $src, 'application/octet-stream'));
        static::__finish($zip, $fh);

        $peak_after = memory_get_peak_usage();
        $delta = $peak_after - $peak_before;

        // Streaming a 3MB file must not add anywhere near 3MB to peak memory. Allow a
        // generous 2MB ceiling for chunk buffers / zlib state.
        static::__assert_true(
            $delta < 2 * 1024 * 1024,
            'peak memory delta while streaming 3MB stays under 2MB (was ' . $delta . ' bytes)'
        );

        // Content still roundtrips exactly.
        $za = new \ZipArchive();
        static::__assert_true($za->open($out, \ZipArchive::CHECKCONS) === true);
        static::__assert_equals(file_get_contents($src), $za->getFromName('big.bin'), '3MB content roundtrips');
        $za->close();

        static::__assert_true(static::__unzip_test_passes($out));
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

        $za = new \ZipArchive();
        static::__assert_true($za->open($out, \ZipArchive::CHECKCONS) === true);
        static::__assert_equals(3, $za->numFiles);
        $za->close();

        static::__assert_true(static::__unzip_test_passes($out), 'mixed archive passes unzip -t');
    }
}
