<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Codegen\Model_Codegen_Rewriter;
use App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;

/**
 * Regenerates model constants and docblocks based on the enum columns and the database schema.
 *
 * This is the single canonical model-codegen command. It scans every concrete model
 * extending Rsx_Model_Abstract and, for each one:
 *
 * 1. Validates the model's enum columns (integer values, integer column type, no duplicate
 *    constants) - fails loud rather than emitting broken metadata.
 * 2. Emits a pre-class `_AUTO_GENERATED_ Database type hints` docblock with:
 *    - a typed `@property` line per base-table column (DATE/DATETIME -> string, matching
 *      the framework's string-time philosophy - model datetime attributes are ISO strings;
 *      DECIMAL -> string), noting the column's declared kind - enum, text type, type ref,
 *      derived (`@property-read`), never exported, not recorded in revisions
 *      (property_line()),
 *    - a `@property` line per Class-Table-Inheritance detail column, tagged `(detail: <table>)`,
 *    - BEM `@property-read string $col__label` / `$col__constant` accessor hints,
 *    - BEM typed `@method static array $col__enum()/__enum_select()/__enum_labels()/__enum_ids()`.
 * 3. Emits an `_AUTO_GENERATED_ Enum constants` block of `const` declarations after the
 *    class brace.
 *
 * The write is surgical: it goes through Model_Codegen_Rewriter, an AST-guided rewriter that
 * replaces ONLY the auto-generated regions and refuses (fails loud + skips the file) if any
 * hand-written byte would change. Framework-authored writes are labeled for the self-update
 * tamper ledger.
 */
class Constants_Regenerate_Command extends Command
{
    protected $signature = 'rsx:constants:regenerate';

    protected $description = 'Regenerate model constants and docblocks based on the enum columns';

    /**
     * Type mapping from database column types to PHP type hints. Keyed on both the DBAL
     * ABSTRACT type names Schema::getColumnType() actually returns here (`string`, `bigint`,
     * `datetime`, `text`, ...) and the raw MySQL type names, so it is correct regardless of
     * which form the schema builder reports.
     *
     * DATE / DATETIME / TIMESTAMP / TIME -> string: model datetime attributes are ISO
     * strings in this framework (Rsx_Date_Cast / Rsx_DateTime_Cast), never Carbon objects.
     */
    protected $type_map = [
        // Integer family.
        'bigint' => 'int',
        'int' => 'int',
        'integer' => 'int',
        'tinyint' => 'int',
        'smallint' => 'int',
        'mediumint' => 'int',
        'year' => 'int',
        // DECIMAL reads as a numeric string (exact; never a float).
        'decimal' => 'string',
        // Floating point.
        'float' => 'float',
        'double' => 'float',
        'real' => 'float',
        // Boolean.
        'boolean' => 'bool',
        // Date/time - ISO strings in this framework, never Carbon.
        'date' => 'string',
        'datetime' => 'string',
        'datetimetz' => 'string',
        'timestamp' => 'string',
        'time' => 'string',
        // String family (`string` is the DBAL abstract type for VARCHAR/CHAR).
        'string' => 'string',
        'char' => 'string',
        'varchar' => 'string',
        'text' => 'string',
        'mediumtext' => 'string',
        'longtext' => 'string',
        'guid' => 'string',
        'blob' => 'string',
        'binary' => 'string',
        // JSON.
        'json' => 'array',
        'jsonb' => 'array',
    ];

