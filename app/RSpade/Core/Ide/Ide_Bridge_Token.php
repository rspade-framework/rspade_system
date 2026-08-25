<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Ide;

use App\RSpade\Core\Rsx;

/**
 * Ide_Bridge_Token - the local-file grant for the IDE bridge (server side, booted).
 *
 * The IDE bridge (/_ide/service/*) authenticates a caller by proving LOCAL filesystem
 * read access: the framework writes a single high-entropy token file into the bridge
 * directory (which lives OUTSIDE the web docroot), and a co-located IDE reads it from
 * disk and presents its contents as the X-Ide-Token bearer. auth.php verifies it
 * constant-time. No network endpoint ever mints or returns the token.
 *
 * Both the FILENAME and the CONTENT are cryptographically random and unguessable, so
 * neither a directory-listing leak (name exposed) nor a stray content leak alone is
 * sufficient - and the trust chain does NOT depend on file permissions (a `chmod 777`
 * cannot open it): the file is not web-served, and its name/content are unguessable.
 * 0600/0700 are applied best-effort as one more layer, never THE layer.
 *
 * THE FILE IS A JSON GRANT DOCUMENT: {"secret": "<hex>", "app_url": "<resolved URL>"}.
 * The secret is what the X-Ide-Token header carries and what hash_equals compares; the
 * app_url is there because the IDE must know which server to call BEFORE it can call
 * anything, and .env may hold the literal $HOSTNAME token that only this machine can
 * resolve correctly (see __write_grant).
 *
 * ensure() is idempotent and dev-only; it also drops passive static-serve guards into
 * the directory and clears the retired auth-*.json / domain.txt artifacts. An
 * ESTABLISHED SECRET IS NEVER ROTATED by it - only app_url is refreshed, so a grant
 * survives an APP_URL change without the IDE having to notice.
 */
class Ide_Bridge_Token
{
    /** Glob pattern (and prefix/suffix) for the grant token file. */
    private const TOKEN_GLOB = 'ide-grant-*.token';

    /**
     * How many grants authenticate at once: the one just minted, and the one before it.
     *
     * Two, because rotation and use are not synchronized. An editor holds whatever it
     * last read for up to a refresh interval, so the moment a new grant is minted there
     * is a window in which the previous one is still the only value any client has. One
     * active grant would 401 every request in that window; three would widen the reuse
     * life of a retired secret for no benefit.
     */
    public const ACTIVE_GRANTS = 2;

    /**
     * The bridge directory absolute path (storage, outside the docroot).
     *
     * @return string
     */
    public static function bridge_dir(): string
    {
        // The configured path is written relative to the directory CONTAINING storage/
        // (historically base_path()); volatile storage now lives at <project>/storage, so
        // resolve against the parent of the RESOLVED storage root instead of base_path().
        return dirname(storage_path()) . '/' . ltrim(config('rsx.ide_integration.bridge_path', 'storage/rsx-ide-bridge'), '/');
    }

    /**
     * Ensure a grant token + passive guards exist (development mode only). Idempotent:
     * creates the token only when none is present, and writes the guard files only when
     * absent. Safe to call on every web-request boot.
     *
     * @return void
     */
    public static function ensure(): void
    {
        // Dev-only. In debug/production the bridge is refused at auth.php anyway, and a
        // sealed build must never carry a token.
        if (!Rsx::is_development()) {
            return;
        }
        if (!config('rsx.ide_integration.enabled', true)) {
            return;
        }

        $dir = self::bridge_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        self::__write_guards($dir);
        self::__clear_retired_artifacts($dir);

        // The token persists across requests: an established grant keeps its SECRET
        // for the life of the file. Only the resolved URL is refreshed, because
        // APP_URL can legitimately change under a grant that is still valid (the
        // first-run screen writes it after the first boot, and a rename or a port
        // change rewrites it later).
        $existing = self::__grants_newest_first($dir);
        $path = $existing[0] ?? null;
        $grant = $path !== null
            ? self::__read_grant($path)
            : ['secret' => null, 'issued_at' => null];

        if ($grant['secret'] === null) {
            // No grant, or a file that does not parse as one. Mint a new pair; the
            // filename is re-rolled with it so name and content are never reused.
            foreach ($existing as $unusable) {
                @unlink($unusable);
            }
            $path = $dir . '/ide-grant-' . bin2hex(random_bytes(16)) . '.token';
            $grant = ['secret' => bin2hex(random_bytes(32)), 'issued_at' => microtime(true)];
        }

        self::__write_grant(
            $path,
            $grant['secret'],
            self::__resolved_app_url(),
            $grant['issued_at'] ?? microtime(true)
        );
    }

