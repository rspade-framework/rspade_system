<?php

namespace App\RSpade\Core\Database\Models;

use App\Database\RestrictedEloquentBuilder;
use App\Exceptions\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Model_Abstract;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Emissions;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Registry;
use App\RSpade\Core\Database\Migration_Guard;
use App\RSpade\Core\Database\Models\Rsx_Actor_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Site_Actor_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Realtime\Realtime_Touch_Registry;
use App\RSpade\Lib\Revisions\Revision;
use App\RSpade\Lib\Revisions\Revision_Model;
use App\RSpade\Lib\Revisions\Revision_Parent_Registry;

/**
 * Abstract base model for all RSpade models
 *
 * This class:
 * - Prohibits mass assignment
 * - Provides enum functionality with magic properties and methods
 * - Prevents eager loading
 * - Serves as the base for all RSpade models
 * - Provides server-side query caching with modification tracking
 *
 * All models in RSpade must extend this class and use explicit field assignment:
 *
 * $model = new Model();
 * $model->field1 = $value1;
 * $model->field2 = $value2;
 * $model->save();
 *
 * Enum System:
 * Declare an enum column in the model's $columns:
 * public static $columns = [
 *     'status_id' => ['enum' => [
 *         1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active'],
 *         2 => ['constant' => 'STATUS_INACTIVE', 'label' => 'Inactive'],
 *     ]],
 * ];
 *
 * This provides magic properties and methods (BEM-style double underscore):
 * - $model->status__label - Get label for current enum value
 * - $model->status__constant - Get constant name for current value
 * - $model->status__badge - Get any custom property for current value
 *
 * Static methods (available in both PHP and JavaScript):
 * - Model::status__enum() - Get all enum definitions with full metadata
 * - Model::status__enum_select() - Get selectable items as [{value, label}] array for dropdowns
 * - Model::status__enum_labels() - Get simple id => label lookup map
 * - Model::status__enum_ids() - Get array of all valid enum IDs
 */
#[Monoprogenic]
#[Instantiatable]
abstract class Rsx_Model_Abstract extends Model
{
    /**
     * Per-instance cache for the active Class-Table Inheritance detail row.
     * @var bool
     */
    private $_active_detail_loaded = false;

    /**
     * The loaded persisted active detail model, or null when none exists / type has no detail.
     * @var Rsx_Detail_Model_Abstract|null
     */
    private $_active_detail = null;

    /**
     * A vivified (new, unsaved, FK pre-set) detail instance, cached so repeated accessor
     * reads return the same object to fill and save.
     * @var Rsx_Detail_Model_Abstract|null
     */
    private $_vivified_detail = null;

    /**
     * Realtime model-change emission opt-in. When true, a successful save()/delete()
     * publishes a notification-only Model_Changed_Topic message ({model, id}) on
     * transaction commit, so subscribed clients refetch. Off by default.
     * @var bool
     */
    public static $realtime = false;

    /**
     * When true, this model's writes do NOT kick the realtime emitter engine. Set on
     * per-request / high-volume infrastructure models (session persistence, mail/SMS
     * queues) whose writes are not interesting to any UI subscriber — otherwise every
     * such write would needlessly schedule an emitter recompute. Off by default (a
     * normal domain write SHOULD kick emitters).
     * @var bool
     */
    public static $realtime_silent = false;

    /**
     * Revision-history opt-in. When true, every committed save()/delete() through the model
     * layer records a `{field: [before, after]}` document on the `_revisions` table, grouped
     * under the current Revision transaction. Off by default, and off costs one static read.
     *
     * Deliberately shaped exactly like $realtime: a model declares what it wants recorded,
     * and nothing else in the application has to know.
     *
     * @var bool
     */
    public static $revisions = false;

    /**
     * Private helper to resolve enum magic properties and methods
     *
     * Handles (these are mirrored in JavaScript stubs, BEM-style double underscore):
     * - field__enum() - Returns all enum definitions with full metadata
     * - field__enum_select() - Returns [{value, label}] array for dropdowns
     * - field__enum_labels() - Returns simple id => label map
     * - field__enum_ids() - Returns array of all valid enum IDs
     *
     * @param string $key The property/method being accessed
     * @param mixed $value Optional value for filtering selectable items
     * @return mixed|null
     */
    private static function _get_static_magic($key, $value = null)
    {
        $class = get_called_class();

        $enum_columns = $class::enum_columns();
        if (!empty($enum_columns)) {
            foreach ($enum_columns as $column => $enum_config) {
                // Sorted once per class+column, not once per call. The sort is by the enum's
                // declared `order` then by key, and neither depends on the record - but this
                // method is reached from __get, so an unmemoized uksort here was a full sort
                // per enum column on EVERY attribute read, performed before we even knew
                // whether the key could match.
                $sorted_config = static::__sorted_enum_config($class, $column, $enum_config);

                // field__enum() - All enum definitions with full metadata
                if ($key == $column . '__enum') {
                    return $sorted_config;
                }

                // field__enum_select() - Selectable items for dropdowns (respects selectable: false)
                // Returns array of ['value' => id, 'label' => label] for consistent ordering
                if ($key == $column . '__enum_select') {
                    $return = [];

                    foreach ($sorted_config as $k => $v) {
                        // Filter based on 'selectable' property
                        $selectable = isset($v['selectable']) ? $v['selectable'] : true;

                        if (($selectable || ($k == $value && $value !== null))) {
                            $return[] = ['value' => $k, 'label' => $v['label']];
                        }
                    }

                    return $return;
                }

                // field__enum_labels() - Simple id => label map (all items, ignores selectable)
                if ($key == $column . '__enum_labels') {
                    $return = [];
                    foreach ($sorted_config as $k => $v) {
                        if (isset($v['label'])) {
                            $return[$k] = $v['label'];
                        }
                    }
                    return $return;
                }

                // field__enum_ids() - Array of all valid enum IDs
                if ($key == $column . '__enum_ids') {
                    return array_keys($sorted_config);
                }
            }
        }

        return null;
    }

    /**
     * One enum column's config, sorted by `order` then key, memoized per class+column.
     *
     * The ordering is a property of the DECLARATION, not of any record, so it can be
     * computed once. Callers must not mutate the returned array - it is the shared copy.
     *
     * @param string $class The called class, used as half the memo key
     * @param string $column The enum column
     * @param array $enum_config That column's declared enum value map
     * @return array
     */
    private static function __sorted_enum_config(string $class, string $column, array $enum_config): array
    {
        static $cache = [];

        if (isset($cache[$class][$column])) {
            return $cache[$class][$column];
        }

        $sorted_config = $enum_config;
        uksort($sorted_config, function ($keyA, $keyB) use ($sorted_config) {
            $orderA = isset($sorted_config[$keyA]['order']) ? $sorted_config[$keyA]['order'] : 0;
            $orderB = isset($sorted_config[$keyB]['order']) ? $sorted_config[$keyB]['order'] : 0;

            // First compare by order
            if ($orderA !== $orderB) {
                return $orderA - $orderB;
            }

            // If order is same, compare by key (use spaceship operator for string comparison)
            return $keyA <=> $keyB;
        });

        return $cache[$class][$column] = $sorted_config;
    }

    /**
     * Magic getter for enum properties
     *
     * Uses BEM-style double underscore to separate field from property:
     * - field__label - Label for current enum value
     * - field__constant - Constant name for current value
     * - field__badge - Any custom property for current value
     * - field__enum(), field__enum_select(), etc. - Via _get_static_magic
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        // FAST PATH
        // __get fires on EVERY column read: Eloquent keeps attributes in an array, not as
        // declared properties, so `$model->title` lands here. Anything unconditional in this
        // method is therefore paid once per column per row, and a list endpoint reading 20
        // columns across a thousand rows pays it twenty thousand times.
        //
        // Every magic key this method can answer is BEM-style and contains a DOUBLE
        // underscore: the static lookups (`field__enum`, `field__enum_select`,
        // `field__enum_labels`, `field__enum_ids`) and the per-value properties
        // (`field__label`, `field__constant`, and any custom one). An ordinary column name
        // does not - not `title`, and not a system column like `_ext_src`, which has a
        // single leading underscore. One substring test therefore skips the entire enum
        // machinery for the overwhelming majority of reads.
        //
        // A `__`-prefixed column takes the slow path and still resolves correctly (it simply
        // matches nothing and falls through) - it is merely not accelerated.
        if (str_contains($key, '__')) {
            // Check for enum lookup functions: __enum, __enum_select, __enum_ids
            $static_call = self::_get_static_magic($key);
            if ($static_call !== null) {
                return $static_call;
            }

            // Look up enum properties related to current column value (BEM-style:
            // field__property). The map is precomputed per class, so this is one hash
            // lookup rather than a walk of every column x value x property combination.
            $enum_map = static::__enum_property_map();
            if (isset($enum_map[$key])) {
                [$column, $by_value] = $enum_map[$key];
                $current = $this->$column;
                foreach ($by_value as $enum_val => $prop_value) {
                    if ($current == $enum_val) {
                        return $prop_value;
                    }
                }
            }
        }

        // Class-Table Inheritance: detail accessor (e.g. $record->party_person).
        // Returns the embedded/loaded detail for the active type, or throws on wrong-type.
        if (static::has_detail_tables() && static::__is_detail_accessor($key)) {
            return $this->__get_detail_for_accessor($key);
        }

        return parent::__get($key);
    }

    /**
     * `field__property` => [column, [enum_value => property_value, ...]], memoized per class.
     *
     * Inverts static::enum_columns() once so a magic-property read is a hash lookup instead of a
     * nested walk of every column x value x property combination. Declaration order is
     * preserved inside each key, so when several enum values define the same property the
     * first one whose value matches still wins - the same answer the walk gave.
     *
     * @return array<string, array{0: string, 1: array}>
     */
    private static function __enum_property_map(): array
    {
        static $cache = [];

        $class = static::class;
        if (isset($cache[$class])) {
            return $cache[$class];
        }

        $map = [];
        foreach (static::enum_columns() as $column => $enum_config) {
            foreach ((array) $enum_config as $enum_val => $enum_properties) {
                if (!is_array($enum_properties)) {
                    continue;
                }
                foreach ($enum_properties as $prop_name => $prop_value) {
                    $magic_key = $column . '__' . $prop_name;
                    if (!isset($map[$magic_key])) {
                        $map[$magic_key] = [$column, []];
                    }
                    $map[$magic_key][1][$enum_val] = $prop_value;
                }
            }
        }

        return $cache[$class] = $map;
    }

    /**
     * Magic isset for enum properties
     *
     * Required because PHP's ?? operator calls __isset() before __get().
     * Without this, Eloquent's __isset() returns false for enum properties,
     * causing ?? to use the default value without ever calling __get().
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key)
    {
        // Same fast path as __get, and for the same reason: PHP calls __isset() before
        // __get() for every `??`, so this is on the hot path too. See __get for why a
        // double underscore is the reliable discriminator.
        if (str_contains($key, '__')) {
            // field__label, field__constant, field__* (any custom enum property)
            $enum_map = static::__enum_property_map();
            if (isset($enum_map[$key])) {
                [$column, $by_value] = $enum_map[$key];
                $current = $this->$column;
                foreach (array_keys($by_value) as $enum_val) {
                    // Property exists if current column value matches this enum value
                    if ($current == $enum_val) {
                        return true;
                    }
                }
            }
        }

        // A detail accessor is "set" only when it matches this record's active type
        // (so `$record->party_company ?? null` yields null rather than throwing wrong-type).
        if (static::has_detail_tables() && static::__is_detail_accessor($key)) {
            return $key === $this->__active_detail_accessor();
        }

        return parent::__isset($key);
    }

    /**
     * Magic static method handler for enum methods
     *
     * Provides static access to (mirrored in JavaScript stubs, BEM-style):
     * - Model::field__enum() - All enum definitions with full metadata
     * - Model::field__enum_select() - [{value, label}] array for dropdowns
     * - Model::field__enum_labels() - Simple id => label map
     * - Model::field__enum_ids() - Array of all valid enum IDs
     *
     * @param string $key
     * @param array $args
     * @return mixed
     */
    public static function __callStatic($key, $args)
    {
        $class = get_called_class();

        $result = $class::_get_static_magic($key, isset($args[0]) ? $args[0] : null);

        if ($result !== null) {
            return $result;
        }

        return parent::__callStatic($key, $args);
    }

    /**
     * Override toArray to include enum properties and model identifier
     *
     * Adds all enum properties and __MODEL identifier for client-side ORM instantiation
     * Excludes every column in never_exported_columns() for security
     *
     * @return array
     */
    public function toArray()
    {
        try {
            $array = parent::toArray();
        } catch (LogicException $e) {
            // Check if this is the relationship instance error
            if (str_contains($e->getMessage(), 'must return a relationship instance')) {
                // Extract the method name from the error message
                $message = $e->getMessage();
                preg_match('/::([\w]+) must return a relationship instance/', $message, $matches);
                $method_name = $matches[1] ?? 'unknown';

                throw new RuntimeException(
                    'Error in ' . static::class . "::toArray(): {$message}\n\n" .
                    "This error typically occurs when:\n" .
                    "1. A method name conflicts with a database column name\n" .
                    "2. An enum is defined for a non-existent column that matches a method name\n" .
                    "3. A method is not marked with #[Relationship] but Laravel is auto-detecting it as a relationship\n\n" .
                    "To diagnose:\n" .
                    "- Run 'php artisan rsx:check' to check for column/method conflicts\n" .
                    "- Verify '{$method_name}' is either a column OR a method, not both\n" .
                    "- If '{$method_name}' is a relationship, add #[Relationship] attribute to the method\n" .
                    "- If '{$method_name}' is not a relationship, ensure it's not being auto-detected",
                    0,
                    $e
                );
            }

            // Re-throw other exceptions
            throw $e;
        }

        // Remove fields that must never leave the server. This runs AFTER parent::toArray(),
        // so nothing Laravel offers ($visible, makeVisible()) can put one of them back.
        foreach (static::never_exported_columns() as $field) {
            unset($array[$field]);
        }

        // A single leading underscore marks a SYSTEM column: framework bookkeeping the
        // client has no business seeing and no way to interpret. Stripping it here is what
        // makes the prefix a convention a model can rely on rather than a naming habit.
        //
        // DOUBLE underscore is untouched - `__MODEL` and `__details` are framework keys this
        // very method adds, and they are the payload's contract with the JS ORM.
        foreach (array_keys($array) as $field) {
            if (is_string($field) && str_starts_with($field, '_') && !str_starts_with($field, '__')) {
                unset($array[$field]);
            }
        }

        // EXTERNAL API: every DATETIME/TIMESTAMP column also carries the same instant in the
        // key holder's zone - <col>__localized (ISO 8601 with its offset) and
        // <col>__localized_tz (the identifier) - through the one encoder a hand-built API
        // payload uses too (Rsx_Time::encode_for_output()). Ajax payloads carry UTC alone
        // (the envelope's _user_timezone is the browser's conversion key), and a DATE column
        // never gets companions: a calendar date has no zone.
        if (\App\RSpade\Core\Session\Session::is_api_request()) {
            foreach ($this->getCasts() as $column => $cast) {
                if ($cast === \App\RSpade\Core\Time\Rsx_DateTime_Cast::class && array_key_exists($column, $array)) {
                    foreach (\App\RSpade\Core\Time\Rsx_Time::encode_for_output($column, $array[$column]) as $key => $value) {
                        $array[$key] = $value;
                    }
                }
            }
        }

        // Add enum field extra data - ALL properties (BEM-style: field__property)
        foreach (static::enum_columns() as $column => $definitions) {
            if (isset($this->$column) && isset($definitions[$this->$column])) {
                foreach ($definitions[$this->$column] as $prop => $value) {
                    // Add all enum properties to the export
                    $array[$column . '__' . $prop] = $value;
                }
            }
        }

        // Class-Table Inheritance: eager-embed the active PERSISTED detail so the client
        // resolves `await record.party_person()` from the same payload (no extra fetch). It
        // rides under a single "__details" blob (not the accessor key) so it never collides
        // with the generated accessor method of the same name on the JS model.
        if (static::has_detail_tables()) {
            $accessor = $this->__active_detail_accessor();
            if ($accessor !== null) {
                $detail = $this->active_detail();
                if ($detail !== null) {
                    $array['__details'][$accessor] = $detail->toArray();
                }
            }
        }

        // __MODEL SYSTEM: Enables automatic ORM instantiation when fetching from PHP models.
        // PHP models add "__MODEL": "ClassName" to JSON, JavaScript uses it to create proper instances.
        // This provides typed model objects instead of plain JSON, with methods and type checking.

        // Here we inject the model's class name into the JSON output. When JavaScript receives
        // this data, it will see __MODEL and know to instantiate the matching JS model class
        // instead of leaving it as a plain object. This enables ORM features on the client side.
        $array['__MODEL'] = class_basename(static::class);

        return $array;
    }

