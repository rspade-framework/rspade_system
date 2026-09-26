<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Health\Rsx_Php_Requirements;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Prod\Rsx_Env_Symlink;
use App\RSpade\Core\Rsx;

/**
 * Environment_Health_Checks - the PHP runtime, Node binary, storage writability,
 * env-encryption posture and application-mode posture checks for rsx:health.
 *
 * These are cross-cutting environment invariants with no single feature file to hang
 * them on, so they live together in the Health domain. Each method is a public static
 * `#[Health_Check('label')]` (a bare marker attribute - never a defined class), invoked
 * by Health_Check_Runner and returning a row (or a list of rows) per the runner's
 * contract.
 */
class Environment_Health_Checks
{
    /*
     * The required-extension list and the minimum PHP version are NOT declared here.
     * They live in Rsx_Php_Requirements, which the boot check reads too - one list,
     * two consumers, so the guard and the diagnostic can never disagree about what
     * this framework needs.
     */

    /**
     * PHP version + required extensions.
     *
     * @return array
     */
    #[Health_Check('PHP')]
    public static function php_environment(): array
    {
        $rows = [];

        // PHP version.
        if (version_compare(PHP_VERSION, Rsx_Php_Requirements::MIN_PHP_VERSION, '>=')) {
            $rows[] = ['label' => 'PHP Version', 'status' => 'OK', 'detail' => 'PHP ' . PHP_VERSION];
        } else {
            $rows[] = [
                'label' => 'PHP Version',
                'status' => 'FAIL',
                'detail' => 'PHP ' . PHP_VERSION . ' is below the required ' . Rsx_Php_Requirements::MIN_PHP_VERSION,
                'remediation' => 'upgrade PHP to ' . Rsx_Php_Requirements::MIN_PHP_VERSION . ' or newer',
            ];
        }

        // Required extensions: a summary OK for those present, a FAIL row per missing one.
        $missing = Rsx_Php_Requirements::missing_extensions();
        $present = array_values(array_diff(Rsx_Php_Requirements::REQUIRED_EXTENSIONS, $missing));

        if (!empty($present)) {
            $rows[] = [
                'label' => 'PHP Extensions',
                'status' => 'OK',
                'detail' => 'loaded: ' . implode(', ', $present),
            ];
        }

        foreach ($missing as $ext) {
            $rows[] = [
                'label' => 'PHP Extension: ' . $ext,
                'status' => 'FAIL',
                'detail' => "required extension '{$ext}' is not loaded",
                'remediation' => Rsx_Php_Requirements::remediation_for($ext),
            ];
        }

        foreach (static::cli_tier_rows(PHP_SAPI) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The CLI extension tier, as seen from the SAPI this report runs under.
     *
     * Reported separately because it is a promise about a different SAPI: pcntl is
     * compiled into php-cli and NOT into php-fpm, so a box can be correct for the web
     * tier and wrong for the CLI tier. Under the CLI SAPI (rsx:health) what this process
     * sees IS what a command would get. Under any other SAPI - the report run inside a
     * web request, by the /_sys dashboard - the CLI tier is not observable at all, so
     * the answer is one INFO row saying so rather than a FAIL per extension the web
     * tier was never meant to carry.
     *
     * The SAPI is a PARAMETER (as in Rsx_Php_Requirements::extensions_for_sapi()) so a
     * test can ask for the web-request answer without being php-fpm.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cli_tier_rows(string $sapi): array
    {
        if ($sapi !== 'cli') {
            return [[
                'label' => 'PHP CLI Extensions',
                'status' => 'INFO',
                'detail' => "not observable from the {$sapi} SAPI this report runs under"
                    . ' - run php artisan rsx:health to check the CLI tier ('
                    . implode(', ', Rsx_Php_Requirements::REQUIRED_CLI_EXTENSIONS) . ')',
            ]];
        }

        $rows = [];

        $cli_missing = Rsx_Php_Requirements::missing_cli_extensions();
        $cli_present = array_values(array_diff(Rsx_Php_Requirements::REQUIRED_CLI_EXTENSIONS, $cli_missing));

        if (!empty($cli_present)) {
            $rows[] = [
                'label' => 'PHP CLI Extensions',
                'status' => 'OK',
                'detail' => 'loaded: ' . implode(', ', $cli_present),
            ];
        }

        foreach ($cli_missing as $ext) {
            $rows[] = [
                'label' => 'PHP CLI Extension: ' . $ext,
                'status' => 'FAIL',
                'detail' => "required CLI extension '{$ext}' is not loaded",
                'remediation' => Rsx_Php_Requirements::remediation_for($ext),
            ];
        }

        return $rows;
    }

    /**
     * Node.js binary (realtime relay, fpc-proxy, rsx:debug, build tooling).
     *
     * MODE: every mode. node is not development tooling here - the realtime relay and
     * the fpc proxy are node daemons a production box runs.
     *
     * @return array
     */
    #[Health_Check('Node.js')]
    public static function node_binary(): array
    {
        $process = new Process(['node', '--version']);
        // NO TIMEOUT (null). A probe that hangs means the thing it probes is wedged,
        // and that is a fault to SEE, not to convert into a tidy FAIL row that reads the
        // same as "not installed". See the no-timeout mandate.
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            return [
                'status' => 'FAIL',
                'detail' => 'node not found on PATH',
                'remediation' => 'install nodejs (apt-get install -y nodejs)',
            ];
        }

        return ['status' => 'OK', 'detail' => 'node ' . trim($process->getOutput())];
    }

    /**
     * The three project trees a running application writes to, and whether this process
     * can write them.
     *
     * storage/ holds USER DATA and tmp/ holds derived caches and runtime temp, so both
     * must be writable in EVERY mode - a box that cannot write them cannot accept an
     * upload or compile a template. build/ is different: it holds build OUTPUTS, which
     * in a production mode are written once by `rsx:build` and then only read, so it is
     * required writable in development and merely REPORTED in a production mode (see
     * readonly_posture()).
     *
     * EXIST-OR-CREATE: a missing tree is created here rather than reported, because all
     * three are created on demand by the framework anyway and a health check that FAILs
     * on a state the next command repairs is noise. What is NOT repairable - absent and
     * uncreatable, or present and unwritable - is a FAIL.
     *
     * @return array
     */
    #[Health_Check('Storage Writable')]
    public static function storage_writability(): array
    {
        $rows = [];

        $rows[] = static::_tree_row('storage/', Rsx_Project_Paths::storage_root());
        $rows[] = static::_tree_row('tmp/', Rsx_Project_Paths::tmp_root());

        if (Rsx::is_development()) {
            $rows[] = static::_tree_row('build/', Rsx_Project_Paths::build_root());
        }

        // Feature subdirs: created on first use. A missing one passes when the nearest
        // existing ancestor is writable (the first use will create it); it is a WARN only
        // when that ancestor is not.
        $subdirs = [
            'storage/logs' => Rsx_Project_Paths::logs_dir(),
            'tmp/thumbnails' => Rsx_File_Paths::thumbnails_root(),
            'tmp/renditions' => Rsx_File_Paths::renditions_root(),
        ];

        foreach (['logs' => Rsx_Project_Paths::logs_dir(), 'app' => Rsx_Project_Paths::app_dir()] as $name => $target) {
            $rows[] = static::_storage_link_row($name, $target);
        }

        $rows[] = static::_php_temp_dir_row();

        foreach ($subdirs as $label => $path) {
            if (!is_dir($path)) {
                $parent = dirname($path);
                while (!is_dir($parent) && dirname($parent) !== $parent) {
                    $parent = dirname($parent);
                }

                if (is_writable($parent)) {
                    $rows[] = ['label' => $label, 'status' => 'OK', 'detail' => 'not created yet; ' . $parent . ' is writable, so first use will create it'];
                } else {
                    $rows[] = [
                        'label' => $label,
                        'status' => 'WARN',
                        'detail' => 'does not exist yet and ' . $parent . ' is not writable',
                        'remediation' => 'chmod/chown ' . $parent . ' so the web/CLI user can create ' . $path,
                    ];
                }
            } elseif (!is_writable($path)) {
                $rows[] = [
                    'label' => $label,
                    'status' => 'FAIL',
                    'detail' => 'not writable: ' . $path,
                    'remediation' => 'chmod/chown ' . $path . ' so the web/CLI user can write',
                ];
            } else {
                $rows[] = ['label' => $label, 'status' => 'OK', 'detail' => 'writable'];
            }
        }

        return $rows;
    }

    /**
     * One of the two links inside tmp/ that send a persistent name back into storage.
     *
     * Laravel's storage path is the tmp tree, so storage_path('logs') and
     * storage_path('app') resolve under tmp/ - and tmp/ is wiped by rsx:clean. These
     * two links are what keeps those two names persistent, which makes a broken one a
     * silent data-loss shape rather than an error.
     *
     * @param string $name 'logs' | 'app'
     * @param string $target The persistent directory it must reach
     * @return array
     */
    private static function _storage_link_row(string $name, string $target): array
    {
        $link = Rsx_Project_Paths::tmp_path($name);
        $label = 'tmp/' . $name . ' link';

        if (is_link($link)) {
            if (readlink($link) === $target) {
                return ['label' => $label, 'status' => 'OK', 'detail' => '-> ' . $target];
            }

            return [
                'label' => $label,
                'status' => 'FAIL',
                'detail' => 'points at ' . (readlink($link) ?: '(unreadable)') . ', not ' . $target,
                'remediation' => 'the tmp skeleton repairs it: php artisan rsx:clean, or any boot',
            ];
        }

        if (file_exists($link)) {
            return [
                'label' => $label,
                'status' => 'FAIL',
                'detail' => 'a real ' . (is_dir($link) ? 'directory' : 'file') . ' sits where the link belongs',
                'remediation' => 'move what is in ' . $link . ' somewhere permanent, remove it, then boot again',
            ];
        }

        return [
            'label' => $label,
            'status' => 'FAIL',
            'detail' => 'missing - the persistent ' . $name . ' directory is not reachable from tmp/',
            'remediation' => 'the tmp skeleton recreates it: php artisan rsx:clean, or any boot',
        ];
    }

    /**
     * Where a plain library's scratch file lands.
     *
     * PHP answers sys_get_temp_dir() from the sys_temp_dir ini setting, then TMPDIR,
     * then /tmp. The framework exports TMPDIR in both entrypoints, so a WARN here means
     * something upstream - a php-fpm pool, an ini, a supervisor program - answered
     * first, and third-party scratch is landing outside the project.
     *
     * @return array
     */
    private static function _php_temp_dir_row(): array
    {
        $temp = rtrim(sys_get_temp_dir(), '/');
        $tmp_root = Rsx_Project_Paths::tmp_root();

        if ($temp === $tmp_root || str_starts_with($temp . '/', $tmp_root . '/')) {
            return ['label' => 'PHP temp dir', 'status' => 'OK', 'detail' => $temp];
        }

        return [
            'label' => 'PHP temp dir',
            'status' => 'WARN',
            'detail' => $temp . ' is outside the tmp tree (' . $tmp_root . ')',
            'remediation' => 'set TMPDIR to the tmp root for this process (the php-fpm pool env, the container entrypoint, the supervisor program)',
        ];
    }

    /**
     * An encrypted copy of the environment file beside a decrypted one.
     *
     * .env.encrypted is a SNAPSHOT, not a live file: nothing keeps it in step with
     * .env, so every key sync the heal performs and every APP_KEY it mints leaves it
     * further behind. On a production box that is the point - the encrypted file is
     * what was deployed - so it is only INFO there. On a development box, where the
     * heal rewrites .env routinely, a stale snapshot is a trap worth naming.
     *
     * @return array
     */
    #[Health_Check('Env Encryption')]
    public static function env_encryption(): array
    {
        if (!is_file(Rsx_Env_Symlink::get_root_env_encrypted_path())) {
            return ['status' => 'OK', 'detail' => 'no encrypted copy'];
        }

        if (Rsx::is_production()) {
            return ['status' => 'INFO', 'detail' => '.env.encrypted present at the project root'];
        }

        return [
            'status' => 'WARN',
            'detail' => '.env.encrypted present beside a decrypted .env on a development box',
            'remediation' => 'it goes stale after every heal sync or APP_KEY mint - re-run php artisan env:encrypt --force, or delete it',
        ];
    }

    /**
     * Application-mode sanity: the active RSX_MODE, the deployed trees' read-only
     * posture, and the log level a sealed build writes at.
     *
     * MODE: every mode. It reports WHICH mode is active, which is a question with an
     * answer everywhere; the rows that only mean something on a sealed box live in
     * Production_Health_Checks, which declares the two prod modes. Login auto-fill moved
     * there as a FAIL - a working credential on an unauthenticated page is not advisory.
     *
     * @return array
     */
    #[Health_Check('Application Mode')]
    public static function mode_sanity(): array
    {
        $rows = [];

        $mode = Rsx::get_mode();

        if ($mode === Rsx::MODE_DEVELOPMENT) {
            $rows[] = [
                'label' => 'RSX Mode',
                'status' => 'INFO',
                'detail' => 'development (auto-rebuild, JIT compile, full debugging)',
            ];
        } else {
            $rows[] = [
                'label' => 'RSX Mode',
                'status' => 'INFO',
                'detail' => $mode . ' (sealed build)',
            ];
        }

        // The recommended production posture: the deployed trees read-only to this user.
        if (Rsx::is_production()) {
            foreach (static::readonly_posture_rows() as $row) {
                $rows[] = $row;
            }
        }

        // DEBUG logging on a production build writes every query, every dispatch and
        // every framework notice to disk forever. It fills a volume and it puts request
        // detail in a file that is not the place for it.
        $log_level = static::_effective_log_level();
        if (Rsx::is_production() && $log_level === 'debug') {
            $rows[] = [
                'label' => 'Log Level',
                'status' => 'WARN',
                'detail' => "the '" . config('logging.default') . "' log channel resolves to level debug on a production-mode box",
                'remediation' => 'set LOG_LEVEL=info in .env',
            ];
        }

        return $rows;
    }

    /**
     * The read-only posture of a correctly configured production box, as INFO rows.
     *
     * On a production host system/, rsx/ and build/ are the DEPLOYED ARTIFACT: source
     * that was built elsewhere and is only read here. Making them read-only to the
     * process that serves requests is the recommended posture (rsx:man prod), and it is
     * a posture rather than a requirement - a box that has not adopted it works fine.
     *
     * So these rows never FAIL and never WARN. They state what is true, with the expected
     * answer written beside it, because "is my prod box actually read-only" is a question
     * an operator has no other way to ask and an INFO row answers in one line.
     *
     * Reported by mode_sanity() rather than as a check of its own, because in development
     * all three trees are written constantly and there is nothing to report - and a check
     * that returns no rows at all is out of contract.
     *
     * @return array
     */
    public static function readonly_posture_rows(): array
    {
        $trees = [
            'system/' => base_path(),
            'rsx/' => rsx_project_file_path('rsx'),
            'build/' => Rsx_Project_Paths::build_root(),
        ];

        $rows = [];

        foreach ($trees as $label => $path) {
            if (!is_dir($path)) {
                $rows[] = [
                    'label' => $label,
                    'status' => 'INFO',
                    'detail' => 'does not exist: ' . $path,
                ];

                continue;
            }

            $writable = is_writable($path);

            $rows[] = [
                'label' => $label,
                'status' => 'INFO',
                'detail' => $writable
                    ? 'WRITABLE by ' . static::__process_user() . ' - the recommended production posture is read-only'
                    : 'read-only to ' . static::__process_user() . ' (the recommended production posture)',
            ];
        }

        return $rows;
    }

    /**
     * One exist-or-create + writable row for a project tree.
     *
     * Public so the health tests can drive every branch against a sandbox path: two of
     * the three trees this reports on are not redirectable per-process, and a check whose
     * failure branches cannot be exercised is a check nobody has ever seen fail.
     *
     * @return array{label: string, status: string, detail: string, remediation?: string}
     */
    public static function _tree_row(string $label, string $path): array
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        if (!is_dir($path)) {
            return [
                'label' => $label,
                'status' => 'FAIL',
                'detail' => 'missing and could not be created: ' . $path,
                'remediation' => 'create ' . $path . ' and make it writable by the web/CLI user',
            ];
        }

        if (!is_writable($path)) {
            return [
                'label' => $label,
                'status' => 'FAIL',
                'detail' => 'not writable: ' . $path,
                'remediation' => 'chmod/chown ' . $path . ' so the web/CLI user can write',
            ];
        }

        return ['label' => $label, 'status' => 'OK', 'detail' => 'writable'];
    }

