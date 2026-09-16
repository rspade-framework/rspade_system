<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Commands\Restricted\Optimize_Cache_Command;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The build's views phase compiles exactly what a served request can render, located the
 * way the request will locate it.
 *
 * Two properties are pinned. The SET is the manifest's Blade views plus the templates under
 * config('view.paths'), and nothing else: the framework tree carries templates the manifest
 * does not index (the reference application under resource/), which extend layouts an
 * application need not declare, so compiling them fails the build. The PATH is the one the
 * view finder answers for the name, which is what a request asks: a compiled file is keyed
 * on the path spelling, and rsx:: resolves through the system/rsx symlink, so a compile at
 * the real path leaves the request compiling again into a read-only tree.
 *
 * Reads the running manifest and view finder; no DB.
 */
class View_Precompile_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_every_manifest_blade_view_is_in_the_set_and_resolves()
    {
        $names = Optimize_Cache_Command::view_names();
        $finder = app('view')->getFinder();

        static::__assert_true(count($names) > 0, 'the build has templates to compile');

        $resolved = [];
        foreach ($names as $name) {
            $resolved[$name] = $finder->find($name);
        }

        foreach (Manifest::$data['data']['blade_views'] as $id => $path) {
            $found = array_filter($resolved, fn ($abs) => realpath($abs) === realpath(base_path($path)));
            static::__assert_count(1, $found, "manifest view {$id} ({$path}) is compiled exactly once");
        }
    }

    public static function test_nothing_outside_the_application_is_compiled()
    {
        $finder = app('view')->getFinder();

        foreach (Optimize_Cache_Command::view_names() as $name) {
            $abs = $finder->find($name);
            static::__assert_false(
                str_contains($abs, '/resource/'),
                "a framework-ignored resource/ template is never compiled: {$name} -> {$abs}"
            );
        }
    }

    public static function test_the_framework_views_rendered_by_name_are_in_the_set()
    {
        $names = Optimize_Cache_Command::view_names();

        static::__assert_true(in_array('errors.rsx_error', $names, true), 'the error screen');
        static::__assert_true(in_array('mail.unsubscribe', $names, true), 'the unsubscribe page');
    }

    /**
     * An rsx:: name resolves through the application hint as registered (base_path('rsx')),
     * never through a real-path expansion: that spelling is the compiled file's identity.
     */
    public static function test_rsx_views_are_located_at_the_hint_spelling()
    {
        $finder = app('view')->getFinder();
        $hint = base_path('rsx');

        foreach (Optimize_Cache_Command::view_names() as $name) {
            if (!str_starts_with($name, 'rsx::')) {
                continue;
            }

            static::__assert_true(
                str_starts_with($finder->find($name), $hint . '/'),
                "{$name} is located under the rsx:: hint as registered"
            );
        }
    }
}
