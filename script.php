<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * THE EXTERNAL-SCRIPT BOOT ENTRY.
 *
 * One include brings the whole RSpade shell up for a PHP script that lives outside the
 * application tree - a standalone tooling repository, a one-off maintenance script, a
 * cron job:
 *
 *     #!/usr/bin/env php
 *     <?php
 *     $app = require '/path/to/project/system/script.php';
 *
 * What comes up is exactly what an artisan command gets: the pre-boot guards, the
 * manifest, the RSpade autoloader, the polymorphic morph map, Main::init() and with it
 * the application's declared site, the ORM and every model hook. What does NOT come up
 * is Symfony's console: nothing is dispatched, there is no Task_Instance and no
 * progress bar. The script's own arguments are the script's business - the framework
 * reads no command name out of them.
 *
 * REVISIONS. The run is ONE unit of work, whose transaction row names the script file
 * as its endpoint. A script that writes many records declares its own boundaries with
 * Revision::begin_unit_of_work() / Revision::unit_of_work(), or its whole life is filed
 * as one change.
 *
 * OPTIONS. Set $RSX_SCRIPT_OPTIONS before the require. One key is defined:
 *
 *     $RSX_SCRIPT_OPTIONS = ['force' => true];   // run during maintenance mode
 *
 * Maintenance mode otherwise REFUSES the process with 503 and exit code 75, which is
 * the point: a long write run must not land in the middle of what the window was
 * raised for.
 *
 * EXIT STATUS. 0 when the script runs to its end, whatever it passes to exit() when it
 * calls it, 1 when it dies of an uncaught exception or error, 255 on a PHP fatal.
 *
 * WORKING DIRECTORY. Left at the framework tree (system/), as under artisan. A script
 * that needs its own directory saves getcwd() before the include.
 *
 * Full treatment, including the identity contract and the worked example:
 * rsx:man scripting.
 *
 * @return \Illuminate\Foundation\Application
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "system/script.php is the CLI script entry point and cannot be used under the '" . PHP_SAPI . "' SAPI.\n");
    fwrite(STDERR, "A web request enters through system/public/index.php.\n");
    exit(1);
}

// Declared BEFORE anything reads argv: it is what tells the framework's argv sniffs
// that the first argument is the script's own and not a command name.
// The booted-world reader is App\RSpade\Core\Console\Rsx_Script.
define('RSX_SCRIPT_MODE', true);

if (!defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

require_once __DIR__ . '/bootstrap/rsx_preboot.php';

rsx_preboot([
    'script' => true,
    'force' => !empty($GLOBALS['RSX_SCRIPT_OPTIONS']['force']),
]);

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

// bootstrap(), never handle(): the providers, the manifest, the autoloaders, the morph
// map and Main::init() all run, and no command is dispatched.
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// One script run is one unit of work for revision history, named after the script file. A
// script performing many units (an import) declares each one itself with
// Revision::begin_unit_of_work() or Revision::unit_of_work() - rsx:man scripting.
\App\RSpade\Core\Revisions\Revision::_reset_request_state('cli', basename((string) ($_SERVER['argv'][0] ?? 'script')));

// EXIT STATUS. PHP ends a script with status 0 once a registered exception handler has
// returned, and the application's handler is one: it reports and prints an uncaught
// exception, then returns. Everything that runs a script - a shell && chain, cron, a CI
// step, a parent's passthru() - reads the status and nothing else, so an uncaught
// Throwable is handed to the application's handler as before and the process then ends
// with status 1. A fatal PHP cannot hand to a handler (memory exhausted) already ends 255,
// and a script's own exit(N) means what it says.
(static function (): void {
    $application_handler = null;
    $application_handler = set_exception_handler(static function (\Throwable $e) use (&$application_handler): void {
        if ($application_handler !== null) {
            $application_handler($e);
        } else {
            fwrite(STDERR, (string) $e . "\n");
        }

        exit(1);
    });
})();

return $app;
