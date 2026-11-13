<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use ReflectionClass;
use ReflectionMethod;
use App\RSpade\Commands\Rsx\Constants_Regenerate_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Database\Php\Model_Columns_Fixture_Base_Model_Abstract;
use App\RSpade\Tests\Database\Php\Model_Columns_Fixture_Grandchild_Model;
use App\RSpade\Tests\Migrate\Php\Constants_Regenerate_Bare_Fixture_Model_Abstract;

/**
 * rsx:constants:regenerate never rewrites a file under system/ unless this box is a
 * framework-developer box.
 *
 * THE TEST IS ON THE RESOLVED FILE, and that is the whole point. A core model is an
 * abstract base under app/RSpade/ plus a shell in rsx/models/, and resolve_declaring_file()
 * follows $table and $columns up to the base - so a model whose OWN file is an application
 * file still has its docblock and enum constants written into the FRAMEWORK'S file. On a
 * downstream box that dirties the system/ submodule on every development migrate (a
 * downstream field report, 2026-09-15), and `rsx:framework:pull` discards the bytes on the
 * next update.
 *
 * is_writable_by_this_box() is pure path arithmetic plus one config read, so every case is
 * driven directly with no model, no schema and no file write. resolve_declaring_file() is
 * reflection over fixture classes, equally free of schema and writes.
 *
 * No database access - skip the per-test transaction.
 */
class Constants_Regenerate_Scope_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    const CONFIG_KEY = 'rsx.code_quality.is_framework_developer';

    private static function __ask(string $path, bool $is_framework_developer): bool
    {
        $restore = config(self::CONFIG_KEY);
        config([self::CONFIG_KEY => $is_framework_developer]);

        try {
            $command = new Constants_Regenerate_Command();
            $method = new ReflectionMethod($command, 'is_writable_by_this_box');
            $method->setAccessible(true);

            return (bool) $method->invoke($command, $path);
        } finally {
            config([self::CONFIG_KEY => $restore]);
        }
    }

    /** A real file in the framework tree - the predicate resolves real paths. */
    private static function __framework_file(): string
    {
        return base_path('app/RSpade/Core/Rsx.php');
    }

    /**
     * A path in the application tree. It need not exist - the predicate is path
     * arithmetic, and naming a concrete application file would tie a framework test to a
     * template file an installed application may have renamed.
     */
    private static function __application_file(): string
    {
        return rsx_project_file_path('rsx/models/some_app_model.php');
    }

    // -------------------------------------------------------------------------

    public static function test_a_framework_file_is_refused_downstream()
    {
        static::__assert_false(
            self::__ask(self::__framework_file(), false),
            'a file under app/RSpade/ is never written when this box is not a framework-developer box'
        );
    }

    public static function test_a_framework_file_is_allowed_for_a_framework_developer()
    {
        static::__assert_true(
            self::__ask(self::__framework_file(), true),
            'the monorepo authors those docblocks, so it writes them'
        );
    }

    /**
     * The abstract half of a split core model is the file this command would actually
     * rewrite for an application's shell model. It is under app/RSpade/, so it is refused.
     */
    public static function test_a_core_model_abstract_is_refused_downstream()
    {
        $candidates = glob(base_path('app/RSpade/Core/Models/*_Model_Abstract.php')) ?: [];

        static::__assert_true(
            !empty($candidates),
            'the framework ships split core models - their abstract halves live under app/RSpade/'
        );

        static::__assert_false(
            self::__ask($candidates[0], false),
            'the abstract base a shell model resolves to is framework property'
        );
    }

    public static function test_an_application_file_is_always_writable()
    {
        foreach ([true, false] as $is_framework_developer) {
            static::__assert_true(
                self::__ask(self::__application_file(), $is_framework_developer),
                'an application file is this command\'s to write in either posture'
            );
        }
    }

    /**
     * A path that does not exist still classifies: rsxrealpath() answers false for it and
     * the predicate falls back to the literal string, so a model whose file was deleted
     * between the manifest build and the run is classified, not crashed on.
     */
    public static function test_a_nonexistent_framework_path_is_still_refused()
    {
        static::__assert_false(
            self::__ask(base_path('app/RSpade/Core/Deleted_Model_Abstract.php'), false),
            'a path under app/RSpade/ is refused whether or not the file is there'
        );
    }

    /**
     * A directory whose name merely BEGINS with the framework root is not inside it.
     */
    public static function test_a_sibling_directory_is_not_the_framework_tree()
    {
        static::__assert_true(
            self::__ask(base_path('app/RSpade_extras/Some_Model.php'), false),
            'app/RSpade_extras is not app/RSpade'
        );
    }

    /**
     * [class basename, file] as resolve_declaring_file() answers for a model class.
     *
     * @return array{0:string,1:string}
     */
    private static function __resolve(string $fqcn): array
    {
        $command = new Constants_Regenerate_Command();
        $method = new ReflectionMethod($command, 'resolve_declaring_file');
        $method->setAccessible(true);

        return $method->invoke($command, $fqcn);
    }

    /**
     * A model that declares $columns but inherits $table resolves to the class declaring
     * the table: the table is the anchor.
     */
    public static function test_the_class_declaring_the_table_is_the_anchor()
    {
        [$class_name, $file] = self::__resolve(Model_Columns_Fixture_Grandchild_Model::class);

        static::__assert_equals('Model_Columns_Fixture_Base_Model_Abstract', $class_name);
        static::__assert_equals(
            (new ReflectionClass(Model_Columns_Fixture_Base_Model_Abstract::class))->getFileName(),
            $file,
            'the regions are written where the table is declared'
        );
    }

    /**
     * Rsx_Model_Abstract declares $columns for every model (the audit type columns), and
     * Eloquent's Model declares $table. Neither may be the anchor: a model declaring neither
     * is documented in its own file.
     */
    public static function test_a_declaration_on_the_base_is_never_the_anchor()
    {
        [$class_name, $file] = self::__resolve(Constants_Regenerate_Bare_Fixture_Model_Abstract::class);

        static::__assert_equals('Constants_Regenerate_Bare_Fixture_Model_Abstract', $class_name);
        static::__assert_equals(
            (new ReflectionClass(Constants_Regenerate_Bare_Fixture_Model_Abstract::class))->getFileName(),
            $file,
            'never Rsx_Model_Abstract.php, never the vendor Model'
        );
    }

    /**
     * The gate is applied to the RESOLVED file, after resolve_declaring_file() - not to the
     * model's own manifest file record, which for a shell model names the wrong tree.
     */
    public static function test_the_gate_follows_the_declaring_file_resolution()
    {
        $method = new ReflectionMethod(Constants_Regenerate_Command::class, 'handle');
        $lines = file($method->getFileName());
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $resolve = strpos($source, 'resolve_declaring_file(');
        $gate = strpos($source, 'is_writable_by_this_box(');

        static::__assert_true($resolve !== false, 'handle() resolves the declaring file');
        static::__assert_true($gate !== false, 'handle() asks the gate');
        static::__assert_true(
            $gate > $resolve,
            'the gate is asked about the RESOLVED file, not about the model\'s own file record'
        );
    }
}
