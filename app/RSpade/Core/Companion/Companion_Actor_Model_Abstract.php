<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Models\Rsx_Actor_Model_Abstract;

/**
 * Companion_Actor_Model - the system actor a companion application writes as
 * (_companion_actors), one row per companion name.
 *
 * Audit columns hold a class and a row, never a label, so a companion needs a row for its
 * writes to be attributed. Cross-site, because a companion is per install. An actor is never
 * hard-deleted, so authorship survives removing a companion from the code.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _companion_actors
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property string $deleted_at
 * @property int $deleted_by_id
 * @property string $deleted_by_type type ref: a model class name, stored as a type-ref id
 * @property int $id
 * @property string $name
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
 *
 * @mixin \Eloquent
 */
abstract class Companion_Actor_Model_Abstract extends Rsx_Actor_Model_Abstract
{
    // Infrastructure table; nothing subscribes to it.
    public static $realtime_silent = true;

    protected $table = '_companion_actors';
    protected $fillable = [];

    /**
     * The id of a companion's actor row, created on first use.
     *
     * The row is written directly: this runs from inside a model's audit stamp, and a model
     * save here would stamp itself. INSERT IGNORE on the unique name makes two first requests
     * racing each other land on one row.
     */
    public static function id_for_name(string $name): int
    {
        $id = DB::table('_companion_actors')->where('name', $name)->value('id');

        if ($id === null) {
            DB::table('_companion_actors')->insertOrIgnore(['name' => $name]);
            $id = DB::table('_companion_actors')->where('name', $name)->value('id');
        }

        return (int) $id;
    }

    public function get_printed_name(): string
    {
        return 'Companion:' . $this->name;
    }

    /**
     * No page shows a companion's actor row.
     */
    public function get_view_profile_url(): ?string
    {
        return null;
    }
}
