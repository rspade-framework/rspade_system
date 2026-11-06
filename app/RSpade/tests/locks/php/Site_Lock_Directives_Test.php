<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use RuntimeException;
use App\RSpade\Core\Locks\Site_Lock_Directives;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * SITE-LOCK-01 - #[Writer] / #[Reader] / #[Manual_Lock] placement, and the mode each surface
 * kind resolves to when it declares none. Driven as a pure function over synthetic attribute
 * buckets: Auth_ManifestSupport calls exactly this for every public static method it indexes,
 * and a refusal there aborts the manifest build.
 */
class Site_Lock_Directives_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const WHERE = 'Fixture_Controller::method in fixture.php';

    public static function test_request_surfaces_are_readers_by_default()
    {
        foreach ([['ajax'], ['api'], ['companion'], ['model_fetch'], ['model_relationship']] as $kinds) {
            static::__assert_equals('reader', Site_Lock_Directives::resolve(null, $kinds, false, self::WHERE), implode(',', $kinds) . ' defaults to reader');
        }
    }

    public static function test_page_routes_take_no_automatic_lock_by_default()
    {
        foreach ([['route'], ['spa'], ['portal_route'], ['js_action']] as $kinds) {
            static::__assert_null(Site_Lock_Directives::resolve(null, $kinds, false, self::WHERE), implode(',', $kinds) . ' takes no lock');
        }
    }

    public static function test_declared_modes_resolve()
    {
        static::__assert_equals('writer', Site_Lock_Directives::resolve(['Writer' => [[]]], ['ajax'], false, self::WHERE));
        static::__assert_equals('manual', Site_Lock_Directives::resolve(['Manual_Lock' => [[]]], ['api'], false, self::WHERE));
        static::__assert_equals('writer', Site_Lock_Directives::resolve(['Writer' => [[]]], ['route'], false, self::WHERE));
        static::__assert_equals('reader', Site_Lock_Directives::resolve(['Reader' => [[]]], ['spa'], false, self::WHERE));
        static::__assert_equals('writer', Site_Lock_Directives::resolve(['Some\\Ns\\Writer' => [[]]], ['companion'], false, self::WHERE), 'a namespaced spelling resolves');
    }

    public static function test_refusals()
    {
        $cases = [
            'two modes' => [['Writer' => [[]], 'Manual_Lock' => [[]]], ['ajax'], false, null, 'at most one'],
            'reader where it is the default' => [['Reader' => [[]]], ['ajax'], false, null, 'already a reader by default'],
            'writer on a model fetch' => [['Writer' => [[]]], ['model_fetch'], false, null, 'does not honour it'],
        ];

        foreach ($cases as $label => [$attributes, $kinds, $is_task, $method_name, $expected]) {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Site_Lock_Directives::resolve($attributes, $kinds, $is_task, self::WHERE, $method_name),
                $expected
            );
        }
    }

    public static function test_a_directive_off_a_surface_is_refused_with_the_manage_your_own_lock_remedy()
    {
        $places = [
            'a task' => [['Writer' => [[]]], [], true, 'run', 'on a #[Task] method'],
            'a pre_dispatch hook' => [['Writer' => [[]]], [], false, 'pre_dispatch', 'on a pre_dispatch() hook'],
            'an init hook' => [['Writer' => [[]]], [], false, 'init', 'on an init() hook'],
            'an unhandled_route hook' => [['Writer' => [[]]], [], false, 'unhandled_route', 'on an unhandled_route() hook'],
            'a plain method' => [['Writer' => [[]]], [], false, 'helper', 'on a function that is not an endpoint or route'],
            'manual lock on a task' => [['Manual_Lock' => [[]]], [], true, 'run', '#[Manual_Lock] is only allowed on an Ajax endpoint'],
        ];

        foreach ($places as $label => [$attributes, $kinds, $is_task, $method_name, $where]) {
            $e = static::__assert_throws(
                RuntimeException::class,
                fn () => Site_Lock_Directives::resolve($attributes, $kinds, $is_task, self::WHERE, $method_name),
                'SITE-LOCK-01'
            );

            static::__assert_contains($where, $e->getMessage(), "{$label}: names where it was found");
            static::__assert_contains('is only allowed on an Ajax endpoint, an API endpoint, an MVC route', $e->getMessage(), "{$label}: names where it belongs");
            static::__assert_contains('A task, pre_dispatch(), init() or other function that needs to write manages its lock itself', $e->getMessage(), "{$label}: names the remedy");
            static::__assert_contains('Site_Lock::write($site_id, fn () => ...)', $e->getMessage(), "{$label}: the write section");
            static::__assert_contains('Site_Lock::bypass($site_id, $reason, fn () => ...)', $e->getMessage(), "{$label}: and the bypass");
        }

        $e = static::__assert_throws(
            RuntimeException::class,
            fn () => Site_Lock_Directives::resolve(['Reader' => [[]]], [], false, self::WHERE, 'pre_dispatch'),
            '#[Reader] is only allowed on an MVC route'
        );
        static::__assert_contains('Site_Lock::read($site_id, fn () => ...)', $e->getMessage(), 'a misplaced #[Reader] names the read section');

        foreach (['a class', 'an instance method'] as $what) {
            $e = static::__assert_throws(
                RuntimeException::class,
                fn () => Site_Lock_Directives::assert_none(['Writer' => [[]]], $what, 'Fixture_Controller in fixture.php'),
                'SITE-LOCK-01'
            );
            static::__assert_contains("on {$what}", $e->getMessage());
            static::__assert_contains('manages its lock itself', $e->getMessage(), "{$what}: the same remedy");
        }
    }
}