    /**
     * The grant document as it is written to disk.
     *
     * WHY THE URL LIVES HERE. The IDE has to know which server to call before it can
     * call anything, and the only other source is APP_URL in .env - which may hold the
     * literal `$HOSTNAME` token. The framework resolves that token with the SERVER's
     * gethostname(); an editor resolving it locally would substitute the WORKSTATION's
     * name instead, and every request would go to the wrong host (or nowhere). That
     * failure is invisible on a co-located setup and total on a remote-mount one.
     *
     * Writing the already-resolved value beside the secret removes the guess: the
     * machine that knows its own name is the one that answers the question.
     *
     * It is NOT a secret, and it does not weaken the grant - the address is public and
     * already sits in .env. What guards the bridge is still the unguessable filename
     * plus the unguessable secret, neither of which this adds to.
     *
     * issued_at is SET AT MINT AND CARRIED FORWARD, never re-stamped by a refresh. A
     * refresh that moved it would keep promoting the oldest grant to "newest" on every
     * web request - and the bootstrap grant, which sees the most refreshes, would
     * outrank every rotation.
     *
     * @param string $path
     * @param string $secret
     * @param string $app_url
     * @param float $issued_at
     * @return void
     */
    private static function __write_grant(string $path, string $secret, string $app_url, float $issued_at): void
    {
        // issued_at (microtime float) is the ORDERING KEY, and it exists because
        // filemtime cannot answer the question: it has one-second granularity, so two
        // grants minted in the same second are indistinguishable by it and "newest"
        // silently degrades into whichever filename sorts higher. Rotation runs 15
        // minutes apart and would rarely collide, but ensure()-then-rotate collides
        // routinely, and a client that picked the wrong one of the pair as "newest"
        // would retire its own working grant first.
        $document = json_encode(
            [
                'secret' => $secret,
                'app_url' => $app_url,
                'issued_at' => $issued_at,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        file_put_contents_safe($path, $document . "\n");
        @chmod($path, 0600);
    }

    /**
     * Parse a grant file into ['secret' => ?string, 'app_url' => ?string,
     * 'issued_at' => ?float].
     *
     * Both entries are null when the file is unreadable or is not a grant document.
     * A caller that needs the secret treats null as "no grant established" - never as
     * a reason to fall back to something weaker.
     *
     * @param string $path
     * @return array{secret: ?string, app_url: ?string, issued_at: ?float}
     */
    private static function __read_grant(string $path): array
    {
        $empty = ['secret' => null, 'app_url' => null, 'issued_at' => null];

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $empty;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $empty;
        }

        $secret = isset($decoded['secret']) && is_string($decoded['secret'])
            ? trim($decoded['secret'])
            : '';
        $app_url = isset($decoded['app_url']) && is_string($decoded['app_url'])
            ? trim($decoded['app_url'])
            : '';

        return [
            'secret' => $secret !== '' ? $secret : null,
            'app_url' => $app_url !== '' ? $app_url : null,
            'issued_at' => isset($decoded['issued_at']) && is_numeric($decoded['issued_at'])
                ? (float) $decoded['issued_at']
                : null,
        ];
    }

    /**
     * The application URL with every token already resolved, trailing slashes trimmed.
     *
     * config('app.url') is the patched value: bootstrap/app.php substitutes $HOSTNAME
     * through Rsx_App_Url::patch_environment() during afterLoadingEnvironment, before
     * LoadConfiguration reads it. So this is the address the server itself believes it
     * answers on - which is exactly what the IDE needs and cannot work out alone.
     *
     * @return string
     */
    private static function __resolved_app_url(): string
    {
        return rtrim((string) config('app.url', ''), '/');
    }

    /**
     * The current grant token's content, or null if none is established.
     *
     * @return string|null
     */
    public static function current_token(): ?string
    {
        $files = glob(self::bridge_dir() . '/' . self::TOKEN_GLOB) ?: [];
        if (empty($files)) {
            return null;
        }
        return self::__read_grant($files[0])['secret'];
    }

    /**
     * Drop the passive static-serve guards (idempotent). These defend a MISCONFIGURED
     * environment (an operator who serves storage/, or an Apache switch where autoindex
     * is on by default): an index file that 404s, and an Apache deny rule. nginx never
     * autoindexes and funnels unknown paths to the front controller, so these are belt,
     * not the primary defense.
     *
     * @param string $dir
     * @return void
     */
    private static function __write_guards(string $dir): void
    {
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents_safe($index, "<?php http_response_code(404); exit;\n");
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents_safe($htaccess, "Require all denied\nDeny from all\n");
        }
    }

