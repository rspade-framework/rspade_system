<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Task\Task_Alias_Command;
use App\RSpade\Core\Task\Task_Command_ManifestSupport;
use App\RSpade\Core\Task\Task_Command_Registrar;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Test_Echo_Service;

/**
 * #[Command] discovery: the five build-time FATALs, the baked table, and registration.
 *
 * The FATAL tests drive Task_Command_ManifestSupport::process() over SYNTHETIC manifest
 * data. That is the whole point of the module being a pure function over the files array:
 * a bad declaration can be proved to break the build without a bad declaration ever
 * existing in the tree, and without a real build.
 *
 * Reads only - no rows, no writes, so default transaction isolation is correct.
 */
class Task_Command_Definition_Test extends Rsx_Test_Abstract
{
    /**
     * A synthetic one-file manifest declaring one #[Command] with the given arguments.
     *
     * @param array $command_arguments Attribute arguments as the scanner records them.
     * @param bool $with_task Whether the method also carries #[Task].
     */
    private static function __manifest(array $command_arguments, bool $with_task = true): array
    {
        $attributes = ['Command' => [$command_arguments]];

        if ($with_task) {
            $attributes = ['Task' => [['a task']]] + $attributes;
        }

        // The module reads the ATTRIBUTE INDEX, not the file map - a #[Command] name is
        // unique tree-wide, and the index already lists every declaration. A real manifest
        // always carries one (Phase 4 builds it before the modules run), so the synthetic
        // shape carries one too.
        return [
            'data' => [
                'files' => [
                    'rsx/services/synthetic_service.php' => [
                        'class' => 'Synthetic_Service',
                        'fqcn' => 'Rsx\\Services\\Synthetic_Service',
                        'public_static_methods' => [
                            'do_work' => ['attributes' => $attributes],
                        ],
                    ],
                ],
                'attribute_index' => [
                    'Command' => [
                        [
                            'file' => 'rsx/services/synthetic_service.php',
                            'class' => 'Synthetic_Service',
                            'member' => 'do_work',
                            'instances' => $attributes['Command'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Run the support module over synthetic data and return the baked table.
     */
    private static function __process(array $manifest): array
    {
        Task_Command_ManifestSupport::process($manifest, array_keys($manifest['data']['files']), []);

        return $manifest['data']['task_commands'];
    }

    // -------------------------------------------------------------------------
    // The happy path
    // -------------------------------------------------------------------------

    public static function test_a_valid_declaration_is_baked_into_the_table()
    {
        $table = static::__process(static::__manifest(['myapp:import', 'Import the feed']));

        static::__assert_array_has_key('myapp:import', $table);
        static::__assert_equals('Rsx\\Services\\Synthetic_Service', $table['myapp:import']['class']);
        static::__assert_equals('do_work', $table['myapp:import']['method']);
        static::__assert_equals('Import the feed', $table['myapp:import']['description']);
    }

    public static function test_named_attribute_arguments_are_accepted()
    {
        $table = static::__process(static::__manifest([
            'name' => 'myapp:import',
            'description' => 'Import the feed',
        ]));

        static::__assert_array_has_key('myapp:import', $table);
        static::__assert_equals('Import the feed', $table['myapp:import']['description']);
    }

    public static function test_a_method_with_no_command_produces_no_row()
    {
        $manifest = [
            'data' => [
                'files' => [
                    'rsx/services/synthetic_service.php' => [
                        'fqcn' => 'Rsx\\Services\\Synthetic_Service',
                        'public_static_methods' => [
                            'do_work' => ['attributes' => ['Task' => [['a task']]]],
                        ],
                    ],
                ],
            ],
        ];

        static::__assert_equals([], static::__process($manifest));
    }

    // -------------------------------------------------------------------------
    // The five FATALs
    // -------------------------------------------------------------------------

    public static function test_fatal_command_without_task()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process(static::__manifest(['myapp:import', 'Import'], false)),
            'may only annotate a #[Task] method'
        );
    }

    public static function test_fatal_name_without_a_prefix_segment()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process(static::__manifest(['import', 'Import'])),
            "needs a 'prefix:name' segment"
        );
    }

    public static function test_fatal_name_with_an_empty_prefix_or_suffix()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process(static::__manifest([':import', 'Import'])),
            "needs a 'prefix:name' segment"
        );

        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process(static::__manifest(['myapp:', 'Import'])),
            "needs a 'prefix:name' segment"
        );
    }

