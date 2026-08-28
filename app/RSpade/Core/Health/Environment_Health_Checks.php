<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Health\Rsx_Php_Requirements;
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

        // The CLI tier, reported separately because it is a promise about a different
        // SAPI: pcntl is compiled into php-cli and NOT into php-fpm, so a box can be
        // correct for the web tier and wrong for the CLI tier. rsx:health always runs
        // under the CLI SAPI, so what it sees here IS what a command would get.
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
     * Node.js binary (realtime relay, rsx:debug, build tooling).
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
     * Storage directory writability. The base storage_path() must exist and be writable;
     * the feature subdirs are created lazily on first use, so a missing one is a WARN.
     *
     * @return array
     */
    #[Health_Check('Storage Writable')]
    public static function storage_writability(): array
    {
        $rows = [];

        // The base storage directory is not created lazily - a missing one is a real fault.
        $base = storage_path();
        if (!is_dir($base)) {
            $rows[] = [
                'label' => 'storage/',
                'status' => 'FAIL',
                'detail' => 'storage directory missing: ' . $base,
                'remediation' => 'create ' . $base . ' and make it writable by the web/CLI user',
            ];
        } elseif (!is_writable($base)) {
            $rows[] = [
                'label' => 'storage/',
                'status' => 'FAIL',
                'detail' => 'not writable: ' . $base,
                'remediation' => 'chmod/chown ' . $base . ' so the web/CLI user can write',
            ];
        } else {
            $rows[] = ['label' => 'storage/', 'status' => 'OK', 'detail' => 'writable'];
        }

        // Feature subdirs: created on first use, so a missing one is only a WARN.
        $subdirs = [
            'storage/logs' => storage_path('logs'),
            'storage/rsx-thumbnails' => storage_path('rsx-thumbnails'),
            'storage/rsx-renditions' => storage_path('rsx-renditions'),
        ];

        foreach ($subdirs as $label => $path) {
            if (!is_dir($path)) {
                $rows[] = [
                    'label' => $label,
                    'status' => 'WARN',
                    'detail' => 'does not exist yet',
                    'remediation' => 'created lazily on first use - no action needed unless a feature fails to write it',
                ];
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
     * Application-mode sanity: the active RSX_MODE, plus a warning for a risky prod-mode
     * posture (debug-site backdoors active on a prod-mode box).
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

        // Credential auto-fill on a production build puts a working login on an
        // unauthenticated page. It is a development convenience and nothing else.
        if (Rsx::is_production() && config('rsx.development.login_autofill')) {
            $rows[] = [
                'label' => 'Login Auto-fill',
                'status' => 'WARN',
                'detail' => 'the login form pre-fills RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD on a production-mode box',
                'remediation' => 'clear RSPADE_LOGIN_AUTOFILL in .env',
            ];
        }

        return $rows;
    }
}
