<?php

namespace App\RSpade\Core\Bundle;

use RuntimeException;
use App\RSpade\Core\Externals\Rsx_Externals;
use App\RSpade\Core\Rsx;

/**
 * Cdn_Cache - the local mirror store for every remote asset the application serves.
 *
 * THE STORE
 *
 * One flat directory of FILES, `rsx/resource/.cdn-cache/`, git-tracked so a build never
 * depends on a CDN being reachable. Bytes are stored VERBATIM: nothing is prepended, no
 * source header, no normalisation - so an integrity hash means what it says and a font
 * file is the font file. The only content that is REWRITTEN on the way in is CSS, whose
 * remote `url()` and `@import` references are localized into further entries of this same
 * store (see _localize_css() below), because a stylesheet that reaches an external host
 * at render time is a CSP violation waiting to happen.
 *
 * THE NAMING RULE  (filename_for(); the node localizer implements the identical rule and
 * EXT-49 pins the two together)
 *
 *     md5(<url exactly as given>) . '_' . <safe basename> . '.' . <ext>
 *
 * where <safe basename> is the URL PATH's basename-without-extension with every character
 * outside [A-Za-z0-9_-] replaced by '_', truncated to 50 characters ('asset' when empty),
 * and <ext> is the path's lowercased extension when it is one of EXTENSIONS, otherwise the
 * DECLARED type ('css' or 'js'). The path is used raw - never percent-decoded. The md5 is
 * the identity (a query string is part of the URL and therefore part of the name); the
 * readable tail exists so a human can see what a file is.
 *
 * THE PERMISSION PREDICATE  (_download_is_permitted(); pure)
 *
 *     $build_phase || $is_cli || !$is_sealed_mode
 *
 * Downloading is a BUILD activity, never a request-time one. A sealed build serves every
 * mirrored asset from /_vendor/, so a cache MISS while serving a page means the mirror was
 * never populated (or was deleted) - a broken build, which must fail LOUD rather than
 * silently curl the internet from a web worker. CLI is permitted regardless: every CLI
 * entry into the compiler is a build or a developer workflow, and the hole this guard
 * closes is the WEB one.
 *
 * SEAMS
 *
 * - $_build_phase        the explicit build-pipeline marker (rsx:prod:build sets it).
 * - $_testing_cache_dir  redirects the whole store at a scratch directory.
 * - $_testing_fetcher    callable(string $url): string|false standing in for the network.
 *
 * See: php artisan rsx:man external_resources
 */
class Cdn_Cache
{
    /** base_path()-relative location of the store. Git-tracked; survives rsx:clean. */
    public const DIR = 'rsx/resource/.cdn-cache';

    /** Extensions a mirrored file may carry verbatim from its URL path. */
    public const EXTENSIONS = [
        'js', 'css',
        'woff2', 'woff', 'ttf', 'otf', 'eot',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
    ];

    /** Exactly what filename_for() can produce - the /_vendor/ route validates against it. */
    public const FILENAME_PATTERN =
        '/^[a-f0-9]{32}_[A-Za-z0-9_-]{1,50}\.(js|css|woff2|woff|ttf|otf|eot|svg|png|jpg|jpeg|gif|webp|ico)$/';

    /**
     * Are we inside the build pipeline right now? See THE PERMISSION PREDICATE above.
     */
    public static bool $_build_phase = false;

    /**
     * Testing seam: redirect the entire store at a scratch directory.
     */
    public static ?string $_testing_cache_dir = null;

    /**
     * Testing seam: callable(string $url): string|false standing in for the network.
     *
     * @var callable|null
     */
    public static $_testing_fetcher = null;

    // -------------------------------------------------------------------------
    // Naming and location
    // -------------------------------------------------------------------------

