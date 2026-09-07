<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Externals\Asset;

use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The CSS localizer: Core/Bundle/resource/localize-css-externals.js.
 *
 * A stylesheet that reaches an external host at render time is a CSP violation waiting to
 * happen - the page's policy whitelists what the PAGE declares, and it cannot know about a
 * font file some third-party stylesheet asks for. The localizer closes that hole: every
 * remote `@import` is fetched and spliced in place, and every remote `url()` is mirrored to
 * a file in the vendor store and rewritten to `/_vendor/<name>`.
 *
 * Everything here runs against `file://` fixtures in a scratch directory - the localizer
 * reads a file:// URL from disk precisely so the suite never touches the network. The real
 * rsx/resource/.cdn-cache is never touched.
 *
 * The naming rule is implemented TWICE - once in PHP (Cdn_Cache::filename_for) and once in
 * node (the localizer names the files it writes). EXT-49 pins the two together; if they
 * ever drift, a stylesheet points at a /_vendor/ name that PHP will never serve.
 */
class Css_Localizer_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const USER_AGENT = 'RSpade-Test/1.0';

    private const FONT_BYTES = "wOF2-not-really-a-font\x00\x01\x02";

    private const PIXEL_BYTES = "\x89PNG\r\n\x1a\nfixture";

    public static function setup()
    {
        static::__reset_scratch();
    }

    public static function teardown()
    {
        Cdn_Cache::$_testing_cache_dir = null;
        Cdn_Cache::$_testing_fetcher = null;
        rmdir_recursive(static::__root());
    }

    // -------------------------------------------------------------------------
    // Scratch fixture
    // -------------------------------------------------------------------------

    private static function __root(): string
    {
        return storage_path('rsx-tmp/css_localizer_test-temp');
    }

    private static function __src(string $name = ''): string
    {
        return static::__root() . '/src' . ($name === '' ? '' : '/' . $name);
    }

    private static function __cache(): string
    {
        return static::__root() . '/cache';
    }

    private static function __work(string $name): string
    {
        return static::__root() . '/work/' . $name;
    }

    private static function __file_url(string $absolute_path): string
    {
        return 'file://' . $absolute_path;
    }

    /**
     * A pristine scratch tree: source fixtures, an EMPTY cache, an empty work directory.
     */
    private static function __reset_scratch(): void
    {
        rmdir_recursive(static::__root());
        ensure_directory(static::__src());
        ensure_directory(static::__cache());
        ensure_directory(static::__root() . '/work');

        file_put_contents(static::__src('font.woff2'), self::FONT_BYTES);
        file_put_contents(static::__src('pixel.png'), self::PIXEL_BYTES);
        file_put_contents(
            static::__src('icon.svg'),
            "<svg xmlns=\"http://www.w3.org/2000/svg\"><linearGradient id=\"gradient\"/></svg>\n"
        );

        file_put_contents(
            static::__src('nested.css'),
            "@font-face {\n"
            . "    font-family: Nested;\n"
            . "    src: url(font.woff2) format(\"woff2\");\n"
            . "}\n"
        );

        file_put_contents(
            static::__src('root.css'),
            '@import url("' . static::__file_url(static::__src('nested.css')) . "\") screen and (min-width: 100px);\n"
            . ".root {\n"
            . '    background-image: url("' . static::__file_url(static::__src('pixel.png')) . "\");\n"
            . "}\n"
        );

        Cdn_Cache::$_testing_cache_dir = static::__cache();
        Cdn_Cache::$_testing_fetcher = null;
    }

    // -------------------------------------------------------------------------
    // Running the localizer
    // -------------------------------------------------------------------------

    private static function __script(): string
    {
        return base_path('app/RSpade/Core/Bundle/resource/localize-css-externals.js');
    }

    /**
     * Run the localizer over $css and return ['exit', 'json', 'output', 'css'].
     */
    private static function __localize(string $css, string $base_url, bool $no_download = false): array
    {
        $in = static::__work('in-' . random_hash(8) . '.css');
        $out = static::__work('out-' . random_hash(8) . '.css');
        file_put_contents($in, $css);

        $arguments = ['--cache-dir', static::__cache(), '--user-agent', self::USER_AGENT];

        if ($no_download) {
            $arguments[] = '--no-download';
        }

        array_push($arguments, '--base-url', $base_url, '--in', $in, '--out', $out);

        $command = 'node ' . escapeshellarg(static::__script());
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        // Explicit bash (never the implicit /bin/sh), with the exit code as the last line.
        $raw = shell_exec('bash -c ' . escapeshellarg("({$command} 2>&1); echo \$?"));

        $lines = explode("\n", trim((string) $raw));
        $exit_code = (int) array_pop($lines);
        $output = trim(implode("\n", $lines));

        $json_line = '';
        foreach (array_reverse($lines) as $line) {
            if (trim($line) !== '') {
                $json_line = trim($line);
                break;
            }
        }

        return [
            'exit' => $exit_code,
            'json' => json_decode($json_line, true) ?? [],
            'output' => $output,
            'css' => file_exists($out) ? file_get_contents($out) : '',
        ];
    }

    /**
     * `node localize-css-externals.js --name URL EXT`
     */
    private static function __name(string $url, string $ext): string
    {
        $command = 'node ' . escapeshellarg(static::__script())
            . ' --name ' . escapeshellarg($url) . ' ' . escapeshellarg($ext);

        return trim((string) shell_exec('bash -c ' . escapeshellarg($command . ' 2>&1')));
    }

    // -------------------------------------------------------------------------
    // EXT-45 - @import splice
    // -------------------------------------------------------------------------

    public static function test_a_remote_import_is_fetched_localized_and_spliced_under_its_media_prelude()
    {
        static::__reset_scratch();

        $root_url = static::__file_url(static::__src('root.css'));
        $result = static::__localize(file_get_contents(static::__src('root.css')), $root_url);

        static::__assert_equals(0, $result['exit'], 'the run succeeded: ' . $result['output']);
        static::__assert_equals([], $result['json']['failures']);

        static::__assert_false(
            str_contains($result['css'], '@import'),
            'no @import survives - an @import is a request to an external host'
        );

        static::__assert_contains('@media screen and (min-width: 100px)', $result['css']);
        static::__assert_contains('@font-face', $result['css']);

        // The nested stylesheet's RELATIVE url() resolved against NESTED's directory.
        $font_name = Cdn_Cache::filename_for(static::__file_url(static::__src('font.woff2')), 'woff2');

        static::__assert_contains('/_vendor/' . $font_name, $result['css']);
        static::__assert_contains('format("woff2")', $result['css'], 'the format() hint is untouched');

        static::__assert_equals(
            self::FONT_BYTES,
            file_get_contents(static::__cache() . '/' . $font_name),
            'the mirrored font is byte-identical to the source'
        );

        // The @media wrapper contains the spliced body, not the other way round.
        $media_at = strpos($result['css'], '@media');
        static::__assert_true(
            $media_at !== false && $media_at < strpos($result['css'], '@font-face'),
            'the spliced rules sit INSIDE the media at-rule'
        );
    }

    // -------------------------------------------------------------------------
    // EXT-46 - what is left alone
    // -------------------------------------------------------------------------

    public static function test_data_fragment_root_relative_and_relative_references_are_untouched()
    {
        static::__reset_scratch();

        $css = ".a {\n"
            . "    background: url(data:image/gif;base64,R0lGOD);\n"
            . "    mask: url(#clip);\n"
            . "    border-image: url(/img/border.png);\n"
            . "    cursor: url(cursors/hand.cur), pointer;\n"
            . "}\n";

        // A locally concatenated bundle has no absolute base, which is exactly when a
        // relative url() means "somewhere in the application", not "somewhere remote".
        $result = static::__localize($css, '/bundle.css');

        static::__assert_equals(0, $result['exit'], $result['output']);
        static::__assert_equals([], $result['json']['failures']);
        static::__assert_equals($css, $result['css'], 'byte-identical - nothing here is remote');
        static::__assert_equals([], $result['json']['written']);
    }

    // -------------------------------------------------------------------------
    // EXT-47 - a warm store is network-free
    // -------------------------------------------------------------------------

    public static function test_a_second_run_hits_the_store_and_writes_nothing()
    {
        static::__reset_scratch();

        $root_url = static::__file_url(static::__src('root.css'));
        $css = file_get_contents(static::__src('root.css'));

        $first = static::__localize($css, $root_url);
        static::__assert_equals(0, $first['exit'], $first['output']);
        static::__assert_true(count($first['json']['written']) > 0, 'the cold run wrote the mirror');

        $second = static::__localize($css, $root_url);

        static::__assert_equals(0, $second['exit'], $second['output']);
        static::__assert_equals([], $second['json']['written'], 'a warm store writes nothing');

        $expected_hits = [
            Cdn_Cache::filename_for(static::__file_url(static::__src('nested.css')), 'css'),
            Cdn_Cache::filename_for(static::__file_url(static::__src('pixel.png')), 'png'),
        ];

        foreach ($expected_hits as $name) {
            static::__assert_true(
                in_array($name, $second['json']['hits'], true),
                "{$name} is reported as a store hit"
            );
        }

        static::__assert_equals($first['css'], $second['css'], 'the same input localizes the same way');
    }

    // -------------------------------------------------------------------------
    // EXT-48 - --no-download against a cold store
    // -------------------------------------------------------------------------

    public static function test_no_download_against_a_cold_store_fails_loud_naming_the_remedy()
    {
        static::__reset_scratch();

        $missing = static::__file_url(static::__src('pixel.png'));
        $css = ".a { background: url(\"{$missing}\"); }\n";

        $result = static::__localize($css, '/bundle.css', true);

        static::__assert_equals(1, $result['exit'], 'a missing mirror is a failure, never a silent request');
        static::__assert_count(1, $result['json']['failures']);
        static::__assert_equals($missing, $result['json']['failures'][0]['url']);
        static::__assert_contains('rsx:cdn_externals:refresh', $result['json']['failures'][0]['error']);
        static::__assert_equals([], glob(static::__cache() . '/*'), 'nothing was downloaded');
    }

    // -------------------------------------------------------------------------
    // EXT-49 - the two implementations of the naming rule agree
    // -------------------------------------------------------------------------

    public static function test_the_node_naming_rule_matches_cdn_cache_exactly()
    {
        $cases = [
            ['https://fonts.googleapis.com/css2?family=Roboto', 'css'],
            ['https://fonts.gstatic.com/s/x/v1/KFOmCnqEu92Fr1Mu4mxK.woff2', 'css'],
            ['https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.default.min.css', 'css'],
            ['https://cdn.jsdelivr.net/npm/bootstrap@5/dist/js/bootstrap.bundle.min.js', 'js'],
            ['https://x.example/?v=1', 'css'],
            ['https://x.example/a%20b%2Fc.css', 'css'],
            ['https://x.example/' . str_repeat('n', 60) . '.js', 'js'],
            ['https://x.example/Icon.SVG', 'css'],
        ];

        foreach ($cases as [$url, $type]) {
            static::__assert_equals(
                Cdn_Cache::filename_for($url, $type),
                static::__name($url, $type),
                "PHP and node name the same file for {$url}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // EXT-50 - Cdn_Cache::ensure() runs the localizer for a css asset
    // -------------------------------------------------------------------------

    public static function test_a_mirrored_stylesheet_reaches_no_external_host()
    {
        static::__reset_scratch();

        $font_url = static::__file_url(static::__src('font.woff2'));
        $pixel_url = static::__file_url(static::__src('pixel.png'));

        Cdn_Cache::$_testing_fetcher = fn ($url) => "@font-face { src: url(\"{$font_url}\"); }\n"
            . ".p { background: url(\"{$pixel_url}\"); }\n";

        $css_url = 'https://cdn.example.com/lib/widget.css';
        $filename = Cdn_Cache::ensure($css_url, 'css');

        $stored = file_get_contents(static::__cache() . '/' . $filename);

        static::__assert_false(str_contains($stored, 'file://'), 'no remote reference survives');
        static::__assert_contains('/_vendor/' . Cdn_Cache::filename_for($font_url, 'woff2'), $stored);
        static::__assert_contains('/_vendor/' . Cdn_Cache::filename_for($pixel_url, 'png'), $stored);

        static::__assert_equals(
            self::FONT_BYTES,
            file_get_contents(static::__cache() . '/' . Cdn_Cache::filename_for($font_url, 'woff2'))
        );
        static::__assert_equals(
            self::PIXEL_BYTES,
            file_get_contents(static::__cache() . '/' . Cdn_Cache::filename_for($pixel_url, 'png'))
        );
    }

    // -------------------------------------------------------------------------
    // EXT-51 - an SVG fragment id survives the rewrite
    // -------------------------------------------------------------------------

    public static function test_a_fragment_id_survives_on_the_rewritten_url()
    {
        static::__reset_scratch();

        $icon_url = static::__file_url(static::__src('icon.svg'));
        $css = ".a { fill: url(\"{$icon_url}#gradient\"); }\n";

        $result = static::__localize($css, '/bundle.css');

        static::__assert_equals(0, $result['exit'], $result['output']);

        $name = Cdn_Cache::filename_for($icon_url, 'svg');

        static::__assert_contains(
            '/_vendor/' . $name . '#gradient',
            $result['css'],
            'an SVG fragment id is load-bearing and must survive'
        );
    }
}
