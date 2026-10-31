<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Man\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Commands\Rsx\Man_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Man page TAGS: the section a page names its subjects in, rsx:man --tag=<tag> (repeatable,
 * every tag required) and rsx:man --tags (the shared vocabulary with counts).
 *
 * The listing assertions use the framework's own `time` page, which is tagged time, stdlib,
 * models and api.
 */
class Man_Tags_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_the_tags_section_is_parsed()
    {
        $page = "NAME\n    portal_tenancy - per-site portals\n\nTAGS\n    Portal, tenancy ,deploy, portal\n\nSYNOPSIS\n    x\n";

        static::__assert_equals(['portal', 'tenancy', 'deploy'], Man_Command::page_tags($page), 'lowercased, trimmed, unique');
        static::__assert_equals([], Man_Command::page_tags("NAME\n    x - y\n\nSYNOPSIS\n    z\n"), 'an untagged page carries none');
    }

    public static function test_tag_lists_only_the_tagged_topics()
    {
        Artisan::call('rsx:man', ['--tag' => ['time']]);
        $output = Artisan::output();

        static::__assert_contains('Documentation tagged time', $output);
        static::__assert_contains('time ', $output);
        static::__assert_false(str_contains($output, 'jqhtml '), 'an untagged page is not listed');
    }

    public static function test_repeated_tags_must_all_match()
    {
        Artisan::call('rsx:man', ['--tag' => ['time', 'api']]);
        static::__assert_contains("\n  time ", Artisan::output(), 'time carries both');

        Artisan::call('rsx:man', ['--tag' => ['time', 'portal']]);
        static::__assert_contains('(none', Artisan::output(), 'time is not tagged portal');
    }

    public static function test_tags_lists_the_vocabulary_with_counts()
    {
        Artisan::call('rsx:man', ['--tags' => true]);
        $output = Artisan::output();

        foreach (array_keys(Man_Command::TAG_VOCABULARY) as $tag) {
            static::__assert_contains("  {$tag} ", $output, "the vocabulary lists {$tag}");
        }
        static::__assert_true((bool) preg_match('/^  time\s+[1-9]\d*\s/m', $output), 'and counts the pages carrying a tag');
    }
}
