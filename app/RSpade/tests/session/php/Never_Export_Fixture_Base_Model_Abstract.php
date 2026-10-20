<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: the middle of a three-class chain, never-exporting one column of its own.
 * Never_Export_Fixture_Model beneath it declares another; Model_Never_Export_Union_Test
 * proves the leaf strips both. Built unsaved only - its table does not exist.
 */
abstract class Never_Export_Fixture_Base_Model_Abstract extends Rsx_Model_Abstract
{
    protected $table = 'never_export_fixtures';

    public static $enums = [];

    public static $neverExport = ['base_secret_column'];
}
