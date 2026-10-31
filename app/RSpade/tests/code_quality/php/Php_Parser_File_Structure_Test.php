<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\PHP\Php_Parser;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Php_Parser's two structure validators (the source of PhpFileStructure_CodeQualityRule's
 * findings), read through Php_Parser::parse() over fixtures written to a scratch tree whose
 * path carries an `rsx/` segment - the validators judge application source by its path.
 *
 * Proves they are armed (executed code at file scope is reported, in a classless file and
 * a class file alike), that an anonymous class's body is CLASS scope (a migration's
 * `return new class ... { }` is clean; any other `new class` at file scope is ONE T_NEW
 * finding, nothing from inside its body), and that a file in a directory the manifest
 * never loads (`resource/`) is outside their subject.
 */
class Php_Parser_File_Structure_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The structure violations Php_Parser::parse() reports for $source written at rsx/<$relative> */
    private static function __violations(string $relative, string $source): array
    {
        $root = Rsx_Project_Paths::tmp_path('php_parser_structure_' . random_hash(8));
        $file = $root . '/rsx/' . $relative;
        ensure_directory(dirname($file));
        file_put_contents($file, $source);

        try {
            return Php_Parser::parse($file)['structure_violations'] ?? [];
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    public static function test_a_migration_returning_an_anonymous_class_is_clean()
    {
        $violations = static::__violations('lib/fixture_migration.php', <<<'PHP'
<?php

return new class extends Fixture_Migration_Base
{
    public function up()
    {
        DB::statement('CREATE TABLE x (id BIGINT)');
        if (true) {
            $this->helper("{$this->name} }");
        }
    }
};
PHP);

        static::__assert_equals([], $violations, 'the body of the returned anonymous class is class scope');
    }

    public static function test_an_attributed_anonymous_class_with_constructor_arguments_is_skipped_whole()
    {
        $violations = static::__violations('lib/fixture_attributed.php', <<<'PHP'
<?php

return new #[Fixture_Attribute(['a' => 1])] class(fn () => ['{']) extends Fixture_Base
{
    public function run()
    {
        return "${nested} {" . strtolower('X');
    }
};
PHP);

        static::__assert_equals([], $violations, 'attributes, arguments and braces in strings do not miscount the body');
    }

    public static function test_an_anonymous_class_instantiated_at_file_scope_is_one_finding()
    {
        $violations = static::__violations('lib/fixture_instantiates.php', <<<'PHP'
<?php

$handler = new class
{
    public function handle()
    {
        do_something();
    }
};
PHP);

        static::__assert_count(1, $violations, 'one finding, nothing from inside the body');
        static::__assert_equals('T_NEW', $violations[0]['token'] ?? null);
        static::__assert_equals(3, $violations[0]['line'] ?? null);
    }

    public static function test_code_executed_by_a_classless_file_is_reported()
    {
        $violations = static::__violations('lib/fixture_helpers.php', <<<'PHP'
<?php

function fixture_helper()
{
    return strtolower('X');
}

register_something();
echo 'loaded';
PHP);

        $messages = implode("\n", array_column($violations, 'message'));
        static::__assert_count(2, $violations, 'the call and the echo, not the function body');
        static::__assert_contains('register_something()', $messages);
        static::__assert_contains('T_ECHO', $messages);
    }

    public static function test_a_global_function_in_a_class_file_is_reported()
    {
        $violations = static::__violations('lib/fixture_class_file.php', <<<'PHP'
<?php

class Fixture_Structure_Class
{
    public static function ok()
    {
        return 1;
    }
}

function fixture_stray_function()
{
    return 2;
}
PHP);

        static::__assert_count(1, $violations);
        static::__assert_contains('fixture_stray_function', $violations[0]['message'] ?? '');
    }

    public static function test_a_file_the_manifest_never_loads_is_not_judged()
    {
        $violations = static::__violations('resource/config/fixture_config.php', <<<'PHP'
<?php

return [
    'enabled' => env('FIXTURE_ENABLED', false),
    'hosts' => explode(',', (string) env('FIXTURE_HOSTS', '')),
];
PHP);

        static::__assert_equals([], $violations, 'resource/ is framework-ignored, so outside the validators\' subject');
    }
}
