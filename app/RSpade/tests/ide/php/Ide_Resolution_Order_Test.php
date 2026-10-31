<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Ide\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The order an ambiguous identifier resolves in for go-to-definition
 * (`Ide/Services/ide_resolution_order.php`): jqhtml template, PHP class, JS class, Blade
 * view id, stylesheet id - and, among the files carrying the same `id`, the best match by
 * that order rather than by which record the manifest lists first.
 *
 * The file is pure and is required here directly; handler.php, which uses it, is a
 * pre-boot request handler and cannot be included by a test.
 */
class Ide_Resolution_Order_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __load(): void
    {
        require_once base_path('app/RSpade/Ide/Services/ide_resolution_order.php');
    }

    public static function test_an_untyped_identifier_resolves_in_the_owner_order()
    {
        static::__load();

        static::__assert_equals(
            ['jqhtml_template', 'php_class', 'js_class', 'view', 'scss', 'bundle_alias'],
            ide_ambiguous_type_order()
        );
    }

    public static function test_a_template_beats_a_view_beats_a_stylesheet_whatever_the_map_order()
    {
        static::__load();

        $files = [
            'rsx/app/a/widget.scss' => ['extension' => 'scss', 'id' => 'Widget'],
            'rsx/app/a/widget.blade.php' => ['extension' => 'blade.php', 'id' => 'Widget'],
            'rsx/app/a/widget.jqhtml' => ['extension' => 'jqhtml', 'id' => 'Widget'],
        ];

        static::__assert_equals('rsx/app/a/widget.jqhtml', ide_best_id_match($files, 'Widget')['file']);

        unset($files['rsx/app/a/widget.jqhtml']);
        static::__assert_equals('rsx/app/a/widget.blade.php', ide_best_id_match($files, 'Widget')['file']);

        unset($files['rsx/app/a/widget.blade.php']);
        static::__assert_equals('rsx/app/a/widget.scss', ide_best_id_match($files, 'Widget')['file']);
    }

    public static function test_a_tie_goes_to_the_first_path_and_the_record_rides_along()
    {
        static::__load();

        $files = [
            'rsx/app/z/page.blade.php' => ['extension' => 'blade.php', 'id' => 'Probe_Item'],
            'rsx/app/b/page.blade.php' => ['extension' => 'blade.php', 'id' => 'Probe_Item', 'marker' => 1],
        ];

        $match = ide_best_id_match($files, 'Probe_Item');
        static::__assert_equals('rsx/app/b/page.blade.php', $match['file']);
        static::__assert_equals(1, $match['marker'] ?? null, 'the matched record is returned with its path');
    }

    public static function test_the_stylesheet_lookup_sees_only_stylesheets_and_unknown_kinds_never_match()
    {
        static::__load();

        $files = [
            'rsx/app/a/card.jqhtml' => ['extension' => 'jqhtml', 'id' => 'Probe_Panel'],
            'rsx/app/a/card.scss' => ['extension' => 'scss', 'id' => 'Probe_Panel'],
            'rsx/app/a/odd.php' => ['extension' => 'php', 'id' => 'Odd'],
        ];

        static::__assert_equals('rsx/app/a/card.scss', ide_best_id_match($files, 'Probe_Panel', ['scss'])['file']);
        static::__assert_null(ide_best_id_match($files, 'Odd'), 'an id on a kind the order does not know is not a declaration');
        static::__assert_null(ide_best_id_match($files, 'Missing'));
    }
}
