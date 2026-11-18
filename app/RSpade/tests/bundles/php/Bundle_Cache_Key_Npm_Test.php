<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Bundles\Php;

use ReflectionMethod;
use ReflectionProperty;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * AN npm DECLARATION IS PART OF A BUNDLE'S CACHE KEY (BUNDLE-NPM-01).
 *
 * An asset bundle's `npm` entries are not files, so nothing in a bundle's file list moves
 * when one is added, removed or changed - yet the vendor output embeds the compiled
 * packages and the app output embeds the `const X = window._rsx_npm.X` shims. The key folds
 * the declarations in explicitly (and, for vendor, the installed package versions), sorted by
 * global name so resolution order never churns the output filename.
 *
 * Nothing is compiled: the key is computed directly over a one-file input list with the
 * resolved npm declarations set on the compiler, as a compile would leave them.
 */
class Bundle_Cache_Key_Npm_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __key(string $type, array $npm): string
    {
        $compiler = new BundleCompiler();
        (new ReflectionProperty(BundleCompiler::class, 'bundle_files'))->setValue(
            $compiler,
            [$type => [base_path('app/RSpade/Core/Js/decorator.js')]]
        );
        (new ReflectionProperty(BundleCompiler::class, 'npm_includes'))->setValue($compiler, $npm);

        return (new ReflectionMethod(BundleCompiler::class, '_get_cache_key'))->invoke($compiler, $type);
    }

    public static function test_adding_removing_or_changing_an_npm_entry_moves_both_keys()
    {
        $one = ['probe_lib' => "import probe_lib from 'probe-lib'"];
        $two = $one + ['other_lib' => "import other_lib from 'other-lib'"];
        $changed = ['probe_lib' => "import { thing } from 'probe-lib'"];

        foreach (['vendor', 'app'] as $type) {
            static::__assert_equals(static::__key($type, $one), static::__key($type, $one), "the {$type} key is stable for one declaration set");

            static::__assert_true(static::__key($type, []) !== static::__key($type, $one), "{$type}: adding an npm entry moves the key");
            static::__assert_true(static::__key($type, $two) !== static::__key($type, $one), "{$type}: adding a second entry / removing it moves the key");
            static::__assert_true(static::__key($type, $changed) !== static::__key($type, $one), "{$type}: changing an entry's import moves the key");
        }
    }

    public static function test_declaration_order_does_not_move_the_key()
    {
        $forward = ['aa_lib' => "import aa from 'aa'", 'zz_lib' => "import zz from 'zz'"];
        $reversed = array_reverse($forward, true);

        foreach (['vendor', 'app'] as $type) {
            static::__assert_equals(
                static::__key($type, $forward),
                static::__key($type, $reversed),
                "{$type}: the order bundles were resolved in does not change the output filename"
            );
        }
    }
}