    public static function test_fatal_name_in_the_rsx_namespace()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process(static::__manifest(['rsx:import', 'Import'])),
            "The 'rsx:' prefix belongs to the framework"
        );
    }

    public static function test_fatal_collision_with_another_command()
    {
        $manifest = static::__manifest(['myapp:import', 'Import']);
        $manifest['data']['files']['rsx/services/other_service.php'] = [
            'fqcn' => 'Rsx\\Services\\Other_Service',
            'public_static_methods' => [
                'also_imports' => [
                    'attributes' => [
                        'Task' => [['a task']],
                        'Command' => [['myapp:import', 'Import again']],
                    ],
                ],
            ],
        ];
        $manifest['data']['attribute_index']['Command'][] = [
            'file' => 'rsx/services/other_service.php',
            'class' => 'Other_Service',
            'member' => 'also_imports',
            'instances' => [['myapp:import', 'Import again']],
        ];

        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process($manifest),
            'One command name names exactly one task'
        );
    }

    /**
     * db:wipe is a real framework command (Commands/Restricted) whose name carries a
     * prefix and is not 'rsx:', so it reaches the framework-collision rule rather than
     * one of the naming rules ahead of it.
     */
    public static function test_fatal_collision_with_a_framework_command()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process(static::__manifest(['db:wipe', 'Wipe it'])),
            'already a framework command'
        );
    }

    public static function test_fatal_missing_description()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process(static::__manifest(['myapp:import'])),
            'the description and is required'
        );
    }

    public static function test_fatal_empty_description()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process(static::__manifest(['myapp:import', '   '])),
            'the description and is required'
        );
    }

    public static function test_fatal_missing_name()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn() => static::__process(static::__manifest([])),
            'the command name and is required'
        );
    }

    // -------------------------------------------------------------------------
    // The real baked table and the registrar
    // -------------------------------------------------------------------------

    /**
     * The fixture service in this directory declares two aliases; they must be in the
     * table this build produced, pointing at the right task.
     */
    public static function test_the_real_manifest_table_names_the_fixture_tasks()
    {
        $table = Manifest::get_task_commands();

        static::__assert_array_has_key('rsx_test:echo', $table);
        static::__assert_equals(Test_Echo_Service::class, $table['rsx_test:echo']['class']);
        static::__assert_equals('echo_params', $table['rsx_test:echo']['method']);

        static::__assert_array_has_key('rsx_test:fail', $table);
        static::__assert_equals('always_fail', $table['rsx_test:fail']['method']);
    }

    /**
     * The registrar builds one Task_Alias_Command per row, and the alias IS
     * rsx:task:run with the service and method already decided.
     */
    public static function test_the_alias_carries_the_name_description_and_target()
    {
        $alias = new Task_Alias_Command('myapp:import', 'Import the feed', 'Report_Service', 'generate');

        static::__assert_equals('myapp:import', $alias->getName());
        static::__assert_equals('Import the feed', $alias->getDescription());
        static::__assert_true(
            $alias->getDefinition()->hasOption('debug'),
            'an alias keeps --debug, exactly as rsx:task:run has it'
        );
    }

    /**
     * Registration reads the baked table and nothing else - so an unbuilt manifest
     * registers nothing rather than fataling artisan's boot.
     */
    public static function test_registration_from_an_absent_table_registers_nothing()
    {
        $before = static::__registered_alias_names();

        $manifest = &Manifest::get_full_manifest();
        $saved = $manifest['data']['task_commands'] ?? null;
        unset($manifest['data']['task_commands']);

        try {
            static::__assert_equals([], Manifest::get_task_commands());
            Task_Command_Registrar::register();
        } finally {
            if ($saved !== null) {
                $manifest['data']['task_commands'] = $saved;
            }
        }

        static::__assert_equals(
            $before,
            static::__registered_alias_names(),
            'an absent table must register no commands at all'
        );
    }

    /**
     * The fixture aliases really are registered with the running console application -
     * the registrar's Kernel::commands() hook, observed from the far end.
     */
    public static function test_the_fixture_aliases_are_registered_with_artisan()
    {
        $names = static::__registered_alias_names();

        static::__assert_true(in_array('rsx_test:echo', $names, true), 'rsx_test:echo must be registered');
        static::__assert_true(in_array('rsx_test:fail', $names, true), 'rsx_test:fail must be registered');
    }

    /**
     * Names of every Task_Alias_Command currently registered with artisan.
     *
     * @return array<int, string>
     */
    private static function __registered_alias_names(): array
    {
        $names = [];

        foreach (\Illuminate\Support\Facades\Artisan::all() as $name => $command) {
            if ($command instanceof Task_Alias_Command) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }
}
