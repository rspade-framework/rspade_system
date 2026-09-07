<?php

namespace App\RSpade\Sys\App\Apidocs\Components;

use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;

/**
 * _Apidocs_Vendor_Bundle - CDN assets for the API documentation / tester page.
 *
 * Auto-discovered when a Module Bundle scans this module's directory (_Apidocs_Bundle
 * includes its own __DIR__, which is this file's parent). Kept OUT of every other module bundle's include paths - the
 * control panel's _Sys_Bundle included - so these assets load on the console and nowhere
 * else.
 *
 * Assets:
 *   - highlight.js (core+common languages, incl. json/bash/javascript/python) + github-dark
 *     theme: read-only syntax highlighting for request previews, responses, and examples.
 *
 * The monospace face is the framework application's --rsx-mono (Sys/theme/theme.scss), a
 * system ui-monospace stack - a webfont CDN is a page-blocking external dependency and adds
 * nothing a system mono lacks.
 *
 * In development these load from their external URLs; in sealed builds they are cached and
 * served locally via /_vendor/. The midnight theme itself is the framework application's
 * (Sys/theme); the console's icons are inline SVG (no icon-font CDN).
 */
class _Apidocs_Vendor_Bundle extends Rsx_Asset_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [],
            'cdn_assets' => [
                'css' => [
                    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/styles/github-dark.min.css'],
                ],
                'js' => [
                    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/highlight.min.js'],
                    // PowerShell is not in the common bundle; load the language module so the
                    // PowerShell code sample highlights like the rest.
                    ['url' => 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0/languages/powershell.min.js'],
                ],
            ],
        ];
    }
}
