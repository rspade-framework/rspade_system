<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ModelFetch\Php;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\ModelFetch\Php\Model_Fetch_Child_Fixture_Model;
/**
 * The PARENT half of the concern's fixture family: a fetchable model with a fetchable
 * plural relationship, on a table this concern creates and drops itself.
 *
 * It exists because the batch endpoint can only be driven against a model that declares
 * the whole surface - a gated fetch(), a #[Relationship] carrying
 * #[Ajax_Endpoint_Model_Fetch], and a real table with real rows - and borrowing an
 * application model for that is what made this concern fail in an installed application.
 *
 * SoftDeletes is deliberate: the preload's scope guard is proved by a withTrashed() find(),
 * which only exists on a soft-deleting model.
 *
 * The table is created by Model_Fetch_Fixture_Tables::create() in each test class's
 * setup() and dropped in teardown(), so it is absent from the manifest's schema metadata -
 * nothing here may depend on an automatic cast or on field_length().
 */
#[Auth('is_logged_in')]
class Model_Fetch_Parent_Fixture_Model extends Rsx_Model_Abstract
{
    use SoftDeletes;

    protected $table = 'model_fetch_parent_fixtures';

    protected $fillable = [];

    public static $enums = [];

    /**
     * The fetch surface. The body looks the record up under the model's DEFAULT scopes,
     * which is what lets the batch preload serve it (MODEL-FETCH-TRASHED-01).
     */
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]
    public static function fetch($id)
    {
        return static::find($id);
    }

    /**
     * The plural relationship the preload's related-id branch is driven against.
     */
    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function children()
    {
        return $this->hasMany(Model_Fetch_Child_Fixture_Model::class, 'parent_fixture_id');
    }
}
