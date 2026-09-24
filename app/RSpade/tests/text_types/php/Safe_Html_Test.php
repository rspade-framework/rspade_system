<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TextTypes\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * safe_html() keeps a Quill 2 checklist's state and nothing else it did not already allow.
 *
 * Quill 2 stores a list item's kind as li[data-list], and for a checklist that attribute IS
 * the state ('checked' / 'unchecked'). The sanitizer used to drop it, so every checklist
 * silently became a plain list on save. It is now allowed on <li> only, and only with
 * Quill's four values - an allowance for a format, not a general data-attribute hole.
 */
class Safe_Html_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_a_checklist_keeps_its_state()
    {
        static::__assert_equals(
            '<ul><li data-list="checked">done</li><li data-list="unchecked">todo</li></ul>',
            safe_html('<ul><li data-list="checked">done</li><li data-list="unchecked">todo</li></ul>'),
            'both checklist states survive'
        );
    }

    public static function test_the_ordinary_list_kinds_survive_too()
    {
        static::__assert_equals(
            '<ol><li data-list="ordered">a</li><li data-list="bullet">b</li></ol>',
            safe_html('<ol><li data-list="ordered">a</li><li data-list="bullet">b</li></ol>'),
            'Quill marks every list item, so ordered and bullet are kept alongside the checklist values'
        );
    }

    /**
     * The editor's live DOM carries its checkbox UI too; only the attribute survives, and
     * that is enough - Quill rebuilds the UI from it on load.
     */
    public static function test_the_editor_ui_is_dropped_and_the_state_kept()
    {
        static::__assert_equals(
            '<ol><li data-list="checked"><span></span>x</li></ol>',
            safe_html('<ol><li data-list="checked"><span class="ql-ui" contenteditable="false"></span>x</li></ol>'),
            'the ql-ui span loses its attributes; data-list stays'
        );
    }

    public static function test_an_unknown_value_is_dropped()
    {
        static::__assert_equals('<ul><li>x</li></ul>', safe_html('<ul><li data-list="javascript:1">x</li></ul>'));
    }

    public static function test_data_list_off_an_li_is_dropped()
    {
        static::__assert_equals('<p>x</p>', safe_html('<p data-list="checked">x</p>'));
    }

    public static function test_other_data_attributes_are_still_dropped()
    {
        static::__assert_equals('<ul><li>x</li></ul>', safe_html('<ul><li data-foo="1" onclick="x()">x</li></ul>'));
    }
}
