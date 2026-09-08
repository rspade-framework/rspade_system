<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ModelFetch\Php;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\ModelFetch\Php\Model_Fetch_Parent_Fixture_Model;
/**
 * The CHILD half of the concern's fixture family: the related record the plural branch
 * fetches one by one, and a second model class for the "the preload is keyed by model
 * class" guard.
 *
 * Its fetch() body is the DEFAULT-SCOPED lookup the preload can serve - a scope-stripped
 * body would miss it and put one `where id = ?` back per related record, which is exactly
 * what the preload test measures.
 */
#[Auth('is_logged_in')]
class Model_Fetch_Child_Fixture_Model extends Rsx_Model_Abstract
{
    use SoftDeletes;

    protected $table = 'model_fetch_child_fixtures';

    protected $fillable = [];

    public static $enums = [];

    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]
    public static function fetch($id)
    {
        return static::find($id);
    }

    /**
     * The singular half of the family - a belongsTo back to the parent.
     */
    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function owner()
    {
        return $this->belongsTo(Model_Fetch_Parent_Fixture_Model::class, 'parent_fixture_id');
    }
}