    public function handle()
    {
        $this->info('Regenerating model constants...');

        // Master list of models from the manifest.
        $model_entries = Manifest::php_get_extending('Rsx_Model_Abstract');

        // Remove duplicates (in case a model is found multiple times).
        $unique_models = [];
        foreach ($model_entries as $model) {
            if (isset($model['fqcn'])) {
                $unique_models[$model['fqcn']] = $model;
            }
        }

        $processed_count = 0;
        $skipped = [];
        $framework_files_skipped = 0;

        foreach ($unique_models as $model_metadata) {
            $file_rel = $model_metadata['file'] ?? '';
            $full_class_name = $model_metadata['fqcn'] ?? '';

            if (!$file_rel || !$full_class_name) {
                continue;
            }

            // Skip abstract classes.
            if (Manifest::php_is_abstract($full_class_name)) {
                continue;
            }

            // The codegen's regions live in the class that DECLARES the model - which, for a
            // split core model, is the abstract base beside the shell, not the shell itself.
            // Resolving it here is what keeps a regeneration from inserting a second copy of
            // the docblock and the enum constants into a file that declares nothing.
            [$class_name, $file_path] = $this->resolve_declaring_file($full_class_name);

            // NOTHING UNDER system/ IS WRITTEN unless this is a framework-developer box.
            // The test is on the RESOLVED file, not on the model's own: a core model is an
            // abstract base under app/RSpade/ plus a shell in rsx/models/, so a model whose
            // OWN file is an application file still declares its $table and $columns in the
            // framework's file - and that is the file this command would rewrite, dirtying
            // the framework submodule on a downstream box (a downstream field report,
            // 2026-09-15). Framework model docblocks are the framework author's to
            // regenerate, in the monorepo, and they ship with the release.
            if (!$this->is_writable_by_this_box($file_path)) {
                $framework_files_skipped++;
                continue;
            }

            // A model whose table is not in the current schema (a test-fixture model whose
            // tables exist only during a test run, or a model whose migration has not been
            // applied yet), or whose table lists no columns, has nothing to document - skip
            // it. The command runs post-migrate, so this must never fatal.
            $table = with(new $full_class_name())->getTable();
            if (!Schema::hasTable($table) || empty(Schema::getColumnListing($table))) {
                continue;
            }

            // Enum validation is a HARD gate: broken enum definitions must stop the run so
            // they are fixed before any metadata is emitted. Returns non-null exit code.
            $validation_exit = $this->validate_enums($full_class_name, $class_name);
            if ($validation_exit !== null) {
                return $validation_exit;
            }

            try {
                $updated = $this->regenerate_model($full_class_name, $class_name, $file_path);
                if ($updated) {
                    $processed_count++;
                    $this->line(" Updated: {$class_name}");
                }
            } catch (RuntimeException $e) {
                // A file the rewriter cannot rewrite cleanly is left untouched and reported.
                // A skipped file is recoverable; a silently rewritten one is not.
                $skipped[$file_rel] = $e->getMessage();
                $this->error("Skipped {$class_name}: " . $e->getMessage());
            }
        }

        $this->newLine();
        if ($processed_count > 0) {
            $this->info("[OK] Successfully updated {$processed_count} model(s) with constants and docblocks.");
        } else {
            $this->info('No models needed updating.');
        }

        if ($framework_files_skipped > 0) {
            $this->info(
                "Skipped {$framework_files_skipped} model(s) whose metadata is declared under system/ -"
                . ' framework source is regenerated by the framework, and ships with the release.'
            );
        }

        if (!empty($skipped)) {
            $this->newLine();
            $this->error('The following files were SKIPPED (not written) and need attention:');
            foreach ($skipped as $file => $reason) {
                $this->line("  - {$file}");
                $this->line("      {$reason}");
            }
            return 1;
        }

        return 0;
    }

    /**
     * Whether this box may rewrite the given source file.
     *
     * Everything under app/RSpade/ is FRAMEWORK PROPERTY: downstream it is a git submodule
     * that `rsx:framework:pull` resets, so a generated byte written there is either lost on
     * the next pull or shows up as a dirty submodule nobody asked for. Only a framework
     * developer's box - the monorepo, where that tree is authored source - may write it.
     *
     * The application tree is always writable by this command.
     */
    protected function is_writable_by_this_box(string $file_path): bool
    {
        $framework_root = rsxrealpath(base_path('app/RSpade')) ?: base_path('app/RSpade');
        $resolved = rsxrealpath($file_path) ?: $file_path;

        if (!str_starts_with($resolved, rtrim($framework_root, '/') . '/')) {
            return true;
        }

        return (bool) config('rsx.code_quality.is_framework_developer', false);
    }