    /**
     * The level the DEFAULT log channel actually writes at, lowercased.
     *
     * The default channel is normally `stack`, which carries no level of its own - the
     * level lives on each channel it wraps. So a stack is resolved to the LOUDEST level
     * among its members: that is the level a message has to clear to be written
     * somewhere, which is the thing being asked about. Null when nothing declares one.
     *
     * Public for the same reason _tree_row() is: the branches are worth testing and the
     * input is config, not this box.
     */
    public static function _effective_log_level(?string $channel = null): ?string
    {
        $channel = $channel ?? (string) config('logging.default');
        $config = config('logging.channels.' . $channel);

        if (!is_array($config)) {
            return null;
        }

        if (($config['driver'] ?? null) === 'stack') {
            $loudest = null;

            foreach ((array) ($config['channels'] ?? []) as $member) {
                $level = static::_effective_log_level((string) $member);

                if ($level === null) {
                    continue;
                }

                if ($loudest === null || static::__log_level_rank($level) < static::__log_level_rank($loudest)) {
                    $loudest = $level;
                }
            }

            return $loudest;
        }

        $level = $config['level'] ?? null;

        return is_string($level) && $level !== '' ? strtolower($level) : null;
    }

    /**
     * Monolog's severity order, quietest level = highest rank. An unknown name sorts as
     * the quietest so it can never masquerade as debug.
     */
    private static function __log_level_rank(string $level): int
    {
        $order = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $position = array_search(strtolower($level), $order, true);

        return $position === false ? count($order) : $position;
    }

    /**
     * The user this process runs as, for the posture rows' detail text.
     *
     * posix is a REQUIRED extension (Rsx_Php_Requirements), so the functions exist.
     * posix_getpwuid() still answers false for a uid with no passwd entry - a container
     * running as an arbitrary uid - and the numeric form is the honest answer there.
     */
    private static function __process_user(): string
    {
        $entry = posix_getpwuid(posix_geteuid());
        $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';

        return $name !== '' ? $name : 'uid ' . posix_geteuid();
    }
}
