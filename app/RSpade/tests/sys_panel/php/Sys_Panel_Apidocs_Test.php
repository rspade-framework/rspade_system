<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use App\RSpade\Core\Api\Rsx_Api_Docs;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Apidocs\_Apidocs_Bundle;

/**
 * The API reference console as a module of the framework's own application.
 *
 * The console is mounted by an APPLICATION route and rendered by one framework
 * call, so nothing downstream would notice it moving trees - which is exactly
 * why the move needs stating in the suite. These rows pin where it lives, that
 * the manifest still resolves the two names Rsx_Api_Docs::page() depends on, and
 * that Core/Api is engine-only now, so a stray view or stylesheet cannot drift
 * back into it unannounced.
 */
class Sys_Panel_Apidocs_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * RP-APIDOCS-01 - The console's bundle names no rsx/ path.
     *
     * Same invariant as the panel's own bundles (CONV-BUNDLE-04): the console is
     * framework code mounted on an application route, and the application it
     * borrowed a stylesheet from is free to delete it.
     */
    public static function test_console_bundle_names_no_application_path()
    {
        $definition = _Apidocs_Bundle::define();

        $named = array_merge(
            $definition['include'] ?? [],
            $definition['watch'] ?? []
        );

        foreach ($named as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $normalized = str_replace('\\', '/', $entry);

            static::__assert_false(
                str_starts_with($normalized, 'rsx/') || str_contains($normalized, '/rsx/'),
                "_Apidocs_Bundle names the application path '{$entry}'"
            );
        }
    }

    /**
     * RP-APIDOCS-02 - The console draws on the framework application's theme
     * rather than carrying a palette of its own.
     */
    public static function test_console_bundle_includes_the_framework_application_theme()
    {
        $include = _Apidocs_Bundle::define()['include'];

        static::__assert_true(
            in_array('_Sys_Theme_Bundle', $include, true),
            'the console does not include the framework application theme bundle'
        );

        static::__assert_true(
            in_array('app/RSpade/Sys/theme', $include, true),
            'the console does not include the framework application theme directory'
        );
    }

    /**
     * RP-APIDOCS-03 - The manifest holds the console's blade id and its root
     * component, at their addresses in the framework application tree.
     *
     * These are the two names Rsx_Api_Docs::page() depends on: it returns
     * rsx_view('_Apidocs_App'), and that view's body is <_Apidocs_Console />.
     */
    public static function test_manifest_holds_the_console_view_and_component()
    {
        $view_path = Manifest::find_view_by_rsx_id('_Apidocs_App');

        static::__assert_equals(
            'app/RSpade/Sys/app/apidocs/_Apidocs_App.blade.php',
            $view_path,
            '_Apidocs_App does not resolve to the console module'
        );

        $manifest = Manifest::get_full_manifest();
        $components = $manifest['data']['jqhtml']['components'] ?? [];

        static::__assert_array_has_key(
            '_Apidocs_Console',
            $components,
            'the console root component is not a registered jqhtml component'
        );

        static::__assert_equals(
            'app/RSpade/Sys/app/apidocs/components/_Apidocs_Console.jqhtml',
            $components['_Apidocs_Console']['file'],
            '_Apidocs_Console is not in the console module'
        );
    }

    /**
     * RP-APIDOCS-04 - Core/Api is the API ENGINE and holds no view layer.
     *
     * The console left it whole. A .jqhtml, .blade.php or .scss appearing there
     * again means a piece of the console grew back on the engine's side of the
     * line, where no bundle includes it and no CLAUDE.md describes it.
     */
    public static function test_core_api_holds_no_view_layer()
    {
        $engine_dir = base_path('app/RSpade/Core/Api');

        static::__assert_true(is_dir($engine_dir), "{$engine_dir} is missing");

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($engine_dir, \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];

        foreach ($iterator as $file) {
            $name = $file->getFilename();

            if (str_ends_with($name, '.jqhtml') || str_ends_with($name, '.blade.php') || str_ends_with($name, '.scss')) {
                $offenders[] = $name;
            }
        }

        static::__assert_equals(
            [],
            $offenders,
            'Core/Api holds view-layer files: ' . implode(', ', $offenders)
        );
    }

    /**
     * RP-APIDOCS-05 - An application's sections reach the page rendered and sanitized.
     *
     * page()'s $sections are exported in the order given as {slug, title, html}: the slug
     * from the title, the html from Markdown_Rendition - a GFM table is a table, and raw
     * HTML in the Markdown is discarded rather than passed through to the console.
     */
    public static function test_sections_are_exported_rendered_and_sanitized()
    {
        Rsx_Api_Docs::page(restrict_to_key: false, sections: [
            [
                'title' => 'Error Codes',
                'markdown' => "| Code | Meaning |\n|---|---|\n| `not_found` | No such record. |\n\n<script>alert(1)</script><img src=x onerror=alert(2)>",
            ],
            ['title' => 'Conventions', 'markdown' => 'GET reads, **POST** writes.'],
        ]);

        $sections = Rsx_Api_Docs::rsxapp_data()['api_catalog']['sections'];

        static::__assert_equals(['error-codes', 'conventions'], array_column($sections, 'slug'), 'slugs or order');
        static::__assert_equals(['Error Codes', 'Conventions'], array_column($sections, 'title'), 'titles');
        static::__assert_equals(['slug', 'title', 'html'], array_keys($sections[0]), 'a section exports exactly slug, title, html');

        static::__assert_contains('<table>', $sections[0]['html'], 'the GFM table did not render as a table');
        static::__assert_contains('<code>not_found</code>', $sections[0]['html'], 'inline code did not render');
        static::__assert_false(str_contains($sections[0]['html'], '<script'), 'raw <script> passed through');
        static::__assert_false(str_contains($sections[0]['html'], 'onerror'), 'an event handler attribute passed through');
        static::__assert_contains('<strong>POST</strong>', $sections[1]['html'], 'emphasis did not render');

        Rsx_Api_Docs::page(restrict_to_key: false);

        static::__assert_equals([], Rsx_Api_Docs::rsxapp_data()['api_catalog']['sections'], 'no sections must export an empty list');
    }

    /**
     * RP-APIDOCS-06 - A malformed section throws at the application's page() call.
     *
     * Unknown keys (a 'content' typo would publish an empty page), a blank title or
     * Markdown, an associative $sections, a title with nothing to slug, and two titles
     * that slug alike (one would be unreachable) are each refused.
     */
    public static function test_malformed_sections_are_refused()
    {
        $cases = [
            'unknown key' => [[['title' => 'Errors', 'content' => 'x']], "unknown key(s) 'content'"],
            'blank title' => [[['title' => '  ', 'markdown' => 'x']], "non-empty string 'title'"],
            'missing markdown' => [[['title' => 'Errors']], "non-empty string 'markdown'"],
            'not a string' => [[['title' => 'Errors', 'markdown' => ['x']]], "non-empty string 'markdown'"],
            'not a list' => [['errors' => ['title' => 'Errors', 'markdown' => 'x']], 'must be a list'],
            'not an array' => [['Errors'], 'must be an array'],
            'no slug' => [[['title' => '---', 'markdown' => 'x']], 'no letter or digit'],
            'duplicate slug' => [[
                ['title' => 'Error codes', 'markdown' => 'x'],
                ['title' => 'Error  Codes!', 'markdown' => 'y'],
            ], "share the URL slug 'error-codes'"],
        ];

        foreach ($cases as $label => [$sections, $needle]) {
            static::__assert_throws(
                \InvalidArgumentException::class,
                fn () => Rsx_Api_Docs::page(restrict_to_key: false, sections: $sections),
                $needle
            );
        }

        Rsx_Api_Docs::page(restrict_to_key: false);
    }
}
