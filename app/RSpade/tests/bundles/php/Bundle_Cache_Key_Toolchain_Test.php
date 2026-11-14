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
use App\RSpade\Core\JsParsers\Js_Transformer;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A COMPILED BUNDLE'S CACHE KEY MOVES WITH THE JS TRANSFORM TOOLCHAIN.
 *
 * Both bundle types babel-transform their JavaScript, so a changed babel-service.js,
 * decorator fork or @babel/core changes what a compile produces even when no source changed.
 * The key folds in the transform cache's own fingerprint
 * (Js_Transformer::toolchain_fingerprint()); without it, the transform cache refreshed while
 * every compiled bundle went on serving the old transform's output.
 *
 * Nothing is compiled here: the key is computed directly over a one-file input list, with the
 * process's memoized fingerprint pinned to a chosen value and restored afterwards.
 */
class Bundle_Cache_Key_Toolchain_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The cache key of $type over a one-file input list, with the toolchain fingerprint
     * pinned to $fingerprint.
     */
    private static function __key(string $type, string $fingerprint): string
    {
        $memo = new ReflectionProperty(Js_Transformer::class, 'toolchain_fingerprint');
        $saved = $memo->getValue();
        $memo->setValue(null, $fingerprint);

        try {
            $compiler = new BundleCompiler();
            (new ReflectionProperty(BundleCompiler::class, 'bundle_files'))->setValue(
                $compiler,
                [$type => [base_path('app/RSpade/Core/Js/decorator.js')]]
            );

            return (new ReflectionMethod(BundleCompiler::class, '_get_cache_key'))->invoke($compiler, $type);
        } finally {
            $memo->setValue(null, $saved);
        }
    }

    public static function test_both_bundle_types_rekey_when_the_toolchain_fingerprint_changes()
    {
        foreach (['vendor', 'app'] as $type) {
            static::__assert_equals(
                static::__key($type, 'aaaaaaaaaaaa'),
                static::__key($type, 'aaaaaaaaaaaa'),
                "the {$type} key is stable for one toolchain"
            );
            static::__assert_true(
                static::__key($type, 'aaaaaaaaaaaa') !== static::__key($type, 'bbbbbbbbbbbb'),
                "the {$type} key did not move with the toolchain fingerprint - a changed transform would keep serving the old bundle"
            );
        }
    }

    public static function test_the_toolchain_is_not_keyed_when_nothing_is_transformed()
    {
        $saved = config('rsx.javascript.babel.transform_enabled');
        config(['rsx.javascript.babel.transform_enabled' => false]);

        try {
            static::__assert_equals(
                static::__key('app', 'aaaaaaaaaaaa'),
                static::__key('app', 'bbbbbbbbbbbb'),
                'with the transform off the toolchain cannot change the output, so it does not move the key'
            );
        } finally {
            config(['rsx.javascript.babel.transform_enabled' => $saved]);
        }
    }
}
