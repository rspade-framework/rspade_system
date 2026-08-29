<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use RuntimeException;
use App\RSpade\Core\Rsx;

/**
 * Rsx_Php_Requirements - the ONE declaration of the PHP runtime this framework needs.
 *
 * Two consumers read this list and nothing else declares one:
 *
 *   1. rsx:health (Environment_Health_Checks::php_environment) - the DIAGNOSTIC. It
 *      reports every missing extension as its own FAIL row with a remediation.
 *   2. The boot check (enforce(), called from Rsx_Framework_Provider::boot()) - the
 *      GUARD. It runs on every web request and every artisan invocation and THROWS
 *      naming every missing extension.
 *
 * WHY BOTH. The health command is only consulted by somebody who already suspects
 * something. A missing extension otherwise surfaces as whatever the first feature to
 * touch it does - "Class Redis not found" from a cache write, a blank thumbnail, a
 * gmp_init() fatal three layers into a file upload - each of which has to be
 * diagnosed on its own before anybody thinks to ask what the box has installed. One
 * throw at boot, naming the extension, is the whole of that problem.
 *
 * WHY IT IS CHEAP ENOUGH TO DO EVERY REQUEST. extension_loaded() is a hash lookup in
 * an already-populated table. The whole list is microseconds, and it buys an
 * invariant every line of framework code after it may assume.
 *
 * NEVER DEGRADE. Nothing in this framework has a second code path for a missing
 * extension - no GD-instead-of-Imagick, no file-cache-instead-of-Redis. An
 * environment missing one of these is misconfigured, and the framework says so
 * rather than running in a shape nobody designed.
 *
 * TWO TIERS. REQUIRED_EXTENSIONS is the RUNTIME tier - every SAPI, every request,
 * every artisan run. REQUIRED_CLI_EXTENSIONS is the CLI tier, enforced only when
 * PHP_SAPI is 'cli'.
 *
 * WHY A CLI TIER EXISTS AT ALL. Debian/sury compile pcntl into the php-cli binary and
 * NOT into php-fpm, deliberately: pcntl_fork() inside an fpm worker forks a process
 * that shares the pool's sockets and its database connections, and the corruption that
 * follows is the reason the packagers took the decision for us. So pcntl is genuinely
 * unavailable to the web tier on a CORRECTLY built image, and a single flat list would
 * have to choose between refusing every web request and letting the CLI commands that
 * do use it (Db_Rebuild_Provision_Cache_Snapshot_Command's SIGINT/SIGTERM handlers) go unguaranteed. The tier
 * split refuses neither: the web tier promises what the web tier can deliver, the CLI
 * tier promises what CLI code may assume, and both are declared here.
 *
 * ADDING TO EITHER LIST is a promise about every environment this framework runs in, so
 * it comes with the apt package in the framework's Dockerfile
 * (system/app/RSpade/resource/docker/Dockerfile) and a mention in `rsx:man health`.
 */
