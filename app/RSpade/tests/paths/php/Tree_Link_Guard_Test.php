<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Paths\Php;

use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The system/ build link, on THIS box.
 *
 * Laravel boots from system/, so a path written relative to the framework tree resolves
 * inside it, while the volatile trees live at the project root. system/build is the
 * symlink that makes both spellings one directory, and it is a TRACKED file - a checkout
 * that lost it does not fail at the write, it writes into a directory the framework pull
 * is about to clean.
 *
 * THERE IS NO system/storage AND NO system/tmp. Those two roots are relocatable, so a
 * link cannot promise where either one is; code reaches them through the path owner,
 * which reads the live value on every call.
 *
 * The guard's REFUSAL branches are not exercised here: they exit the process, and the
 * shapes they refuse (a real directory holding somebody's data) are not something a
 * test may fabricate against the live tree. The environment-update test drives the
 * repair side against a synthetic tree instead.
 *
 * Pure logic, no DB.
 */
class Tree_Link_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The build link is a symlink and resolves onto the build root.
     */
    public static function test_the_build_link_resolves_onto_its_root()
    {
        $link = base_path('build');

        static::__assert_true(is_link($link), 'system/build is a symlink');

        // A DANGLING system/build is a correct state on a box that has not built, so
        // the comparison is only made when the target exists.
        if (is_dir(Rsx_Project_Paths::build_root())) {
            static::__assert_equals(
                realpath(Rsx_Project_Paths::build_root()),
                realpath($link),
                'system/build resolves onto the build root'
            );
        }
    }

    /**
     * The retired links stay retired. A relocatable root with a link in a read-only
     * system/ is the shape the layout exists to prevent: an operator who moves the root
     * afterwards has no way to repair the link the runtime then refuses to boot without.
     */
    public static function test_the_relocatable_roots_have_no_link()
    {
        foreach (['storage', 'tmp'] as $name) {
            $path = base_path($name);

            static::__assert_false(is_link($path), "system/{$name} is not a symlink");
            static::__assert_false(file_exists($path), "system/{$name} does not exist at all");
        }
    }

    /**
     * Both spellings of a build path are one file. This is the property that lets a
     * base_path()-relative call site inside the framework tree keep working unchanged.
     */
    public static function test_both_spellings_reach_one_directory()
    {
        $probe = 'tree-link-probe-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $through_root = Rsx_Project_Paths::build_path($probe);

        try {
            file_put_contents($through_root, "probe\n");

            static::__assert_true(
                file_exists(base_path('build') . '/' . $probe),
                'a file written through the build root is visible through system/build'
            );
        } finally {
            @unlink($through_root);
        }
    }

    /**
     * Compiled Blade proves the build link is load-bearing rather than decorative:
     * the view compiler writes through it on every development render.
     */
    public static function test_the_compiled_view_directory_is_reachable_through_the_link()
    {
        $compiled = (string) config('view.compiled');

        static::__assert_equals(
            Rsx_Project_Paths::views_compiled_dir(),
            $compiled,
            'view.compiled is the owner-named directory'
        );

        static::__assert_true(
            str_starts_with($compiled, Rsx_Project_Paths::build_root() . '/'),
            'and it is inside the build root'
        );
    }
}
