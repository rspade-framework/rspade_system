<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
/**
 * Test fixture: a plain (non-site) model that OPTS IN to revision recording
 * ($revisions = true) and soft-deletes, so the delete / undelete operations are
 * reachable. Backed by the runtime table revision_fixtures, created by
 * Revision_Recording_Test.
 *
 * `counter` stands in for a denormalized value a history should not show, and is the
 * $revision_exclude case. `_internal` is the automatic system-column case.
 */
class Revision_Fixture_Model extends Rsx_Model_Abstract
{
    use SoftDeletes;

    protected $table = 'revision_fixtures';
    protected $fillable = [];

    public static $enums = [];

    public static $revisions = true;

    /** A denormalized counter: it moves on its own, and a history showing it is noise. */
    protected static $revision_exclude = ['counter'];
}