class Rsx_Php_Requirements
{
    /**
     * Every PHP extension the framework and the template application depend on.
     *
     * Sourced from three kinds of evidence, all of them checked - never from a guess
     * about what "a PHP box usually has":
     *
     *   - a hard `ext-*` require of an installed composer package (ctype, curl, dom,
     *     fileinfo, filter, hash, iconv, json, libxml, mbstring, openssl, pcre, phar,
     *     session, simplexml, tokenizer, xml, xmlwriter);
     *   - an unconditional call site in framework code (gmp - File_Storage_Model's
     *     content-hash increment; zlib - Zip_Stream's deflate_init; xmlreader - the
     *     streaming Office extractor; posix - the task liveness probes; imagick -
     *     File_Attachment_Icons; redis - RsxCache,
     *     the lock daemon and the realtime relay; pdo + pdo_mysql - every query);
     *   - a framework operation that is not PHP code but is not optional either (zip -
     *     composer extracts package dists with it);
     *   - a declared piece of the RSpade STANDARD LIBRARY (ldap, imap, sodium): a
     *     toolkit a downstream application is told it may reach for without asking
     *     whether the box has it. Directory authentication and mailbox reading are
     *     integrations an app writes rather than the framework, and a promise that is
     *     true only on the boxes that happen to have the package is not a toolkit. See
     *     `rsx:man standard_library`.
     *
     * zstd is declared AHEAD of its first call site, deliberately: it is the
     * compression the revision-history feature is being built on, and the apt package
     * is already in the framework image. Declaring it now means an environment that
     * lacks it says so at boot rather than at the first revision write.
     *
     * NOT here, and each for a reason:
     *
     *   - pcntl belongs to the CLI tier below, not to this one: Debian/sury do not
     *     compile it into php-fpm, so requiring it here would refuse every web request
     *     on a correctly-built image.
     *   - intl, bcmath, soap, exif, calendar, igbinary, msgpack, memcached, mysqli and
     *     sockets are present in this box's php -m but no framework or template-app
     *     code calls them.
     *
     * An extension nothing uses is not a requirement, and declaring one fails boxes for
     * no benefit. A CLI-only extension is not a requirement of the RUNTIME either.
     */
    public const REQUIRED_EXTENSIONS = [
        'bcmath',
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'filter',
        'gd',
        'gmp',
        'hash',
        'iconv',
        'imagick',
        'imap',
        'intl',
        'json',
        'ldap',
        'libxml',
        'mbstring',
        'openssl',
        'pcre',
        'pdo',
        'pdo_mysql',
        'phar',
        'posix',
        'redis',
        'session',
        'simplexml',
        'soap',
        // NOT in the Dockerfile, and must never be added to it: Sury compile sodium
        // into the PHP binary itself and publish no php<version>-sodium package, so an
        // apt line naming one fails the image build.
        'sodium',
        'tokenizer',
        'xml',
        'xmlreader',
        'xmlwriter',
        'zip',
        'zlib',
        'zstd',
    ];

    /**
     * Extensions required of the CLI SAPI ONLY, on top of the runtime list above.
     *
     * pcntl: Debian/sury compile it into php-cli and not into php-fpm, because forking
     * inside an fpm worker duplicates the pool's sockets and database connections and
     * corrupts them. That is not a packaging accident to work around - it is the right
     * decision, and it makes pcntl a CLI fact rather than a runtime fact.
     *
     * Declaring it here is what lets CLI code stop asking. Db_Rebuild_Provision_Cache_Snapshot_Command
     * installs SIGINT/SIGTERM handlers so an operator's Ctrl-C during a restore runs the
     * recovery path instead of leaving a half-restored datadir; it used to check
     * function_exists('pcntl_async_signals') and warn that recovery was unavailable.
     * With the CLI tier enforced at boot, the extension is guaranteed by the time any
     * command runs, and the guard is gone.
     */
    public const REQUIRED_CLI_EXTENSIONS = [
        'pcntl',
    ];

    /** Minimum supported PHP version. */
    public const MIN_PHP_VERSION = '8.4';

    /**
     * The commands EXEMPT from the boot check.
     *
     * A guard standing between the operator and the lever that fixes the thing it is
     * complaining about is not a guard, it is a deadlock - the same reasoning that
     * exempts the boot-free commands in system/artisan. rsx:health is how you find out
     * WHICH extension is missing and rsx:heal is how some of them get fixed, so both
     * run with the list unenforced and report the problem instead of inheriting it.
     *
     * Nothing else is exempt. Every other command, and every web request, refuses.
     */
    public const EXEMPT_COMMANDS = [
        'rsx:health',
        'rsx:heal',
    ];

    /**
     * The dev/prod container marker, written by the framework's own Dockerfile.
     *
     * Held as a property rather than read through Rsx::is_rspade_container() so a test
     * can point it at a fabricated path. The default IS that method's path, and
     * production code never assigns this.
     */
    private static ?string $_container_marker_path = null;

