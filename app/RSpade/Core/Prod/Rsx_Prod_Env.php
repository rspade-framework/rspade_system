<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Prod;

use App\RSpade\Core\Rsx;

/**
 * The .env mode writer: the SINGLE implementation of "change RSX_MODE in .env".
 *
 * rsx:prod:enable and rsx:prod:disable are its only callers, so there is exactly one
 * way a box changes mode. Discarding what the old mode built is rsx:clean's job, and
 * producing what the new one needs is rsx:build's.
 *
 * SINGLE-FILE .env ASSUMPTION: get_env_mode()/set_mode() read and write ONLY
 * base_path('.env'). That is correct by construction because Rsx_Env_Symlink keeps
 * base_path('.env') a symlink to the authoritative project-root .env (the healer
 * runs on framework pull, rsx:clean, and every prod-mode transition before this
 * class writes). If that invariant is ever broken, a write here would land in a
 * drifted file Laravel does not read - the healer, not a second write path here,
 * is the fix.
 */
class Rsx_Prod_Env
{
    /**
     * Read the current RSX_MODE straight from the .env file (not env(), which is
     * frozen at process boot).
     */
    public static function get_env_mode(): string
    {
        $env_path = base_path('.env');
        if (!file_exists($env_path)) {
            return Rsx::MODE_DEVELOPMENT;
        }

        $contents = file_get_contents($env_path);
        $found = preg_match('/^RSX_MODE=(.*)$/m', $contents, $matches);
        if ($found) {
            return trim($matches[1]);
        }

        return Rsx::MODE_DEVELOPMENT;
    }

    /**
     * Write RSX_MODE into the .env file, then commit the new mode to this running
     * process (see _apply_mode_to_process).
     */
    public static function set_mode(string $mode): void
    {
        $env_path = base_path('.env');

        if (!file_exists($env_path)) {
            file_put_contents_safe($env_path, "RSX_MODE={$mode}\n");
            self::_apply_mode_to_process($mode);

            return;
        }

        $contents = file_get_contents($env_path);

        // Insertion anchor: APP_URL, the one key every install has. (It used to be
        // APP_DEBUG, which is no longer a key RSpade reads or expects to find.)
        if (preg_match('/^RSX_MODE=.*$/m', $contents)) {
            $contents = preg_replace('/^RSX_MODE=.*$/m', "RSX_MODE={$mode}", $contents);
        } elseif (preg_match('/^APP_URL=.*$/m', $contents)) {
            $contents = preg_replace('/^(APP_URL=.*)$/m', "$1\nRSX_MODE={$mode}", $contents, 1);
        } else {
            $contents = rtrim($contents) . "\nRSX_MODE={$mode}\n";
        }

        file_put_contents_safe($env_path, $contents);
        self::_apply_mode_to_process($mode);
    }

    /**
     * Commit a just-written RSX_MODE to THIS process's environment + mode cache.
     *
     * Writing .env is not enough: the mode commands passthru a build subprocess, and
     * that child INHERITS this process's environment. Laravel's Dotenv does NOT
     * override an already-set env var, so a child that inherits the parent's boot-time
     * RSX_MODE ignores the .env value we just wrote - the classic symptom being an
     * rsx:prod:enable (parent booted in development) that spawns a build which runs
     * unminified/unstripped, and the mirror-image rsx:prod:disable (parent booted in
     * production) whose development build refuses as unsealed. Syncing the process env
     * here makes the inherited value match .env. RSX_MODE is a deployment env fact (the
     * env-params ruling lists it as env-worthy) - this keeps the environment
     * consistent with .env, it is NOT an invocation parameter.
     */
    private static function _apply_mode_to_process(string $mode): void
    {
        putenv("RSX_MODE={$mode}");
        $_ENV['RSX_MODE'] = $mode;
        $_SERVER['RSX_MODE'] = $mode;
        Rsx::clear_mode_cache();
    }
}
