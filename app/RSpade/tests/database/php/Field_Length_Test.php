<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use RuntimeException;
use App\RSpade\Core\Database\Field_Length_Script;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Model_Abstract::field_length() - the ONE definition of a column's character limit.
 *
 * A varchar/char column answers its length, every other type answers null (a real answer,
 * not a lookup failure), and a column the model does not have throws. What a page's
 * JavaScript reads is this method's answer too - served per bundle by Field_Length_Script,
 * so the last group of tests walks every model that has a generated stub and proves the two
 * can never disagree - including the one place they deliberately differ, the
 * leading-underscore system-column filter that lives in the script because it is about what
 * may be PUBLISHED, not about what a length is. The served script itself (names, caching,
 * the page's tag) is Field_Length_Script_Test's subject.
 *
 * The named columns are framework columns on framework tables - users, login_users and
 * _file_attachments - so the class states nothing an installed application may have dropped.
 * The class-table-inheritance case is the exception: the framework declares no CTI model, so
 * that test asks the MANIFEST for one and skips in an application that has none.
 */
class Field_Length_Test extends Rsx_Test_Abstract
{
    // Manifest and schema reads only - nothing here writes.
    protected static $use_database_transactions = false;

    public static function test_a_varchar_column_answers_its_length()
    {
        static::__assert_equals(255, Login_User_Model::field_length('email'), 'login_users.email is varchar(255)');
        static::__assert_equals(100, User_Model::field_length('first_name'), 'users.first_name is varchar(100)');
    }

    public static function test_a_non_character_column_answers_null()
    {
        static::__assert_null(User_Model::field_length('id'), 'a bigint has no character limit');
        static::__assert_null(User_Model::field_length('created_at'), 'a datetime has no character limit');
        static::__assert_null(
            File_Attachment_Model::field_length('fileable_meta'),
            'a text column has no character limit'
        );
    }

    public static function test_an_unknown_column_throws_naming_the_class_and_the_column()
    {
        $exception = static::__assert_throws(
            RuntimeException::class,
            fn () => User_Model::field_length('nope_not_a_column'),
            'nope_not_a_column'
        );

        static::__assert_contains(
            'User_Model',
            $exception->getMessage(),
            'the message names the model, not just the column'
        );
    }

    public static function test_a_cti_base_model_answers_for_a_detail_column()
    {
        // A CTI detail column lives on the detail table, never on the base table - the
        // schema reader merges a base model's detail columns into its map (tagging each with
        // its source_table), so the base model answers for them.
        //
        // The framework declares no CTI model of its own, and the concern's CTI fixtures
        // create their tables at TEST time with plain DDL, which moves no state key. So the
        // subject is whatever CTI base this application declares; an application with none
        // cannot express the case at all and skips.
        $detail = static::__a_merged_detail_column();

        if ($detail === null) {
            static::__skip('this application declares no class-table-inheritance model, so no base model has merged detail columns');

            return;
        }

        [$model_class, $fqcn, $column, $length] = $detail;

        static::__assert_equals(
            $length,
            $fqcn::field_length($column),
            "{$model_class}.{$column} is a detail column and the base model answers for it"
        );

        // The premise: the physical base table does not carry it (getColumns() reads the
        // live schema, not the manifest's merged map).
        static::__assert_false(
            in_array($column, $fqcn::getColumns(), true),
            "{$column} is not a physical column of the base table"
        );
    }

    public static function test_the_manifest_accessor_declines_a_class_that_is_not_a_model()
    {
        static::__assert_null(
            Manifest::php_model_columns('Rsx_Test_Abstract'),
            'a non-model class has no column map (and field_length() turns that into a throw)'
        );
    }

    public static function test_the_published_lengths_agree_with_the_model()
    {
        $published = static::__published_lengths();

        static::__assert_greater_than(0, count($published), 'the build produced model stubs to check');

        foreach ($published as $model_class => $entry) {
            $fqcn = $entry['fqcn'];

            foreach ($entry['lengths'] as $column => $published_length) {
                static::__assert_equals(
                    $fqcn::field_length($column),
                    $published_length,
                    "{$model_class}.{$column}: the published length is the model's answer"
                );
            }
        }
    }

    public static function test_no_publishable_length_is_omitted()
    {
        foreach (static::__published_lengths() as $model_class => $entry) {
            $fqcn = $entry['fqcn'];

            foreach (array_keys(Manifest::php_model_columns($model_class) ?? []) as $column) {
                if (static::__is_system_column($column)) {
                    continue;
                }

                if ($fqcn::field_length($column) === null) {
                    continue;
                }

                static::__assert_array_has_key(
                    $column,
                    $entry['lengths'],
                    "{$model_class}.{$column} has a length and is publishable, so the script carries it"
                );
            }
        }
    }

    public static function test_a_system_columns_length_is_never_published()
    {
        // The filter that lives in the SCRIPT, not in field_length(): a single leading
        // underscore marks a system column, toArray() strips it from every payload, so the
        // client can never hold it and can never need its length. PHP still answers for it.
        $system_columns_seen = 0;

        foreach (static::__published_lengths() as $model_class => $entry) {
            $fqcn = $entry['fqcn'];

            foreach (array_keys($entry['lengths']) as $column) {
                static::__assert_false(
                    static::__is_system_column($column),
                    "{$model_class}.{$column} would be a system column published to the client"
                );
            }

            foreach (array_keys(Manifest::php_model_columns($model_class) ?? []) as $column) {
                if (!static::__is_system_column($column)) {
                    continue;
                }

                $system_columns_seen++;

                // PHP answers for it either way - null or a length, but never a throw.
                $fqcn::field_length($column);

                static::__assert_false(
                    array_key_exists($column, $entry['lengths']),
                    "{$model_class}.{$column} is a system column and stays out of the script"
                );
            }
        }

        // Recorded, not asserted: no model in this tree declares a system column today, so
        // the loop above is a latch that arms the moment one appears. (The filter itself is
        // driven against a fixture table in Field_Length_Script_Test.)
        static::__assert_greater_than(-1, $system_columns_seen, 'system columns examined: ' . $system_columns_seen);
    }

    public static function test_no_generated_stub_carries_a_length_table()
    {
        $stubs = glob(Rsx_Project_Paths::stubs_dir(Rsx_Project_Paths::STUBS_MODEL) . '/*.js');

        static::__assert_greater_than(0, count($stubs), 'the build produced model stubs to check');

        foreach ($stubs as $path) {
            static::__assert_false(
                str_contains(file_get_contents($path), 'field_length'),
                basename($path) . ' declares no field_length of its own: a stub is a function of code, and the lengths are the script\'s'
            );
        }
    }

    /**
     * The first merged CTI detail column this application declares, as
     * [model_class, fqcn, column, max_length] - or null when no model has one.
     *
     * A detail column is recognised by its source_table tag: Rsx_Schema records which
     * physical table each column came from, and a base model's map carries columns from its
     * detail tables. Deterministic (both loops are over sorted manifest indexes) so the
     * subject does not change between runs.
     *
     * @return array{0: string, 1: string, 2: string, 3: ?int}|null
     */
    private static function __a_merged_detail_column(): ?array
    {
        $models = Manifest::$data['data']['models'] ?? [];
        ksort($models);

        foreach ($models as $model_class => $model) {
            $base_table = $model['table'] ?? null;
            $fqcn = $model['fqcn'] ?? null;

            if ($base_table === null || $fqcn === null) {
                continue;
            }

            foreach (Manifest::php_model_columns($model_class) ?? [] as $column => $meta) {
                $source_table = $meta['source_table'] ?? $base_table;

                if ($source_table === $base_table) {
                    continue;
                }

                // A varchar detail column, so the assertion is about a real LENGTH rather
                // than about null - the interesting half of field_length().
                if (($meta['max_length'] ?? null) === null) {
                    continue;
                }

                return [$model_class, $fqcn, $column, (int) $meta['max_length']];
            }
        }

        return null;
    }

    /**
     * A single leading underscore (never a double) marks a system column.
     */
    private static function __is_system_column(string $column): bool
    {
        return str_starts_with($column, '_') && !str_starts_with($column, '__');
    }

    /**
     * What a page would be served for every model that has a generated stub, as
     * model_class => ['fqcn' => ..., 'lengths' => [col => int]].
     *
     * Which models have a stub is read off the stubs themselves (the `static __MODEL` line
     * each one declares); the lengths are Field_Length_Script's, the one producer of what
     * the browser receives. A model whose table is absent from this database is left out -
     * it has nothing to compare.
     */
    private static function __published_lengths(): array
    {
        $fqcn_by_class = [];
        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $entry) {
            if (isset($entry['class'], $entry['fqcn'])) {
                $fqcn_by_class[$entry['class']] = $entry['fqcn'];
            }
        }

        $models = [];

        foreach (glob(Rsx_Project_Paths::stubs_dir(Rsx_Project_Paths::STUBS_MODEL) . '/*.js') as $path) {
            if (!preg_match("/static __MODEL = '([A-Za-z0-9_]+)';/", file_get_contents($path), $model_match)) {
                continue;
            }

            if (isset($fqcn_by_class[$model_match[1]]) && Manifest::php_model_columns($model_match[1]) !== null) {
                $models[] = $model_match[1];
            }
        }

        $published = [];

        foreach (Field_Length_Script::lengths_for_models($models) as $model_class => $lengths) {
            $published[$model_class] = ['fqcn' => $fqcn_by_class[$model_class], 'lengths' => $lengths];
        }

        return $published;
    }
}
