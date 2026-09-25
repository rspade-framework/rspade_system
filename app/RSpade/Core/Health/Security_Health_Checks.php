<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Illuminate\Support\Facades\Http;
use App\RSpade\Core\Ide\Ide_Bridge_Token;

/**
 * Security_Health_Checks - active web-exposure probes for rsx:health.
 *
 * A correctly configured deployment serves ONLY system/public; sensitive files
 * (.env, the .git repo, the IDE bridge token dir) live outside that docroot and must
 * never be reachable over HTTP. Rather than trust that invariant, this check PROVES it:
 * it fetches each sensitive path from our OWN APP_URL and content-matches the response
 * against the real on-disk file. A match = the file is being served = misconfigured
 * docroot / autoindex = hard FAIL. This is the real defense against a bad web-server
 * setup (it does not rely on file permissions, which a stray `chmod` can wipe).
 *
 * MODE: every mode. This is a security probe and the development surface is the one that
 * is secured - a development RSpade site may be serving the public right now. The IDE
 * bridge directory is probed in every mode too, and for the same reason: what it proves
 * is the DOCROOT BOUNDARY, not whether the bridge is switched on.
 *
 * Content-match (not status code) is deliberate: an SPA/catch-all that answers 200 with
 * index.html for unknown paths would false-positive a status-only check.
 */
class Security_Health_Checks
{
    /**
     * NO TIMEOUT on a probe (owner ruling 2026-08-22). Guzzle reads 0 as "no limit", and
     * it is passed EXPLICITLY rather than omitted because Laravel's PendingRequest
     * defaults every call to 30s - saying nothing here would silently reinstate a cap.
     *
     * These probe our own APP_URL for served sensitive files. A probe that never returns
     * means the web server is wedged, which is a fault to SEE and diagnose - not something
     * to convert after 6 seconds into a row that reads identically to "not reachable".
     */
    private const PROBE_TIMEOUT = 0;

