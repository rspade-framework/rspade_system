<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:migrate:check_consistency - the sealed manifest against the schema it is served on.
 *
 * Every case is driven against REAL tables created in the test database and a REPLACED
 * model map in Manifest::$data, so the command's own reads (ModelHelper -> the manifest,
 * SHOW COLUMNS -> the database) are the reads under test. The tables are dropped in a
 * finally; DDL auto-commits in MySQL, so the per-test transaction is off and cleanup is
 * explicit.
 *
 * THE DETAIL-TABLE CASE IS WHY THIS EXISTS. A class-table-inheritance base model carries
 * its detail tables' columns in ONE merged map, and comparing that map against the base
 * table reported every detail column as missing - a false ERROR on every production
 * migrate of an application that uses detail tables. Each manifest column records the
 * source_table it came from and the command compares only its own.
 */
class Check_Consistency_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    const BASE_TABLE = '_test_consistency_base';
    const DETAIL_TABLE = '_test_consistency_detail';

    /** @var array|null The real manifest data, restored after every test. */
    private static $saved_manifest = null;

    /** @var string|null The real build root, restored after the missing-manifest test. */
    private static $saved_build_root = null;

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Create the two fixture tables. The detail table's columns are what a base model's
     * merged column map would carry alongside its own.
     */
    private static function __create_tables(): void
    {
        static::__drop_tables();

        DB::statement(
            'CREATE TABLE `' . self::BASE_TABLE . '` ('
            . ' id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . ' name VARCHAR(255) NULL,'
            . ' status_id BIGINT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        DB::statement(
            'CREATE TABLE `' . self::DETAIL_TABLE . '` ('
            . ' id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . ' first_name VARCHAR(255) NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function __drop_tables(): void
    {
        DB::statement('DROP TABLE IF EXISTS `' . self::DETAIL_TABLE . '`');
        DB::statement('DROP TABLE IF EXISTS `' . self::BASE_TABLE . '`');
    }

    /**
     * One manifest column entry. Only the name and the source table matter here; the rest
     * of the shape is carried so the fixture looks like what the indexer writes.
     */
    private static function __column(string $source_table): array
    {
        return [
            'type' => 'string',
            'max_length' => null,
            'nullable' => true,
            'key' => '',
            'default' => null,
            'extra' => '',
            'source_table' => $source_table,
        ];
    }

    /**
     * Replace the manifest's model registry with $models (class => [table, columns]).
     */
    private static function __install_manifest(array $models): void
    {
        Manifest::init();

        if (self::$saved_manifest === null) {
            self::$saved_manifest = Manifest::$data;
        }

        $rows = [];
        $by_table = [];

        foreach ($models as $class => $definition) {
            $rows[$class] = [
                'fqcn' => 'App\\RSpade\\Tests\\Migrate\\Php\\' . $class,
                'file' => 'app/RSpade/tests/migrate/php/' . $class . '.php',
                'table' => $definition['table'],
                'columns' => $definition['columns'],
                'class' => $class,
                'fingerprint' => 'test',
            ];
            $by_table[$definition['table']] = $class;
        }

        Manifest::$data['data']['models'] = $rows;
        Manifest::$data['data']['models_by_table'] = $by_table;
    }

    /**
     * The healthy fixture: a base model whose merged map spans both tables, plus the
     * detail model as its own row. Exactly the shape that used to false-positive.
     */
    private static function __install_detail_table_manifest(array $extra_base_columns = []): void
    {
        $base_columns = [
            'id' => self::__column(self::BASE_TABLE),
            'name' => self::__column(self::BASE_TABLE),
            'status_id' => self::__column(self::BASE_TABLE),
            // Merged in from the detail table by Model_ManifestSupport.
            'first_name' => self::__column(self::DETAIL_TABLE),
        ];

        foreach ($extra_base_columns as $name) {
            $base_columns[$name] = self::__column(self::BASE_TABLE);
        }

        self::__install_manifest([
            'Consistency_Base_Fixture_Model' => ['table' => self::BASE_TABLE, 'columns' => $base_columns],
            'Consistency_Detail_Fixture_Model' => [
                'table' => self::DETAIL_TABLE,
                'columns' => [
                    'id' => self::__column(self::DETAIL_TABLE),
                    'first_name' => self::__column(self::DETAIL_TABLE),
                ],
            ],
        ]);
    }

    private static function __restore(): void
    {
        if (self::$saved_manifest !== null) {
            Manifest::$data = self::$saved_manifest;
            self::$saved_manifest = null;
        }

        if (self::$saved_build_root !== null) {
            Rsx_Project_Paths::_override(['build' => self::$saved_build_root]);
            self::$saved_build_root = null;
        }

        Rsx::clear_mode_cache();
        static::__drop_tables();
    }

    /**
     * Run the command in production mode and return [exit code, output].
     *
     * @return array{0:int,1:string}
     */
    private static function __run(string $mode = Rsx::MODE_PRODUCTION): array
    {
        Rsx::_testing_set_mode($mode);

        $exit = Artisan::call('rsx:migrate:check_consistency');

        return [$exit, Artisan::output()];
    }

    // -------------------------------------------------------------------------
    // The mode gate
    // -------------------------------------------------------------------------

    public static function test_development_mode_is_a_fatal_refusal()
    {
        try {
            self::__create_tables();
            self::__install_detail_table_manifest();

            [$exit, $output] = self::__run(Rsx::MODE_DEVELOPMENT);

            static::__assert_equals(1, $exit, 'development mode exits 1');
            static::__assert_contains('runs in a production mode only', $output, 'the refusal says why');
        } finally {
            self::__restore();
        }
    }

    public static function test_debug_mode_is_a_production_mode_and_runs()
    {
        try {
            self::__create_tables();
            self::__install_detail_table_manifest();

            [$exit] = self::__run(Rsx::MODE_DEBUG);

            static::__assert_equals(0, $exit, 'debug is a sealed build and the check runs there');
        } finally {
            self::__restore();
        }
    }

    // -------------------------------------------------------------------------
    // The missing-manifest gate
    // -------------------------------------------------------------------------

    public static function test_no_manifest_index_is_a_fatal_refusal()
    {
        try {
            self::__create_tables();
            self::__install_detail_table_manifest();

            // Point the build root at an empty directory so manifest_index_file() names a
            // file that is not there. The real root is restored by __restore().
            self::$saved_build_root = Rsx_Project_Paths::build_root();
            $empty = Rsx_Project_Paths::tmp_path('check-consistency-no-manifest-' . random_hash(8));
            ensure_directory($empty);
            Rsx_Project_Paths::_override(['build' => $empty]);

            [$exit, $output] = self::__run();

            static::__assert_equals(1, $exit, 'no manifest exits 1');
            static::__assert_contains('There is no manifest to check', $output, 'the refusal names the condition');
            static::__assert_contains('rsx:build --force', $output, 'the refusal names the repair');

            exec_safe('rm -rf ' . escapeshellarg($empty));
        } finally {
            self::__restore();
        }
    }

    // -------------------------------------------------------------------------
    // Detail tables - the false positive this rewrite removed
    // -------------------------------------------------------------------------

    public static function test_a_detail_table_model_passes()
    {
        try {
            self::__create_tables();
            self::__install_detail_table_manifest();

            [$exit, $output] = self::__run();

            static::__assert_equals(0, $exit, 'a base model carrying merged detail columns is consistent');
            static::__assert_contains('matches the database schema', $output, 'the success line is printed');
            static::__assert_true(
                strpos($output, 'first_name') === false,
                'the detail column is never reported against the base table'
            );
        } finally {
            self::__restore();
        }
    }

    // -------------------------------------------------------------------------
    // A manifest column the table does not have: ERROR, exit 1
    // -------------------------------------------------------------------------

    public static function test_a_column_missing_from_the_table_is_an_error()
    {
        try {
            self::__create_tables();
            self::__install_detail_table_manifest(['never_migrated']);

            [$exit, $output] = self::__run();

            static::__assert_equals(1, $exit, 'a manifest column missing from its table exits 1');
            static::__assert_contains(
                '[ERROR] ' . self::BASE_TABLE . '.never_migrated is in the manifest and not in the table',
                $output,
                'the missing column is named'
            );
            static::__assert_contains('rsx:build --force', $output, 'the explainer names the repair');
            static::__assert_contains('php artisan migrate', $output, 'the explainer names the recommended order');
            static::__assert_contains('rsx:man prod', $output, 'the explainer points at the prod man page');
        } finally {
            self::__restore();
        }
    }

    /**
     * Every discrepancy is reported before the exit code is decided - an operator on a
     * production box must not have to re-run the check once per fault.
     */
    public static function test_every_discrepancy_is_listed_before_the_verdict()
    {
        try {
            self::__create_tables();
            self::__install_detail_table_manifest(['never_migrated', 'also_never_migrated']);

            [$exit, $output] = self::__run();

            static::__assert_equals(1, $exit, 'the run still exits 1');
            static::__assert_contains('never_migrated is in the manifest and not in the table', $output, 'the first is listed');
            static::__assert_contains('also_never_migrated is in the manifest and not in the table', $output, 'the second is listed');
            static::__assert_contains('2 column(s) the manifest declares are missing', $output, 'the verdict counts both');
        } finally {
            self::__restore();
        }
    }

    public static function test_a_table_missing_from_the_database_is_an_error()
    {
        try {
            self::__create_tables();
            self::__install_manifest([
                'Consistency_Ghost_Fixture_Model' => [
                    'table' => '_test_consistency_never_created',
                    'columns' => ['id' => self::__column('_test_consistency_never_created')],
                ],
            ]);

            [$exit, $output] = self::__run();

            static::__assert_equals(1, $exit, 'a manifest table missing from the database exits 1');
            static::__assert_contains(
                "Table '_test_consistency_never_created' is in the manifest and not in the database",
                $output,
                'the missing table is named'
            );
        } finally {
            self::__restore();
        }
    }

    // -------------------------------------------------------------------------
    // A table column the manifest does not know: WARNING, exit 0
    // -------------------------------------------------------------------------

    public static function test_a_column_unknown_to_the_manifest_is_only_a_warning()
    {
        try {
            self::__create_tables();
            DB::statement('ALTER TABLE `' . self::BASE_TABLE . '` ADD COLUMN ahead_of_the_build BIGINT NULL');

            self::__install_detail_table_manifest();

            [$exit, $output] = self::__run();

            static::__assert_equals(0, $exit, 'the database being ahead of the build is not a failure');
            static::__assert_contains(
                '[WARNING]  ' . self::BASE_TABLE . '.ahead_of_the_build is in the table and not in the manifest',
                $output,
                'the unknown column is named'
            );
            static::__assert_contains('Nothing served reads them', $output, 'the summary says why it is not an error');
        } finally {
            self::__restore();
        }
    }

    /**
     * Both findings at once: the warning does not soften the error, and the error does not
     * hide the warning.
     */
    public static function test_a_warning_and_an_error_together_exit_one_and_both_are_reported()
    {
        try {
            self::__create_tables();
            DB::statement('ALTER TABLE `' . self::BASE_TABLE . '` ADD COLUMN ahead_of_the_build BIGINT NULL');

            self::__install_detail_table_manifest(['never_migrated']);

            [$exit, $output] = self::__run();

            static::__assert_equals(1, $exit, 'one missing column decides the exit code');
            static::__assert_contains('ahead_of_the_build is in the table and not in the manifest', $output, 'the warning is reported');
            static::__assert_contains('never_migrated is in the manifest and not in the table', $output, 'the error is reported');
        } finally {
            self::__restore();
        }
    }
}