    /**
     * Remove the retired auth scheme's artifacts (the unauthenticated-mint session files
     * and the domain.txt discovery file, both no longer used).
     *
     * @param string $dir
     * @return void
     */
    private static function __clear_retired_artifacts(string $dir): void
    {
        foreach (glob($dir . '/auth-*.json') ?: [] as $stale) {
            @unlink($stale);
        }
        $domain_file = $dir . '/domain.txt';
        if (file_exists($domain_file)) {
            @unlink($domain_file);
        }
    }

    /**
     * Mint a fresh grant and retire everything but the newest ACTIVE_GRANTS.
     *
     * ROTATION IS AN ENHANCEMENT, NOT A REQUIREMENT. ensure() mints a single grant on
     * the first dev web request and NOTHING expires it - a box whose operator never
     * enabled cron keeps that one grant working indefinitely, which is the whole reason
     * ensure() is not folded into this method. Rotation narrows the window a leaked
     * secret is useful for on boxes that DO run the scheduler; it is not what makes the
     * bridge work.
     *
     * Outside development every grant is DELETED instead. A sealed build refuses the
     * bridge at auth.php anyway, so a token file there is not a door - it is a secret
     * sitting on disk for no reason, and the sweep is what removes one left behind by a
     * box that was flipped to production after running in development.
     *
     * @return array{minted: ?string, removed: int, mode: string}
     */
    public static function rotate(): array
    {
        $dir = self::bridge_dir();

        if (!Rsx::is_development()) {
            $removed = 0;
            foreach (glob($dir . '/' . self::TOKEN_GLOB) ?: [] as $token) {
                if (@unlink($token)) {
                    $removed++;
                }
            }
            return ['minted' => null, 'removed' => $removed, 'mode' => 'purged'];
        }

        if (!config('rsx.ide_integration.enabled', true)) {
            return ['minted' => null, 'removed' => 0, 'mode' => 'disabled'];
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        self::__write_guards($dir);

        $path = $dir . '/ide-grant-' . bin2hex(random_bytes(16)) . '.token';
        self::__write_grant($path, bin2hex(random_bytes(32)), self::__resolved_app_url(), microtime(true));

        return [
            'minted' => basename($path),
            'removed' => self::__retire_surplus_grants($dir),
            'mode' => 'rotated',
        ];
    }

    /**
     * The grant files that may authenticate, newest first: at most ACTIVE_GRANTS of them.
     *
     * Ordering is by the document's own issued_at, so "newest" means the most recently
     * ISSUED grant - not the most recently touched file. Something that rewrites a
     * grant's mtime cannot promote it, and a file that is not a grant sorts last.
     *
     * @return string[] Absolute paths.
     */
    public static function active_grant_files(): array
    {
        return array_slice(self::__grants_newest_first(self::bridge_dir()), 0, self::ACTIVE_GRANTS);
    }

    /**
     * Every grant file in $dir, newest mtime first.
     *
     * @param string $dir
     * @return string[]
     */
    private static function __grants_newest_first(string $dir): array
    {
        $files = glob($dir . '/' . self::TOKEN_GLOB) ?: [];

        // Ordered by the document's own issued_at, NOT by filemtime - see __write_grant
        // for why the filesystem timestamp cannot decide this. A file with no readable
        // issued_at sorts last: it is either not a grant or predates the field, and
        // either way it must never outrank a grant that states when it was issued.
        $issued = [];
        foreach ($files as $file) {
            $issued[$file] = self::__read_grant($file)['issued_at'] ?? 0.0;
        }

        usort($files, static function (string $a, string $b) use ($issued): int {
            $order = $issued[$b] <=> $issued[$a];
            return $order !== 0 ? $order : strcmp($b, $a);
        });

        return $files;
    }

    /**
     * Delete every grant beyond the newest ACTIVE_GRANTS.
     *
     * @param string $dir
     * @return int Number deleted.
     */
    private static function __retire_surplus_grants(string $dir): int
    {
        $surplus = array_slice(self::__grants_newest_first($dir), self::ACTIVE_GRANTS);

        $removed = 0;
        foreach ($surplus as $token) {
            if (@unlink($token)) {
                $removed++;
            }
        }

        return $removed;
    }
}