    /**
     * Probe our own APP_URL for served sensitive files. One row per target; a served
     * file is a FAIL, an unreachable own-domain degrades to WARN (never a false pass).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Health_Check('Web Exposure')]
    public static function web_exposure(): array
    {
        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') {
            return [[
                'label' => 'Web Exposure',
                'status' => 'FAIL',
                'detail' => 'APP_URL is not set - cannot probe for exposed sensitive files',
                'remediation' => 'set APP_URL in .env to this host',
            ]];
        }

        // Preflight: is our own domain reachable at all? If not, do not report false OKs.
        if (!self::__reachable($base)) {
            return [[
                'label' => 'Web Exposure',
                'status' => 'WARN',
                'detail' => "could not reach {$base} to run exposure probes",
                'remediation' => 'confirm APP_URL is correct and the site is up, then re-run',
            ]];
        }

        $project_root = dirname(base_path());
        $rows = [];

        // .env (the real file is the project-root .env; system/.env symlinks to it).
        $rows[] = self::__probe_file(
            '.env exposure',
            $base . '/.env',
            $project_root . '/.env',
            self::__env_signature($project_root . '/.env')
        );

        // .git repo (HEAD is the canonical dumpability canary; config leaks remote URLs).
        $rows[] = self::__probe_file(
            '.git/HEAD exposure',
            $base . '/.git/HEAD',
            $project_root . '/.git/HEAD',
            null // signature derived from the file's own (small, distinctive) content
        );
        $rows[] = self::__probe_file(
            '.git/config exposure',
            $base . '/.git/config',
            $project_root . '/.git/config',
            'repositoryformatversion'
        );

        // IDE bridge token directory (the grant token must never be web-served).
        $rows[] = self::__probe_bridge($base);

        return $rows;
    }

    /**
     * Ignition, Laravel's debug page, must be read-only: no runnable solutions, no
     * sharing. A runnable solution is an unauthenticated, CSRF-free POST that can
     * rotate APP_KEY, run migrations or rewrite .env, and Ignition's own "local caller"
     * test is fooled by the proxy hop in front of php-fpm. config/ignition.php
     * hard-codes both to false; this row FAILs when either is anything else, and when
     * /_ignition/health-check is answered by Ignition over HTTP - which it never is while
     * the HTTP kernel hands every request to Rsx_Front_Controller instead of Laravel's
     * router, so that half guards the kernel wiring itself.
     *
     * @return array<string, mixed>
     */
    #[Health_Check('Ignition Read-Only')]
    public static function ignition_read_only(): array
    {
        $problems = [];
        foreach (['enable_runnable_solutions', 'enable_share_button'] as $key) {
            if (config('ignition.' . $key) !== false) {
                $problems[] = "ignition.{$key} is " . var_export(config('ignition.' . $key), true);
            }
        }

        $base = rtrim((string) config('app.url'), '/');
        if ($base !== '') {
            try {
                $response = Http::withOptions(['verify' => false, 'allow_redirects' => false])
                    ->timeout(self::PROBE_TIMEOUT)
                    ->get($base . '/_ignition/health-check');
                // Content-matched, as the exposure probes are: an SPA catch-all can answer
                // 200 for any path, and only Ignition's own answer names this key.
                if ($response->status() === 200 && str_contains($response->body(), 'can_execute_commands')) {
                    $problems[] = "{$base}/_ignition/health-check is served by Ignition";
                }
            } catch (\Throwable $e) {
                // An unreachable own domain is reported by the Web Exposure row; the
                // config half of this check still stands on its own.
            }
        }

        if (!empty($problems)) {
            return [
                'status' => 'FAIL',
                'detail' => implode('; ', $problems),
                'remediation' => "set 'enable_runnable_solutions' => false and 'enable_share_button' => false "
                    . 'as literals in system/config/ignition.php (the framework ships them that way)',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => 'runnable solutions and sharing off; /_ignition/* not served',
            'remediation' => null,
        ];
    }

    /**
     * Laravel's route table must be EMPTY.
     *
     * RSX dispatch never consults Laravel's router: App\Http\Kernel hands every request to
     * Rsx_Front_Controller, and the framework provider empties the route table once every
     * provider has booted. A route in the table is therefore a regression in one of those
     * two - the kernel pointed back at the router, or something registering routes AFTER
     * boot - and a route that became reachable would bypass RSX's CSRF, #[Auth] gates and
     * CSP. The row asks the booted router of this very process, which has run the same
     * providers a web request runs; zero routes is OK, any route is a FAIL naming it.
     * Console needs no route, so there is no allowance for one.
     *
     * @return array<string, mixed>
     */
    #[Health_Check('Laravel Route Table')]
    public static function laravel_route_table(): array
    {
        $routes = app('router')->getRoutes()->getRoutes();

        if (!empty($routes)) {
            $names = array_map(
                fn ($route) => implode('|', $route->methods()) . ' /' . ltrim($route->uri(), '/'),
                $routes
            );

            return [
                'status' => 'FAIL',
                'detail' => count($routes) . ' Laravel route(s) registered: ' . implode(', ', $names),
                'remediation' => 'RSX serves no Laravel route. Remove the registration (a routes file, a '
                    . 'provider calling Route::*); confirm App\Http\Kernel::dispatchToRouter() still hands '
                    . 'every request to Rsx_Front_Controller. See: php artisan rsx:man dispatch',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => 'no Laravel route is registered; every request is dispatched by RSX',
            'remediation' => null,
        ];
    }

    /**
     * ImageMagick must refuse the vector and scripting coders. Its SVG/MSVG coder follows
     * references inside a document (an <image href="text:/etc/passwd"> rasterises a local
     * file), MVG and MSL are its own drawing and scripting languages, and TEXT/LABEL turn a
     * file or a string into an image. The framework never asks for any of them (an SVG
     * upload is never rasterised and the extension icons are PNG), so the shipped policy
     * allows raster coders only: system/app/RSpade/resource/docker/imagemagick/policy.xml.
     *
     * Each coder is PROBED with a harmless input, because Imagick::queryFormats() lists a
     * coder whether or not the policy lets it read: a read the policy refuses throws "not
     * allowed by the security policy", and any other outcome means the coder is live.
     *
     * @return array<string, mixed>
     */
    #[Health_Check('ImageMagick Coder Policy')]
    public static function imagemagick_coder_policy(): array
    {
        if (!extension_loaded('imagick')) {
            return [
                'status' => 'INFO',
                'detail' => 'the imagick extension is not loaded - nothing to probe',
                'remediation' => null,
            ];
        }

        $dir = \App\RSpade\Core\Paths\Rsx_Project_Paths::tmp_path('health_imagick_' . bin2hex(random_bytes(6)));
        ensure_directory($dir);

        $inputs = [
            'SVG' => ['svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'],
            'MSVG' => ['msvg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'],
            'MVG' => ['mvg', "viewbox 0 0 1 1\n"],
            // MSL is probed with an ABSENT script: the coder's policy check runs before the
            // file is opened, and a real MSL script is a program - one ImageMagick 6 build
            // segfaults on the smallest well-formed script there is.
            'MSL' => ['msl', false],
            'TEXT' => ['text', "x\n"],
            'LABEL' => ['label', null],
        ];

        $readable = [];

        try {
            foreach ($inputs as $coder => [$prefix, $body]) {
                if ($body === null) {
                    $spec = $prefix . ':x';
                } elseif ($body === false) {
                    $spec = $prefix . ':' . $dir . '/absent.' . $prefix;
                } else {
                    $path = $dir . '/probe.' . $prefix;
                    file_put_contents($path, $body);
                    $spec = $prefix . ':' . $path;
                }

                try {
                    $image = new \Imagick();
                    $image->readImage($spec);
                    $image->clear();
                    $readable[] = $coder;
                } catch (\Throwable $e) {
                    if (!str_contains($e->getMessage(), 'security policy')) {
                        $readable[] = $coder;
                    }
                }
            }
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }

        if (!empty($readable)) {
            return [
                'status' => 'FAIL',
                'detail' => 'ImageMagick policy permits: ' . implode(', ', $readable)
                    . ' (an SVG can rasterise local files through these coders)',
                'remediation' => 'install system/app/RSpade/resource/docker/imagemagick/policy.xml as '
                    . 'the system ImageMagick policy (/etc/ImageMagick-6/policy.xml), which allows raster '
                    . 'coders only; the RSpade docker image does this',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => 'SVG, MSVG, MVG, MSL, TEXT and LABEL are refused by policy',
            'remediation' => null,
        ];
    }

    /**
     * True if the base URL answers at all (any HTTP status, including a redirect, counts
     * as reachable; only a transport/connection failure counts as unreachable).
     *
     * @param string $base
     * @return bool
     */
    private static function __reachable(string $base): bool
    {
        try {
            Http::withOptions(['verify' => false, 'allow_redirects' => false])
                ->timeout(self::PROBE_TIMEOUT)
                ->get($base . '/');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Probe a single on-disk sensitive file: FAIL if fetching its URL returns its actual
     * content, OK if not served (or not present on disk), WARN on transport error.
     *
     * @param string $label
     * @param string $url
     * @param string $real_path Absolute path of the real file on disk.
     * @param string|null $signature A distinct substring that must appear in the served
     *        response to count as exposure. Null = use the file's own trimmed content.
     * @return array<string, mixed>
     */
    private static function __probe_file(string $label, string $url, string $real_path, ?string $signature): array
    {
        if (!is_file($real_path)) {
            return [
                'label' => $label,
                'status' => 'OK',
                'detail' => 'not present on disk - nothing to expose',
                'remediation' => null,
            ];
        }

        if ($signature === null) {
            $signature = trim((string) file_get_contents($real_path));
        }
        if ($signature === '') {
            return [
                'label' => $label,
                'status' => 'OK',
                'detail' => 'file is empty - no signature to match',
                'remediation' => null,
            ];
        }

        try {
            $response = Http::withOptions(['verify' => false, 'allow_redirects' => false])
                ->timeout(self::PROBE_TIMEOUT)
                ->get($url);
        } catch (\Throwable $e) {
            return [
                'label' => $label,
                'status' => 'WARN',
                'detail' => "probe of {$url} failed: " . $e->getMessage(),
                'remediation' => 'retry; if it persists, verify manually that the path is not served',
            ];
        }

        if ($response->status() === 200 && str_contains($response->body(), $signature)) {
            return [
                'label' => $label,
                'status' => 'FAIL',
                'detail' => "SERVED over HTTP at {$url} - the real file's content is web-readable",
                'remediation' => 'point the web-server docroot at system/public ONLY; deny dotfiles/.git/.env',
            ];
        }

        return [
            'label' => $label,
            'status' => 'OK',
            'detail' => 'not web-served',
            'remediation' => null,
        ];
    }

    /**
     * Probe the IDE bridge token directory by writing a throwaway random marker into it
     * and asking the web server for it via the candidate misconfig URLs. Cleans up the
     * marker regardless of outcome.
     *
     * @param string $base
     * @return array<string, mixed>
     */
    private static function __probe_bridge(string $base): array
    {
        // The DIRECTORY comes from the token minter - one resolution, so a probe can
        // never report on a path nothing writes. The configured key is still what the
        // URL spellings below are built from: that is the shape a misconfigured docroot
        // would expose.
        $bridge_rel = trim((string) config('rsx.ide_integration.bridge_path', 'storage/rsx-ide-bridge'), '/');
        $bridge_dir = Ide_Bridge_Token::bridge_dir();
        if (!is_dir($bridge_dir)) {
            return [
                'label' => 'IDE bridge dir exposure',
                'status' => 'OK',
                'detail' => 'bridge directory not present - nothing to expose',
                'remediation' => null,
            ];
        }

        $marker_name = 'probe-' . bin2hex(random_bytes(16)) . '.txt';
        $marker_body = bin2hex(random_bytes(24));
        $marker_path = $bridge_dir . '/' . $marker_name;

        try {
            file_put_contents_safe($marker_path, $marker_body);

            // A misconfigured docroot at the framework tree serves /<key>/..., one at
            // the project root serves /system/<key>/... - probe both spellings, because
            // which one is exposed depends on the mistake, not on the layout.
            $candidates = [
                $base . '/' . $bridge_rel . '/' . $marker_name,
                $base . '/system/' . $bridge_rel . '/' . $marker_name,
            ];

            foreach ($candidates as $url) {
                try {
                    $response = Http::withOptions(['verify' => false, 'allow_redirects' => false])
                        ->timeout(self::PROBE_TIMEOUT)
                        ->get($url);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($response->status() === 200 && str_contains($response->body(), $marker_body)) {
                    return [
                        'label' => 'IDE bridge dir exposure',
                        'status' => 'FAIL',
                        'detail' => "SERVED over HTTP at {$url} - the grant token directory is web-readable",
                        'remediation' => 'point the web-server docroot at system/public ONLY',
                    ];
                }
            }
        } finally {
            if (is_file($marker_path)) {
                @unlink($marker_path);
            }
        }

        return [
            'label' => 'IDE bridge dir exposure',
            'status' => 'OK',
            'detail' => 'not web-served',
            'remediation' => null,
        ];
    }

    /**
     * A distinctive signature line from a .env file (the APP_KEY line if present, else
     * the first KEY=VALUE line). Empty string if none - the caller then treats the file
     * as having no matchable secret.
     *
     * @param string $env_path
     * @return string
     */
    private static function __env_signature(string $env_path): string
    {
        if (!is_file($env_path)) {
            return '';
        }
        $content = (string) file_get_contents($env_path);
        if (preg_match('/^APP_KEY=.{8,}$/m', $content, $m)) {
            return trim($m[0]);
        }
        if (preg_match('/^[A-Z0-9_]+=.+$/m', $content, $m)) {
            return trim($m[0]);
        }
        return '';
    }
}