    /**
     * Where a model's auto-generated regions belong: the nearest class in its ancestry that
     * DECLARES the model itself.
     *
     * A core model is an abstract base carrying every member plus a shell that declares
     * nothing, and an application's override is a class extending that base. In both shapes
     * `$table` and `$columns` - the two inputs this command reads - are declared one or more
     * links up, and the docblock and the enum constants it emits describe THAT class. Writing
     * them into the shell instead would put a second set of constants in the tree and leave
     * the real ones to drift.
     *
     * `$table` is the anchor: every model declares it, and it is what decides which table the
     * docblock documents. `$columns` is consulted only when a model somehow declares its
     * columns further down than its table. A declaration on Rsx_Model_Abstract or above it
     * never counts: the base declares `$columns` (the audit type columns) for every model, and
     * a model whose regions resolved there would have its docblock written into the framework
     * base (or into Eloquent's Model, which declares `$table`).
     * A model that declares neither is documented in its own file, which is the one-file
     * model shape and still correct.
     *
     * @return array{0:string,1:string} [class basename, absolute file path]
     */
    protected function resolve_declaring_file(string $full_class_name): array
    {
        $reflector = new ReflectionClass($full_class_name);
        $declaring = $reflector;

        foreach (['table', 'columns'] as $property) {
            if (!$reflector->hasProperty($property)) {
                continue;
            }

            $declared_by = $reflector->getProperty($property)->getDeclaringClass();

            if (!$declared_by->isSubclassOf(Rsx_Model_Abstract::class)) {
                continue;
            }

            $declaring = $declared_by;
            break;
        }

        $file = $declaring->getFileName();

        if ($file === false) {
            $file = $reflector->getFileName();
            $declaring = $reflector;
        }

        return [$declaring->getShortName(), $file];
    }

    /**
     * Validate the model's enum columns before any metadata is emitted. Bad values / column types
     * are fatal (return an exit code, stopping the whole run); a duplicate constant throws
     * (the caller's per-file catch skips + reports). Returns null when the model is clean.
     */
    protected function validate_enums(string $full_class_name, string $class_name): ?int
    {
        $table = with(new $full_class_name())->getTable();

        foreach ($full_class_name::enum_columns() as $column => $enum_values) {
            // Validate enum values are integers.
            foreach ($enum_values as $value => $props) {
                if (!is_int($value) && !ctype_digit((string)$value)) {
                    $this->error("Invalid enum value '{$value}' for column '{$column}' in model '{$full_class_name}'.");
                    $this->newLine();
                    $this->error('ENUM VALUES MUST BE INTEGERS.');
                    $this->newLine();
                    $this->line('The purpose of enum values is to store an INTEGER in the database that corresponds to a');
                    $this->line("string in the enum definition. The string label can then be changed in the enum 'label'");
                    $this->line('property without affecting the database value, so long as it continues to correspond to');
                    $this->line('the same numeric integer.');
                    $this->newLine();
                    $this->line('Example of a properly defined enum:');
                    $this->newLine();
                    $this->line("    public static \$columns = [");
                    $this->line("        'status' => ['enum' => [");
                    $this->line("            1 => ['label' => 'Active', 'constant' => 'STATUS_ACTIVE'],");
                    $this->line("            2 => ['label' => 'Inactive', 'constant' => 'STATUS_INACTIVE'],");
                    $this->line("            3 => ['label' => 'Pending', 'constant' => 'STATUS_PENDING'],");
                    $this->line('        ]],');
                    $this->line('    ];');
                    $this->newLine();
                    $this->line('For more information, run: php artisan rsx:man enums');
                    return 1;
                }
            }

            // Validate enum column is an integer type in the database.
            $column_type = DB::getSchemaBuilder()->getColumnType($table, $column);
            $valid_integer_types = ['integer', 'bigint', 'smallint', 'tinyint', 'mediumint'];

            // Special case: allow 'boolean' type (TINYINT(1)) ONLY if enum values are 0 and 1.
            $is_boolean_enum = false;
            if ($column_type === 'boolean') {
                $enum_keys = array_keys($enum_values);
                sort($enum_keys);
                if ($enum_keys === [0, 1]) {
                    $is_boolean_enum = true;
                }
            }

            if (!in_array($column_type, $valid_integer_types) && !$is_boolean_enum) {
                $this->error("Invalid column type '{$column_type}' for enum column '{$column}' in table '{$table}' (model '{$full_class_name}').");
                $this->newLine();
                $this->error('ENUM COLUMNS MUST BE INTEGER TYPES.');
                $this->newLine();

                if ($column_type === 'boolean') {
                    $this->line("TINYINT columns are reported as 'boolean' by Laravel because TINYINT is ONLY for true/false values.");
                    $this->line('For enum values with multiple options (1, 2, 3, etc.), you MUST use INT or BIGINT.');
                    $this->newLine();
                }

                $this->line('Enum values are stored as integers in the database. The column must be defined as an');
                $this->line('integer type (INT, BIGINT, SMALLINT, or MEDIUMINT), not VARCHAR, TINYINT, or other types.');
                $this->newLine();
                $this->line("Current column type: {$column_type}");
                $this->line('Required column types: ' . implode(', ', $valid_integer_types));
                $this->newLine();
                $this->line('To fix this issue:');
                $this->line('1. Create a migration to change the column type to INT or BIGINT');
                $this->line('2. Example migration:');
                $this->newLine();
                $this->line('    public function up()');
                $this->line('    {');
                $this->line("        Schema::table('{$table}', function (Blueprint \$table) {");
                $this->line("            \$table->integer('{$column}')->change();");
                $this->line('        });');
                $this->line('    }');
                $this->newLine();
                $this->line('For more information, run: php artisan rsx:man enums');
                return 1;
            }

            // Check for duplicate constants.
            $constants = [];
            foreach ($enum_values as $value => $props) {
                if (isset($props['constant'])) {
                    if (in_array($props['constant'], $constants)) {
                        throw new Exception("Duplicate constant '{$props['constant']}' found in '{$column}' for value '{$value}'");
                    }
                    $constants[] = $props['constant'];
                }
            }
        }

        return null;
    }

