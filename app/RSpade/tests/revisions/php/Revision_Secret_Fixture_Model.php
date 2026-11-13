<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Wrapped_Text;
/**
 * Test fixture: a revisioned, soft-deleting model carrying never-exported columns, for the
 * redaction of secrets in the revision lane. Backed by the runtime table
 * revision_secret_fixtures, created by Revision_Redaction_Test.
 *
 * `api_token` is a plain secret. `secret_note` is a secret with a declared text type, the
 * case where redaction must happen before any encoding. `rotating_secret` carries both
 * never_export and revision_exclude, the case where revision_exclude wins and the column is
 * not recorded at all.
 *
 * @TEXT-TYPE-01-EXCEPTION - the borrowed text-type fixture has no JavaScript twin, and
 * this model is written and read in PHP only; no payload of it ever reaches a browser.
 */
class Revision_Secret_Fixture_Model extends Rsx_Model_Abstract
{
    use SoftDeletes;

    protected $table = 'revision_secret_fixtures';
    protected $fillable = [];

    public static $columns = [
        'api_token' => ['never_export' => true],
        'secret_note' => ['text_type' => Text_Fixture_Wrapped_Text::class, 'never_export' => true],
        'rotating_secret' => ['never_export' => true, 'revision_exclude' => true],
    ];

    public static $revisions = true;
}
