<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Task\Task_Access_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Access_Fixture_Service;

/**
 * #[Task_Access] declarations: the baked table and the build-time FATALs.
 *
 * The FATAL tests drive Task_Access_ManifestSupport::rebuild() over SYNTHETIC manifest data,
 * so a bad declaration is proved to break the build without one ever existing in the tree.
 * One test reads the real table the suite's manifest baked from Task_Access_Fixture_Service;
 * the last holds the line that the PUBLIC API has no generic task interface.
 *
 * Reads only - no rows.
 */
class Task_Access_Definition_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const FILE = 'rsx/services/synthetic_service.php';

    /**
     * A synthetic manifest: one service whose task `do_work` carries the given #[Task_Access]
     * instances, plus the given extra methods.
     *
     * @param array $instances Attribute argument lists as the scanner records them
     * @param array $static_methods method name => parameter count (public static)
     * @param array $instance_methods method name => parameter count (public, not static)
     */
    private static function __manifest(array $instances, array $static_methods = [], array $instance_methods = [], bool $with_task = true): array
    {
        $attributes = $with_task ? ['Task' => [['a task']]] : [];
        $attributes['Task_Access'] = $instances;

        $params = fn (int $count) => array_map(fn ($i) => ['name' => "p{$i}"], range(1, max(1, $count)));
        $methods = ['do_work' => ['attributes' => $attributes, 'parameters' => $params(2)]];
        foreach ($static_methods as $name => $count) {
            $methods[$name] = ['attributes' => [], 'parameters' => $count === 0 ? [] : $params($count)];
        }
        $instance = [];
        foreach ($instance_methods as $name => $count) {
            $instance[$name] = ['parameters' => $params($count)];
        }

        return [
            'data' => [
                'files' => [
                    self::FILE => [
                        'class' => 'Synthetic_Service',
                        'fqcn' => 'Rsx\\Services\\Synthetic_Service',
                        'extends' => 'Rsx_Service_Abstract',
                        'public_static_methods' => $methods,
                        'public_instance_methods' => $instance,
                    ],
                ],
                'php_classes' => [],
                'attribute_index' => [
                    'Task_Access' => [
                        ['file' => self::FILE, 'class' => 'Synthetic_Service', 'member' => 'do_work', 'instances' => $instances],
                    ],
                ],
            ],
        ];
    }

    private static function __rebuild(array $manifest): array
    {
        Task_Access_ManifestSupport::rebuild($manifest);

        return $manifest['data']['task_access'];
    }

    // -------------------------------------------------------------------------
    // The baked table
    // -------------------------------------------------------------------------

    public static function test_dispatcher_and_a_custom_check_are_baked_with_their_realm()
    {
        $table = static::__rebuild(static::__manifest([['dispatcher']]));
        static::__assert_equals(
            ['class' => 'Rsx\\Services\\Synthetic_Service', 'service' => 'Synthetic_Service', 'method' => 'do_work', 'check' => 'dispatcher', 'realm' => 'staff'],
            $table['Rsx\\Services\\Synthetic_Service::do_work']
        );

        $table = static::__rebuild(static::__manifest([['may_see', 'realm' => 'portal']], ['may_see' => 3]));
        static::__assert_equals('may_see', $table['Rsx\\Services\\Synthetic_Service::do_work']['check']);
        static::__assert_equals('portal', $table['Rsx\\Services\\Synthetic_Service::do_work']['realm']);
    }

    public static function test_an_inherited_check_is_accepted()
    {
        $manifest = static::__manifest([['shared_check']]);
        $manifest['data']['php_classes']['Rsx_Service_Abstract'] = ['file' => 'rsx/services/base.php'];
        $manifest['data']['files']['rsx/services/base.php'] = [
            'class' => 'Rsx_Service_Abstract',
            'public_static_methods' => ['shared_check' => ['parameters' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]]],
        ];

        static::__assert_equals('shared_check', static::__rebuild($manifest)['Rsx\\Services\\Synthetic_Service::do_work']['check']);
    }

    public static function test_the_suite_manifest_bakes_the_fixture_declarations()
    {
        $table = Manifest::get_task_access();
        $key = Task_Access_Fixture_Service::class . '::';

        static::__assert_equals('dispatcher', $table[$key . 'mine']['check'] ?? null);
        static::__assert_equals('portal', $table[$key . 'portal_mine']['realm'] ?? null);
        static::__assert_equals('watched_access', $table[$key . 'watched']['check'] ?? null);
        static::__assert_equals('any', $table[$key . 'watched_anywhere']['realm'] ?? null);
        static::__assert_false(isset($table[$key . 'undeclared']), 'an undeclared task has no row');
    }

    // -------------------------------------------------------------------------
    // The FATALs
    // -------------------------------------------------------------------------

    public static function test_fatal_without_task()
    {
        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild(static::__manifest([['dispatcher']], [], [], false)), 'may only annotate a #[Task] method');
    }

    public static function test_fatal_on_a_class()
    {
        $manifest = static::__manifest([['dispatcher']]);
        $manifest['data']['attribute_index']['Task_Access'][0]['member'] = null;

        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild($manifest), 'annotates a #[Task] METHOD');
    }

    public static function test_fatal_when_repeated()
    {
        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild(static::__manifest([['dispatcher'], ['may_see']], ['may_see' => 3])), 'Duplicate #[Task_Access]');
    }

    public static function test_fatal_without_a_check_name()
    {
        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild(static::__manifest([[]])), 'The first argument is required');
        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild(static::__manifest([['']])), 'The first argument is required');
    }

    public static function test_fatal_on_an_unknown_argument_or_realm()
    {
        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild(static::__manifest([['dispatcher', 'actions' => 'view']])), 'Unknown argument(s): actions');
        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild(static::__manifest([['dispatcher', 'realm' => 'admin']])), "realm 'admin'");
    }

    public static function test_fatal_when_the_check_is_missing_not_static_or_the_wrong_arity()
    {
        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild(static::__manifest([['may_see']])), 'has no public static method may_see()');
        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild(static::__manifest([['may_see']], [], ['may_see' => 3])), 'may_see() is not static');
        static::__assert_throws(\RuntimeException::class, fn () => static::__rebuild(static::__manifest([['may_see']], ['may_see' => 2])), 'takes 2 parameter(s)');
    }

    // -------------------------------------------------------------------------
    // The public API has no task interface
    // -------------------------------------------------------------------------

    /**
     * #[Task_Access] governs the BROWSER task API only. The external API has no generic task
     * interface and must never grow one: an integration's run status is an endpoint of the
     * application's own. So no framework API controller reads, controls or starts task runs.
     */
    public static function test_no_framework_api_controller_reaches_task_runs()
    {
        $offenders = [];
        $examined = 0;

        foreach (Manifest::php_get_extending('Rsx_Api_Controller_Abstract') as $class => $record) {
            $file = $record['file'] ?? '';
            if (!str_starts_with($file, 'app/RSpade/') || str_starts_with($file, 'app/RSpade/tests/')) {
                continue;
            }

            $examined++;
            $source = file_get_contents(base_path($file));
            foreach (['Task_Run_Model', 'Task_Gates', 'Task::dispatch', 'Task::internal', 'Rsx_Task_Controller'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = "{$class} names {$needle}";
                }
            }
        }

        static::__assert_greater_than(0, $examined, 'the framework ships API controllers to examine');
        static::__assert_equals([], $offenders, 'the public API has no generic task interface (skill rspade:task-access)');
    }
}
