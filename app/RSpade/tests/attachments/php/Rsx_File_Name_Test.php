<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Rsx_File_Name;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_File_Name - the one rule for a file name a user stores, sees or downloads, and the one way
 * a Content-Disposition header is built from one.
 *
 * PINNED:
 *  - the blacklist (characters illegal on Windows, macOS or Linux, control characters,
 *    text-direction controls) becomes "_", and accented and non-Latin names survive whole;
 *  - trimmed ends, the Windows device-name prefix, composed Unicode, the 255-byte cap keeping
 *    the extension, the empty name for a name with nothing left;
 *  - the header carries the real name encoded and a transliterated ASCII name, and a quote
 *    can no longer add a parameter;
 *  - File_Attachment_Model stores every file_name through the rule;
 *  - nothing else in the framework builds a Content-Disposition header with a file name by hand.
 */
class Rsx_File_Name_Test extends Rsx_Test_Abstract
{
    public static function test_the_blacklist_becomes_underscores()
    {
        static::__assert_equals('a_b_c_d_e_f_g_h_i.doc', Rsx_File_Name::safe('a:b*c?d"e<f>g|h/i.doc'));
        static::__assert_equals('a_b.pdf', Rsx_File_Name::safe('a\\b.pdf'));
        static::__assert_equals('line_break.txt', Rsx_File_Name::safe("line\nbreak.txt"));
        static::__assert_equals('tab_and null_.txt', Rsx_File_Name::safe("tab\tand null\0.txt"));
        static::__assert_equals('inv_fdp.exe', Rsx_File_Name::safe("inv\u{202E}fdp.exe"), 'a text-direction control cannot disguise the extension');
    }

    public static function test_accented_and_non_latin_names_survive()
    {
        static::__assert_equals('Résumé.pdf', Rsx_File_Name::safe('Résumé.pdf'));
        static::__assert_equals('報告書.xlsx', Rsx_File_Name::safe('報告書.xlsx'));
        static::__assert_equals("O'Brien (draft) #2; v1.5 & more.pdf", Rsx_File_Name::safe("O'Brien (draft) #2; v1.5 & more.pdf"), 'ordinary punctuation is kept');
        static::__assert_equals('Résumé.pdf', Rsx_File_Name::safe("Re\u{0301}sume\u{0301}.pdf"), 'decomposed Unicode is composed');
    }

    public static function test_ends_device_names_length_and_empty_names()
    {
        static::__assert_equals('hidden', Rsx_File_Name::safe(' .hidden. '));
        static::__assert_equals('_CON.txt', Rsx_File_Name::safe('CON.txt'));
        static::__assert_equals('_lpt1', Rsx_File_Name::safe('lpt1'));
        static::__assert_equals('CONSOLE.txt', Rsx_File_Name::safe('CONSOLE.txt'), 'only the device names themselves');
        static::__assert_equals('file', Rsx_File_Name::safe('...'));
        static::__assert_equals('file', Rsx_File_Name::safe(null));
        static::__assert_equals('download.zip', Rsx_File_Name::safe('', 'download.zip'));

        $long = Rsx_File_Name::safe(str_repeat('é', 200) . '.pdf');
        static::__assert_true(strlen($long) <= Rsx_File_Name::MAX_BYTES, 'capped at 255 bytes');
        static::__assert_true(str_ends_with($long, 'é.pdf'), 'keeping the extension, cut on a character boundary');
        static::__assert_true(mb_check_encoding($long, 'UTF-8'));
    }

    public static function test_the_header_carries_the_name_and_an_ascii_name()
    {
        static::__assert_equals(
            "attachment; filename=Resume.pdf; filename*=utf-8''R%C3%A9sum%C3%A9.pdf",
            Rsx_File_Name::disposition('attachment', 'Résumé.pdf')
        );
        static::__assert_equals(
            'inline; filename="report.pdf_; filename_=UTF-8\'\'x.html"',
            Rsx_File_Name::disposition('inline', "report.pdf\"; filename*=UTF-8''x.html"),
            'a quote cannot close the name and add a parameter'
        );
        static::__assert_equals('inline; filename=file', Rsx_File_Name::disposition('inline', ''));
        static::__assert_contains('filename="100_ done.pdf"', Rsx_File_Name::disposition('inline', '100% done.pdf'));
    }

    public static function test_the_attachment_model_stores_safe_names()
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        $attachment = File_Attachment_Model::create_from_disk(dirname(__DIR__) . '/resource/cube.gif', [
            'filename' => 'q3 "final" cube:v2.gif',
            'site_id' => $site_id,
        ]);

        static::__assert_equals('q3 _final_ cube_v2.gif', $attachment->fresh()->file_name);

        $attachment->file_name = "CON.gif";
        $attachment->save();
        static::__assert_equals('_CON.gif', $attachment->fresh()->file_name, 'a rename goes through the rule too');
    }

    /**
     * The one-way rule: a Content-Disposition carrying a file name is built by Rsx_File_Name,
     * never by concatenating one into a 'filename="' string.
     */
    public static function test_no_framework_code_builds_a_disposition_by_hand()
    {
        $root = base_path('app/RSpade');
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') || str_contains($path, '/tests/') || str_contains($path, '/resource/')) {
                continue;
            }

            foreach (file($path) as $n => $line) {
                if (preg_match('/filename=\\\\?"\'?\s*\./', $line) || preg_match("/'(inline|attachment); filename=\"' \\./", $line)) {
                    $offenders[] = substr($path, strlen($root) + 1) . ':' . ($n + 1);
                }
            }
        }

        static::__assert_equals([], $offenders, 'Content-Disposition built by hand: ' . implode(', ', $offenders));
    }
}
