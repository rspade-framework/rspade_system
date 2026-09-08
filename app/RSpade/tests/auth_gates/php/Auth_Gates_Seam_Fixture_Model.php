<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * A model whose ONLY purpose is to give the ORM seam a RELATIONSHIP surface to gate.
 *
 * Orm_Controller::fetch_relationship() reaches its gate check only after the model
 * resolves, its (portal_)fetch() carries #[Ajax_Endpoint_Model_Fetch], and the named
 * relationship carries both #[Relationship] and #[Ajax_Endpoint_Model_Fetch] - so the
 * seam cannot be driven without a real model declaring all three. The framework's own
 * models fetch() but declare no fetchable relationship, and borrowing one from the
 * application is what made this concern fail downstream.
 *
 * NO TABLE IS EVER TOUCHED. The tests install a synthetic surface index and the denial
 * they assert lands BEFORE any model code runs, so the declarations are the whole
 * fixture; children() is never executed. The table name is nonetheless distinct so that
 * a future test which does execute it starts from its own DDL rather than someone
 * else's rows.
 */
#[Auth('is_logged_in')]
class Auth_Gates_Seam_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'auth_gates_seam_fixtures';

    protected $fillable = [];

    public static $enums = [];

    /**
     * The fetch surface. The gate list the seam actually evaluates is the one the tests
     * install; this declaration is what makes the surface EXIST.
     */
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]
    public static function fetch($id)
    {
        return static::find($id);
    }

    /**
     * The fetchable relationship the relationship seam is driven against.
     */
    #[Relationship]
    #[Ajax_Endpoint_Model_Fetch]
    public function children()
    {
        return $this->hasMany(static::class, 'parent_id');
    }
}
