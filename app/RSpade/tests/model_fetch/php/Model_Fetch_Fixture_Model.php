<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ModelFetch\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a fetchable model whose fetch() returns an ARRAY WITHOUT the __MODEL
 * marker - the one shape Orm_Controller refuses, because the JavaScript ORM cannot
 * hydrate a class out of it.
 *
 * It deliberately touches no row: the returned array is a constant echo of the requested
 * id, so the fixture exposes nothing. Its table binding exists only because the batch
 * endpoint preloads the requested ids before calling fetch(), and it is a table of this
 * concern's own (created by Model_Fetch_Fixture_Tables::create(), dropped in teardown) -
 * never an application table. The surface is gated on is_logged_in like any other.
 */
#[Auth('is_logged_in')]
class Model_Fetch_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'model_fetch_marker_fixtures';

    protected $fillable = [];

    public static $enums = [];

    /**
     * The malformed return the endpoint must reject: an array with no __MODEL.
     *
     * @param mixed $id
     * @return array
     */
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]
    public static function fetch($id)
    {
        return ['id' => (int) $id];
    }
}
