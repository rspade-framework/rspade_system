<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Kernel\Php;

use App\Http\Kernel;
use Illuminate\Routing\Router;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * app/Http/Kernel.php is a framework-OWNED file, so an application never registers
 * middleware by editing it - it declares middleware in config('rsx.middleware') and the
 * kernel folds that in at bootstrap. These cases drive that merge helper directly, over a
 * kernel built on a THROWAWAY router (the real router must not inherit fixture middleware),
 * pinning the two things ownership makes load-bearing: the merge is APPEND-ONLY, and every
 * bad declaration fails LOUDLY instead of silently doing nothing.
 *
 * Pure logic, no DB.
 */
class Kernel_Middleware_Merge_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Real classes that are NOT in the shipped stack, so their presence proves the merge. */
    private const EXTRA_A = \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class;
    private const EXTRA_B = \Illuminate\Http\Middleware\SetCacheHeaders::class;

    /**
     * A kernel on its own router. The Laravel constructor syncs groups/aliases into the
     * router it is handed; handing it a fresh one keeps the application's router pristine.
     */
    private static function _kernel(): Kernel
    {
        return new Kernel(app(), new Router(app('events'), app()));
    }

    /**
     * Run the protected merge helper for a config block.
     */
    private static function _merge(Kernel $kernel, array $config): void
    {
        $method = new ReflectionMethod($kernel, '__merge_configured_middleware');
        $method->setAccessible(true);
        $method->invoke($kernel, $config);
    }

    /**
     * Read one of the kernel's protected middleware properties.
     */
    private static function _read(Kernel $kernel, string $property): array
    {
        $prop = new ReflectionProperty($kernel, $property);
        $prop->setAccessible(true);

        return $prop->getValue($kernel);
    }

    /**
     * Declared global middleware lands at the END of the framework stack - app middleware
     * runs after framework middleware, always, and the framework entries are untouched.
     */
    public static function test_global_middleware_is_appended_after_the_framework_stack()
    {
        $kernel = self::_kernel();
        $before = self::_read($kernel, 'middleware');

        self::_merge($kernel, ['global' => [self::EXTRA_A, self::EXTRA_B]]);

        $after = self::_read($kernel, 'middleware');
        static::__assert_equals($before, array_slice($after, 0, count($before)));
        static::__assert_equals([self::EXTRA_A, self::EXTRA_B], array_slice($after, count($before)));
    }

    /**
     * A group key names an EXISTING group and appends to its end; sibling groups are
     * untouched.
     */
    public static function test_group_middleware_is_appended_to_that_group_only()
    {
        $kernel = self::_kernel();
        $before = self::_read($kernel, 'middlewareGroups');

        self::_merge($kernel, ['web' => [self::EXTRA_A]]);

        $after = self::_read($kernel, 'middlewareGroups');
        static::__assert_equals($before['api'], $after['api']);
        static::__assert_equals($before['web'], array_slice($after['web'], 0, count($before['web'])));
        static::__assert_equals(self::EXTRA_A, end($after['web']));
    }

    /**
     * A group key the kernel does not declare is a typo, not a request to create a group.
     * It throws, naming the key and the valid ones.
     */
    public static function test_unknown_group_key_throws_naming_the_valid_keys()
    {
        $kernel = self::_kernel();

        $e = static::__assert_throws(
            RuntimeException::class,
            function () use ($kernel) {
                self::_merge($kernel, ['wbe' => [self::EXTRA_A]]);
            },
            "unknown middleware group 'wbe'"
        );

        static::__assert_contains('web', $e->getMessage());
        static::__assert_contains('api', $e->getMessage());
    }

    /**
     * A new alias joins the framework aliases.
     */
    public static function test_a_new_alias_is_merged()
    {
        $kernel = self::_kernel();

        self::_merge($kernel, ['aliases' => ['no_empty_strings' => self::EXTRA_A]]);

        $aliases = self::_read($kernel, 'middlewareAliases');
        static::__assert_equals(self::EXTRA_A, $aliases['no_empty_strings']);
        static::__assert_equals(\App\Http\Middleware\Authenticate::class, $aliases['auth']);
    }

    /**
     * Rebinding an alias the framework already owns would break every route using it,
     * somewhere else entirely. It throws, naming BOTH classes.
     */
    public static function test_alias_collision_throws_naming_both_classes()
    {
        $kernel = self::_kernel();

        $e = static::__assert_throws(
            RuntimeException::class,
            function () use ($kernel) {
                self::_merge($kernel, ['aliases' => ['auth' => self::EXTRA_A]]);
            },
            "already bound to"
        );

        static::__assert_contains(self::EXTRA_A, $e->getMessage());
        static::__assert_contains(\App\Http\Middleware\Authenticate::class, $e->getMessage());
        static::__assert_contains("'auth'", $e->getMessage());

        // And the framework binding survived the refusal.
        $aliases = self::_read($kernel, 'middlewareAliases');
        static::__assert_equals(\App\Http\Middleware\Authenticate::class, $aliases['auth']);
    }

    /**
     * A misspelled class name must fail loudly at bootstrap rather than register nothing
     * and leave the app wondering why its middleware never runs.
     */
    public static function test_a_nonexistent_class_throws_naming_it()
    {
        $kernel = self::_kernel();

        static::__assert_throws(
            RuntimeException::class,
            function () use ($kernel) {
                self::_merge($kernel, ['global' => ['App\Http\Middleware\Nope_Middleware']]);
            },
            'App\Http\Middleware\Nope_Middleware'
        );

        static::__assert_throws(
            RuntimeException::class,
            function () use ($kernel) {
                self::_merge($kernel, ['aliases' => ['nope' => 'App\Http\Middleware\Nope_Middleware']]);
            },
            'which does not exist'
        );
    }

    /**
     * Declaring something already present is a silent no-op, in every bucket - so the merge
     * is idempotent and re-running bootstrap can never double the stack.
     */
    public static function test_declaring_something_already_present_is_a_silent_no_op()
    {
        $kernel = self::_kernel();
        $config = [
            'global' => [\App\Http\Middleware\TrimStrings::class, self::EXTRA_A],
            'web' => [\Illuminate\Routing\Middleware\SubstituteBindings::class],
            'aliases' => ['auth' => \App\Http\Middleware\Authenticate::class],
        ];

        self::_merge($kernel, $config);
        $once = [
            'middleware' => self::_read($kernel, 'middleware'),
            'middlewareGroups' => self::_read($kernel, 'middlewareGroups'),
            'middlewareAliases' => self::_read($kernel, 'middlewareAliases'),
        ];

        self::_merge($kernel, $config);
        static::__assert_equals($once['middleware'], self::_read($kernel, 'middleware'));
        static::__assert_equals($once['middlewareGroups'], self::_read($kernel, 'middlewareGroups'));
        static::__assert_equals($once['middlewareAliases'], self::_read($kernel, 'middlewareAliases'));

        static::__assert_equals(1, count(array_keys($once['middleware'], \App\Http\Middleware\TrimStrings::class, true)));
        static::__assert_equals(
            1,
            count(array_keys($once['middlewareGroups']['web'], \Illuminate\Routing\Middleware\SubstituteBindings::class, true))
        );
    }

    /**
     * The block ships EMPTY, and the framework declares no middleware of its own - so the
     * shipped configuration must leave the kernel exactly as declared. This is the case
     * every real request exercises.
     */
    public static function test_the_shipped_empty_config_changes_nothing()
    {
        $kernel = self::_kernel();
        $before = [
            self::_read($kernel, 'middleware'),
            self::_read($kernel, 'middlewareGroups'),
            self::_read($kernel, 'middlewareAliases'),
        ];

        self::_merge($kernel, []);
        self::_merge($kernel, ['global' => [], 'web' => [], 'api' => [], 'aliases' => []]);

        static::__assert_equals($before[0], self::_read($kernel, 'middleware'));
        static::__assert_equals($before[1], self::_read($kernel, 'middlewareGroups'));
        static::__assert_equals($before[2], self::_read($kernel, 'middlewareAliases'));

        // The framework's own config block is empty: nothing ships declared.
        static::__assert_equals(
            ['global' => [], 'web' => [], 'api' => [], 'aliases' => []],
            (array) config('rsx.middleware', [])
        );
    }

    /**
     * A ':parameters' suffix is Laravel's own spelling and must survive: the class part is
     * what gets existence-checked, the full string is what gets registered.
     */
    public static function test_a_parameterised_entry_is_accepted_whole()
    {
        $kernel = self::_kernel();
        $entry = \Illuminate\Routing\Middleware\ThrottleRequests::class . ':30,1';

        self::_merge($kernel, ['web' => [$entry]]);

        $groups = self::_read($kernel, 'middlewareGroups');
        static::__assert_equals($entry, end($groups['web']));
    }
}
