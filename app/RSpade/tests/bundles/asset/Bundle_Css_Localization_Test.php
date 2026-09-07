<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Bundles\Asset;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * A compiled bundle stylesheet reaches no external host.
 *
 * An application's own SCSS is allowed to name a remote stylesheet - `@import
 * url("https://fonts.googleapis.com/...")` is the canonical case - and a vendored
 * stylesheet may name a font on a CDN. Either would have the browser fetch from a host the
 * page's CSP never whitelisted, so the compiler runs every bundle stylesheet through the
 * mirror: remote `@import`s are spliced in and absolute `url()` references are rewritten to
 * /_vendor/ names backed by real files.
 *
 * The fixture uses `file://` URLs, which the localizer treats exactly like http ones while
 * reading from disk - so the suite proves the whole path with no network at all. The mirror
 * store is redirected at a scratch directory; the real rsx/resource/.cdn-cache is never
 * touched.
 *
 * The fixture is synthetic and lives under this concern's framework-ignored `resource/`
 * directory, so the manifest never indexes it. See Bundle_Watch_Invalidation_Test for the
 * same arrangement.
 */
class Bundle_Css_Localization_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    protected const FIXTURE_RELATIVE = 'app/RSpade/tests/bundles/asset/resource/cdn_fixture-temp';

    protected const FIXTURE_NAMESPACE = 'Rsx_Cdn_Fixture_Temp';

    /**
     * The remote assets the fixture names, as file:// URLs.
     */
    protected static function __remote_url(string $relative): string
    {
        return 'file://' . base_path(static::FIXTURE_RELATIVE . '/remote/' . $relative);
    }

    protected static function __fixture_path(string $relative): string
    {
        return base_path(static::FIXTURE_RELATIVE . '/' . $relative);
    }

    protected static function __scratch_store(): string
    {
        return storage_path('rsx-tmp/bundle_css_localization_test-temp');
    }

    /**
     * Write the fixture: the "remote" assets, the app SCSS that names them, and the bundle.
     */
    protected static function __write_fixture(): void
    {
        // The "remote" side. glyph-temp.woff2 holds bytes, not a font - nothing parses it.
        $files = [
            'remote/glyph-temp.woff2' => "woff2-fixture-bytes\n",
            'remote/pixel-temp.png' => "png-fixture-bytes\n",
            'remote/imported-temp.css' => ".rsx_cdn_fixture_imported {\n"
                . '    src: url("' . static::__remote_url('glyph-temp.woff2') . "\") format(\"woff2\");\n"
                . "}\n",

            // The application's own stylesheet. NOT under a '/vendor/' path, so it lands in
            // the app bucket - and `@import url(...)` is legal there (the non-vendor @import
            // ban covers `@import "file"`, the SCSS partial form).
            'app_entry-temp.scss' => '@import url("' . static::__remote_url('imported-temp.css') . "\");\n\n"
                . ".rsx_cdn_fixture {\n"
                . '    background-image: url("' . static::__remote_url('pixel-temp.png') . "\");\n"
                . "}\n",
        ];

        foreach ($files as $relative => $contents) {
            $path = static::__fixture_path($relative);
            ensure_directory(dirname($path));
            file_put_contents($path, $contents);
        }

        static::__write_fixture_bundle();
    }

    protected static function __write_fixture_bundle(): void
    {
        $base = static::FIXTURE_RELATIVE;
        $namespace = static::FIXTURE_NAMESPACE;

        $php = <<<PHP
        <?php

        namespace {$namespace};

        use App\\RSpade\\Core\\Bundle\\Rsx_Module_Bundle_Abstract;

        class Cdn_Fixture_Bundle extends Rsx_Module_Bundle_Abstract
        {
            public static function define(): array
            {
                return [
                    'include' => [
                        '{$base}/app_entry-temp.scss',
                    ],
                ];
            }
        }
        PHP;

        $path = static::__fixture_path('bundle-temp.php');
        ensure_directory(dirname($path));
        file_put_contents($path, $php . "\n");

        // Idempotent per resolved path: the definition is re-written byte-identically by
        // every test, so the first require_once loads it and the rest are no-ops.
        require_once $path;
    }

    protected static function __compile_fixture(): ?string
    {
        $compiler = new BundleCompiler();
        $result = $compiler->compile('\\' . static::FIXTURE_NAMESPACE . '\\Cdn_Fixture_Bundle', []);

        return $result['app_css_bundle_path'] ?? null;
    }

    protected static function __artifact_contents(string $filename): string
    {
        return file_get_contents(storage_path('rsx-build/bundles/' . $filename));
    }

    protected static function __remove_tree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($root);
    }

    protected static function __remove_fixture(): void
    {
        static::__remove_tree(base_path(static::FIXTURE_RELATIVE));
        static::__remove_tree(static::__scratch_store());

        foreach (glob(storage_path('rsx-build/bundles/Cdn_Fixture_*')) as $artifact) {
            if (is_file($artifact)) {
                unlink($artifact);
            }
        }
    }

    public static function setup()
    {
        parent::setup();

        static::__remove_fixture();
        static::__write_fixture();

        ensure_directory(static::__scratch_store());
        Cdn_Cache::$_testing_cache_dir = static::__scratch_store();
    }

    public static function teardown()
    {
        Cdn_Cache::$_testing_cache_dir = null;

        static::__remove_fixture();

        parent::teardown();
    }

    /**
     * BND-CDN-01 + BND-CDN-02.
     */
    public static function test_a_compiled_bundle_stylesheet_names_only_local_mirror_files()
    {
        $artifact = static::__compile_fixture();

        static::__assert_not_null($artifact, 'the fixture produces an app CSS artifact');

        $css = static::__artifact_contents($artifact);

        $png_name = Cdn_Cache::filename_for(static::__remote_url('pixel-temp.png'), 'png');
        $woff_name = Cdn_Cache::filename_for(static::__remote_url('glyph-temp.woff2'), 'woff2');

        // (a) the bundle's own absolute url() is rewritten
        static::__assert_contains(
            '/_vendor/' . $png_name,
            $css,
            'an absolute url() in application SCSS is rewritten to its mirror'
        );

        // (b) the remote @import is spliced in, and ITS url() is rewritten against its own base
        static::__assert_contains(
            '.rsx_cdn_fixture_imported',
            $css,
            'the remote @import is spliced into the bundle, not left as an at-rule'
        );

        static::__assert_contains(
            '/_vendor/' . $woff_name,
            $css,
            'a url() inside the imported stylesheet is rewritten too'
        );

        static::__assert_contains(
            'format("woff2")',
            $css,
            'the rest of the declaration value survives the rewrite'
        );

        // (c) nothing external survives anywhere in the compiled stylesheet
        static::__assert_false(
            str_contains($css, 'file://'),
            'no absolute reference survives in the compiled bundle stylesheet'
        );

        // (d) the names are backed by real files, byte-identical to the source
        foreach ([$png_name => 'remote/pixel-temp.png', $woff_name => 'remote/glyph-temp.woff2'] as $name => $source) {
            $mirrored = static::__scratch_store() . '/' . $name;

            static::__assert_true(file_exists($mirrored), "the mirror holds {$name}");

            static::__assert_equals(
                file_get_contents(static::__fixture_path($source)),
                file_get_contents($mirrored),
                'mirrored bytes are verbatim'
            );
        }
    }
}