    /**
     * Build the fresh docblock + enum-constants block for a model and write it through the
     * fence-safe rewriter. Returns true when the file changed.
     */
    protected function regenerate_model(string $full_class_name, string $class_name, string $file_path): bool
    {
        [$doc_block, $constants_block] = $this->build_metadata($full_class_name);

        return $this->write_model_file($file_path, $class_name, $doc_block, $constants_block);
    }

    /**
     * Build the pre-class docblock and post-brace enum-constants block for a model from its
     * database schema (base + CTI detail columns) and its enum columns. Returns [$doc_block,
     * $constants_block] ($constants_block is '' for enum-less models). Pure computation - no
     * file writes - so it is directly assertable.
     *
     * @return array{0:string,1:string}
     */
    public function build_metadata(string $full_class_name): array
    {
        $model = new $full_class_name();
        $table = $model->getTable();

        $enum_map = $full_class_name::enum_columns();

        // ---- @property lines for every base-table column ----
        // Columns are listed by NAME, never in physical (ORDINAL_POSITION) order: a column added
        // by ALTER TABLE lands last while the same column restored from a CREATE TABLE sits in
        // its declared place, so physical order is a property of how the schema was BUILT. By
        // name, two databases with the same schema generate byte-identical files.
        $properties = [];
        foreach (static::__columns_by_name($table) as $column) {
            $properties[] = $this->property_line($model, $table, $column);
        }

        // ---- Class-Table Inheritance: document detail-table columns as part of the base
        // model, each tagged with the detail table it lives in. ----
        if ($full_class_name::has_detail_tables()) {
            foreach (Detail_Tables_Resolver::detail_classes($full_class_name::$detail_tables) as $detail_class) {
                if (!class_exists($detail_class)) {
                    continue;
                }
                $detail_model = new $detail_class();
                $detail_table = $detail_model->getTable();
                if (!Schema::hasTable($detail_table)) {
                    continue;
                }
                // Structural columns belong to the detail table's own machinery, not the
                // model's field surface.
                $structural = [
                    'id', $detail_class::parent_key(),
                    'created_at', 'updated_at', 'deleted_at',
                    'created_by_id', 'created_by_type', 'updated_by_id', 'updated_by_type',
                    'deleted_by_id', 'deleted_by_type',
                ];
                // Sorted by name within each detail table; the groups keep $detail_tables order.
                foreach (static::__columns_by_name($detail_table) as $column) {
                    if (in_array($column, $structural, true)) {
                        continue;
                    }
                    $properties[] = $this->property_line($detail_model, $detail_table, $column, "(detail: {$detail_table})");
                }
            }
        }

        // ---- BEM enum accessor properties + typed methods ----
        $enum_properties = [];
        $enum_methods = [];
        foreach ($enum_map as $column_name => $enum_definitions) {
            $enum_properties[] = " * @property-read string \${$column_name}__label";
            $enum_properties[] = " * @property-read string \${$column_name}__constant";

            $enum_methods[] = " * @method static array {$column_name}__enum() Get all enum definitions with full metadata";
            $enum_methods[] = " * @method static array {$column_name}__enum_select() Get [{value, label}] array for dropdowns";
            $enum_methods[] = " * @method static array {$column_name}__enum_labels() Get simple id => label map";
            $enum_methods[] = " * @method static array {$column_name}__enum_ids() Get array of all valid enum IDs";
        }

        // ---- enum constants ----
        $enum_constants = [];
        foreach ($enum_map as $column_name => $enum_definitions) {
            foreach ($enum_definitions as $value => $definition) {
                if (isset($definition['constant'])) {
                    $enum_constants[] = "    const {$definition['constant']} = {$value};";
                }
            }
        }

        // ---- assemble the pre-class docblock ----
        $doc_block = "/**\n";
        $doc_block .= " * _AUTO_GENERATED_ Database type hints - do not edit manually\n";
        $doc_block .= " * Table: {$table}\n";
        $doc_block .= " *\n";

        if (!empty($properties)) {
            $doc_block .= implode("\n", $properties) . "\n";
        }

        if (!empty($enum_properties)) {
            $doc_block .= " *\n";
            $doc_block .= implode("\n", $enum_properties) . "\n";
        }

        if (!empty($enum_methods)) {
            $doc_block .= " *\n";
            $doc_block .= implode("\n", $enum_methods) . "\n";
        }

        $doc_block .= " *\n";
        $doc_block .= " * @mixin \\Eloquent\n";
        $doc_block .= " */";

        // ---- assemble the post-brace enum-constants block (empty for enum-less models) ----
        $constants_block = '';
        if (!empty($enum_constants)) {
            $constants_block = "    /**\n";
            $constants_block .= "     * _AUTO_GENERATED_ Enum constants\n";
            $constants_block .= "     */\n";
            $constants_block .= implode("\n", $enum_constants) . "\n";
        }

        return [$doc_block, $constants_block];
    }

