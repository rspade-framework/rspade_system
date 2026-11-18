<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use RuntimeException;
use App\RSpade\Core\Database\MigrationValidator;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE SCHEMA-BUILDER BAN: MigrationValidator::validate_migration_file() refuses a migration
 * that reaches for Laravel's Schema builder - a Schema:: call (other than the read-only
 * hasTable / hasColumn / getColumnListing), a Blueprint, or a `$table->` column chain - with
 * "Migration validation failed: Schema builder usage detected", after printing the file, the
 * line, the offending construct and the raw-SQL remediation. A migration written as
 * DB::statement() raw SQL passes silently.
 *
 * Every fixture is a scratch migration file; nothing is migrated.
 */
class Migration_Validator_Schema_Builder_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Validate a migration whose up() body is $body. Returns [thrown or null, printed report].
     *
     * @return array{0: ?\Throwable, 1: string}
     */
    private static function __validate(string $body): array
    {
        $root = Rsx_Project_Paths::tmp_path('schema_builder_' . random_hash(8));
        $file = $root . '/2026_01_01_000000_schema_probe.php';
        ensure_directory($root);
        file_put_contents(
            $file,
            "<?php\n\n"
            . "use Illuminate\\Database\\Migrations\\Migration;\n"
            . "use Illuminate\\Support\\Facades\\DB;\n\n"
            . "return new class extends Migration\n"
            . "{\n"
            . "    public function up()\n"
            . "    {\n"
            . $body . "\n"
            . "    }\n"
            . "};\n"
        );

        $thrown = null;
        ob_start();

        try {
            MigrationValidator::validate_migration_file($file);
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $printed = (string) ob_get_clean();
            exec_safe('rm -rf ' . escapeshellarg($root));
        }

        return [$thrown, $printed];
    }

    private static function __assert_refused(string $body, string $construct): void
    {
        [$thrown, $printed] = static::__validate($body);

        static::__assert_not_null($thrown, "{$construct} aborts the migration");
        static::__assert_instance_of(RuntimeException::class, $thrown);
        static::__assert_equals('Migration validation failed: Schema builder usage detected', $thrown->getMessage());

        static::__assert_contains('2026_01_01_000000_schema_probe.php', $printed, 'the report names the file');
        static::__assert_contains($construct, $printed, "the report names {$construct}");
        static::__assert_contains('Remediation:', $printed);
        static::__assert_contains('raw SQL', $printed);
    }

    public static function test_schema_create_is_refused()
    {
        static::__assert_refused(
            "        Schema::create('probe_items', function (Blueprint \$table) {\n            \$table->id();\n        });",
            'Schema::create'
        );
    }

    public static function test_schema_table_drop_and_rename_are_refused()
    {
        static::__assert_refused("        Schema::table('probe_items', function () {});", 'Schema::table');
        static::__assert_refused("        Schema::dropIfExists('probe_items');", 'Schema::dropIfExists');
        static::__assert_refused("        Schema::drop('probe_items');", 'Schema::drop');
        static::__assert_refused("        Schema::rename('probe_items', 'probe_things');", 'Schema::rename');
    }

    public static function test_a_blueprint_alone_is_refused()
    {
        static::__assert_refused("        \$blueprint = new Blueprint('probe_items');", 'Blueprint');
    }

    public static function test_a_table_column_chain_is_refused()
    {
        static::__assert_refused("        \$table->string('name');", '$table->');
    }

    /**
     * The report carries the Schema call's exact line and previews the statement. The fixture
     * puts a multi-line comment ahead of the call: line numbers come from each token's own
     * line, so neither the newline inside `<?php\n` nor a comment's newlines are lost.
     * Lines 1-9 are the fixture header; the body starts on line 10.
     */
    public static function test_the_report_names_the_exact_line_and_previews_the_statement()
    {
        [, $printed] = static::__validate(
            "        DB::statement('SELECT 1');\n\n        /*\n         * A note.\n         */\n        Schema::drop('probe_items');"
        );

        static::__assert_contains("Line:\033[0m 15\n", $printed, 'the report names line 15, where the Schema call is');
        static::__assert_contains("Schema::drop('probe_items');", $printed, 'the preview shows the Schema call');
    }

    public static function test_raw_sql_through_db_statement_passes()
    {
        [$thrown, $printed] = static::__validate(
            "        DB::statement('CREATE TABLE probe_items (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))');\n"
            . "        DB::statement('ALTER TABLE probe_items ADD COLUMN age BIGINT NULL');"
        );

        static::__assert_null($thrown, 'a raw-SQL migration validates');
        static::__assert_equals('', $printed, 'and prints nothing');
    }

    public static function test_read_only_schema_inspection_passes()
    {
        [$thrown] = static::__validate(
            "        if (Schema::hasTable('probe_items') && Schema::hasColumn('probe_items', 'name')) {\n"
            . "            DB::statement('SELECT 1');\n"
            . "        }\n"
            . "        \$columns = Schema::getColumnListing('probe_items');"
        );

        static::__assert_null($thrown, 'hasTable / hasColumn / getColumnListing are not the builder');
    }

    public static function test_a_missing_file_is_refused()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => MigrationValidator::validate_migration_file(Rsx_Project_Paths::tmp_path('no_such_migration_' . random_hash(8) . '.php')),
            'Migration file not found'
        );
    }
}