    /**
     * Override isRelation to only consider methods with #[Relationship] attribute as relationships
     *
     * This prevents Laravel from auto-detecting non-relationship methods
     * like is_active() as relationships
     *
     * @param string $key
     * @return bool
     */
    public function isRelation($key)
    {
        // If this has an attribute mutator, it's not a relationship
        if ($this->hasAttributeMutator($key)) {
            return false;
        }

        // Only consider it a relationship if it's in the relationships list
        $relationships = static::get_relationships();

        return in_array($key, $relationships);
    }

    /**
     * Override getArrayableRelations to only include relationships defined via #[Relationship]
     *
     * This prevents Laravel from auto-detecting non-relationship methods
     * like is_active() as relationships
     *
     * @return array
     */
    protected function getArrayableRelations()
    {
        // Get relationships from annotations
        $relationships = static::get_relationships();

        // Only include relationships that are:
        // 1. Listed in the relationships
        // 2. Actually loaded on this model instance
        $arrayable = [];
        foreach ($relationships as $relation_name) {
            // Check if this relationship is loaded
            if (array_key_exists($relation_name, $this->relations)) {
                $arrayable[$relation_name] = $this->relations[$relation_name];
            }
        }

        return $arrayable;
    }

    /**
     * Mass assignment is explicitly disabled in RSpade
     * This empty array is required by Laravel's Model class
     * @var array
     */
    protected $fillable = [];

    /**
     * Guarded is set to all fields by default
     * @var array
     */
    protected $guarded = ['*'];

    /**
     * FACT GROUPS - one fact a person edits as one thing, stored across several columns
     * (see rsx:man model, FACT GROUPS).
     *
     * public static $fact_groups = [
     *     'when' => ['label' => 'Date and time', 'columns' => ['date_start', 'date_end', 'time_start', 'time_end']],
     * ];
     *
     * A change saved to ANY column of a group is a change to the whole group. Form_Edit reads
     * the declaration: a later save of one column of the group conflicts with a stale
     * submission of a field stored in any other (rsx:man form_conventions, EDITING A RECORD).
     * Stated in column terms because it is a fact about the model, true whichever screen
     * edits it. Read through fact_groups(), which checks it the first time it is read.
     *
     * @var array<string, array{label: string, columns: array<int, string>}>
     */
    public static $fact_groups = [];

    /**
     * Class-Table Inheritance (CTI) detail-table map (see man detail_tables).
     *
     * Declares, per discriminator value, the detail model holding that type's
     * type-specific fields. The single source of truth for "what detail a type has":
     *
     * public static $detail_tables = [
     *     'type_id' => [                                   // discriminator column
     *         self::TYPE_PERSON  => Party_Person_Detail_Model::class,
     *         self::TYPE_COMPANY => Party_Company_Detail_Model::class,
     *         // a value absent from the map => that type has no detail row
     *     ],
     * ];
     *
     * Detail accessors (e.g. $record->party_person) are generated from this map; the
     * detail is eager-embedded on read and throws on wrong-type access. Null when the
     * model has no detail tables.
     *
     * @var array|null
     */
    public static $detail_tables = null;

    /**
     * COLUMN DECLARATIONS - what kind of value each column holds, one entry per column
     * (see rsx:man model).
     *
     * public static $columns = [
     *     'status_id'     => ['enum' => [
     *         1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active'],
     *         2 => ['constant' => 'STATUS_INACTIVE', 'label' => 'Inactive', 'selectable' => false],
     *     ]],
     *     'description'   => ['text_type' => Rich_Text::class],
     *     'fileable_type' => ['type_ref' => true],
     *     'api_secret'    => ['never_export' => true],
     *     'view_count'    => ['revision_exclude' => true],
     *     'project_id'    => ['derived' => 'derive_project_id', 'from' => ['parent_type', 'parent_id', 'direct_project_id']],
     * ];
     *
     * KEYS (anything else is an error):
     *
     *   enum             - integer-keyed map of value => ['constant' => 'NAME', 'label' => 'Shown',
     *                      ...]: optional `constant` (generated as a class constant), `label`,
     *                      `order`, `selectable` (false hides it from __enum_select()) and any
     *                      custom property, readable as $record->{column}__{property}. The column
     *                      is a BIGINT; Model::{column}__enum() and the JS stub mirror it.
     *   text_type        - class-string of an Rsx_Text_Abstract subclass that owns the column's
     *                      encoding (rsx:man text_types): read as a value object, filtered on
     *                      every write, sent to the browser as a {__TEXT, raw} envelope.
     *   type_ref         - literal true, on the {relation}_type column of a polymorphic pair: the
     *                      column stores a _type_refs integer id and reads as a class name, and
     *                      stock morph relations work over it (rsx:man polymorphic).
     *   never_export     - literal true: toArray() - the one serializer behind fetch(),
     *                      relationships, the page's user object and the external API - removes
     *                      the column after Laravel built the array, so makeVisible() and
     *                      $visible cannot reveal it; on a model with $revisions, a change to it
     *                      is recorded with both sides replaced by Revision::REDACTED. For a
     *                      password hash, a token, a secret, an open key/value bag (Laravel's
     *                      $hidden is advisory). A secret-shaped column without it is flagged
     *                      by MODEL-NEVER-EXPORT-01.
     *   revision_exclude - literal true: no revision is recorded for the column, on top of the
     *                      automatic exclusions (`_`-prefixed system columns, updated_at, the
     *                      updated_by pair). For values that change without anybody changing
     *                      them (counters, last-seen stamps, cached aggregates). Not a security
     *                      control. On a never_export column it wins: nothing is recorded.
     *   derived          - the name of a public, non-static method of the model, taking no
     *                      required argument, that computes the column's value (it may read
     *                      other tables). The framework writes the column on save(): on an
     *                      INSERT, and on every UPDATE in which a `from` column changed. The
     *                      value is assigned through setAttribute(), so the column's own kind
     *                      (enum, text_type, type_ref) still applies. Requires `from`.
     *   from             - the list of THIS record's columns the derive method reads (may be
     *                      [], may name another derived column, never the column itself).
     *                      Only allowed beside `derived`.
     *
     * A DERIVED COLUMN IS NEVER ASSIGNED. save() throws a LogicException when one was set by
     * hand - on a new record any assignment at all, even null or the value it would compute -
     * and a bulk update() naming one, Form_Edit::set() of one, and a raw-SQL (Expression)
     * bulk update of a `from` column throw too. Assign the `from` columns instead, or call
     * rederive() when what the method reads lives elsewhere (another table) and has moved.
     * A save that changes only derived columns leaves updated_at and the updated_by pair as
     * they were; a derived column never appears in revision history. An explicit
     * ->raw_bulk()->update() of a `from` column writes no derived value: the caller rederives.
     *
     * At most one of enum / text_type / type_ref per column; never_export and revision_exclude
     * combine with anything except that revision_exclude never sits beside derived (a derived
     * column is never recorded already). A flag is `true` or absent - a kind is removed by
     * replacing the entry, and `'column' => []` declares an explicitly plain column.
     *
     * INHERITANCE: the effective declaration overlays every class from this base down to the
     * model, base first; a class's entry for a column REPLACES its parent's entry for that
     * column wholesale (entries are never merged key by key). A class with nothing to declare
     * declares nothing. A replacing entry may never drop a protection: an entry replacing one
     * that carries never_export carries it too, and an entry for one of the audit type columns
     * below keeps type_ref (MODEL-COLUMNS-01, a manifest-build error).
     *
     * Every rule above is checked at manifest build by MODEL-COLUMNS-01.
     *
     * This base declares the AUDIT type columns: the two authorship pairs every table carries
     * and the deletion pair only soft-deleting tables carry (a cast for a column a table does
     * not have is inert - Eloquent casts only attributes that exist).
     *
     * Read ONLY through columns() and its kind views (enum_columns(), enum_values(),
     * text_type_columns(), type_ref_columns(), never_exported_columns(),
     * revision_excluded_columns(), derived_columns()) - never as a property. Every declared
     * column and every `from` name is checked against the model's table the first time a
     * model with derived columns saves, on rederive() and on Form_Edit::run()
     * (_assert_columns_exist()), and by MODEL-ENUM-01 in rsx:check.
     *
     * @var array<string, array<string, mixed>>
     */
    public static $columns = [
        'created_by_type' => ['type_ref' => true],
        'updated_by_type' => ['type_ref' => true],
        'deleted_by_type' => ['type_ref' => true],
    ];

    /**
     * columns() and its kind views, per model class, for the life of the process. They are
     * facts about the code, so nothing a schema change or a test-database swap does can
     * move them.
     *
     * @var array<string, array<string, array>>
     */
    private static array $_columns_memo = [];

    /**
     * The effective column declarations of this model: column => entry, the $columns of every
     * class from this base down to the model overlaid base first, a class's entry replacing
     * its parent's for the same column.
     *
     * Checks the structure only (an array of arrays keyed by column name), so a class loaded
     * outside a manifest build still fails loud on a malformed declaration.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function columns(): array
    {
        return static::__column_views()['columns'];
    }

    /**
     * Every enum column: column => its value map, in declaration order.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function enum_columns(): array
    {
        return static::__column_views()['enum'];
    }

    /**
     * One enum column's value map. Throws when the column is not an enum - a caller naming
     * a column holds a fact about the model, so a missing declaration is a broken
     * assumption, not an input to handle.
     *
     * @param string $column
     * @return array<int, array<string, mixed>>
     */
    public static function enum_values(string $column): array
    {
        $enum_map = static::__column_views()['enum'];

        if (!array_key_exists($column, $enum_map)) {
            throw new LogicException(static::class . "::{$column} is not an enum column: its \$columns entry has no 'enum'.");
        }

        return $enum_map[$column];
    }

    /**
     * Every column with a declared text type: column => Rsx_Text_Abstract subclass.
     *
     * @return array<string, class-string<\App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract>>
     */
    public static function text_type_columns(): array
    {
        return static::__column_views()['text_type'];
    }

    /**
     * Every polymorphic type-ref column, the base's audit columns included. Every consumer
     * of the declaration (getCasts(), the query builder's WHERE conversion, the orphan
     * report) reads it here.
     *
     * @return array<int, string>
     */
    public static function type_ref_columns(): array
    {
        return static::__column_views()['type_ref'];
    }

    /**
     * Every column that never leaves the server in a payload. toArray(), the revision
     * redaction and the MODEL-NEVER-EXPORT-01 rule read the declaration here.
     *
     * @return array<int, string>
     */
    public static function never_exported_columns(): array
    {
        return static::__column_views()['never_export'];
    }

    /**
     * Every column no revision records: those declared revision_exclude plus every derived
     * column (the automatic exclusions - system columns, updated_at, the updated_by pair -
     * are added by the revision lane itself).
     *
     * @return array<int, string>
     */
    public static function revision_excluded_columns(): array
    {
        return static::__column_views()['revision_exclude'];
    }

    /**
     * Every derived column: column => ['method' => derive method, 'from' => source columns],
     * in DEPENDENCY ORDER - a derived column named in another's `from` comes before it. A
     * cycle among derived columns throws a LogicException naming its path. Empty for a model
     * with none (one memoized read).
     *
     * @return array<string, array{method: string, from: array<int, string>}>
     */
    public static function derived_columns(): array
    {
        return static::__column_views()['derived'];
    }

    /**
     * columns() and each kind view of it, built once per class and memoized.
     *
     * @return array{columns: array, enum: array, text_type: array, type_ref: array, never_export: array, revision_exclude: array, derived: array}
     */
    private static function __column_views(): array
    {
        $class = static::class;

        if (isset(self::$_columns_memo[$class])) {
            return self::$_columns_memo[$class];
        }

        $lineage = [];
        for ($current = $class; $current !== self::class; $current = get_parent_class($current)) {
            $lineage[] = $current;
        }
        $lineage[] = self::class;

        $columns = [];
        foreach (array_reverse($lineage) as $current) {
            $declared = $current::$columns;

            if (!is_array($declared)) {
                throw new LogicException("{$current}::\$columns must be an array of column => entry.");
            }

            foreach ($declared as $column => $entry) {
                if (!is_string($column) || $column === '' || !is_array($entry)) {
                    throw new LogicException(
                        "{$current}::\$columns must map each column name to an array entry, e.g. "
                        . "'status_id' => ['enum' => [...]]. See: php artisan rsx:man model"
                    );
                }

                $columns[$column] = $entry;
            }
        }

        $views = [
            'columns' => $columns,
            'enum' => [],
            'text_type' => [],
            'type_ref' => [],
            'never_export' => [],
            'revision_exclude' => [],
            'derived' => [],
        ];

        $derived = [];

        foreach ($columns as $column => $entry) {
            if (array_key_exists('enum', $entry)) {
                $views['enum'][$column] = $entry['enum'];
            }
            if (array_key_exists('text_type', $entry)) {
                $views['text_type'][$column] = $entry['text_type'];
            }
            foreach (['type_ref', 'never_export', 'revision_exclude'] as $flag) {
                if (($entry[$flag] ?? false) === true) {
                    $views[$flag][] = $column;
                }
            }
            if (array_key_exists('derived', $entry)) {
                $method = $entry['derived'];
                $from = $entry['from'] ?? null;

                if (!is_string($method) || $method === '' || !is_array($from) || !array_is_list($from)) {
                    throw new LogicException(
                        "{$class}::\$columns['{$column}'] declares a derived column as "
                        . "['derived' => 'method_name', 'from' => ['source_column', ...]]. See: php artisan rsx:man model"
                    );
                }

                $derived[$column] = ['method' => $method, 'from' => array_values($from)];
            }
        }

        $views['derived'] = static::__derived_dependency_order($class, $derived);

        foreach (array_keys($views['derived']) as $column) {
            if (!in_array($column, $views['revision_exclude'], true)) {
                $views['revision_exclude'][] = $column;
            }
        }

        return self::$_columns_memo[$class] = $views;
    }

    /**
     * The derived columns sorted so each comes after every derived column its `from` names,
     * declaration order kept otherwise. A cycle throws, naming its path.
     *
     * @param string $class The model, for the message
     * @param array<string, array{method: string, from: array<int, string>}> $derived
     * @return array<string, array{method: string, from: array<int, string>}>
     */
    private static function __derived_dependency_order(string $class, array $derived): array
    {
        $ordered = [];
        $state = [];

        $visit = function (string $column, array $path) use (&$visit, &$ordered, &$state, $derived, $class): void {
            if (($state[$column] ?? null) === 'done') {
                return;
            }

            if (($state[$column] ?? null) === 'active') {
                $cycle = array_slice($path, array_search($column, $path, true));
                $cycle[] = $column;

                throw new LogicException(
                    "{$class}::\$columns declares derived columns that depend on each other in a cycle: "
                    . implode(' -> ', $cycle) . '. A derived column cannot be computed from itself, directly or '
                    . 'through another derived column. See: php artisan rsx:man model'
                );
            }

            $state[$column] = 'active';
            $path[] = $column;

            foreach ($derived[$column]['from'] as $source) {
                if (is_string($source) && isset($derived[$source])) {
                    $visit($source, $path);
                }
            }

            $state[$column] = 'done';
            $ordered[$column] = $derived[$column];
        };

        foreach (array_keys($derived) as $column) {
            $visit($column, []);
        }

        return $ordered;
    }

