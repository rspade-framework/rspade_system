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
 * middleware by editing it - it declares middleware in config('rsx.middleware.global') and
 * the kernel folds that in at bootstrap. These cases drive that merge helper directly, over
 * a kernel built on a THROWAWAY router (the real kernel must not inherit fixture
 * middleware), pinning what ownership makes load-bearing: the merge is APPEND-ONLY, and
 * every bad declaration fails LOUDLY instead of silently doing nothing - including route
 * middleware ('web', 'api', 'aliases'), which could never run because RSX requests do not
 * pass through Laravel's router.
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
     * Route middleware never runs - the kernel hands every request to the RSX front
     * controller, not to Laravel's router - so a non-empty 'web', 'api' or 'aliases'
     * declaration throws, naming the key, instead of silently doing nothing.
     */
    public static function test_route_middleware_keys_throw_naming_the_key()
    {
        $kernel = self::_kernel();

        foreach (['web' => [self::EXTRA_A], 'api' => [self::EXTRA_A], 'aliases' => ['x' => self::EXTRA_A]] as $key => $entries) {
            static::__assert_throws(
                RuntimeException::class,
                function () use ($kernel, $key, $entries) {
                    self::_merge($kernel, [$key => $entries]);
                },
                "rsx.middleware.{$key}') declares route middleware"
            );
        }
    }

    /**
     * Any other key is a typo. It throws, naming the key and the one valid key.
     */
    public static function test_an_unknown_key_throws_naming_the_valid_key()
    {
        $kernel = self::_kernel();

        $e = static::__assert_throws(
            RuntimeException::class,
            function () use ($kernel) {
                self::_merge($kernel, ['globl' => [self::EXTRA_A]]);
            },
            "unknown key 'globl'"
        );

        static::__assert_contains("'global'", $e->getMessage());
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
    }

    /**
     * Declaring something already present is a silent no-op - so the merge is idempotent
     * and re-running bootstrap can never double the stack.
     */
    public static function test_declaring_something_already_present_is_a_silent_no_op()
    {
        $kernel = self::_kernel();
        $config = ['global' => [\App\Http\Middleware\TrimStrings::class, self::EXTRA_A]];

        self::_merge($kernel, $config);
        $once = self::_read($kernel, 'middleware');

        self::_merge($kernel, $config);
        static::__assert_equals($once, self::_read($kernel, 'middleware'));
        static::__assert_equals(1, count(array_keys($once, \App\Http\Middleware\TrimStrings::class, true)));
    }

    /**
     * The block ships EMPTY, and the framework declares no middleware of its own - so the
     * shipped configuration must leave the kernel exactly as declared. Empty route-middleware
     * keys (an application config written before they were retired) change nothing either.
     */
    public static function test_the_shipped_empty_config_changes_nothing()
    {
        $kernel = self::_kernel();
        $before = self::_read($kernel, 'middleware');

        self::_merge($kernel, []);
        self::_merge($kernel, ['global' => [], 'web' => [], 'api' => [], 'aliases' => []]);

        static::__assert_equals($before, self::_read($kernel, 'middleware'));
        static::__assert_equals([], self::_read($kernel, 'middlewareGroups'), 'the kernel declares no route groups');
        static::__assert_equals([], self::_read($kernel, 'middlewareAliases'), 'the kernel declares no aliases');

        // The framework's own config block is empty: nothing ships declared.
        static::__assert_equals(['global' => []], (array) config('rsx.middleware', []));
    }

    /**
     * Laravel's `Class::class.':params'` spelling survives: the class part is what gets
     * existence-checked, the full string is what gets registered.
     */
    public static function test_a_parameterised_entry_is_accepted_whole()
    {
        $kernel = self::_kernel();
        $entry = \Illuminate\Routing\Middleware\ThrottleRequests::class . ':30,1';

        self::_merge($kernel, ['global' => [$entry]]);

        $middleware = self::_read($kernel, 'middleware');
        static::__assert_equals($entry, end($middleware));
    }
}
