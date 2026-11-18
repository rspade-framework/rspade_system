<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * BUNDLE-CDN-01 - every `cdn_assets` entry a bundle declares is an absolute https:// URL.
 *
 * A cdn_asset is downloaded into the committed mirror (rsx/resource/.cdn-cache/) and served
 * to every page of the bundle from our own origin. The download is the one moment the bytes
 * are trusted: fetched over plain http, anything on the path can substitute them, and the
 * substitute is then committed and served to every visitor as first-party code. A
 * protocol-relative (`//host/...`) or schemeless URL leaves the scheme to whatever fetched
 * it. https is therefore the only scheme a declaration may name - the same requirement the
 * external-resources registry already enforces on *.externals.php (rsx:man
 * external_resources). An entry with no `url` declares nothing and is silently dropped by
 * the compiler, so it is refused too.
 *
 * Judged per file on concrete classes in the Rsx_Bundle_Abstract lineage, by calling the
 * bundle's define() (the declaration surface - CONV-BUNDLE-02 and CONV-BUNDLE-04 read it the
 * same way). Fatal by design, so not suppressible.
 *
 * See: php artisan rsx:man bundle_api (CDN ASSETS)
 */
class BundleCdnHttps_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'BUNDLE-CDN-01';
    }

    public function get_name(): string
    {
        return 'Bundle CDN Assets Are https URLs';
    }

    public function get_description(): string
    {
        return "Every cdn_assets entry a bundle declares has a 'url' that is an absolute https:// URL";
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Manifest-time by framework-maintainer decision: a non-https declaration is a supply
     * path for code served to every page, refused before any compile can download it.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $class_name = $metadata['class'] ?? null;
        $fqcn = $metadata['fqcn'] ?? null;

        if (!is_string($class_name) || !is_string($fqcn) || !empty($metadata['abstract'])) {
            return;
        }

        if (!str_contains($contents, 'cdn_assets')) {
            return;
        }

        if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Bundle_Abstract')) {
            return;
        }

        if (!class_exists($fqcn) || !method_exists($fqcn, 'define')) {
            return;
        }

        $this->evaluate_definition($file_path, $contents, $class_name, $fqcn::define());
    }

    /**
     * Judge one bundle definition's cdn_assets. Public so a test drives it over a synthetic
     * definition without declaring a bundle class.
     */
    public function evaluate_definition(string $file_path, string $contents, string $class_name, array $definition): void
    {
        $cdn_assets = $definition['cdn_assets'] ?? [];

        if (!is_array($cdn_assets)) {
            $this->__report($file_path, $contents, $class_name, "'cdn_assets' is " . get_debug_type($cdn_assets) . '; it is a map of \'js\' / \'css\' => list of [\'url\' => ...].');

            return;
        }

        foreach ($cdn_assets as $type => $entries) {
            if (!is_array($entries)) {
                $this->__report($file_path, $contents, $class_name, "cdn_assets['{$type}'] is " . get_debug_type($entries) . '; it is a list of [\'url\' => ...].');

                continue;
            }

            foreach ($entries as $index => $entry) {
                $url = is_array($entry) ? ($entry['url'] ?? null) : null;

                if (!is_string($url) || $url === '') {
                    $this->__report($file_path, $contents, $class_name,
                        "cdn_assets['{$type}'][{$index}] has no 'url': each entry is ['url' => 'https://...'] (plus an optional 'integrity').");

                    continue;
                }

                if (!str_starts_with($url, 'https://')) {
                    $this->__report($file_path, $contents, $class_name,
                        "cdn_assets['{$type}'][{$index}] url '{$url}' is not an https:// URL. The asset is downloaded once and served "
                        . 'to every page of the bundle as first-party code, so it is fetched over https only.', $url);
                }
            }
        }
    }

    private function __report(string $file_path, string $contents, string $class_name, string $message, ?string $needle = null): void
    {
        $line = 1;
        $lines = explode("\n", $contents);

        foreach ($lines as $index => $text) {
            if (str_contains($text, $needle ?? 'cdn_assets')) {
                $line = $index + 1;
                break;
            }
        }

        $this->add_violation(
            $file_path,
            $line,
            "{$class_name}: {$message}",
            trim($lines[$line - 1] ?? ''),
            "Declare every CDN asset as an absolute https:// URL:\n"
            . "    'cdn_assets' => [\n"
            . "        'js' => [['url' => 'https://cdn.example.com/lib.min.js', 'integrity' => 'sha384-...']],\n"
            . "    ],\n"
            . "A host that serves no https cannot be a cdn_asset; vendor the file into the bundle's include list instead.\n"
            . 'See: php artisan rsx:man bundle_api (CDN ASSETS)',
            'critical'
        );
    }
}