    /**
     * The declared text type for a column, or null when the column has none.
     *
     * @param string $column
     * @return class-string<\App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract>|null
     */
    public static function text_type_or_null(string $column): ?string
    {
        return static::text_type_columns()[$column] ?? null;
    }

    /**
     * The declared text type for a column.
     *
     * Throws when the column has none - every caller reaches this from a cast that only
     * exists because a declaration put it there, so an absence is a broken invariant and
     * not an input to handle.
     *
     * @param string $column
     * @return class-string<\App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract>
     */
    public static function text_type_for(string $column): string
    {
        $type = static::text_type_or_null($column);

        if ($type === null) {
            shouldnt_happen(static::class . " declares no text type for '{$column}'");
        }

        if (!is_subclass_of($type, \App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract::class)) {
            throw new \LogicException(
                static::class . "::\$columns['{$column}']['text_type'] names {$type}, which does not extend "
                . 'Rsx_Text_Abstract.'
            );
        }

        return $type;
    }

    /**
     * This model's fact groups (see $fact_groups): name => ['label' => ..., 'columns' => [...]].
     *
     * The declaration is checked the first time it is read in a process, against the live
     * columns of the model's table, and throws naming the model for anything malformed: a
     * group that is not ['label' => text, 'columns' => [...]], fewer than two columns, a
     * column in two groups, a column the table does not have. The manifest holds code facts
     * only, so whether a column exists is a question for the database, at first use.
     *
     * @return array<string, array{label: string, columns: array<int, string>}>
     */
    public static function fact_groups(): array
    {
        $declaration = static::$fact_groups;

        if ($declaration === []) {
            return [];
        }

        $memo_key = 'fact_groups|' . static::class;
        $checked = Rsx_Schema::derived($memo_key);

        if ($checked !== null) {
            return $checked;
        }

        $checked = static::_check_fact_groups(
            class_basename(static::class),
            $declaration,
            Rsx_Schema::model_columns(class_basename(static::class))
        );
        Rsx_Schema::remember_derived($memo_key, $checked);

        return $checked;
    }

    /**
     * Check, the first time it is asked in a process, that every column this model's
     * $columns declares below this base, and every `from` column a derived entry names, is a
     * column of the model's table. Throws a LogicException naming the model and the first
     * missing column, and when the table is not in the connected database.
     *
     * The audit type columns this base declares are exempt: the deletion pair exists only on
     * soft-deleting tables. Asked on the WRITE side only - save() of a model with derived
     * columns, rederive(), Form_Edit::run() - and never on an attribute read: a model read
     * during a migration that has not yet added a declared column must still work.
     */
    public static function _assert_columns_exist(): void
    {
        $memo_key = 'columns_checked|' . static::class;

        if (Rsx_Schema::derived($memo_key) !== null) {
            return;
        }

        $model = class_basename(static::class);
        $table_columns = Rsx_Schema::model_columns($model);

        if ($table_columns === null) {
            throw new LogicException(
                "{$model}::\$columns cannot be checked: the model's table is not in the connected database."
            );
        }

        foreach (static::_columns_missing_from($table_columns) as $column => $why) {
            throw new LogicException(
                "{$model}::\$columns {$why} '{$column}', which is not a column of the model's table. "
                . 'Add the column with a migration, or correct the declaration. See: php artisan rsx:man model'
            );
        }

        Rsx_Schema::remember_derived($memo_key, true);
    }

    /**
     * The declared names that are not columns of a table: column => what names it ("declares"
     * or "names in ['x']['from']"). The audit type columns this base declares are exempt.
     * Shared by _assert_columns_exist() and MODEL-ENUM-01.
     *
     * @param array<string, mixed> $table_columns The table's column map, keyed by column name
     * @return array<string, string>
     */
    public static function _columns_missing_from(array $table_columns): array
    {
        $exempt = self::$columns;
        $missing = [];

        foreach (static::columns() as $column => $entry) {
            if (!array_key_exists($column, $exempt) && !array_key_exists($column, $table_columns)) {
                $missing[$column] = 'declares';
            }
        }

        foreach (static::derived_columns() as $column => $spec) {
            foreach ($spec['from'] as $source) {
                if (!isset($missing[$source]) && !array_key_exists($source, $table_columns)) {
                    $missing[$source] = "names in ['{$column}']['from']";
                }
            }
        }

        return $missing;
    }

    /**
     * Check a $fact_groups declaration against a table's columns, answering it normalized.
     * Throws a LogicException naming the model and what is wrong.
     *
     * @param string $model The model's class name, for the message
     * @param mixed $declaration The declared $fact_groups
     * @param array<string, mixed>|null $columns The table's column map (Rsx_Schema), null when
     *     the table is not in the connected database
     * @return array<string, array{label: string, columns: array<int, string>}>
     */
    public static function _check_fact_groups(string $model, mixed $declaration, ?array $columns): array
    {
        $fail = function (string $problem) use ($model): never {
            throw new LogicException(
                "{$model}::\$fact_groups {$problem}. A group is "
                . "'name' => ['label' => 'Shown name', 'columns' => ['column_a', 'column_b', ...]]. "
                . 'See: php artisan rsx:man model (FACT GROUPS)'
            );
        };

        if (!is_array($declaration)) {
            $fail('is not an array');
        }

        if ($columns === null) {
            $fail("cannot be checked: the model's table is not in the connected database");
        }

        $normalized = [];
        $owner = [];

        foreach ($declaration as $name => $group) {
            if (!is_string($name) || $name === '') {
                $fail('has a group with no name: groups are keyed by name');
            }

            if (!is_array($group) || array_diff(array_keys($group), ['label', 'columns']) !== []) {
                $fail("['{$name}'] must be exactly ['label' => ..., 'columns' => [...]]");
            }

            $label = $group['label'] ?? null;
            $group_columns = $group['columns'] ?? null;

            if (!is_string($label) || trim($label) === '') {
                $fail("['{$name}'] has no label: the conflict question names the group by it");
            }

            if (!is_array($group_columns) || !array_is_list($group_columns)) {
                $fail("['{$name}']['columns'] must be a list of column names");
            }

            if (count(array_unique($group_columns, SORT_REGULAR)) < 2) {
                $fail("['{$name}'] names fewer than two columns: one column is already one fact");
            }

            foreach ($group_columns as $column) {
                if (!is_string($column) || $column === '') {
                    $fail("['{$name}']['columns'] holds something that is not a column name");
                }

                if (!array_key_exists($column, $columns)) {
                    $fail("['{$name}'] names '{$column}', which is not a column of the model's table");
                }

                if (isset($owner[$column]) && $owner[$column] !== $name) {
                    $fail("names '{$column}' in both '{$owner[$column]}' and '{$name}': a column belongs to at most one group");
                }

                $owner[$column] = $name;
            }

            $normalized[$name] = ['label' => $label, 'columns' => array_values(array_unique($group_columns))];
        }

        return $normalized;
    }

    /**
     * Get the casts array with automatic type casting based on database schema
     *
     * This method automatically adds type casts by consulting the manifest's
     * database metadata. This eliminates the need to manually define $casts
     * for standard framework conventions.
     *
     * Auto-detected casts:
     * - TINYINT(1) columns → 'boolean'
     * - DATETIME/TIMESTAMP columns → Rsx_DateTime_Cast (returns ISO 8601 UTC strings)
     * - DATE columns → Rsx_Date_Cast (returns YYYY-MM-DD strings)
     * - TIME columns → 'time' (string)
     *
     * IMPORTANT: Date and datetime columns return STRINGS, not Carbon objects.
     * This prevents timezone bugs and keeps PHP/JS in sync with identical formats.
     *
     * Users can still override by defining their own $casts property, and mergeCasts()
     * still adds casts to a single INSTANCE at runtime: the schema-derived half is
     * memoized per class+table (__schema_derived_casts()), the parent's half is not.
     *
     * @return array
     */
    public function getCasts(): array
    {
        // Start with parent casts (includes $casts property + timestamps). Deliberately NOT
        // memoized: mergeCasts() can add casts to a SINGLE instance at runtime, and those
        // must keep winning for that instance and not leak to any other. It is a cheap
        // array read.
        $casts = parent::getCasts();

        // The schema-derived half IS memoized - see __schema_derived_casts() for why that
        // matters far more than it looks. Merged with the parent's casts winning, which is
        // the same precedence the inline version had.
        foreach (static::__schema_derived_casts($this->getTable()) as $column => $cast) {
            if (!isset($casts[$column])) {
                $casts[$column] = $cast;
            }
        }

        return $casts;
    }

    /**
     * The casts implied by the TABLE SCHEMA and the class's type-ref declarations, memoized
     * per class+table for as long as the schema is (Rsx_Schema holds the memo, so
     * Rsx_Schema::reset() drops it together with the column maps it was derived from).
     *
     * Eloquent calls getCasts() from hasCast(), which runs on EVERY attribute read. Building
     * this map inline therefore meant four Manifest column-type scans plus a type-ref pass
     * per column per row - the dominant cost of reading a model attribute, measured by a
     * downstream field report at ~21us per read against ~0.1us for a raw array element.
     * Nothing here varies by record or even by instance: it is a function of the schema and
     * the class.
     *
     * The key is class+table because both inputs vary independently - two models may share a
     * table, and one model may be pointed at a different table.
     *
     * Precedence inside this map is FIRST WINS, in the original declaration order: boolean,
     * datetime, date, time, then the type-ref columns.
     *
     * @param string $table_name
     * @return array<string, string> column => cast
     */
    private static function __schema_derived_casts(string $table_name): array
    {
        $cache_key = 'casts|' . static::class . '|' . $table_name;
        $cached = Rsx_Schema::derived($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        $casts = [];

        // Auto-detect boolean columns (TINYINT(1))
        foreach (Manifest::db_get_columns_by_type($table_name, 'boolean') as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = 'boolean';
            }
        }

        // Auto-detect datetime columns (DATETIME, TIMESTAMP)
        // Returns ISO 8601 UTC strings, NOT Carbon objects
        foreach (Manifest::db_get_columns_by_type($table_name, 'datetime') as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = \App\RSpade\Core\Time\Rsx_DateTime_Cast::class;
            }
        }

        // Auto-detect date columns (DATE)
        // Returns YYYY-MM-DD strings, NOT Carbon objects
        foreach (Manifest::db_get_columns_by_type($table_name, 'date') as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = \App\RSpade\Core\Time\Rsx_Date_Cast::class;
            }
        }

        // Auto-detect time columns (TIME)
        // Laravel's 'time' cast returns strings, which is what we want
        foreach (Manifest::db_get_columns_by_type($table_name, 'time') as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = 'string';
            }
        }

        // Apply type ref casts for polymorphic type columns
        // These columns store integer IDs but expose class name strings
        foreach (static::type_ref_columns() as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = \App\RSpade\Core\Database\TypeRefs\Rsx_Type_Ref_Cast::class;
            }
        }

        // Apply text-value casts for columns with a declared type. Opt-in and last, so a
        // declaration never fights a schema-derived cast: a column is a date or a text
        // type, never both. An explicit $casts entry on a declared column would win here
        // and shadow the text cast, which is why TEXT-TYPE-01 refuses one at build time.
        foreach (array_keys(static::text_type_columns()) as $column) {
            if (!isset($casts[$column])) {
                $casts[$column] = \App\RSpade\Core\Database\TextTypes\Rsx_Text_Cast::class;
            }
        }

        Rsx_Schema::remember_derived($cache_key, $casts);

        return $casts;
    }

    /**
     * Override the fill method to prevent mass assignment
     *
     * Exception: Allow timestamp-only updates from Laravel's query builder
     *
     * @param array $attributes
     * @return $this
     * @throws MassAssignmentException
     */
    public function fill(array $attributes)
    {
        if (empty($attributes)) {
            return $this;
        }

        // Allow if ONLY timestamps are being set (Laravel's query builder does this)
        $timestampColumns = array_filter([
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ]);

        $nonTimestampAttributes = array_diff(array_keys($attributes), $timestampColumns);

        // If only timestamps are being set, allow it (Laravel internals)
        if (empty($nonTimestampAttributes)) {
            return parent::fill($attributes);
        }

        // Otherwise, block mass assignment
        throw new MassAssignmentException(
            get_class($this),
            array_keys($attributes)
        );
    }

    /**
     * Override the forceFill method to prevent mass assignment
     *
     * Exception: Allow timestamp-only updates from Laravel's query builder
     *
     * @param array $attributes
     * @return $this
     * @throws MassAssignmentException
     */
    public function forceFill(array $attributes)
    {
        if (empty($attributes)) {
            return $this;
        }

        // Allow if ONLY timestamps are being set (Laravel's query builder does this)
        $timestampColumns = array_filter([
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ]);

        $nonTimestampAttributes = array_diff(array_keys($attributes), $timestampColumns);

        // If only timestamps are being set, allow it (Laravel internals)
        if (empty($nonTimestampAttributes)) {
            return parent::forceFill($attributes);
        }

        // Otherwise, block mass assignment
        throw new MassAssignmentException(
            get_class($this),
            array_keys($attributes),
            true
        );
    }

    /**
     * Override the create method to prevent mass assignment
     *
     * @param array $attributes
     * @return static
     * @throws MassAssignmentException
     */
    public static function create(array $attributes = [])
    {
        if (!empty($attributes)) {
            throw new MassAssignmentException(
                static::class,
                array_keys($attributes),
                false,
                true,
                'create'
            );
        }

        return parent::create($attributes);
    }

    /**
     * Override the firstOrCreate method to prevent mass assignment
     *
     * @param array $attributes
     * @param array $values
     * @return static
     * @throws MassAssignmentException
     */
    public static function firstOrCreate(array $attributes = [], array $values = [])
    {
        if (!empty($attributes) || !empty($values)) {
            throw new MassAssignmentException(
                static::class,
                array_merge(array_keys($attributes), array_keys($values)),
                false,
                true,
                'firstOrCreate'
            );
        }

        return parent::firstOrCreate($attributes, $values);
    }

    /**
     * Override the firstOrNew method to prevent mass assignment
     *
     * @param array $attributes
     * @param array $values
     * @return static
     * @throws MassAssignmentException
     */
    public static function firstOrNew(array $attributes = [], array $values = [])
    {
        if (!empty($attributes) || !empty($values)) {
            throw new MassAssignmentException(
                static::class,
                array_merge(array_keys($attributes), array_keys($values)),
                false,
                true,
                'firstOrNew'
            );
        }

        return parent::firstOrNew($attributes, $values);
    }

    /**
     * Override the updateOrCreate method to prevent mass assignment
     *
     * @param array $attributes
     * @param array $values
     * @return static
     * @throws MassAssignmentException
     */
    public static function updateOrCreate(array $attributes = [], array $values = [])
    {
        if (!empty($attributes) || !empty($values)) {
            throw new MassAssignmentException(
                static::class,
                array_merge(array_keys($attributes), array_keys($values)),
                false,
                true,
                'updateOrCreate'
            );
        }

        return parent::updateOrCreate($attributes, $values);
    }

    /**
     * Override the update method to prevent mass assignment
     *
     * @param array $attributes
     * @param array $options
     * @return bool
     * @throws MassAssignmentException
     */
    public function update(array $attributes = [], array $options = [])
    {
        if (!empty($attributes)) {
            throw new MassAssignmentException(
                get_class($this),
                array_keys($attributes),
                false,
                false,
                'update'
            );
        }

        return parent::update($attributes, $options);
    }

    /**
     * Allow the constructor to work normally for framework internals
     * but log a warning if attributes are passed
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        if (!empty($attributes) && app()->environment() !== 'testing') {
            // Log warning but don't throw exception in constructor
            // Some Laravel internals need this to work
            logger()->warning('Mass assignment attempted in constructor for ' . static::class, [
                'attributes' => array_keys($attributes),
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]);
        }

        parent::__construct($attributes);
    }

    /**
     * Helper method to create a model with explicit field assignment
     * This is the recommended way to create models in RSpade
     *
     * @return static
     */
    public static function make_new()
    {
        return new static();
    }

    /**
     * Get a helpful example of how to properly create/update this model
     *
     * @return string
     */
    public function get_mass_assignment_example()
    {
        $class = get_class($this);
        $short_class = class_basename($class);
        $var_name = '$' . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short_class));

        return <<<EXAMPLE