    /**
     * Enum constants named on an enum column's @property line before the rest are counted
     * instead ("and 7 more").
     */
    private const ENUM_CONSTANTS_LISTED = 6;

    /**
     * One column's `@property` line: the PHP type its attribute reads as, then what kind of
     * column it is, so the model file alone tells a reader how to treat it. The kind notes,
     * joined by "; " when a column carries several:
     *
     *   enum: STATUS_OPEN, STATUS_DONE (also $status__label and the other $status__* properties)
     *   text type: a value object, filtered on write          (typed as the text-type class)
     *   type ref: a model class name, stored as a type-ref id (typed string)
     *   money: a value object, two decimal places, never a float  (typed as Rsx_Money)
     *   derived: computed by derive_x() from a, b; never assigned  (tagged @property-read)
     *   never exported to the browser
     *   not recorded in revisions
     *   decimal                                               (typed string)
     *   json                                                  (typed string: no array cast)
     *
     * The kinds come from the model's column declarations (columns() and its views); the
     * type from the schema, corrected for the casts a read goes through: a boolean column
     * reads as bool, a DECIMAL as a string (a money column as Rsx_Money), and a JSON column as an array only when the
     * model casts it to one. $tag (the CTI detail marker) follows the name.
     */
    protected function property_line(Rsx_Model_Abstract $model, string $table, string $column, string $tag = ''): string
    {
        $class = get_class($model);
        $db_type = strtolower(DB::getSchemaBuilder()->getColumnType($table, $column));
        $php_type = $this->map_database_type_to_php($db_type);
        $cast = $model->getCasts()[$column] ?? null;
        $notes = [];
        $is_money = $class::is_money_column($column);

        if ($cast === 'boolean' || $cast === 'bool') {
            $php_type = 'bool';
        } elseif (str_starts_with($db_type, 'decimal') && !$is_money) {
            $php_type = 'string';
            $notes[] = 'decimal';
        } elseif (str_starts_with($db_type, 'json')) {
            $php_type = in_array($cast, ['array', 'json'], true) ? 'array' : 'string';
            if ($php_type === 'string') {
                $notes[] = 'json';
            }
        }

        $enum_map = $class::enum_columns();
        $text_types = $class::text_type_columns();
        $derived = $class::derived_columns();

        if (isset($derived[$column])) {
            $from = $derived[$column]['from'];
            $notes[] = "derived: computed by {$derived[$column]['method']}()"
                . ($from === [] ? '' : ' from ' . implode(', ', $from))
                . '; never assigned';
        }

        if (array_key_exists($column, $enum_map)) {
            $notes[] = 'enum: ' . static::__enum_summary($enum_map[$column])
                . " (also \${$column}__label and the other \${$column}__* properties)";
        } elseif (isset($text_types[$column])) {
            $php_type = '\\' . ltrim($text_types[$column], '\\');
            $notes[] = 'text type: a value object, filtered on write';
        } elseif (in_array($column, $class::type_ref_columns(), true)) {
            $php_type = 'string';
            $notes[] = 'type ref: a model class name, stored as a type-ref id';
        } elseif ($is_money) {
            $php_type = '\\' . Rsx_Money::class;
            $notes[] = 'money: a value object, two decimal places, never a float';
        }

        if (in_array($column, $class::never_exported_columns(), true)) {
            $notes[] = 'never exported to the browser';
        }

        // A derived column is never recorded already; the note is for a declared exclusion.
        if (!isset($derived[$column]) && in_array($column, $class::revision_excluded_columns(), true)) {
            $notes[] = 'not recorded in revisions';
        }

        $tag_name = isset($derived[$column]) ? '@property-read' : '@property';
        $line = " * {$tag_name} {$php_type} \${$column}";

        if ($tag !== '') {
            $line .= " {$tag}";
        }

        if ($notes !== []) {
            $line .= ' ' . implode('; ', $notes);
        }

        return $line;
    }

