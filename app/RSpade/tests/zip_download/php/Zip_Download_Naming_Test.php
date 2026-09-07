<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ZipDownload\Php;

use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The pure, unit-testable seams of the /_download_zip endpoint: archive-entry name
 * sanitization (a hostile custom name degrades silently to the attachment file_name),
 * cross-set collision de-duplication, the error-marker naming convention, the download
 * filename sanitizer, and the non-ZIP64 streaming-envelope guard. All static, no
 * database, no HTTP.
 *
 * The full HTTP round-trip (auth cascade, streaming, byte-identity, the denial path) is
 * exercised on the live dev box during development verification and cataloged as a
 * deferred http row - these unit rows pin the branch logic those seams encode.
 */
class Zip_Download_Naming_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // =====================================================================
    // _sanitize_zip_entry_name
    // =====================================================================

    public static function test_entry_name_null_uses_attachment_name()
    {
        static::__assert_equals(
            'report.pdf',
            File_Attachment_Controller::_sanitize_zip_entry_name(null, 'report.pdf')
        );
    }

    public static function test_entry_name_custom_directory_prefix_kept()
    {
        static::__assert_equals(
            'docs/renamed.pdf',
            File_Attachment_Controller::_sanitize_zip_entry_name('docs/renamed.pdf', 'x.pdf')
        );
    }

    public static function test_entry_name_leading_slash_stripped()
    {
        static::__assert_equals(
            'a/b.txt',
            File_Attachment_Controller::_sanitize_zip_entry_name('/a/b.txt', 'f.txt')
        );
    }

    public static function test_entry_name_duplicate_slashes_collapsed()
    {
        static::__assert_equals(
            'a/b/c.txt',
            File_Attachment_Controller::_sanitize_zip_entry_name('a//b///c.txt', 'f.txt')
        );
    }

    public static function test_entry_name_backslash_uses_attachment_name()
    {
        static::__assert_equals(
            'default.pdf',
            File_Attachment_Controller::_sanitize_zip_entry_name('a\\b.txt', 'default.pdf')
        );
    }

    public static function test_entry_name_traversal_uses_attachment_name()
    {
        static::__assert_equals(
            'default.pdf',
            File_Attachment_Controller::_sanitize_zip_entry_name('../etc/passwd', 'default.pdf')
        );
    }

    public static function test_entry_name_control_char_uses_attachment_name()
    {
        static::__assert_equals(
            'default.pdf',
            File_Attachment_Controller::_sanitize_zip_entry_name("a\nb.txt", 'default.pdf')
        );
    }

    public static function test_entry_name_empty_uses_attachment_name()
    {
        static::__assert_equals(
            'default.pdf',
            File_Attachment_Controller::_sanitize_zip_entry_name('///', 'default.pdf')
        );
    }

    public static function test_entry_name_attachment_name_reduced_to_bare()
    {
        // An attachment name carrying path components is reduced to its basename.
        static::__assert_equals(
            'x.pdf',
            File_Attachment_Controller::_sanitize_zip_entry_name(null, '/evil/../x.pdf')
        );
    }

    // =====================================================================
    // _dedupe_zip_names
    // =====================================================================

    public static function test_dedupe_appends_numbered_suffix_before_extension()
    {
        static::__assert_equals(
            ['report.pdf', 'report (2).pdf', 'report (3).pdf'],
            File_Attachment_Controller::_dedupe_zip_names(['report.pdf', 'report.pdf', 'report.pdf'])
        );
    }

    public static function test_dedupe_preserves_directory_prefix()
    {
        static::__assert_equals(
            ['d/a.txt', 'd/a (2).txt'],
            File_Attachment_Controller::_dedupe_zip_names(['d/a.txt', 'd/a.txt'])
        );
    }

    public static function test_dedupe_name_without_extension()
    {
        static::__assert_equals(
            ['README', 'README (2)'],
            File_Attachment_Controller::_dedupe_zip_names(['README', 'README'])
        );
    }

    public static function test_dedupe_distinct_names_unchanged()
    {
        static::__assert_equals(
            ['a.txt', 'b.txt', 'c.txt'],
            File_Attachment_Controller::_dedupe_zip_names(['a.txt', 'b.txt', 'c.txt'])
        );
    }

    // =====================================================================
    // _error_marker_name
    // =====================================================================

    public static function test_error_marker_plain_name()
    {
        static::__assert_equals(
            '~ERROR~q2.pdf.inf',
            File_Attachment_Controller::_error_marker_name('q2.pdf')
        );
    }

    public static function test_error_marker_preserves_directory_prefix()
    {
        static::__assert_equals(
            'reports/~ERROR~q2.pdf.inf',
            File_Attachment_Controller::_error_marker_name('reports/q2.pdf')
        );
    }

    // =====================================================================
    // _sanitize_zip_filename
    // =====================================================================

    public static function test_zip_filename_default_when_empty()
    {
        static::__assert_equals('download.zip', File_Attachment_Controller::_sanitize_zip_filename(null));
        static::__assert_equals('download.zip', File_Attachment_Controller::_sanitize_zip_filename(''));
    }

    public static function test_zip_filename_suffix_added()
    {
        static::__assert_equals('myfiles.zip', File_Attachment_Controller::_sanitize_zip_filename('myfiles'));
    }

    public static function test_zip_filename_path_components_stripped()
    {
        static::__assert_equals('foo.zip', File_Attachment_Controller::_sanitize_zip_filename('/etc/foo.zip'));
    }

    public static function test_zip_filename_quotes_stripped()
    {
        static::__assert_equals('ab.zip', File_Attachment_Controller::_sanitize_zip_filename('a"b.zip'));
    }

    // =====================================================================
    // _zip64_limit_error
    // =====================================================================

    public static function test_zip64_within_limits_returns_null()
    {
        static::__assert_null(File_Attachment_Controller::_zip64_limit_error([100, 200, 300]));
    }

    public static function test_zip64_too_many_entries()
    {
        static::__assert_not_null(
            File_Attachment_Controller::_zip64_limit_error(array_fill(0, 65001, 1))
        );
    }

    public static function test_zip64_single_file_too_large()
    {
        static::__assert_not_null(
            File_Attachment_Controller::_zip64_limit_error([0xFFFFFFFF])
        );
    }

    public static function test_zip64_total_over_four_gb()
    {
        static::__assert_not_null(
            File_Attachment_Controller::_zip64_limit_error([2000000000, 2000000001])
        );
    }
}