// Creating a new {$short_class}:
{$var_name} = new {$short_class}();
{$var_name}->field1 = \$request->input('field1');
{$var_name}->field2 = \$request->input('field2');
{$var_name}->save();

// Updating an existing {$short_class}:
{$var_name} = {$short_class}::find(\$id);
{$var_name}->field1 = \$request->input('field1');
{$var_name}->field2 = \$request->input('field2');
{$var_name}->save();
EXAMPLE;
    }

    /**
     * ==========================================
     * EAGER LOADING PREVENTION
     * ==========================================
     * All forms of eager loading are explicitly disabled in RSpade.
     * Use explicit queries for each relationship instead.
     *
     * load(), loadMissing() and loadCount() refuse a RELATION; handed an empty list they
     * return $this, because a request to load nothing loads nothing.
     */

    /**
     * Re-read this record from the database, in place.
     *
     * The row's attributes are replaced with what the database holds now (unsaved changes
     * are discarded) and every relation and detail row the instance had read is DROPPED
     * rather than reloaded: the next read of one queries again, so it is as current as the
     * row. Eloquent's own refresh() reloads the held relations through load(), which this
     * framework refuses - and it would do so after the attributes were already replaced.
     *
     * A pivot the record was loaded through is kept, as Eloquent keeps it.
     *
     * @return $this
     */
    public function refresh()
    {
        if (!$this->exists) {
            return $this;
        }

        foreach ($this->relations as $name => $relation) {
            $is_pivot = $relation instanceof \Illuminate\Database\Eloquent\Relations\Pivot
                || (is_object($relation) && isset(class_uses_recursive($relation)[\Illuminate\Database\Eloquent\Relations\Concerns\AsPivot::class]));

            if (!$is_pivot) {
                unset($this->relations[$name]);
            }
        }

        $this->_active_detail_loaded = false;
        $this->_active_detail = null;
        $this->_vivified_detail = null;

        return parent::refresh();
    }

    /**
     * Override the with() method to prevent eager loading
     *
     * @param array|string $relations
     * @return \Illuminate\Database\Eloquent\Builder
     * @throws RuntimeException
     */
    public static function with($relations)
    {
        throw new RuntimeException(
            'Eager loading is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to eager load: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Refuse lazy eager loading. An empty relation list is not a load and returns $this.
     *
     * @param array|string|null $relations
     * @return $this
     * @throws RuntimeException when a relation is named
     */
    public function load($relations)
    {
        // Nothing to load is not eager loading. refresh() ends in exactly this call.
        if ($relations === [] || $relations === null) {
            return $this;
        }

        throw new RuntimeException(
            'Lazy eager loading is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to lazy eager load: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Refuse conditional eager loading. An empty relation list is not a load and returns $this.
     *
     * @param array|string $relations
     * @return $this
     * @throws RuntimeException
     */
    public function loadMissing($relations)
    {
        // Nothing to load is not eager loading. refresh() ends in exactly this call.
        if ($relations === [] || $relations === null) {
            return $this;
        }

        throw new RuntimeException(
            'Conditional eager loading is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to conditionally eager load: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Refuse eager loading counts. An empty relation list is not a load and returns $this.
     *
     * @param array|string $relations
     * @return $this
     * @throws RuntimeException
     */
    public function loadCount($relations)
    {
        // Nothing to load is not eager loading. refresh() ends in exactly this call.
        if ($relations === [] || $relations === null) {
            return $this;
        }

        throw new RuntimeException(
            'Eager loading counts is not allowed in the RSpade framework. ' .
            'Use explicit count queries instead. ' .
            'Attempted to eager load counts for: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Override the loadMorph() method to prevent morphed eager loading
     *
     * @param string $relation
     * @param array $relations
     * @return $this
     * @throws RuntimeException
     */
    public function loadMorph($relation, $relations)
    {
        throw new RuntimeException(
            'Morphed eager loading is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to eager load morph relation: ' . $relation
        );
    }

    /**
     * Override the loadAggregate() method to prevent aggregate eager loading
     *
     * @param array|string $relations
     * @param string $column
     * @param string $function
     * @return $this
     * @throws RuntimeException
     */
    public function loadAggregate($relations, $column, $function = null)
    {
        throw new RuntimeException(
            'Aggregate eager loading is not allowed in the RSpade framework. ' .
            'Use explicit aggregate queries instead. ' .
            'Attempted to eager load aggregates for: ' . (is_array($relations) ? implode(', ', $relations) : $relations)
        );
    }

    /**
     * Override the loadMax() method
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws RuntimeException
     */
    public function loadMax($relations, $column)
    {
        throw new RuntimeException(
            'Max eager loading is not allowed in the RSpade framework. ' .
            'Use explicit max queries instead.'
        );
    }

    /**
     * Override the loadMin() method
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws RuntimeException
     */
    public function loadMin($relations, $column)
    {
        throw new RuntimeException(
            'Min eager loading is not allowed in the RSpade framework. ' .
            'Use explicit min queries instead.'
        );
    }

    /**
     * Override the loadSum() method
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws RuntimeException
     */
    public function loadSum($relations, $column)
    {
        throw new RuntimeException(
            'Sum eager loading is not allowed in the RSpade framework. ' .
            'Use explicit sum queries instead.'
        );
    }

    /**
     * Override the loadAvg() method
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws RuntimeException
     */
    public function loadAvg($relations, $column)
    {
        throw new RuntimeException(
            'Avg eager loading is not allowed in the RSpade framework. ' .
            'Use explicit avg queries instead.'
        );
    }

    /**
     * Override the loadExists() method
     *
     * @param array|string $relations
     * @return $this
     * @throws RuntimeException
     */
    public function loadExists($relations)
    {
        throw new RuntimeException(
            'Exists eager loading is not allowed in the RSpade framework. ' .
            'Use explicit exists queries instead.'
        );
    }

    /**
     * ==========================================
     * QUERY BUILDER CUSTOMIZATION
     * ==========================================
     */

    /**
     * Create a new Eloquent query builder that blocks eager loading
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newEloquentBuilder($query)
    {
        return new RestrictedEloquentBuilder($query);
    }

    /**
     * Override newCollection to prevent eager loading via collections
     * Collections can also trigger eager loading through methods like load()
     *
     * @param array $models
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function newCollection(array $models = [])
    {
        return new \App\Database\RestrictedEloquentCollection($models);
    }

    /**
     * ==========================================
     * AJAX ORM INTERFACE
     * ==========================================
     */

    /**
     * Fetch model record(s) by ID for Ajax ORM
     *
     * This method MUST be overridden in child classes that want to enable Ajax fetching.
     * Child classes must:
     * 1. Add the #[Ajax_Endpoint_Model_Fetch] attribute to their fetch() implementation
     * 2. Check authorization (e.g., can current user view this record?)
     * 3. Filter sensitive data (e.g., remove password fields)
     *
     * @param int|array $id Single ID or array of IDs
     * @throws RuntimeException Always - this method must be implemented by child classes
     */
    #[Replaceable]
    public static function fetch($id)
    {
        throw new RuntimeException(
            "Cannot call fetch() on abstract Rsx_Model_Abstract class directly.\n\n" .
            'To enable Ajax fetching for ' . static::class . ", you must:\n" .
            "1. Override the fetch() method in your model class\n" .
            "2. Add the #[Ajax_Endpoint_Model_Fetch] attribute to your fetch() method\n" .
            "3. Implement authorization checks (can current user view these records?)\n" .
            "4. Filter sensitive data before returning (e.g., unset password fields)\n\n" .
            "Example implementation:\n\n" .
            "#[Ajax_Endpoint_Model_Fetch]\n" .
            "public static function fetch(\$id)\n" .
            "{\n" .
            "    // Check authorization\n" .
            "    if (!Session::is_logged_in()) {\n" .
            "        return false;\n" .
            "    }\n\n" .
            "    // Fetch single record\n" .
            "    \$model = static::find(\$id);\n" .
            "    return \$model ?: false;\n" .
            '}'
        );
    }

    /**
     * Get table name statically without instantiating the model
     * Cached to avoid repeated instantiation
     *
     * @return string The table name for this model
     */
    public static function get_table_static()
    {
        static $tableToModelMap = [];
        $className = get_called_class();

        if (empty($tableToModelMap[$className])) {
            $instance = new $className();
            $tableToModelMap[$className] = $instance->getTable();
        }

        return $tableToModelMap[$className];
    }

    /**
     * Get column listing for this model's table
     * Uses Laravel's Schema facade to get column information
     *
     * @return array Array of column names
     */
    public static function getColumns()
    {
        $tableName = self::get_table_static();

        return Schema::getColumnListing($tableName);
    }

    /**
     * Check if a column exists in this model's table
     *
     * @param string $column Column name to check
     * @return bool True if column exists
     */
    public static function hasColumn($column)
    {
        $columns = self::getColumns();

        return in_array($column, $columns);
    }

    /**
     * Max character length of a varchar/char column, or null for every other column type.
     *
     * The ONE definition of "how long may this field be", read from the live schema
     * (Rsx_Schema). The lengths a page's JavaScript reads (Field_Length_Script, behind
     * Rsx_Js_Model.field_length()) are this method's answers, so a server-side length check
     * and the client's cannot drift, and app code never has to mirror a schema literal in a
     * constant.
     *
     * Null means "this column has no character limit" (an int, a datetime, a text blob) - it
     * is a real answer, never a lookup failure. A column the model does not have is a coding
     * error and throws, matching the manifest's fail-fast lookups: silently answering null
     * would let a typo'd column name read as "unlimited" and defeat the check being written.
     *
     * Class-Table Inheritance: a base model's column map has its detail-table columns merged
     * in, so a base model answers for its detail columns too.
     *
     * @param string $column Column name
     * @return int|null Max length for varchar/char, null for any other type
     * @throws RuntimeException When the class is not a registered model, its table does not
     *                          exist in the connected database, or it has no such column
     */
    public static function field_length(string $column): ?int
    {
        $class_name = Manifest::_normalize_class_name(get_called_class());

        // Rsx_Schema memoizes the merged map per class, and drops it on reset().
        $columns = Rsx_Schema::model_columns($class_name);

        if ($columns === null) {
            $record = Manifest::php_model_record($class_name);

            if ($record === null) {
                throw new RuntimeException("field_length(): {$class_name} is not a model in the manifest's registry");
            }

            throw new RuntimeException(
                "field_length(): table '{$record['table']}' ({$class_name}) does not exist in the "
                . 'connected database. Run `php artisan migrate`.'
            );
        }

        if (!array_key_exists($column, $columns)) {
            throw new RuntimeException("field_length(): {$class_name} has no column '{$column}'");
        }

        return $columns[$column]['max_length'] ?? null;
    }

    /**
     * Re-entrancy depth of a SINGLE model write (this class's own save()/delete()). A single
     * write already routes its DB statement through RestrictedEloquentBuilder::update()/delete()
     * (Eloquent's performUpdate / performDeleteOnModel run through the model's builder), so the
     * builder must be able to tell "this update()/delete() IS a model's own single-row write"
     * (do the raw statement — the model emits realtime + hooks itself) from "this is a genuine
     * user bulk statement" (fetch-then-iterate). The model bumps this counter strictly around
     * its parent::save()/parent::delete() DB write; the builder reads it (> 0 => internal).
     *
     * A COUNTER, not a boolean: an Eloquent saving/updating/updated observer may itself write
     * another model inside parent::save(); that nested single write must not clear the outer
     * write's guard, so the flag is active while depth > 0.
     *
     * @var int
     */
    public static int $_single_model_write_depth = 0;

    /**
     * Whether the current call stack is inside a single model's own save()/delete() DB write
     * (so a builder update()/delete() reached now is that write's raw single-row statement, not
     * a user bulk op). The single choke point the builder consults for its re-entrancy guard.
     */
    public static function _is_inside_single_model_write(): bool
    {
        return self::$_single_model_write_depth > 0;
    }

    /**
     * The raw value the framework last wrote into each derived column of this instance
     * (column => raw attribute), so the guard can tell its own write from a hand assignment:
     * a save that threw after deriving can be retried, while an assignment made after the
     * framework's write still throws. Cleared by a successful save.
     *
     * @var array<string, mixed>
     */
    private array $__derived_written = [];

    /**
     * Set by rederive() around its save(): every derived column is recomputed, not only those
     * whose `from` columns changed.
     */
    private bool $__rederive_all = false;

    /**
     * Set around the write of a save that changes only derived columns, so the UPDATE holds
     * updated_at where it is (see _merge_derived_only_write()).
     */
    private bool $__derived_only_write = false;

    /**
     * Override save to derive the derived columns, stamp authorship, and fire the write's
     * effects (revision, text-type hooks, realtime, lifecycle) on a committed write.
     */
    public function save(array $options = [])
    {
        // A migration's up() never writes through a model (Migration_Guard): before anything else.
        Migration_Guard::_refuse_model_write(static::class, 'save()');

        // CTI: the discriminator is immutable once set (it selects the detail table; changing
        // it would orphan the existing detail). Reject before the write.
        $this->__assert_detail_discriminator_immutable();

        // Snapshot existence BEFORE the write: an INSERT is a not-yet-existing record that
        // exists after a successful save. wasRecentlyCreated is unreliable here — it stays
        // true for the instance's lifetime, so reusing one instance for a later update would
        // misclassify the update as a create.
        $was_existing = $this->exists;

        // DERIVED COLUMNS: refuse a hand assignment, then compute every derived column whose
        // sources changed (all of them on an insert or a rederive()). One memoized read for a
        // model that declares none.
        $derived = static::derived_columns();
        if ($derived !== []) {
            static::_assert_columns_exist();
            $this->__assert_no_explicit_derived_assignment($derived, $was_existing);
            $this->__apply_derivations($derived, $was_existing, $this->__rederive_all);
        }

        // A save whose only changes are derived columns records nobody as the updater and
        // leaves updated_at alone: nobody edited the record, the framework kept it consistent.
        $derived_only = $derived !== [] && $was_existing && $this->__dirty_only_derived($derived);

        // Stamp authorship BEFORE the write so the columns ride the same INSERT/UPDATE.
        if (!$derived_only) {
            $this->__apply_audit_stamp($was_existing);
        }

        // Snapshot the BEFORE values while they still exist. parent::save() ends with
        // syncOriginal(), so once the write returns the "original" IS the new row and the
        // before half of every pair is gone. Null when this model records no revisions,
        // which is one static read on the hot path.
        $revision_original = $this->_revision_original_snapshot();

        // Same reason, for the declared text types that react to their own writes: the
        // before value of each changed text column, keyed by column (see
        // Rsx_Text_Abstract::after_model_saved()). Empty for a model with none.
        $text_hook_before = $this->__text_hook_before_snapshot($was_existing);

        // Guard the DB write: any RestrictedEloquentBuilder::update() reached from inside
        // parent::save() (Eloquent's performUpdate) is THIS record's own single-row write and
        // must run raw — never re-enter the user-bulk fetch-then-iterate path (that would
        // recurse). Decremented in finally so an exception cannot leave the guard stuck on.
        // A derived-only write also turns Eloquent's timestamps off for its duration and asks
        // the builder to hold updated_at in the statement (MySQL's ON UPDATE would bump it).
        $uses_timestamps = $this->timestamps;
        if ($derived_only) {
            $this->timestamps = false;
            $this->__derived_only_write = true;
        }

        self::$_single_model_write_depth++;
        try {
            $result = parent::save($options);
        } finally {
            self::$_single_model_write_depth--;

            if ($derived_only) {
                $this->timestamps = $uses_timestamps;
                $this->__derived_only_write = false;
            }
        }

        if ($result) {
            $this->_dispatch_write_effects_on_save($was_existing, $revision_original, $text_hook_before);
            $this->__derived_written = [];
        }

        return $result;
    }

    /**
     * Recompute every derived column from the record as stored and save it when, and only
     * when, a value differs. Answers whether it saved. For when what a derive method reads
     * lives outside this record (another table) and has moved; a change to a `from` column
     * of this record is derived by save() itself.
     *
     * The save is the model's own save(), so site-scope checks and a model's save() override
     * run; it is a derived-only save (no updated_at, no updated_by, no revision; realtime and
     * after_update() still fire). A record whose values are already right is never saved.
     *
     * Throws on an unsaved record, and with a LogicException on a record with unsaved
     * changes - those would ride the same save unasked. False for a model with no derived
     * columns.
     */
    public function rederive(): bool
    {
        if (!$this->exists) {
            shouldnt_happen(static::class . '::rederive() needs a saved record: an unsaved one is derived by its first save().');
        }

        $derived = static::derived_columns();

        if ($derived === []) {
            return false;
        }

        if ($this->isDirty()) {
            throw new LogicException(
                static::class . " #{$this->getKey()}::rederive() refused: the record has unsaved changes ("
                . implode(', ', array_keys($this->getDirty())) . '). Save them, or discard them with refresh(), first.'
            );
        }

        static::_assert_columns_exist();
        $this->__assign_all_derivations($derived);

        if (!$this->isDirty()) {
            $this->__derived_written = [];

            return false;
        }

        $this->__rederive_all = true;
        try {
            return (bool) $this->save();
        } finally {
            $this->__rederive_all = false;
        }
    }

    /**
     * The derived columns whose stored value differs from a fresh computation, as column =>
     * [stored raw value, computed raw value]. Saves nothing and leaves the record exactly as
     * it was (attributes and dirty state). Empty for a model with no derived columns.
     *
     * Throws like rederive() on an unsaved record or one with unsaved changes. Framework-
     * internal ('_' prefix): the verify path reports with it.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function _derived_drift(): array
    {
        if (!$this->exists) {
            shouldnt_happen(static::class . '::_derived_drift() needs a saved record.');
        }

        $derived = static::derived_columns();

        if ($derived === []) {
            return [];
        }

        if ($this->isDirty()) {
            throw new LogicException(
                static::class . " #{$this->getKey()}::_derived_drift() refused: the record has unsaved changes ("
                . implode(', ', array_keys($this->getDirty())) . ').'
            );
        }

        $attributes = $this->getAttributes();
        $written = $this->__derived_written;
        $drift = [];

        try {
            $this->__assign_all_derivations($derived);

            foreach (array_keys($derived) as $column) {
                if ($this->isDirty($column)) {
                    $drift[$column] = [$this->getRawOriginal($column), $this->getAttributes()[$column] ?? null];
                }
            }
        } finally {
            $this->setRawAttributes($attributes);
            $this->__derived_written = $written;
        }

        return $drift;
    }

    /**
     * Refuse a derived column that was assigned by hand (see $__derived_written for how the
     * framework's own write is told apart). On an existing record a dirty derived column
     * throws; on a new record any presence of one does, even null or the right value.
     *
     * @param array<string, array{method: string, from: array<int, string>}> $derived
     */
    private function __assert_no_explicit_derived_assignment(array $derived, bool $was_existing): void
    {
        $attributes = $this->getAttributes();

        foreach ($derived as $column => $spec) {
            $assigned = $was_existing ? $this->isDirty($column) : array_key_exists($column, $attributes);

            if (!$assigned) {
                continue;
            }

            if (array_key_exists($column, $this->__derived_written)
                && ($attributes[$column] ?? null) === $this->__derived_written[$column]) {
                continue;
            }

            $model = class_basename(static::class);

            throw new LogicException(
                "{$model}::{$column} is a derived column - {$model}::{$spec['method']}() computes it from ["
                . implode(', ', $spec['from']) . '] on save. It is never assigned. Assign its source column(s), '
                . 'or call rederive().'
            );
        }
    }

    /**
     * Compute the derived columns that need it, in dependency order: every one on an insert
     * or when forced, otherwise those with a changed `from` column (a derived column that
     * just changed counts as changed for the ones after it). An identical value stays clean.
     *
     * @param array<string, array{method: string, from: array<int, string>}> $derived
     */
    private function __apply_derivations(array $derived, bool $was_existing, bool $force): void
    {
        if (!$was_existing || $force) {
            $this->__assign_all_derivations($derived);

            return;
        }

        foreach ($derived as $column => $spec) {
            foreach ($spec['from'] as $source) {
                if ($this->isDirty($source)) {
                    $this->__assign_derivation($column, $spec['method']);
                    break;
                }
            }
        }
    }

    /**
     * Compute and assign every derived column, in dependency order. The one compute path of
     * save(), rederive() and _derived_drift().
     *
     * @param array<string, array{method: string, from: array<int, string>}> $derived
     */
    private function __assign_all_derivations(array $derived): void
    {
        foreach ($derived as $column => $spec) {
            $this->__assign_derivation($column, $spec['method']);
        }
    }

    /**
     * Compute one derived column and assign it through setAttribute(), so its cast applies,
     * recording the raw value written.
     */
    private function __assign_derivation(string $column, string $method): void
    {
        $this->setAttribute($column, $this->{$method}());
        $this->__derived_written[$column] = $this->getAttributes()[$column] ?? null;
    }

    /**
     * Whether this save changes something, and every change is a derived column.
     *
     * @param array<string, array{method: string, from: array<int, string>}> $derived
     */
    private function __dirty_only_derived(array $derived): bool
    {
        $dirty = array_keys($this->getDirty());

        return $dirty !== [] && array_diff($dirty, array_keys($derived)) === [];
    }

    /**
     * Hold updated_at in a derived-only save's UPDATE: `updated_at = updated_at`. Eloquent's
     * timestamps are off for that write, but every normalized table declares updated_at
     * ON UPDATE CURRENT_TIMESTAMP, which MySQL applies to any UPDATE that does not name the
     * column. Returns $values untouched for every other write, and for a record that has no
     * updated_at.
     *
     * Framework-internal ('_' prefix); the builder's single-model-write branch is its only
     * caller.
     *
     * @param array<string, mixed> $values The columns the UPDATE is about to write
     * @return array<string, mixed>
     */
    public function _merge_derived_only_write(array $values): array
    {
        if (!$this->__derived_only_write) {
            return $values;
        }

        $column = $this->getUpdatedAtColumn();

        if ($column === null || array_key_exists($column, $values) || !array_key_exists($column, $this->getAttributes())) {
            return $values;
        }

        $values[$column] = DB::raw($this->getConnection()->getQueryGrammar()->wrap($column));

        return $values;
    }

    /**
     * Re-entrancy guard for the audit stamp. Resolving the actor reads the session facades,
     * and reading a session can itself write the _sessions row - a nested save() that must
     * not try to resolve the actor again (infinite recursion). While this is true the stamp
     * is skipped entirely.
     *
     * @var bool
     */
    private static bool $_audit_stamp_resolving = false;

    /**
     * The framework audit authorship columns. Present on every table (the schema-hygiene
     * command puts them there), NULL until an identified actor writes the record.
     */
    private const AUDIT_COLUMNS = ['created_by_id', 'created_by_type', 'updated_by_id', 'updated_by_type'];

    /**
     * The framework audit DELETION columns. Present only on tables that soft-delete: a hard
     * delete removes the row, so there is nothing to stamp and no column worth carrying. The
     * schema-hygiene command puts them on every table that has a deleted_at.
     */
    private const AUDIT_DELETED_COLUMNS = ['deleted_by_id', 'deleted_by_type'];

    /**
     * The ONLY class names the audit stamp will ever write into a *_by_type column,
     * keyed by the cell of the stamp matrix they serve. This is the single declaration
     * of that set: __resolve_audit_actor() reads it rather than repeating the literals.
     *
     * Public because the ACTOR-01 code-quality rule reads it to verify, at manifest
     * build time, that each named class still extends the actor layer - including after
     * a downstream app replaces one through the class-override pattern. A stamp target
     * that is not an actor would produce audit columns nothing can resolve a name for.
     *
     * @var array<string, string>
     */
    public const AUDIT_ACTOR_MODELS = [
        'staff_site' => 'User_Model',
        'portal' => 'Portal_User_Model',
        'cross_site' => 'Login_User_Model',
        'companion' => 'Companion_Actor_Model',
    ];

    /**
     * Per-process memo of get_relationships(), keyed by FQCN. isRelation() is on Eloquent's
     * attribute-miss path, so the manifest lookup behind it is worth doing once per class.
     *
     * @var array<string, array<int, string>>
     */
    private static array $_relationships_cache = [];

    /**
     * Stamp WHO is writing this record.
     *
     * INSERT stamps both pairs; UPDATE stamps only the updated_by pair, and only when the
     * write is going to happen anyway (an update with nothing dirty stays a no-op - stamping
     * it would manufacture a write). An explicitly-assigned value always wins: application
     * code that sets its own authorship is never overwritten.
     *
     * THE STAMP MATRIX. "Sites match" means the record is site-scoped AND its site_id equals
     * the actor's session site AND that site is not 0 (0 is "no site selected", not a site):
     *
     *   | actor                                  | sites match | stamp                    |
     *   |----------------------------------------|-------------|--------------------------|
     *   | portal user (portal request)           | yes         | Portal_User_Model        |
     *   | portal user (portal request)           | no          | NOTHING (see below)      |
     *   | staff (web, CLI, API, impersonating)   | yes         | User_Model               |
     *   | staff                                  | no          | Login_User_Model         |
     *   | a companion request                    | -           | Companion_Actor_Model    |
     *   | nobody signed in                       | -           | NOTHING                  |
     *
     * The portal "no" cell is deliberately empty rather than a Login_User_Model stamp: a
     * portal account has no cross-site identity at all, so there is nothing truthful to
     * write. A NULL pair reads as "unattributed", which is the honest answer; inventing an
     * identity would be a lie in an audit column.
     *
     * Impersonation is intentionally invisible here - the stamp records the EFFECTIVE actor
     * (who the write is attributed to), which is what an audit column asks. The impersonator
     * is recorded on the session.
     *
     * A table without the columns is skipped. A migration's up() never saves through a model
     * (Migration_Guard), but the framework helpers it may call under Migration_Guard::allow()
     * (the file-attachment repairs) do, against a schema that has not been converged yet.
     *
     * THE DELETION PAIR IS ALSO HANDLED HERE, but only for the saves that move deleted_at:
     * a record whose deleted_at goes null -> set is stamped with its deleter, and one whose
     * deleted_at goes set -> null (a restore) has the pair CLEARED. The soft delete performed
     * by SoftDeletes::delete() does NOT come through save() at all - Laravel's runSoftDelete()
     * writes its own two-column UPDATE and ignores dirty attributes - so that path is stamped
     * at the write choke point instead (see _merge_soft_delete_stamp).
     *
     * @param bool $was_existing Whether the record existed before this save (false = INSERT)
     */
    private function __apply_audit_stamp(bool $was_existing): void
    {
        if (self::$_audit_stamp_resolving) {
            return;
        }

        $has_authorship = $this->__table_has_audit_columns();
        $has_deletion = $this->__table_has_deleted_audit_columns();

        if (!$has_authorship && !$has_deletion) {
            return;
        }

        // An UPDATE that changes nothing must remain a no-op.
        if ($was_existing && !$this->isDirty()) {
            return;
        }

        $attributes = $this->getAttributes();

        $deleted_by_is_explicit = $was_existing
            ? ($this->isDirty('deleted_by_id') || $this->isDirty('deleted_by_type'))
            : (array_key_exists('deleted_by_id', $attributes) || array_key_exists('deleted_by_type', $attributes));

        $deleted_at_column = $this->__deleted_at_column();

        // RESTORE. Clearing the pair is not an attribution, so it happens whether or not there
        // is an actor to name - a restored record is not deleted BY anybody, exactly as its
        // deleted_at is no longer set.
        if ($has_deletion
            && !$deleted_by_is_explicit
            && $this->isDirty($deleted_at_column)
            && $this->{$deleted_at_column} === null) {
            $this->deleted_by_type = null;
            $this->deleted_by_id = null;
        }

        self::$_audit_stamp_resolving = true;
        try {
            $actor = $this->__resolve_audit_actor();
        } finally {
            self::$_audit_stamp_resolving = false;
        }

        if ($actor === null) {
            return;
        }

        // A pair is treated as ONE unit: if either half was assigned by hand, the whole pair
        // is the application's to own (a half-set morph pair fails loud on read, so it must
        // never be the framework mixing its stamp into a hand-written one).
        if ($has_authorship) {
            if (!$was_existing
                && !array_key_exists('created_by_id', $attributes)
                && !array_key_exists('created_by_type', $attributes)) {
                $this->created_by_type = $actor['type'];
                $this->created_by_id = $actor['id'];
            }

            $updated_by_is_explicit = $was_existing
                ? ($this->isDirty('updated_by_id') || $this->isDirty('updated_by_type'))
                : (array_key_exists('updated_by_id', $attributes) || array_key_exists('updated_by_type', $attributes));

            if (!$updated_by_is_explicit) {
                $this->updated_by_type = $actor['type'];
                $this->updated_by_id = $actor['id'];
            }
        }

        // SOFT DELETE performed by hand (deleted_at assigned, then save()) - the shape
        // File_Attachment_Model::force_destroy() uses. Only a transition INTO the deleted
        // state stamps; re-saving an already-deleted record does not rewrite its deleter.
        if ($has_deletion
            && !$deleted_by_is_explicit
            && $this->isDirty($deleted_at_column)
            && $this->{$deleted_at_column} !== null) {
            $this->deleted_by_type = $actor['type'];
            $this->deleted_by_id = $actor['id'];
        }
    }

    /**
     * The soft-delete timestamp column. Always `deleted_at` by framework schema convention;
     * resolved through the trait when the model has it so the two stamp paths key on exactly
     * the same column Laravel does.
     *
     * @return string
     */
    private function __deleted_at_column(): string
    {
        return method_exists($this, 'getDeletedAtColumn') ? $this->getDeletedAtColumn() : 'deleted_at';
    }

    /**
     * Merge the deletion stamp into a soft delete's UPDATE values, so deleted_by_id/_type ride
     * the SAME statement that sets deleted_at.
     *
     * WHY THIS IS NOT IN save(). A SoftDeletes delete never reaches save(): Laravel's
     * runSoftDelete() builds its own two-column array ([deleted_at, updated_at]), calls
     * $query->update() with it, and DISCARDS every dirty attribute on the model. Setting the
     * pair before parent::delete() would therefore write nothing. The model's own builder is
     * where that UPDATE actually lands (RestrictedEloquentBuilder::update(), under the
     * single-model-write guard), so that is where the pair is merged in - one statement, no
     * follow-up write against a row that is already soft-deleted.
     *
     * Framework-internal ('_' prefix); the builder is its only caller.
     *
     * @param array<string, mixed> $values The columns the soft delete is about to write.
     * @return array<string, mixed> The same array, plus the deletion pair when one applies.
     */
    public function _merge_soft_delete_stamp(array $values): array
    {
        if (self::$_audit_stamp_resolving) {
            return $values;
        }

        // Not a soft-deleting model at all (getDeletedAtColumn() comes from the trait).
        if (!method_exists($this, 'getDeletedAtColumn')) {
            return $values;
        }

        $deleted_at_column = $this->__deleted_at_column();

        // Not a soft delete: an ordinary performUpdate whose dirty set happens to arrive here,
        // or a restore (deleted_at -> null), which rides save() and is stamped there.
        if (!array_key_exists($deleted_at_column, $values) || $values[$deleted_at_column] === null) {
            return $values;
        }

        // Already carried by the save() path (force_destroy's hand-assigned deleted_at).
        if (array_key_exists('deleted_by_id', $values) || array_key_exists('deleted_by_type', $values)) {
            return $values;
        }

        if (!$this->__table_has_deleted_audit_columns()) {
            return $values;
        }

        // EXPLICIT WINS - and here it also repairs a Laravel behavior: a caller that assigned
        // the pair before calling delete() would otherwise have those attributes silently
        // dropped by runSoftDelete(). Carrying them into the statement is what honors them.
        if (!$this->isDirty('deleted_by_id') && !$this->isDirty('deleted_by_type')) {
            self::$_audit_stamp_resolving = true;
            try {
                $actor = $this->__resolve_audit_actor();
            } finally {
                self::$_audit_stamp_resolving = false;
            }

            if ($actor === null) {
                return $values;
            }

            // Assign through the model so the type-ref cast converts the class name to its
            // registry id (and registers it) - and so the in-memory record matches the row.
            $this->deleted_by_type = $actor['type'];
            $this->deleted_by_id = $actor['id'];
        }

        // Raw attributes: the statement takes stored values, not cast-facing class names.
        $raw = $this->getAttributes();
        $values['deleted_by_id'] = $raw['deleted_by_id'] ?? null;
        $values['deleted_by_type'] = $raw['deleted_by_type'] ?? null;

        return $values;
    }

    /**
     * Whether this model's table carries the audit authorship pair.
     *
     * Two tiers, both cheap. Rsx_Schema's column map is consulted first and is free - in
     * steady state it answers every call with no query at all. It is only ever trusted when
     * AFFIRMATIVE; anything else (a table or a column that arrived after this process read
     * the schema - the run of migrate that created it, writing to it afterwards) falls through
     * to one authoritative column listing. The answer is memoized with the schema itself
     * (Rsx_Schema::derived()), separately per column set: every table has the authorship
     * pairs, only a soft-deleting table has the deletion pair.
     *
     * @return bool
     */
    private function __table_has_audit_columns(): bool
    {
        return $this->__table_has_column_set('authorship', self::AUDIT_COLUMNS);
    }

    /**
     * Whether this model's table carries the audit DELETION pair.
     *
     * Asked SEPARATELY from the authorship pairs and memoized under its own key, because the
     * answers genuinely differ per table: every table has created_by/updated_by, only a table
     * with deleted_at has deleted_by. Folding the two questions into one check would turn off
     * the authorship stamp on every hard-deleting table in the schema.
     *
     * @return bool
     */
    private function __table_has_deleted_audit_columns(): bool
    {
        return $this->__table_has_column_set('deletion', self::AUDIT_DELETED_COLUMNS);
    }

    /**
     * Whether this model's table carries every column of one named audit set. See
     * __table_has_audit_columns() for the two-tier resolution this implements.
     *
     * @param string $set_name Memo key discriminator ('authorship' / 'deletion')
     * @param array<int, string> $columns
     * @return bool
     */
    private function __table_has_column_set(string $set_name, array $columns): bool
    {
        $table = $this->getTable();
        $memo_key = 'audit|' . $table . '|' . $set_name;

        $memoized = Rsx_Schema::derived($memo_key);
        if ($memoized !== null) {
            return $memoized;
        }

        $schema_columns = Rsx_Schema::table_columns($table);
        foreach ($columns as $column) {
            if (!isset($schema_columns[$column])) {
                $schema_columns = null;
                break;
            }
        }

        if ($schema_columns !== null) {
            Rsx_Schema::remember_derived($memo_key, true);

            return true;
        }

        $live_columns = Schema::getColumnListing($table);
        $present = true;
        foreach ($columns as $column) {
            if (!in_array($column, $live_columns, true)) {
                $present = false;
                break;
            }
        }

        Rsx_Schema::remember_derived($memo_key, $present);

        return $present;
    }

    /**
     * Resolve the actor to stamp on this record, per the matrix documented on
     * __apply_audit_stamp(). Returns ['type' => simple class name, 'id' => int] or null when
     * there is nobody truthful to name.
     *
     * @return array{type: string, id: int}|null
     */
    private function __resolve_audit_actor(): ?array
    {
        // A companion request has no signed-in identity: the companion itself is the writer.
        $companion_actor_id = \App\RSpade\Core\Companion\Companion_Dispatcher::current_actor_id();
        if ($companion_actor_id !== null) {
            return ['type' => self::AUDIT_ACTOR_MODELS['companion'], 'id' => $companion_actor_id];
        }

        // Portal realm. A portal request is a wholly separate identity space - never fall
        // through to the staff facade, which would attribute a client's write to whatever
        // staff cookie happens to be in the same browser.
        if (\App\RSpade\Lib\Portal\Rsx_Portal::is_portal_request()) {
            if (!\App\RSpade\Lib\Portal\Portal_Session::is_logged_in()) {
                return null;
            }

            $portal_user_id = \App\RSpade\Lib\Portal\Portal_Session::get_portal_user_id();
            if (empty($portal_user_id)) {
                return null;
            }

            if (!$this->__record_site_matches(\App\RSpade\Lib\Portal\Portal_Session::get_site_id())) {
                return null;
            }

            return ['type' => self::AUDIT_ACTOR_MODELS['portal'], 'id' => (int) $portal_user_id];
        }

        $login_user_id = \App\RSpade\Core\Session\Session::get_login_user_id();
        if (empty($login_user_id)) {
            return null;
        }

        if ($this->__record_site_matches(\App\RSpade\Core\Session\Session::get_site_id())) {
            $user_id = \App\RSpade\Core\Session\Session::get_user_id();
            if (!empty($user_id)) {
                return ['type' => self::AUDIT_ACTOR_MODELS['staff_site'], 'id' => (int) $user_id];
            }
        }

        return ['type' => self::AUDIT_ACTOR_MODELS['cross_site'], 'id' => (int) $login_user_id];
    }

    /**
     * The actor a revision of this record names: the one this record's own audit stamp names
     * for the current write, per the stamp matrix. A revision row is not a tenant's row, so
     * its own stamp could never match a site and would always name the cross-site identity;
     * Revision::_record() assigns this pair instead, so a record and its history agree on who
     * wrote it.
     *
     * @return array{type: string, id: int}|null
     */
    public function _revision_author(): ?array
    {
        return $this->__resolve_audit_actor();
    }

    /**
     * Resolve the actor for something that is NOT a record: a unit of work, a log line - a
     * revision transaction. Returns ['type' => simple class name, 'id' => int] or null when
     * nobody identifiable is acting.
     *
     * Same matrix as __resolve_audit_actor(), minus the one question that needs a record:
     * "do the sites match". A transaction is not a row in a tenant's table, so there is no
     * site to compare against - the staff branch therefore names User_Model whenever the
     * session has a site-scoped user and Login_User_Model otherwise, which is exactly what
     * the record version does once the record's site is out of the picture.
     *
     * Public because it is the seam Revision::transaction_id() resolves its actor through;
     * the record version stays private, because a caller holding a record must use the
     * matrix that considers it.
     *
     * @return array{type: string, id: int}|null
     */
    public static function _resolve_context_actor(): ?array
    {
        $companion_actor_id = \App\RSpade\Core\Companion\Companion_Dispatcher::current_actor_id();
        if ($companion_actor_id !== null) {
            return ['type' => self::AUDIT_ACTOR_MODELS['companion'], 'id' => $companion_actor_id];
        }

        if (\App\RSpade\Lib\Portal\Rsx_Portal::is_portal_request()) {
            if (!\App\RSpade\Lib\Portal\Portal_Session::is_logged_in()) {
                return null;
            }

            $portal_user_id = \App\RSpade\Lib\Portal\Portal_Session::get_portal_user_id();

            if (empty($portal_user_id)) {
                return null;
            }

            return ['type' => self::AUDIT_ACTOR_MODELS['portal'], 'id' => (int) $portal_user_id];
        }

        $login_user_id = \App\RSpade\Core\Session\Session::get_login_user_id();

        if (empty($login_user_id)) {
            return null;
        }

        $user_id = \App\RSpade\Core\Session\Session::get_user_id();

        if (!empty($user_id)) {
            return ['type' => self::AUDIT_ACTOR_MODELS['staff_site'], 'id' => (int) $user_id];
        }

        return ['type' => self::AUDIT_ACTOR_MODELS['cross_site'], 'id' => (int) $login_user_id];
    }

    /**
     * Whether this record belongs to the actor's site, which is what selects the site-scoped
     * actor model over the cross-site one.
     *
     * A record only HAS a site when its model is site-scoped. For a new site-scoped record
     * the site_id attribute has not been forced from the session yet (that happens in the
     * `creating` hook, inside parent::save()), so the session's site is the answer - which is
     * exactly what that hook is about to write. Site 0 means "no site selected" and never
     * matches.
     *
     * @param int $actor_site_id
     * @return bool
     */
    private function __record_site_matches(int $actor_site_id): bool
    {
        if (!($this instanceof Rsx_Site_Model_Abstract)) {
            return false;
        }

        if ($actor_site_id === 0) {
            return false;
        }

        $record_site_id = $this->site_id;
        if ($record_site_id === null) {
            $record_site_id = $actor_site_id;
        }

        return (int) $record_site_id === $actor_site_id;
    }

    /**
     * The actor that created this record: a User_Model, Portal_User_Model or
     * Login_User_Model, or null when the record is unattributed. Resolved through the
     * created_by_type / created_by_id polymorphic pair.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    #[Relationship]
    public function created_by()
    {
        return $this->morphTo('created_by', 'created_by_type', 'created_by_id');
    }

    /**
     * The actor that last updated this record. See created_by().
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    #[Relationship]
    public function updated_by()
    {
        return $this->morphTo('updated_by', 'updated_by_type', 'updated_by_id');
    }

    /**
     * The actor that soft-deleted this record, or null when the record is live, was deleted
     * by nobody identifiable, or lives on a table that does not soft-delete at all (the pair
     * only exists where deleted_at does). See created_by().
     *
     * A restore clears the pair, so this always answers for the CURRENT deletion.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    #[Relationship]
    public function deleted_by()
    {
        return $this->morphTo('deleted_by', 'deleted_by_type', 'deleted_by_id');
    }

    /**
     * Per-request memo for resolved authorship display, keyed by VIEWER identity +
     * actor. get_view_profile_url() is explicitly forbidden from memoizing across
     * realms, so the viewer tuple is part of the key rather than assumed constant -
     * a CLI process, a test using __acting_as_user(), or a queued task that changes
     * identity mid-process gets a fresh answer.
     *
     * @var array<string, array{name: string, url: ?string}|null>
     */
    private static array $_author_display_cache = [];

    /**
     * Who created this record, ready to display: ['name' => string, 'url' => ?string],
     * or null when the record is unattributed.
     *
     * This is the server-side half of the <Record_Author> widget. Call it from a
     * controller endpoint or a model fetch() and hand the result to the component:
     *
     *     $data['created_by_author'] = $record->get_created_by_author();
     *     // template: <Record_Author $author=this.data.record.created_by_author />
     *
     * WHY IT IS EXPLICIT AND NOT AUTOMATIC IN toArray(). Resolving an author costs a
     * query and discloses a name, so doing it on every serialization in the framework
     * would tax and expose paths that never display authorship. Endpoints that show it
     * ask for it. There is deliberately no Ajax endpoint for "who wrote record X"
     * either: that would be a new identity-lookup surface over arbitrary records,
     * gated by nothing the record's own fetch() already decided.
     *
     * The N+1 a list page would otherwise pay is absorbed by the per-request memo - a
     * datagrid of 200 rows written by 3 people costs 3 lookups.
     *
     * TRASHED ACTORS RESOLVE. Actors soft-delete precisely so historical authorship
     * keeps reading; the lookup includes trashed rows and the name comes back
     * unchanged (no "(deleted)" marker - that is the actor contract, and a screen that
     * wants to mark it reads trashed() itself).
     *
     * @return array{name: string, url: ?string}|null
     */
    public function get_created_by_author(): ?array
    {
        return $this->__resolve_author_display('created_by');
    }

    /**
     * Who last updated this record, ready to display. See get_created_by_author().
     *
     * @return array{name: string, url: ?string}|null
     */
    public function get_updated_by_author(): ?array
    {
        return $this->__resolve_author_display('updated_by');
    }

    /**
     * Who soft-deleted this record, ready to display - the datum a "recently deleted" /
     * recovery screen exists to show. Null on a live record. See get_created_by_author().
     *
     * @return array{name: string, url: ?string}|null
     */
    public function get_deleted_by_author(): ?array
    {
        return $this->__resolve_author_display('deleted_by');
    }

    /**
     * Resolve one authorship relation to its display pair.
     *
     * A half-set pair (id without type, or type without id) resolves to null rather
     * than throwing: the stamp writes both halves together, so a half-set pair is
     * hand-written data the framework has nothing truthful to say about.
     *
     * @param string $relation 'created_by', 'updated_by' or 'deleted_by'
     * @return array{name: string, url: ?string}|null
     */
    private function __resolve_author_display(string $relation): ?array
    {
        $type = $this->{$relation . '_type'};
        $id = $this->{$relation . '_id'};

        if (empty($type) || empty($id)) {
            return null;
        }

        $cache_key = implode('|', [
            \App\RSpade\Lib\Portal\Rsx_Portal::is_portal_request() ? 'portal' : 'staff',
            (int) \App\RSpade\Core\Session\Session::get_user_id(),
            (int) \App\RSpade\Core\Session\Session::get_login_user_id(),
            (int) \App\RSpade\Lib\Portal\Portal_Session::get_portal_user_id(),
            (string) $type,
            (int) $id,
        ]);

        if (array_key_exists($cache_key, self::$_author_display_cache)) {
            return self::$_author_display_cache[$cache_key];
        }

        // withTrashed: a deleted actor must still print. The site global scope is
        // deliberately NOT bypassed - the stamp matrix only writes a site-scoped actor
        // when its site matches the record's, so an in-scope reader of the record can
        // always see its author.
        $actor = $this->{$relation}()->withTrashed()->first();

        // Either half of the actor layer answers; there is no shared interface to test
        // against (the manifest indexes classes, not interfaces).
        $display = null;
        if ($actor instanceof Rsx_Actor_Model_Abstract || $actor instanceof Rsx_Site_Actor_Model_Abstract) {
            $display = [
                'name' => $actor->get_printed_name(),
                'url' => $actor->get_view_profile_url(),
            ];
        }

        self::$_author_display_cache[$cache_key] = $display;

        return $display;
    }

    /**
     * Override delete to fire realtime emission on a committed write.
     *
     * A SOFT delete is also stamped with its deleter (deleted_by_id/_type), merged into the
     * same UPDATE by _merge_soft_delete_stamp() at the builder. A HARD delete is not stamped
     * and cannot be: the row it would be written on ceases to exist.
     */
    public function delete()
    {
        // A migration's up() never writes through a model (Migration_Guard).
        Migration_Guard::_refuse_model_write(static::class, 'delete()');

        // Guard the DB write: a hard delete reaches RestrictedEloquentBuilder::delete() and a
        // SoftDeletes delete reaches builder::update([deleted_at]) — both from inside
        // parent::delete(). Under the guard those run raw (no re-iterate), and the model fires
        // its own realtime + after_delete once below.
        self::$_single_model_write_depth++;
        try {
            $result = parent::delete();
        } finally {
            self::$_single_model_write_depth--;
        }

        if ($result) {
            // The deletion pair was written by the soft delete's own UPDATE, which syncs only
            // the columns IT knows about - so mark ours clean too, or the next save() would
            // re-write values already in the row.
            $attributes = $this->getAttributes();
            foreach (self::AUDIT_DELETED_COLUMNS as $column) {
                if (array_key_exists($column, $attributes)) {
                    $this->syncOriginalAttribute($column);
                }
            }

            $this->_dispatch_write_effects_on_delete();
        }

        return $result;
    }

    /**
     * The single post-write dispatch for a committed save(). Realtime emission is now one
     * EFFECT under the lifecycle dispatch (a consumer of the same write signal), not a
     * parallel model path: the realtime effect runs first (preserving frame ordering), then
     * the app after_create/after_update hooks. Both are individually gated and no-op for a
     * model that opts into neither.
     */
    private function _dispatch_write_effects_on_save(bool $was_existing, ?array $revision_original = null, array $text_hook_before = []): void
    {
        // Revision first: it describes THIS write, and an after_* hook that writes another
        // revisioned model must land after it in the transaction's sequence.
        $this->_record_revision_on_save($was_existing, $revision_original);
        $this->__dispatch_text_type_hooks($text_hook_before);
        $this->_realtime_emit_on_write();
        $this->_lifecycle_dispatch_on_save($was_existing);
    }

    /**
     * The declared text columns whose type defines after_model_saved(), memoized per class.
     * Public because the bulk update path asks it too: a model listed here takes the
     * per-record path so every record runs its types' hooks.
     *
     * @return array<string, class-string<\App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract>>
     */
    public static function text_columns_with_save_hooks(): array
    {
        static $memo = [];

        if (!isset($memo[static::class])) {
            $columns = [];
            foreach (array_keys(static::text_type_columns()) as $column) {
                $type = static::text_type_for($column);
                if ($type::has_after_model_saved_hook()) {
                    $columns[$column] = $type;
                }
            }
            $memo[static::class] = $columns;
        }

        return $memo[static::class];
    }

    /**
     * The stored form of each hooked text column this write changes, as it stood BEFORE the
     * write: null on an insert or for a column that was NULL. A column the write leaves
     * alone is absent, so it is not dispatched.
     *
     * @param bool $was_existing
     * @return array<string, string|null>
     */
    private function __text_hook_before_snapshot(bool $was_existing): array
    {
        $columns = static::text_columns_with_save_hooks();
        if (!$columns) {
            return [];
        }

        $before = [];
        foreach (array_keys($columns) as $column) {
            if (!$was_existing) {
                if ($this->getAttributes()[$column] ?? null) {
                    $before[$column] = null;
                }
            } elseif ($this->isDirty($column)) {
                $before[$column] = $this->getRawOriginal($column);
            }
        }

        return $before;
    }

    /**
     * Call each changed column's type hook (Rsx_Text_Abstract::after_model_saved()).
     *
     * @param array<string, string|null> $text_hook_before
     * @return void
     */
    private function __dispatch_text_type_hooks(array $text_hook_before): void
    {
        $columns = static::text_columns_with_save_hooks();

        foreach ($text_hook_before as $column => $old_raw) {
            $type = $columns[$column];
            $type::after_model_saved($this, $column, $type::from_storage($old_raw));
        }
    }

    /**
     * The single post-write dispatch for a committed delete() — realtime effect then the
     * after_delete hook (see _dispatch_write_effects_on_save).
     */
    private function _dispatch_write_effects_on_delete(): void
    {
        $this->_record_revision_on_delete();
        $this->_realtime_emit_on_write();
        $this->_lifecycle_dispatch_on_delete();
    }

    // =========================================================================
    // REVISION HISTORY
    // =========================================================================

    /**
     * Every revision recorded for THIS record, newest first.
     *
     * Marked #[Replaceable]: a model whose `revisions` name means something else of its own
     * replaces it outright (Transaction_Model does exactly that, with the FK relation to the
     * revisions it groups). No return type is declared for the same reason - an overriding
     * relation method returns a relation object, and a declared return type here would make
     * that override a fatal signature clash.
     *
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    #[Replaceable]
    public function revisions()
    {
        return Revision_Model::where('record_type', class_basename(static::class))
            ->where('record_id', (int) $this->getKey())
            ->orderBy('id', 'desc')
            ->result_set();
    }

    /**
     * Every revision recorded for this record AND for every record that declares it as its
     * #[Revision_Parent], newest first.
     *
     * This is what a history screen wants: "everything that happened to this client",
     * including the writes that landed on its contacts. It is ONE indexed query on the root
     * pair rather than a union over every child table, which is the entire reason the root
     * pair is stored at write time instead of being resolved at read time.
     *
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    #[Replaceable]
    public function revisions_including_children()
    {
        return Revision_Model::where('root_type', class_basename(static::class))
            ->where('root_id', (int) $this->getKey())
            ->orderBy('id', 'desc')
            ->result_set();
    }

    /**
     * The raw attribute values as they stood BEFORE this write, or null when this model
     * records nothing (the fast path: one static read and a suppression check).
     *
     * @return array<string, mixed>|null
     */
    private function _revision_original_snapshot(): ?array
    {
        if (!static::$revisions || Revision::is_suppressed()) {
            return null;
        }

        return $this->getRawOriginal();
    }

    /**
     * Record the revision for a committed save().
     *
     * CREATE records every non-null column as [null, value] - a created record's history
     * must be readable on its own, without inferring the starting state from the schema.
     * UPDATE records only the columns that actually changed; an update that changed nothing
     * wrote nothing and records nothing. A restore is an UPDATE whose deleted_at went back
     * to null, and is recorded as its own operation so a history can name it.
     *
     * A never-exported column is recorded REDACTED - both sides Revision::REDACTED - decided
     * before _revision_value() sees the value, so neither the old nor the new value of a
     * secret ever reaches the document in any form.
     *
     * @param array<string, mixed>|null $original The pre-write raw attributes, or null when
     *                                            recording was off before the write.
     */
    private function _record_revision_on_save(bool $was_existing, ?array $original): void
    {
        if ($original === null || Revision::is_suppressed()) {
            return;
        }

        $excluded = $this->_revision_excluded_columns();
        $redacted = array_fill_keys(static::never_exported_columns(), true);

        if (!$was_existing) {
            $diff = [];

            foreach ($this->getAttributes() as $column => $value) {
                if ($value === null || isset($excluded[$column]) || $this->_is_system_column($column)) {
                    continue;
                }

                if (isset($redacted[$column])) {
                    $diff[$column] = [Revision::REDACTED, Revision::REDACTED];
                    continue;
                }

                $diff[$column] = [null, $this->_revision_value($value)];
            }

            Revision::_record($this, Revision_Model::OPERATION_CREATE, $diff, $this->_revision_root());

            return;
        }

        $changes = $this->getChanges();

        if (empty($changes)) {
            return;
        }

        $diff = [];

        foreach ($changes as $column => $value) {
            if (isset($excluded[$column]) || $this->_is_system_column($column)) {
                continue;
            }

            if (isset($redacted[$column])) {
                $diff[$column] = [Revision::REDACTED, Revision::REDACTED];
                continue;
            }

            $diff[$column] = [
                $this->_revision_value($original[$column] ?? null),
                $this->_revision_value($value),
            ];
        }

        // Everything that changed was excluded - the record moved, but nothing a history
        // would show did.
        if (empty($diff)) {
            return;
        }

        $operation = $this->_revision_is_undelete($original, $changes)
            ? Revision_Model::OPERATION_UNDELETE
            : Revision_Model::OPERATION_UPDATE;

        Revision::_record($this, $operation, $diff, $this->_revision_root());
    }

    /**
     * Record the revision for a committed delete(), soft or hard.
     *
     * The document is EMPTY, deliberately. A delete does not change fields, it removes the
     * record; what its values were is already in the revisions that put them there. (This is
     * also why there is no restore-this-revision feature: the history says what the user
     * changed, not what the record was at each instant.)
     */
    private function _record_revision_on_delete(): void
    {
        if (!static::$revisions || Revision::is_suppressed()) {
            return;
        }

        Revision::_record($this, Revision_Model::OPERATION_DELETE, [], $this->_revision_root());
    }

    /**
     * The root pair a revision on this record is filed under: the #[Revision_Parent]
     * target's pair when one is declared and set, otherwise this record's own.
     *
     * @return array{type: string, id: int}
     */
    private function _revision_root(): array
    {
        foreach (Revision_Parent_Registry::parent_metadata(static::class) as $entry) {
            $foreign_key = $this->getAttribute($entry['fk_column']);

            if ($foreign_key === null) {
                continue;
            }

            return ['type' => $entry['parent_class'], 'id' => (int) $foreign_key];
        }

        return ['type' => class_basename(static::class), 'id' => (int) $this->getKey()];
    }

    /**
     * The columns this model never records, as a lookup.
     *
     * updated_at and the updated_by pair are excluded ALWAYS: they change on every single
     * write, so recording them would put two entries nobody reads in front of the one that
     * matters. created_at and the created_by pair are NOT excluded - they appear exactly
     * once, on the create, where they are the record's provenance.
     *
     * @return array<string, bool>
     */
    private function _revision_excluded_columns(): array
    {
        $excluded = [
            'updated_at' => true,
            'updated_by_id' => true,
            'updated_by_type' => true,
        ];

        foreach (static::revision_excluded_columns() as $column) {
            $excluded[$column] = true;
        }

        return $excluded;
    }

    /**
     * Whether a column is a framework SYSTEM column - a single leading underscore. Excluded
     * from every diff for the same reason toArray() strips it: it is bookkeeping, not data
     * anybody changed.
     */
    private function _is_system_column(string $column): bool
    {
        return str_starts_with($column, '_');
    }

    /**
     * Whether an update restored a soft-deleted record: deleted_at moved, and it moved to
     * null.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $changes
     */
    private function _revision_is_undelete(array $original, array $changes): bool
    {
        if (!method_exists($this, 'getDeletedAtColumn')) {
            return false;
        }

        $column = $this->getDeletedAtColumn();

        if (!array_key_exists($column, $changes)) {
            return false;
        }

        return $changes[$column] === null && ($original[$column] ?? null) !== null;
    }

    /**
     * One stored value, reduced to something JSON can carry.
     *
     * Raw attributes are already the values that went to the database - scalars and strings
     * - so this is a floor, not a conversion: anything else (an Expression, a value object a
     * custom cast produced) is encoded rather than allowed to make the whole document
     * unserializable.
     *
     * @param mixed $value
     * @return mixed
     */
    private function _revision_value($value)
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return json_encode($value);
    }

    /**
     * Lifecycle hook: fired after this record is first INSERTED, once the write has
     * COMMITTED (single-write path). Override on a model to run a post-create side effect;
     * base default is a no-op. Runs synchronously in the caller's post-commit context and is
     * DISCARDED if the surrounding transaction rolls back.
     *
     * Marked #[Replaceable] — this is a template hook, so an overriding model REPLACES it and
     * must NOT call parent::after_create() (the parent-call-chain rule is suppressed for
     * replaceable methods).
     */
    #[Replaceable]
    public function after_create(): void
    {
    }

    /**
     * Lifecycle hook: fired after this record is UPDATED, once the write has COMMITTED
     * (single-write path). Receives the changed column names (the keys of the write's dirty
     * set). Only fires when the update actually changed columns. Override on a model; base
     * default is a no-op. Runs synchronously in the caller's post-commit context and is
     * DISCARDED if the surrounding transaction rolls back.
     *
     * Marked #[Replaceable] — an overriding model REPLACES it and must NOT call
     * parent::after_update().
     *
     * @param array<int, string> $changed_fields Names of the columns changed by this write.
     */
    #[Replaceable]
    public function after_update(array $changed_fields): void
    {
    }

    /**
     * Lifecycle hook: fired after this record is DELETED, once the write has COMMITTED
     * (single-write path). Override on a model to run post-delete cleanup (e.g. blob
     * removal); base default is a no-op. Runs synchronously in the caller's post-commit
     * context and is DISCARDED if the surrounding transaction rolls back.
     *
     * Marked #[Replaceable] — an overriding model REPLACES it and must NOT call
     * parent::after_delete().
     */
    #[Replaceable]
    public function after_delete(): void
    {
    }

    /**
     * Queue the lifecycle hook for a committed save() (create vs update). No-op for a model
     * that declares no hook (the common case — one cached boolean). CREATE vs UPDATE is
     * distinguished by the pre-write existence snapshot ($was_existing false = INSERT). An
     * update with an empty changed set performed no DB write (Laravel skips the query when
     * not dirty), so it queues nothing.
     */
    private function _lifecycle_dispatch_on_save(bool $was_existing): void
    {
        if (!Model_Lifecycle_Registry::has_lifecycle_surface($this)) {
            return;
        }

        if (!$was_existing) {
            if (Model_Lifecycle_Registry::declares($this, 'after_create')) {
                Model_Lifecycle_Emissions::queue($this, Model_Lifecycle_Emissions::EVENT_CREATE);
            }

            return;
        }

        $changed = array_keys($this->getChanges());

        if (!empty($changed) && Model_Lifecycle_Registry::declares($this, 'after_update')) {
            Model_Lifecycle_Emissions::queue($this, Model_Lifecycle_Emissions::EVENT_UPDATE, $changed);
        }
    }

    /**
     * Queue the lifecycle hook for a committed delete(). No-op unless the model declares
     * after_delete().
     */
    private function _lifecycle_dispatch_on_delete(): void
    {
        if (!Model_Lifecycle_Registry::has_lifecycle_surface($this)) {
            return;
        }

        if (Model_Lifecycle_Registry::declares($this, 'after_delete')) {
            Model_Lifecycle_Emissions::queue($this, Model_Lifecycle_Emissions::EVENT_DELETE);
        }
    }

    /**
     * Parent model instances whose result sets depend on THIS record — a change to this
     * record should also notify subscribers of those parents. The touch cascade queues an
     * emission for each returned parent (and their parents, cycle-guarded) WITHOUT writing
     * any parent row. Override on a child model; base default is no dependencies.
     *
     * Example: Contact_Model::realtime_touch() returns [$this->client()] so editing a
     * contact live-refreshes the client's contacts result set.
     *
     * @return Rsx_Model_Abstract[]
     */
    #[Replaceable]
    public function realtime_touch(): array
    {
        return [];
    }

    /**
     * Manually queue a realtime change emission for THIS record — the explicit companion
     * to the automatic save()/delete() hook. Use it after a write that bypasses the model
     * layer (a query-builder / bulk / raw SQL update, an import, a derived-value change):
     * those never fire the auto hook, so subscribers would otherwise never be notified.
     *
     * Calling this IS the intent, so it works regardless of static::$realtime — but it is
     * still a no-op when realtime is disabled (safe to leave in code unconditionally). It
     * requires a persisted record; a manual emit on an unsaved row is a programming error
     * and fails loud. It runs the SAME path as auto-emission (touch cascade included) and
     * is deduped to at most one emission per (model, id) per request (see
     * Realtime_Emissions::request_emit). It never emits uncommitted state — inside a
     * transaction the emission still flushes on commit and is discarded on rollback.
     */
    public function realtime_emit(): void
    {
        if (!Realtime::is_enabled()) {
            return;
        }

        if (empty($this->id)) {
            shouldnt_happen('realtime_emit() requires a persisted record (empty id) — a manual emit on an unsaved row is a bug.');
        }

        Realtime_Emissions::request_emit($this);
    }

    /**
     * Realtime hook fired from the save()/delete() success path (the single model write
     * choke point). Query-builder writes (DB::table, Model::where()->update()) bypass the
     * model layer by design and do not emit. No-op unless realtime is enabled.
     */
    private function _realtime_emit_on_write(): void
    {
        // Off, or switched off for this pass (Realtime::without_model_frames()).
        if (!Realtime::is_enabled() || Realtime::model_frames_suppressed()) {
            return;
        }

        // Model-change emission: only opt-in models publish their own change (plus the
        // realtime_touch() method cascade, walked inside queue_model_change()).
        if (static::$realtime) {
            Realtime_Emissions::queue_model_change($this);
        } elseif (Realtime_Touch_Registry::overrides_realtime_touch(static::class)) {
            // Method-surface touch-only model: walk the realtime_touch() cascade without
            // publishing the model's own frame, mirroring #[Realtime_Touch] semantics.
            Realtime_Emissions::queue_touch_cascade($this);
        }

        // #[Realtime_Touch] parent emissions: fired regardless of this model's own $realtime
        // flag — a touch-only child (not itself watched) must still notify its parents. No-op
        // when the model declares no attributed relationships.
        Realtime_Emissions::queue_attributed_touches($this);

        // Emitter dirty-kick: any non-silent write schedules a (gated) emitter recompute.
        if (!static::$realtime_silent) {
            Realtime_Emissions::_mark_emitters_dirty();
        }
    }

    /**
     * Get relationship method names for this model
     *
     * Returns every method marked with the #[Relationship] attribute on this class AND on
     * each of its manifest-visible ancestors. The manifest indexes methods per FILE, so a
     * concrete model's own entry never lists an inherited relation - the framework audit
     * relations (created_by / updated_by / deleted_by) are declared on Rsx_Model_Abstract
     * and reach every model through this walk.
     *
     * @return array Array of relationship method names
     */
    public static function get_relationships(): array
    {
        $called_class = get_called_class();

        if (isset(self::$_relationships_cache[$called_class])) {
            return self::$_relationships_cache[$called_class];
        }

        $manifest_data = Manifest::php_get_metadata_by_fqcn($called_class);

        if (!$manifest_data) {
            throw new RuntimeException("Class {$called_class} not found in manifest");
        }

        // Climb extends_fqcn, collecting each class's OWN attributed methods. The walk ends
        // at the last manifest-visible ancestor: Eloquent's Model has no manifest entry, which
        // is the natural terminator. An override redeclares the name (RELATIONSHIP-OVERRIDE-01
        // requires it to carry the attribute too), so the union is de-duplicated by name.
        $relationships = [];
        $entry = $manifest_data;
        $seen_classes = [$called_class => true];

        while ($entry !== null) {
            foreach ($entry['public_instance_methods'] ?? [] as $method_name => $method_data) {
                if (isset($method_data['attributes']['Relationship'])) {
                    $relationships[] = $method_name;
                }
            }

            $parent_fqcn = $entry['extends_fqcn'] ?? null;
            if ($parent_fqcn === null || isset($seen_classes[$parent_fqcn])) {
                break;
            }

            $seen_classes[$parent_fqcn] = true;
            $entry = self::__manifest_entry_for_fqcn($parent_fqcn);
        }

        $relationships = array_values(array_unique($relationships));
        self::$_relationships_cache[$called_class] = $relationships;

        return $relationships;
    }

    /**
     * The manifest entry declaring $fqcn, or null when the class is not indexed.
     *
     * Null is an ordinary answer, not an error: the manifest never scans vendor/, so a
     * lineage walk reaches an unindexed ancestor (Eloquent's Model) at its root. The
     * php_classes index is keyed by SIMPLE name, so the resolved entry is confirmed to be
     * this exact class before it is used.
     */
    private static function __manifest_entry_for_fqcn(string $fqcn): ?array
    {
        $simple_name = Manifest::_normalize_class_name($fqcn);

        try {
            $file = Manifest::php_find_class($simple_name);
        } catch (RuntimeException $e) {
            return null;
        }

        $entry = Manifest::get_file($file);

        return (($entry['fqcn'] ?? null) === $fqcn) ? $entry : null;
    }

    /**
     * Whether this model declares Class-Table Inheritance detail tables.
     */
    public static function has_detail_tables(): bool
    {
        return !empty(static::$detail_tables);
    }

    /**
     * The discriminator column that selects which detail table applies, or null.
     */
    public static function detail_discriminator_column(): ?string
    {
        return \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::discriminator_column(static::$detail_tables);
    }

    /**
     * The detail model class for a discriminator value, or null when that value has no detail.
     */
    public static function detail_class_for($value): ?string
    {
        return \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::class_for_value(static::$detail_tables, $value);
    }

    /**
     * [accessor_name => detail_class] for every detail this model has (deduped).
     */
    public static function detail_accessors(): array
    {
        return \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::accessors(static::$detail_tables);
    }

    /**
     * Whether $key is one of this model's detail accessor names (for any type).
     */
    private static function __is_detail_accessor(string $key): bool
    {
        return array_key_exists($key, static::detail_accessors());
    }

    /**
     * The detail accessor name for THIS record's current discriminator value, or null
     * when the record's type has no detail (or it is untyped).
     */
    private function __active_detail_accessor(): ?string
    {
        $column = static::detail_discriminator_column();
        if ($column === null) {
            return null;
        }

        $class = static::detail_class_for($this->{$column});
        if ($class === null) {
            return null;
        }

        return \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::accessor_name($class);
    }

    /**
     * Load (and cache) the PERSISTED detail row for this record's active type, or null when
     * none exists yet / the type has no detail / the record is unsaved. Used by the embed and
     * preload paths (serialization only ever exposes persisted details).
     */
    public function active_detail(): ?Rsx_Detail_Model_Abstract
    {
        if (!static::has_detail_tables() || !$this->id) {
            return null;
        }

        if ($this->_active_detail_loaded) {
            return $this->_active_detail;
        }

        $class = static::detail_class_for($this->{static::detail_discriminator_column()});
        $this->_active_detail = $class !== null ? $class::for_parent($this->id) : null;
        $this->_active_detail_loaded = true;

        return $this->_active_detail;
    }

    /**
     * Resolve a detail accessor by name (e.g. $record->party_company). Wrong-type access
     * (the accessor does not match this record's discriminator) throws - fail loud, local.
     * For the active type, returns the persisted detail, or a vivified new instance with the
     * parent FK pre-set (plain construction, parent FK populated), cached so the caller can fill
     * and save it. The vivified blank is never serialized (toArray embeds persisted only).
     */
    private function __get_detail_for_accessor(string $accessor): Rsx_Detail_Model_Abstract
    {
        $active = $this->__active_detail_accessor();

        if ($accessor !== $active) {
            $column = static::detail_discriminator_column();
            $value = $this->{$column};
            $active_text = $active === null ? "no detail (type {$column}={$value} has no detail table)" : "'{$active}'";

            throw new RuntimeException(
                static::class . " #{$this->id}: detail accessor '{$accessor}' is not valid for {$column}={$value} - " .
                "this record's detail is {$active_text}. Check the type before accessing type-specific fields."
            );
        }

        // Return the same vivified instance on repeated access (covers fill-then-save).
        if ($this->_vivified_detail !== null) {
            return $this->_vivified_detail;
        }

        $persisted = $this->active_detail();
        if ($persisted !== null) {
            return $persisted;
        }

        // No persisted row: vivify a new, unsaved detail with the parent FK already set.
        $class = static::detail_class_for($this->{static::detail_discriminator_column()});
        $detail = new $class();
        $detail->{$class::parent_key()} = $this->id;
        $this->_vivified_detail = $detail;

        return $detail;
    }

    /**
     * Batch-preload active details for a set of base records (avoids N+1 when serializing a
     * list). Groups by detail table, loads each with one WHERE {parent}_id IN (...) query,
     * and populates each record's detail cache. Eloquent eager loading is disabled
     * framework-wide, so datagrids/lists call this explicitly.
     *
     * @param iterable $records Base model instances (all of this class)
     */
    public static function preload_details(iterable $records): void
    {
        if (!static::has_detail_tables()) {
            return;
        }

        $column = static::detail_discriminator_column();

        // Bucket record ids by the detail class their type selects.
        $ids_by_class = [];
        $records_by_id = [];
        foreach ($records as $record) {
            if (!$record->id) {
                continue;
            }
            $records_by_id[$record->id] = $record;
            $class = static::detail_class_for($record->{$column});
            if ($class !== null) {
                $ids_by_class[$class][] = $record->id;
            } else {
                // Absent-detail type: cache "no detail" so toArray/accessor never query.
                $record->_active_detail = null;
                $record->_active_detail_loaded = true;
            }
        }

        foreach ($ids_by_class as $class => $ids) {
            $parent_key = $class::parent_key();
            $details = $class::whereIn($parent_key, $ids)->get();
            foreach ($details as $detail) {
                $parent_id = $detail->{$parent_key};
                if (isset($records_by_id[$parent_id])) {
                    $records_by_id[$parent_id]->_active_detail = $detail;
                    $records_by_id[$parent_id]->_active_detail_loaded = true;
                }
            }
        }
    }

    /**
     * CTI: the discriminator column is immutable once a record exists. Changing it would
     * leave the previously-selected detail row orphaned, so we reject the change (fail loud).
     */
    private function __assert_detail_discriminator_immutable(): void
    {
        if (!$this->exists || !static::has_detail_tables()) {
            return;
        }

        $column = static::detail_discriminator_column();
        if ($this->isDirty($column)) {
            throw new RuntimeException(
                static::class . " #{$this->id}: the detail discriminator '{$column}' is immutable " .
                '(it selects the detail table). Changing it from ' . $this->getOriginal($column) .
                " to {$this->{$column}} would orphan the existing detail row."
            );
        }
    }

    /**
     * THE SEAM every attachment reader on this record is built from: a query already
     * constrained to "attachments belonging to THIS record".
     *
     * It exists so the ownership constraint is written ONCE. Every public reader below
     * (get_attachment, get_attachments, get_all_attachments, get_deleted_attachments,
     * find_attachment) narrows this query rather than re-deriving fileable_type/fileable_id
     * for itself - and so that a subclass can tighten ALL of them at once by overriding this
     * one method. Rsx_Site_Model_Abstract does exactly that, pinning the tenant.
     *
     * An override MUST chain to parent::__attachment_query() - deliberately NOT #[Replaceable],
     * because the parent call is what applies the ownership constraint. An override that
     * skipped it would silently return attachments belonging to other records, so
     * PHP-PARENT-CHAIN-01 enforcing the chain here is the point rather than a formality.
     *
     * @param \Illuminate\Database\Eloquent\Builder|null $query Base query (defaults to live rows)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function __attachment_query($query = null)
    {
        $query = $query ?? File_Attachment_Model::query();

        // Use simple class name (type_ref system stores simple names, not FQCNs)
        return $query->where('fileable_type', class_basename($this))
            ->where('fileable_id', $this->id);
    }

    /**
     * Get a single attachment for this model by category
     *
     * Returns the first attachment with the specified category.
     * Use this for single-file attachments (e.g., profile_photo, logo).
     *
     * @param string $category Category identifier (e.g., 'profile_photo')
     * @return File_Attachment_Model|null
     */
    public function get_attachment(string $category)
    {
        return $this->__attachment_query()
            ->where('fileable_category', $category)
            ->first();
    }

    /**
     * Find ONE attachment that belongs to THIS record, in THIS category, by id or key.
     *
     * THE OWNERSHIP RE-VERIFICATION STEP. An endpoint that acts on an attachment named by
     * the caller - remove it, rename it, share it - has been handed an id it must not
     * trust. File_Attachment_Model::find() and find_by_key() are tenant-scoped and nothing
     * more: within one site they will happily return an attachment hanging off a DIFFERENT
     * record, or off the same record under a different category. Resolving through the
     * OWNING RECORD is what closes that, and it is why this lives on the model rather than
     * being re-derived per endpoint.
     *
     * All three must hold or the answer is null: the attachment is fileable to this class,
     * to this id, under this category. A miss and a mismatch are the SAME null - the caller
     * reports "not found" either way and never confirms that someone else's id exists.
     *
     * Live records only. A soft-deleted attachment is not found here; recovery flows walk
     * get_deleted_attachments() instead.
     *
     * @param int|string $id_or_key Numeric attachment id, or the 64-hex attachment key
     * @param string $category Category identifier (e.g., 'documents')
     * @return File_Attachment_Model|null
     */
    public function find_attachment($id_or_key, string $category)
    {
        $query = $this->__attachment_query()->where('fileable_category', $category);

        // A key is 64 hex characters; an id is an integer. Test for the key FIRST, because
        // a key drawn entirely from the digits 0-9 is numeric and an id is never 64 digits
        // long - checking is_numeric() first would misread that key as an id.
        $id_or_key = (string) $id_or_key;

        if (strlen($id_or_key) === 64 && ctype_xdigit($id_or_key)) {
            return $query->where('key', $id_or_key)->first();
        }

        if (!is_numeric($id_or_key)) {
            return null;
        }

        return $query->where('id', (int) $id_or_key)->first();
    }

    /**
     * Get all attachments for this model by category.
     *
     * Returns EVERY attachment in the category - foreach it, count() it, map it. The set
     * is fetched a page at a time (Rsx_Result_Set) because one record's attachment count
     * has no ceiling: a bulk import can hang thousands off a single row.
     *
     * @param string $category Category identifier (e.g., 'documents')
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    public function get_attachments(string $category)
    {
        return $this->__attachment_query()
            ->where('fileable_category', $category)
            ->result_set();
    }

    /**
     * Get EVERY live attachment on this record, across ALL categories.
     *
     * The category-less reader. get_attachments() answers "the documents on this client";
     * this answers "everything hanging off this client" - the shape a delete cascade, an
     * export, an audit or a "does this record carry any files at all" check needs, none of
     * which can enumerate the categories in advance (a category is an arbitrary app-chosen
     * string, and nothing indexes the set in use).
     *
     * NO LIMITER OF ANY KIND. Every category, every attachment, however many there are: the
     * set comes back as an Rsx_Result_Set, which an ordinary foreach walks to the end one
     * keyset page at a time. A record's attachment count has no ceiling - a bulk import can
     * hang thousands off a single row - so this is bounded memory, never a bounded ANSWER.
     *
     * Read the category off each row ($attachment->fileable_category) when you need to
     * group them.
     *
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    public function get_all_attachments()
    {
        return $this->__attachment_query()->result_set();
    }

    /**
     * Get this model's attachments in a category that are currently in the RETENTION
     * WINDOW (soft-deleted but not yet destroyed) - the "Recently Deleted" recovery set
     * for that category. Excludes permanently-destroyed rows. Restore one with
     * $attachment->undelete().
     *
     * @param string $category Category identifier (e.g., 'documents')
     * @return \App\RSpade\Core\Database\Rsx_Result_Set
     */
    public function get_deleted_attachments(string $category)
    {
        // Use simple class name (type_ref system stores simple names, not FQCNs)
        //
        // NOTE the orderByDesc is dropped by iteration: Rsx_Result_Set walks by primary
        // key. Recovery lists are small enough to sort in the view, and the whole-set
        // guarantee matters more here than arrival order.
        return $this->__attachment_query(File_Attachment_Model::withTrashed())
            ->whereNotNull('deleted_at')
            ->whereNull('destroyed_at')
            ->where('fileable_category', $category)
            ->result_set();
    }
}