    /**
     * Extensions from the declared list that this PHP does not have.
     *
     * @return array<int, string>
     */
    public static function missing_extensions(): array
    {
        return static::missing_from(self::REQUIRED_EXTENSIONS);
    }

    /**
     * Extensions from the CLI tier that this PHP does not have.
     *
     * Asked unconditionally by rsx:health, which always runs under the CLI SAPI - the
     * report is about the box, not about the process asking.
     *
     * @return array<int, string>
     */
    public static function missing_cli_extensions(): array
    {
        return static::missing_from(self::REQUIRED_CLI_EXTENSIONS);
    }

    /**
     * The full list a given SAPI must satisfy: the runtime tier always, plus the CLI
     * tier under 'cli' and nowhere else.
     *
     * The SAPI is a PARAMETER rather than a read of PHP_SAPI so a test can ask what the
     * check would do under php-fpm without pretending to be php-fpm.
     *
     * @return array<int, string>
     */
    public static function extensions_for_sapi(string $sapi): array
    {
        if ($sapi === 'cli') {
            return array_merge(self::REQUIRED_EXTENSIONS, self::REQUIRED_CLI_EXTENSIONS);
        }

        return self::REQUIRED_EXTENSIONS;
    }

    /**
     * The same computation over an arbitrary list - the seam the tests drive.
     *
     * @param array<int, string> $extensions
     * @return array<int, string>
     */
    public static function missing_from(array $extensions): array
    {
        $missing = [];

        foreach ($extensions as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        return $missing;
    }

    /**
     * The boot check. Throws when any declared extension is absent.
     *
     * Called first thing in Rsx_Framework_Provider::boot() - the earliest point shared
     * by the web and CLI entrypoints at which a throw renders READABLY. Earlier is not
     * better here and was measured: from AppServiceProvider::register() the throw is
     * raised before the RSX autoloader exists, so the configured exception handler
     * cannot be resolved and the browser gets a blank fatal instead of an error page.
     * Same reasoning, and the same landing spot, as the deferred APP_URL scheme
     * enforcement beside it (see bootstrap/app.php).
     */
    public static function enforce(): void
    {
        if (static::is_exempt_invocation()) {
            return;
        }

        static::enforce_for_sapi(PHP_SAPI);
    }

    /**
     * enforce() with the SAPI handed in - the seam the tests drive.
     *
     * Under 'cli' the CLI tier applies on top of the runtime tier; under any other SAPI
     * only the runtime tier does.
     */
    public static function enforce_for_sapi(string $sapi): void
    {
        static::enforce_list(static::extensions_for_sapi($sapi));
    }

    /**
     * enforce() over an arbitrary list - the seam the tests drive.
     *
     * @param array<int, string> $extensions
     */
    public static function enforce_list(array $extensions): void
    {
        $missing = static::missing_from($extensions);

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(static::missing_message($missing));
    }

    /**
     * The message the boot throw carries: every missing extension by name, then how to
     * get it.
     *
     * @param array<int, string> $missing
     */
    public static function missing_message(array $missing): string
    {
        $names = implode(', ', $missing);
        $plural = count($missing) === 1 ? 'extension is' : 'extensions are';

        $message = "Required PHP {$plural} not loaded: {$names}. "
            . 'RSpade has no second code path for a missing extension - every one of '
            . 'these is called unconditionally by framework code. ';

        if (static::in_rspade_container()) {
            $message .= 'This is the RSpade container, so the image itself is missing '
                . static::apt_packages($missing) . ' - the development container image may '
                . 'need to be regenerated to install the additional dependency (add the '
                . 'package in ' . static::dockerfile_path() . ' and rebuild the image). ';
        } else {
            $message .= 'Install/enable ' . static::php_packages($missing) . '. ';
        }

        $message .= 'Run `php artisan rsx:health` for the full environment report.';

        return $message;
    }

    /**
     * The remediation text rsx:health puts on ONE missing-extension FAIL row.
     *
     * Same two branches as the boot message, sized for a table cell.
     */
    public static function remediation_for(string $extension): string
    {
        if (static::in_rspade_container()) {
            return 'the RSpade development container image may need to be regenerated to '
                . 'install the additional dependency: add ' . static::apt_packages([$extension])
                . ' to ' . static::dockerfile_path() . ' and rebuild the image';
        }

        return "install/enable the php-{$extension} extension";
    }

    /**
     * Whether this process is running inside an RSpade container image.
     *
     * Reads the /.rspade_container marker the framework's Dockerfile writes - the same
     * marker Rsx::is_rspade_container() and bootstrap/rsx_container_gate.php read, via
     * the test seam above.
     */
    public static function in_rspade_container(): bool
    {
        if (self::$_container_marker_path !== null) {
            return is_file(self::$_container_marker_path);
        }

        return Rsx::is_rspade_container();
    }

    /**
     * Whether the boot check must stand down for this invocation.
     *
     * CLI only, and argv[1] only - the same shape system/artisan's pre-boot
     * maintenance interception uses. A web request is never exempt: there is no
     * diagnostic to protect there.
     */
    public static function is_exempt_invocation(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }

        $command = $_SERVER['argv'][1] ?? '';

        return in_array($command, self::EXEMPT_COMMANDS, true);
    }