    /**
     * An enum's constants for its @property line: every constant up to
     * ENUM_CONSTANTS_LISTED, then a count of the values left unnamed.
     *
     * @param array<int, array<string, mixed>> $values
     */
    private static function __enum_summary(array $values): string
    {
        $constants = [];
        foreach ($values as $definition) {
            if (isset($definition['constant'])) {
                $constants[] = $definition['constant'];
            }
        }

        if ($constants === []) {
            return count($values) . ' values, no constants';
        }

        $listed = count($constants) > self::ENUM_CONSTANTS_LISTED
            ? array_slice($constants, 0, self::ENUM_CONSTANTS_LISTED - 1)
            : $constants;
        $rest = count($values) - count($listed);

        return implode(', ', $listed) . ($rest > 0 ? " and {$rest} more" : '');
    }

    /**
     * Fence-safe rewrite: the rewriter replaces ONLY the auto-generated docblock and
     * enum-constants block (locating them structurally via php-parser), leaving every other
     * byte - including hand-written declarations between the class brace and the first fence -
     * verbatim. An embedded self-check refuses the write if any hand-written byte would change.
     * A structural surprise (class not found, duplicate/unbalanced markers, parse error) throws;
     * the caller skips the file and reports. Returns true when the file changed.
     */
    protected function write_model_file(string $file_path, string $class_name, string $doc_block, string $constants_block): bool
    {
        $content = file_get_contents($file_path);

        $new_content = Model_Codegen_Rewriter::rewrite($content, $class_name, $doc_block, $constants_block);

        if ($new_content === $content) {
            return false;
        }

        file_put_contents_safe($file_path, $new_content);

        return true;
    }

    /**
     * The table's column names in byte order - a function of the schema's content alone.
     *
     * @return string[]
     */
    private static function __columns_by_name(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        sort($columns, SORT_STRING);

        return $columns;
    }

    protected function map_database_type_to_php(string $db_type): string
    {
        $db_type = strtolower($db_type);

        // Handle types with parentheses (e.g., "varchar(255)").
        if (preg_match('/^(\w+)/', $db_type, $matches)) {
            $db_type = $matches[1];
        }

        return $this->type_map[$db_type] ?? 'mixed';
    }
}