    /**
     * The mirror filename for a URL. THE NAMING RULE - see the class docblock.
     *
     * @param string $type 'css' or 'js' - the DECLARED kind, used when the path has no
     *                     recognisable extension of its own.
     */
    public static function filename_for(string $url, string $type): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        $safe = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($path, PATHINFO_FILENAME)), 0, 50);

        if ($safe === '') {
            $safe = 'asset';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');

        if (!in_array($ext, self::EXTENSIONS, true)) {
            $ext = $type;
        }

        return md5($url) . '_' . $safe . '.' . $ext;
    }

    /**
     * Absolute path of a URL's mirror file.
     */
    public static function path_for(string $url, string $type): string
    {
        return self::get_cache_directory() . '/' . self::filename_for($url, $type);
    }

    /**
     * Is this URL already mirrored?
     */
    public static function is_cached(string $url, string $type): bool
    {
        return file_exists(self::path_for($url, $type));
    }

    /**
     * The store's directory (public: the /_vendor/ route serves out of it).
     */
    public static function get_cache_directory(): string
    {
        return self::$_testing_cache_dir ?? base_path(self::DIR);
    }

    // -------------------------------------------------------------------------
    // Populating the store
    // -------------------------------------------------------------------------

    /**
     * Guarantee a URL is mirrored, and return its FILENAME (never its content).
     *
     * @param string      $type      'css' or 'js' - the declared kind.
     * @param string|null $integrity 'algo-base64' subresource hash, verified when given.
     */
    public static function ensure(string $url, string $type, ?string $integrity = null): string
    {
        $filename = self::filename_for($url, $type);
        $path = self::get_cache_directory() . '/' . $filename;

        if (file_exists($path)) {
            return $filename;
        }

        // A miss is only ever resolved by downloading, and downloading is a build activity.
        self::_assert_download_permitted($url, $filename);

        $bytes = self::_fetch($url);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException("Failed to download external asset: {$url}");
        }

        if ($integrity !== null) {
            self::_verify_integrity($url, $bytes, $integrity);
        }

        if ($type === 'css') {
            $bytes = self::_localize_css($bytes, $url);
        }

        ensure_directory(self::get_cache_directory());
        file_put_contents_safe($path, $bytes);

        return $filename;
    }

    /**
     * Mirror every declared external resource that asks to be mirrored.
     *
     * Both realms, deduplicated by URL: a filename is mode- and realm-independent, so one
     * URL is one file no matter who declares it.
     *
     * @param callable|null $log callable(string $line) - progress narration, optional.
     * @return int the number of URLs mirrored.
     */
    public static function mirror_externals(?callable $log = null): int
    {
        $entries = Rsx_Externals::all_for_realm('staff') + Rsx_Externals::all_for_realm('portal');

        $seen = [];
        $mirrored = 0;

        foreach ($entries as $entry) {
            if ($entry['mirror'] !== true) {
                continue;
            }

            foreach (array_merge($entry['js'], $entry['css']) as $url) {
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;

                $type = Rsx_Externals::url_asset_type($url);
                $was_cached = self::is_cached($url, $type);

                $filename = self::ensure($url, $type, $entry['integrity'][$url] ?? null);

                if ($log !== null) {
                    $log("  -> {$filename} " . ($was_cached ? '(cached)' : '(downloaded)'));
                }

                $mirrored++;
            }
        }

        return $mirrored;
    }

    /**
     * Delete every file in the store.
     *
     * @return int the number of files removed.
     */
    public static function clear(): int
    {
        $cache_dir = self::get_cache_directory();

        if (!is_dir($cache_dir)) {
            return 0;
        }

        $removed = 0;

        foreach (glob($cache_dir . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    // -------------------------------------------------------------------------
    // Policy
    // -------------------------------------------------------------------------

    /**
     * May a cache miss be resolved by downloading? PURE - the decision, no I/O.
     *
     * @param bool $is_cli         PHP_SAPI === 'cli' - a build or developer workflow
     * @param bool $is_sealed_mode Rsx::is_production() - debug or strict production
     * @param bool $build_phase    the explicit build-pipeline marker
     */
    public static function _download_is_permitted(bool $is_cli, bool $is_sealed_mode, bool $build_phase): bool
    {
        return $build_phase || $is_cli || !$is_sealed_mode;
    }

    /**
     * The User-Agent every mirror download sends - curl here and node in the localizer.
     *
     * @throws RuntimeException when nothing configures one.
     */
    public static function user_agent(): string
    {
        $user_agent = (string) config('rsx.cdn_externals.user_agent');

        if ($user_agent === '') {
            throw new RuntimeException(
                "rsx.cdn_externals.user_agent is empty\n" .
                '  Every mirror download must identify itself; see php artisan rsx:man config_rsx.'
            );
        }

        return $user_agent;
    }

    /**
     * Refuse a request-time download in a sealed mode.
     *
     * @throws RuntimeException naming the missing mirror file and the remedy.
     */
    private static function _assert_download_permitted(string $url, string $filename): void
    {
        if (self::_download_is_permitted(PHP_SAPI === 'cli', Rsx::is_production(), self::$_build_phase)) {
            return;
        }

        throw new RuntimeException(
            "Missing mirrored external asset: {$filename}\n" .
            "  Source: {$url}\n" .
            "  This build serves external assets from its own /_vendor/ mirror, and this file is\n" .
            '  not in ' . self::DIR . ". A sealed build never downloads at request time.\n" .
            '  Remedy: php artisan rsx:prod:refresh'
        );
    }

    /**
     * Verify a subresource-integrity hash against the downloaded bytes.
     */
    private static function _verify_integrity(string $url, string $bytes, string $integrity): void
    {
        $parts = explode('-', $integrity, 2);

        if (count($parts) !== 2 || !in_array($parts[0], ['sha256', 'sha384', 'sha512'], true)) {
            throw new RuntimeException(
                "Unsupported integrity hash for {$url}\n" .
                "  Declared: {$integrity}\n" .
                '  Expected one of sha256-, sha384-, sha512- followed by a base64 digest.'
            );
        }

        [$algo, $expected] = $parts;
        $actual = base64_encode(hash($algo, $bytes, true));

        if (!hash_equals($actual, $expected)) {
            throw new RuntimeException(
                "Integrity mismatch for {$url}\n" .
                "  Expected: {$algo}-{$expected}\n" .
                "  Actual:   {$algo}-{$actual}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Fetching
    // -------------------------------------------------------------------------

    /**
     * Fetch the bytes for a URL - the testing seam, then curl.
     */
    private static function _fetch(string $url): string|false
    {
        if (self::$_testing_fetcher !== null) {
            return (self::$_testing_fetcher)($url);
        }

        return self::_download($url);
    }

    /**
     * Download URL content using curl.
     */
    private static function _download(string $url): string|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            // NO TRANSFER TIMEOUT. This carried CURLOPT_TIMEOUT => 30, which capped the
            // whole download - and expiry throws (see _fetch's caller), FAILING a
            // sealed production build. A large vendor bundle on a slow link was
            // indistinguishable from an outage, so expiry did not degrade to a working
            // outcome and the cap failed even the sanctioned test.
            //
            // CONNECTTIMEOUT stays: it bounds only reaching an EXTERNAL host that may
            // never answer, never the transfer, and a host that will not accept a
            // connection in 10s is down rather than slow.
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::user_agent(),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || $content === false) {
            return false;
        }

        return $content;
    }

    // -------------------------------------------------------------------------
    // CSS localization
    // -------------------------------------------------------------------------

    /**
     * Rewrite every remote reference inside a stylesheet to a /_vendor/ mirror entry.
     *
     * Runs the node localizer (postcss): remote `@import`s are fetched and spliced in
     * place, and every remaining absolute or protocol-relative `url()` is mirrored into
     * this same store and rewritten to `/_vendor/<name>`. So the stylesheet we store
     * reaches no external host at render time, in ANY mode.
     *
     * PUBLIC because the bundle compiler runs it over every compiled stylesheet too (an
     * application's own SCSS may `@import url(https://fonts.googleapis.com/...)`). The
     * underscore prefix marks it framework-internal, which is exactly what it is - there
     * is ONE name for this operation and BundleCompiler calls it by that name.
     *
     * @param string $css      the raw stylesheet bytes
     * @param string $base_url the URL the stylesheet came from (relative refs resolve to
     *                         it). EMPTY for a locally concatenated bundle: with no
     *                         absolute base only absolute and protocol-relative references
     *                         are localizable, which is the correct rule there.
     * @return string the localized CSS
     */
    public static function _localize_css(string $css, string $base_url): string
    {
        $tmp_dir = storage_path('rsx-tmp');
        ensure_directory($tmp_dir);
        ensure_directory(self::get_cache_directory());

        // uniqid() is load-bearing: the base URL alone is NOT unique (every bundle-level
        // localization passes an empty base, so md5('') would name one shared temp file
        // and two concurrent compiles would read each other's bytes).
        $stem = $tmp_dir . '/css_localize_' . md5($base_url) . '_' . uniqid('', true);
        $in = $stem . '.in.css';
        $out = $stem . '.out.css';

        file_put_contents_safe($in, $css);

        $script = base_path('app/RSpade/Core/Bundle/resource/localize-css-externals.js');

        $arguments = [
            '--cache-dir', self::get_cache_directory(),
            '--user-agent', self::user_agent(),
        ];

        if (!self::_download_is_permitted(PHP_SAPI === 'cli', Rsx::is_production(), self::$_build_phase)) {
            $arguments[] = '--no-download';
        }

        array_push($arguments, '--base-url', $base_url, '--in', $in, '--out', $out);

        $command = 'node ' . escapeshellarg($script);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        // The base URL is the stylesheet's identity in every message below; a bundle-level
        // localization has none, so it is named for what it is.
        $subject = $base_url === '' ? 'the compiled bundle stylesheet' : $base_url;

        try {
            // Explicit bash (never the implicit /bin/sh), with the exit code as the last line.
            $raw = shell_exec('bash -c ' . escapeshellarg("({$command} 2>&1); echo \$?"));

            $lines = explode("\n", trim((string) $raw));
            $exit_code = (int) array_pop($lines);
            $output = trim(implode("\n", $lines));

            if ($exit_code !== 0) {
                throw new RuntimeException(
                    "Failed to localize external references in {$subject}:\n{$output}"
                );
            }

            if (!file_exists($out)) {
                throw new RuntimeException("CSS localization produced no output for {$subject}");
            }

            return file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
