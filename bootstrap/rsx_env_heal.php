<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT ENV HEAL
 *
 * An RSpade project carries a TRACKED `.env.dist` at its root - the canonical
 * default settings - and an UNTRACKED `.env` that is this one machine's own
 * configuration. This guard makes the second follow from the first: it creates
 * `.env` when it is absent, adds keys that appeared in `.env.dist` since the
 * last boot, checks the credentials the application cannot start without, and
 * mints an APP_KEY in development when there is none.
 *
 * It runs from BOTH entrypoints (`system/artisan`, `system/public/index.php`)
 * and from the container entrypoint, which invokes this file directly:
 *
 *     php /var/www/html/system/bootstrap/rsx_env_heal.php
 *
 * That is the whole reason this file exists as a separate runner rather than
 * living in the class: it must work with no autoloader, no config, and no
 * Laravel - the framework cannot boot until the file this creates is there.
 * The IMPLEMENTATION is App\RSpade\Core\Prod\Rsx_Env_Symlink::full_heal(), the
 * one and only copy of it, which `php artisan rsx:env:heal` runs as well.
 *
 * IN DEVELOPMENT THIS RUNS ON EVERY BOOT, so the common path is one filesystem
 * stat against a stamp under storage/rsx-tmp and nothing else (see boot_heal()).
 *
 * Report lines are printed on the CLI only. A web request cannot have framework
 * chatter injected into its response, so there the work is done silently -
 * except for a failure, which is a plain-text 500, because booting against a
 * .env that is missing or incoherent is not something to paper over.
 */

(static function (): void {
    $system_dir = dirname(__DIR__);

    if (!class_exists(\App\RSpade\Core\Prod\Rsx_Env_Symlink::class, false)) {
        require_once $system_dir . '/app/RSpade/Core/Prod/Rsx_Env_Symlink.php';
    }

    \App\RSpade\Core\Prod\Rsx_Env_Symlink::_set_paths_from_system_dir($system_dir);

    try {
        $report = \App\RSpade\Core\Prod\Rsx_Env_Symlink::boot_heal();

        if (PHP_SAPI === 'cli') {
            foreach ($report['actions'] as $action) {
                echo 'rsx env: ' . $action . "\n";
            }
        }
    } catch (\Throwable $e) {
        $message = "RSpade: the environment configuration could not be prepared.\n\n"
            . $e->getMessage() . "\n";

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message);
        } else {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $message;
        }

        exit(1);
    } finally {
        \App\RSpade\Core\Prod\Rsx_Env_Symlink::_clear_path_overrides();
    }
})();