    /**
     * The apt package names for the running PHP, as the framework Dockerfile spells
     * them (php<major>.<minor>-<ext>).
     *
     * @param array<int, string> $extensions
     */
    public static function apt_packages(array $extensions): string
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $packages = [];
        foreach ($extensions as $extension) {
            $packages[] = 'php' . $version . '-' . static::__apt_suffix($extension);
        }

        return implode(', ', $packages);
    }

    /**
     * The generic (unversioned) package spelling, for a box outside the container.
     *
     * @param array<int, string> $extensions
     */
    public static function php_packages(array $extensions): string
    {
        $packages = [];
        foreach ($extensions as $extension) {
            $packages[] = 'php-' . static::__apt_suffix($extension);
        }

        return implode(', ', $packages);
    }

    /** Path to the framework Dockerfile, project-relative so it reads the same everywhere. */
    public static function dockerfile_path(): string
    {
        return 'system/app/RSpade/resource/docker/Dockerfile';
    }

    /**
     * Extension name -> the apt package suffix that provides it.
     *
     * Only the cases where the two differ are listed; everything else is its own
     * package name. A wrong suggestion here sends somebody to `apt-get install` a
     * package that does not exist, so the mapping is explicit rather than clever.
     */
    private static function __apt_suffix(string $extension): string
    {
        $map = [
            'pdo' => 'mysql',
            'pdo_mysql' => 'mysql',
            'dom' => 'xml',
            'libxml' => 'xml',
            'xmlreader' => 'xml',
            'xmlwriter' => 'xml',
            'simplexml' => 'xml',
            'ctype' => 'common',
            'filter' => 'common',
            'fileinfo' => 'common',
            'hash' => 'common',
            'iconv' => 'common',
            'json' => 'common',
            'openssl' => 'common',
            'pcre' => 'common',
            'phar' => 'common',
            'posix' => 'common',
            'session' => 'common',
            'tokenizer' => 'common',
            'zlib' => 'common',
        ];

        return $map[$extension] ?? $extension;
    }

    /**
     * TESTING SEAM: point the container marker at a fabricated path.
     *
     * The real /.rspade_container is never created, moved or removed by a test.
     */
    public static function _testing_set_container_marker(?string $path): void
    {
        self::$_container_marker_path = $path;
    }

    /** TESTING SEAM: restore the real marker resolution. */
    public static function _testing_reset(): void
    {
        self::$_container_marker_path = null;
    }
}
