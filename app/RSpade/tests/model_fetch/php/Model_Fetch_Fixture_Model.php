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
 * id, so the fixture exposes nothing (its table binding exists only because the batch
 * endpoint preloads the requested ids before calling fetch()). The surface is gated on
 * is_logged_in like any other.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: tasks
 *
 * @property int $id
 * @property int $site_id
 * @property string $title
 * @property string $description
 * @property int $taskable_type
 * @property int $taskable_id
 * @property int $project_id
 * @property int $status
 * @property int $priority
 * @property string $due_date
 * @property string $completed_date
 * @property int $assigned_to_user_id
 * @property string $notes
 * @property float $hour_estimate
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $created_at
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property int $deleted_by_type
 *
 * @mixin \Eloquent
 */
class Model_Fetch_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'tasks';
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
